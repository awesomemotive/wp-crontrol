<?php
/**
 * Cron alerts: an email when a logged cron event fails.
 *
 * Alerts only cover events that have logging turned on. Emails are sent with wp_mail() once the
 * request has finished, never from inside a running event, and they're throttled so a failing
 * event can't flood an inbox.
 */

namespace Crontrol\Alerts;

use Crontrol\Pro\EventRun;
use Crontrol\Pro\Log;

const SETTINGS_OPTION = 'crontrol_alerts';
const STATE_OPTION    = 'crontrol_alerts_state';
const DIGEST_HOOK     = 'crontrol/alerts_digest';

/**
 * At most one email per hook in this many seconds, when alerts are sent for each failure.
 */
const HOOK_THROTTLE = HOUR_IN_SECONDS;

/**
 * At most this many alert emails per hour for the whole site.
 */
const SITE_LIMIT = 10;

/**
 * At most this many failures are kept for the daily digest.
 */
const DIGEST_LIMIT = 50;

add_action( 'crontrol/run_finished', __NAMESPACE__ . '\on_run_finished' );
add_action( DIGEST_HOOK, __NAMESPACE__ . '\send_digest' );
add_action( 'admin_init', __NAMESPACE__ . '\handle_actions' );

/**
 * Returns the alert settings.
 *
 * @return array{enabled: bool, recipients: string, mode: string}
 */
function get_alert_settings(): array {
	$settings = get_option( SETTINGS_OPTION, array() );
	$settings = is_array( $settings ) ? $settings : array();

	return array(
		'enabled'    => ! empty( $settings['enabled'] ),
		'recipients' => isset( $settings['recipients'] ) && is_string( $settings['recipients'] ) ? $settings['recipients'] : '',
		'mode'       => ( isset( $settings['mode'] ) && 'digest' === $settings['mode'] ) ? 'digest' : 'each',
	);
}

/**
 * Returns the stored alert state: throttling, the digest, and the last send result.
 *
 * @return array{sent: array<string,int>, window_start: int, window_count: int, digest: list<array<string,mixed>>, last_result: array<string,mixed>}
 */
function get_state(): array {
	$state = get_option( STATE_OPTION, array() );
	$state = is_array( $state ) ? $state : array();

	return array(
		'sent'         => isset( $state['sent'] ) && is_array( $state['sent'] ) ? $state['sent'] : array(),
		'window_start' => (int) ( $state['window_start'] ?? 0 ),
		'window_count' => (int) ( $state['window_count'] ?? 0 ),
		'digest'       => isset( $state['digest'] ) && is_array( $state['digest'] ) ? array_values( $state['digest'] ) : array(),
		'last_result'  => isset( $state['last_result'] ) && is_array( $state['last_result'] ) ? $state['last_result'] : array(),
	);
}

/**
 * Saves the alert state.
 *
 * @param array<string,mixed> $state The alert state.
 * @return bool Whether the state is now stored. update_option() also returns false when nothing
 *              changed, so a false result is checked against a fresh read.
 */
function save_state( array $state ): bool {
	if ( update_option( STATE_OPTION, $state, false ) ) {
		return true;
	}

	wp_cache_delete( STATE_OPTION, 'options' );

	return get_option( STATE_OPTION ) === $state;
}

/**
 * Runs a change to the alert state while holding a database lock, so overlapping cron requests
 * can't both pass the throttle or overwrite each other's changes. The state is read fresh from the
 * database inside the lock.
 *
 * If the lock can't be had within two seconds, the change is skipped: for alerts it's better to
 * miss one email than to send a burst.
 *
 * @param callable $change Receives the current state and returns the new state, or null to leave it.
 * @return bool Whether the change ran and, if it changed the state, the new state was stored.
 */
function with_state_lock( callable $change ): bool {
	global $wpdb;

	// Lock names are server-wide, so the name includes this install's database, prefix, and site.
	$lock = 'crontrol_alerts_' . md5( DB_NAME . '|' . $wpdb->prefix );

	if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 2)', $lock ) ) ) {
		return false;
	}

	$saved = false;

	try {
		wp_cache_delete( STATE_OPTION, 'options' );
		wp_cache_delete( 'notoptions', 'options' );

		$state = $change( get_state() );
		$saved = is_array( $state ) ? save_state( $state ) : true;
	} finally {
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
	}

	return $saved;
}

/**
 * Returns the valid recipient addresses, up to ten. Falls back to the site's admin email.
 *
 * @return list<string>
 */
function get_recipients(): array {
	$recipients = array();

	foreach ( preg_split( '/[\s,;]+/', get_alert_settings()['recipients'] ) ?: array() as $address ) {
		$address = sanitize_email( $address );

		if ( is_email( $address ) ) {
			$recipients[] = $address;
		}
	}

	$recipients = array_slice( array_values( array_unique( $recipients ) ), 0, 10 );

	if ( ! $recipients && is_email( get_option( 'admin_email' ) ) ) {
		$recipients[] = (string) get_option( 'admin_email' );
	}

	return $recipients;
}

/**
 * Failures waiting to be emailed at the end of this request.
 *
 * @return list<array<string,mixed>>
 */
function &queue(): array {
	static $queue = array();
	return $queue;
}

/**
 * Describes a failed run for an alert email.
 *
 * @param EventRun $run The failed run.
 *
 * @return array<string,mixed>
 */
function describe_run( EventRun $run ): array {
	return array(
		'site_id' => $run->site_id,
		'id'      => $run->ID,
		'hook'    => $run->hook,
		'time'    => $run->started_at->getTimestamp(),
		'fatal'   => $run->fatal,
		'message' => isset( $run->errors[0]['message'] ) ? substr( (string) $run->errors[0]['message'], 0, 500 ) : '',
	);
}

/**
 * Queues an alert for a failed run, or adds it to the digest.
 *
 * Run using the 'crontrol/run_finished' action.
 *
 * @param EventRun $run The finished run.
 */
function on_run_finished( EventRun $run ): void {
	if ( ! $run->is_errored() ) {
		return;
	}

	// An event can finish in another site's context, for example if it switched sites and threw.
	// Its alert belongs to the site it started on: that site's settings, throttle, and recipients.
	$switched = is_multisite() && $run->site_id > 0 && get_current_blog_id() !== $run->site_id;

	if ( $switched ) {
		switch_to_blog( $run->site_id );
	}

	try {
		queue_failure( $run );
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Queues an alert for a failed run, or adds it to the digest, in the current site's context.
 *
 * @param EventRun $run The failed run.
 */
function queue_failure( EventRun $run ): void {
	$settings = get_alert_settings();

	if ( ! $settings['enabled'] ) {
		return;
	}

	$failure = describe_run( $run );

	if ( 'digest' === $settings['mode'] ) {
		with_state_lock(
			static function ( array $state ) use ( $failure ) {
				if ( count( $state['digest'] ) >= DIGEST_LIMIT ) {
					return null;
				}

				$state['digest'][] = $failure;
				return $state;
			}
		);
		return;
	}

	$now      = time();
	$reserved = false;

	// The limits are checked and reserved together, under the lock. The alert is only sent once
	// the reservation is stored, so a failed write can't let repeats through.
	$stored = with_state_lock(
		static function ( array $state ) use ( $run, $now, &$reserved ) {
			// One email per hook per hour.
			if ( isset( $state['sent'][ $run->hook ] ) && ( $now - (int) $state['sent'][ $run->hook ] ) < HOOK_THROTTLE ) {
				return null;
			}

			// And a ceiling for the whole site, in case many events fail at once.
			if ( ( $now - $state['window_start'] ) >= HOUR_IN_SECONDS ) {
				$state['window_start'] = $now;
				$state['window_count'] = 0;
			}

			if ( $state['window_count'] >= SITE_LIMIT ) {
				return null;
			}

			// Throttle on queueing, so the limits hold even if the email can't be sent.
			$state['sent'][ $run->hook ] = $now;
			++$state['window_count'];

			// Forget hooks that haven't alerted for a day, so the state stays small.
			$state['sent'] = array_filter(
				$state['sent'],
				static fn ( $time ): bool => ( $now - (int) $time ) < DAY_IN_SECONDS
			);

			$reserved = true;
			return $state;
		}
	);

	if ( ! $stored || ! $reserved ) {
		return;
	}

	$queue = &queue();

	if ( ! $queue ) {
		// Sent once the request is done, after the logger has recorded any fatal error.
		register_shutdown_function( __NAMESPACE__ . '\flush_queue' );
	}

	$queue[] = $failure;
}

/**
 * Sends the queued alerts as one email.
 */
function flush_queue(): void {
	$queue    = &queue();
	$failures = $queue;
	$queue    = array();

	if ( ! $failures ) {
		return;
	}

	// A network worker can run events for several sites in one request, so each site's failures go
	// to that site's own recipients, from within that site.
	$by_site = array();

	foreach ( $failures as $failure ) {
		$by_site[ (int) ( $failure['site_id'] ?? get_current_blog_id() ) ][] = $failure;
	}

	foreach ( $by_site as $site_id => $site_failures ) {
		$switched = is_multisite() && get_current_blog_id() !== $site_id;

		if ( $switched ) {
			switch_to_blog( $site_id );
		}

		try {
			send_failures( $site_failures, false );
		} catch ( \Throwable $e ) {
			// An alert must never break the site.
			continue;
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}
}

/**
 * Sends the daily digest, if any failures were collected.
 *
 * Run using the 'crontrol/alerts_digest' action.
 */
function send_digest(): void {
	$settings = get_alert_settings();

	if ( ! $settings['enabled'] || 'digest' !== $settings['mode'] ) {
		return;
	}

	$failures = array();

	// Take the collected failures and empty the digest in one step.
	$stored = with_state_lock(
		static function ( array $state ) use ( &$failures ) {
			$failures        = $state['digest'];
			$state['digest'] = array();
			return $state;
		}
	);

	// If emptying the digest wasn't stored, send nothing now rather than send the same failures twice.
	if ( $stored && $failures ) {
		send_failures( $failures, true );
	}
}

/**
 * Builds and sends an alert email for a list of failures.
 *
 * @param list<array<string,mixed>> $failures The failed runs.
 * @param bool                      $digest   Whether this is the daily digest.
 */
function send_failures( array $failures, bool $digest ): bool {
	$site = wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES );
	$hooks = array_values( array_unique( array_map( static fn ( array $f ): string => (string) $f['hook'], $failures ) ) );

	if ( $digest ) {
		/* translators: 1: Site name, 2: Number of failed runs */
		$subject = sprintf( __( '[%1$s] Cron events failed in the last day (%2$s)', 'wp-crontrol' ), $site, number_format_i18n( count( $failures ) ) );
	} elseif ( 1 === count( $hooks ) ) {
		/* translators: 1: Site name, 2: Hook name */
		$subject = sprintf( __( '[%1$s] Cron event failed: %2$s', 'wp-crontrol' ), $site, $hooks[0] );
	} else {
		/* translators: 1: Site name, 2: Number of hooks */
		$subject = sprintf( __( '[%1$s] %2$s cron events failed', 'wp-crontrol' ), $site, number_format_i18n( count( $hooks ) ) );
	}

	$lines   = array();
	$lines[] = $digest
		? __( 'These logged cron events failed on your site in the last day:', 'wp-crontrol' )
		: __( 'A logged cron event failed on your site:', 'wp-crontrol' );
	$lines[] = '';

	foreach ( array_slice( $failures, 0, 20 ) as $failure ) {
		$lines[] = sprintf(
			/* translators: 1: Hook name, 2: Date and time */
			__( '%1$s at %2$s', 'wp-crontrol' ),
			$failure['hook'],
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $failure['time'] )
		);
		$lines[] = ! empty( $failure['fatal'] ) ? __( 'Status: fatal error', 'wp-crontrol' ) : __( 'Status: errored', 'wp-crontrol' );

		if ( '' !== (string) $failure['message'] ) {
			/* translators: %s: Error message */
			$lines[] = sprintf( __( 'Error: %s', 'wp-crontrol' ), wp_strip_all_tags( (string) $failure['message'] ) );
		}

		$lines[] = add_query_arg(
			array(
				'page'            => 'wp-crontrol-logs',
				'crontrol_hook'   => rawurlencode( (string) $failure['hook'] ),
				'crontrol_status' => 'errored',
			),
			admin_url( 'tools.php' )
		);
		$lines[] = '';
	}

	if ( count( $failures ) > 20 ) {
		/* translators: %s: Number of failures */
		$lines[] = sprintf( __( 'And %s more.', 'wp-crontrol' ), number_format_i18n( count( $failures ) - 20 ) );
		$lines[] = '';
	}

	$lines[] = '--';
	$lines[] = $digest
		? __( 'You get this daily summary because cron alerts are turned on in WP Crontrol. Change who gets them, or turn them off:', 'wp-crontrol' )
		: __( 'You get this email because cron alerts are turned on in WP Crontrol. You get at most one email an hour for each event. Change who gets them, or turn them off:', 'wp-crontrol' );
	$lines[] = admin_url( 'tools.php?page=wp-crontrol-alerts' );

	return send( $subject, implode( "\n", $lines ), false );
}

/**
 * Sends an alert email and records whether it was sent.
 *
 * @param string $subject The subject.
 * @param string $body    The plain text body.
 * @param bool   $test    Whether this is a test email.
 */
function send( string $subject, string $body, bool $test ): bool {
	static $sending = false;

	$recipients = get_recipients();

	if ( $sending || ! $recipients ) {
		return false;
	}

	$sending = true;
	$error   = '';

	$capture = static function ( $wp_error ) use ( &$error ): void {
		if ( $wp_error instanceof \WP_Error && '' === $error ) {
			$error = substr( $wp_error->get_error_message(), 0, 300 );
		}
	};

	add_action( 'wp_mail_failed', $capture );

	try {
		$sent = wp_mail( $recipients, $subject, $body );
	} finally {
		remove_action( 'wp_mail_failed', $capture );
		$sending = false;
	}

	$result = array(
		'time'  => time(),
		'sent'  => (bool) $sent,
		'error' => $sent ? '' : ( '' !== $error ? $error : __( 'WordPress could not send the email.', 'wp-crontrol' ) ),
		'test'  => $test,
	);

	with_state_lock(
		static function ( array $state ) use ( $result ) {
			$state['last_result'] = $result;
			return $state;
		}
	);

	return (bool) $sent;
}

/**
 * Schedules or clears the daily digest event to match the settings.
 */
function sync_digest_schedule(): void {
	$settings = get_alert_settings();
	$wanted   = $settings['enabled'] && 'digest' === $settings['mode'];
	$next     = wp_next_scheduled( DIGEST_HOOK );

	if ( $wanted && ! $next ) {
		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', DIGEST_HOOK );
	} elseif ( ! $wanted && $next ) {
		wp_clear_scheduled_hook( DIGEST_HOOK );
	}
}

/**
 * Handles saving the settings and sending a test email.
 *
 * Run using the 'admin_init' action.
 */
function handle_actions(): void {
	if ( empty( $_POST['crontrol_action'] ) || ! in_array( $_POST['crontrol_action'], array( 'save-alerts', 'test-alert' ), true ) ) {
		return;
	}

	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You are not allowed to change cron alerts.', 'wp-crontrol' ), 403 );
	}

	$action = sanitize_key( wp_unslash( $_POST['crontrol_action'] ) );
	check_admin_referer( 'crontrol-' . $action );

	if ( 'save-alerts' === $action ) {
		$recipients = isset( $_POST['crontrol_alerts_recipients'] ) ? sanitize_text_field( wp_unslash( $_POST['crontrol_alerts_recipients'] ) ) : '';

		update_option(
			SETTINGS_OPTION,
			array(
				'enabled'    => ! empty( $_POST['crontrol_alerts_enabled'] ),
				'recipients' => $recipients,
				'mode'       => ( isset( $_POST['crontrol_alerts_mode'] ) && 'digest' === $_POST['crontrol_alerts_mode'] ) ? 'digest' : 'each',
			),
			false
		);

		sync_digest_schedule();
		$message = 'saved';
	} else {
		$sent = send(
			/* translators: %s: Site name */
			sprintf( __( '[%s] Test cron alert from WP Crontrol', 'wp-crontrol' ), wp_specialchars_decode( (string) get_option( 'blogname' ), ENT_QUOTES ) ),
			__( 'This is a test email from WP Crontrol. If you can read it, cron alerts from this site will reach you.', 'wp-crontrol' ) . "\n\n" . admin_url( 'tools.php?page=wp-crontrol-alerts' ),
			true
		);
		$message = $sent ? 'test-sent' : 'test-failed';
	}

	wp_safe_redirect( add_query_arg( array(
		'page'                    => 'wp-crontrol-alerts',
		'crontrol_alerts_message' => $message,
	), admin_url( 'tools.php' ) ) );
	exit;
}

/**
 * Renders the Cron Alerts screen.
 */
function admin_alerts_page(): void {
	$settings   = get_alert_settings();
	$state      = get_state();
	$last       = $state['last_result'];
	$smtp       = \Crontrol\Promos\get_plugin( 'wp-mail-smtp' );
	$logged     = class_exists( Log::class ) ? Log::get_enabled_hooks() : array();
	$message    = isset( $_GET['crontrol_alerts_message'] ) ? sanitize_key( wp_unslash( $_GET['crontrol_alerts_message'] ) ) : '';
	$recipients = get_recipients();
	$show_smtp  = $smtp && ! $smtp['active'];

	?>
	<div class="wrap">
		<?php \Crontrol\do_tabs(); ?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Cron Alerts', 'wp-crontrol' ); ?></h1>
		<hr class="wp-header-end">

		<?php if ( 'saved' === $message ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Alert settings saved.', 'wp-crontrol' ); ?></p></div>
		<?php elseif ( 'test-sent' === $message ) : ?>
			<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Test email sent. Check the inbox, and the spam folder, of each recipient.', 'wp-crontrol' ); ?></p></div>
		<?php endif; ?>

		<?php if ( ! empty( $last ) && empty( $last['sent'] ) ) : ?>
			<div class="notice notice-error">
				<p>
					<?php
					echo esc_html( ! empty( $last['test'] )
						? __( 'The test email failed to send.', 'wp-crontrol' )
					: __( 'The last cron alert failed to send.', 'wp-crontrol' ) );
					echo ' ';
					/* translators: %s: Error message */
					echo esc_html( sprintf( __( 'WordPress reported: %s', 'wp-crontrol' ), (string) ( $last['error'] ?? '' ) ) );
					?>
				</p>
				<?php if ( $show_smtp ) : ?>
					<p><?php esc_html_e( 'Many hosts can’t send email reliably from WordPress. WP Mail SMTP sends it through a real mail provider instead.', 'wp-crontrol' ); ?></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<div class="<?php echo $show_smtp ? 'crontrol-columns' : ''; ?>">
		<div>
		<p><?php esc_html_e( 'Get an email when a logged cron event errors or hits a fatal error.', 'wp-crontrol' ); ?></p>

		<?php if ( ! $logged ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: URL of the Cron Logs screen */
						wp_kses( __( 'Alerts only cover events that are logged, and logging is off for every event. <a href="%s">Turn on logging</a> for the events you want alerts about.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
						esc_url( admin_url( 'tools.php?page=wp-crontrol-logs' ) )
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<p>
				<?php
				printf(
					/* translators: %s: Number of logged events */
					esc_html( _n( 'Alerts cover the %s event hook that has logging turned on.', 'Alerts cover the %s event hooks that have logging turned on.', count( $logged ), 'wp-crontrol' ) ),
					esc_html( number_format_i18n( count( $logged ) ) )
				);
				?>
			</p>
		<?php endif; ?>

		<form method="post">
			<?php wp_nonce_field( 'crontrol-save-alerts' ); ?>
			<input type="hidden" name="crontrol_action" value="save-alerts" />
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'Alerts', 'wp-crontrol' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="crontrol_alerts_enabled" value="1" <?php checked( $settings['enabled'] ); ?> />
							<?php esc_html_e( 'Email me when a logged cron event fails', 'wp-crontrol' ); ?>
						</label>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="crontrol_alerts_recipients"><?php esc_html_e( 'Send to', 'wp-crontrol' ); ?></label></th>
					<td>
						<input type="text" class="regular-text" id="crontrol_alerts_recipients" name="crontrol_alerts_recipients" value="<?php echo esc_attr( $settings['recipients'] ); ?>" placeholder="<?php echo esc_attr( (string) get_option( 'admin_email' ) ); ?>" />
						<p class="description"><?php esc_html_e( 'Email addresses separated by commas, up to ten. Leave empty to use the site’s admin email address.', 'wp-crontrol' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'How often', 'wp-crontrol' ); ?></th>
					<td>
						<fieldset>
							<label>
								<input type="radio" name="crontrol_alerts_mode" value="each" <?php checked( 'each', $settings['mode'] ); ?> />
								<?php esc_html_e( 'As they happen, at most one email an hour for each event', 'wp-crontrol' ); ?>
							</label><br />
							<label>
								<input type="radio" name="crontrol_alerts_mode" value="digest" <?php checked( 'digest', $settings['mode'] ); ?> />
								<?php esc_html_e( 'One summary a day', 'wp-crontrol' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save Alert Settings', 'wp-crontrol' ) ); ?>
		</form>

		<h2><?php esc_html_e( 'Check that alerts reach you', 'wp-crontrol' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: Comma-separated email addresses */
				esc_html__( 'Send a test email to %s.', 'wp-crontrol' ),
				esc_html( $recipients ? implode( ', ', $recipients ) : __( 'nobody: add an email address above', 'wp-crontrol' ) )
			);
			?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'crontrol-test-alert' ); ?>
			<input type="hidden" name="crontrol_action" value="test-alert" />
			<?php submit_button( __( 'Send Test Email', 'wp-crontrol' ), 'secondary', 'submit', false, $recipients ? array() : array( 'disabled' => 'disabled' ) ); ?>
		</form>

		</div>
		<?php if ( $show_smtp ) : ?>
			<aside class="crontrol-alerts-smtp">
				<?php
				$card = \Crontrol\Promos\featured_card(
					'wp-mail-smtp',
					$smtp,
					__( 'Make sure alert emails get delivered', 'wp-crontrol' ),
					__( 'WordPress sends email through your host, and many hosts don’t deliver it reliably, so alerts can end up in spam or never arrive. WP Mail SMTP sends WordPress email through a real mail provider such as Gmail, Outlook, or SendGrid.', 'wp-crontrol' )
				);
				echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in featured_card().
				?>
			</aside>
		<?php endif; ?>
		</div>
	</div>
	<?php
}
