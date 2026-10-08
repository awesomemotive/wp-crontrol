<?php
/**
 * Suggestions for related plugins from the WP Crontrol team.
 *
 * Shown only on WP Crontrol's own screens, never for plugins that are already active,
 * and each one can be dismissed for good per user. No remote assets are loaded.
 */

namespace Crontrol\Promos;

const DISMISSED_META = 'crontrol_dismissed_promos';

add_action( 'crontrol/tab-header', __NAMESPACE__ . '\show_welcome_panel', 30 );
add_action( 'crontrol/manage/hookcode', __NAMESPACE__ . '\show_wpcode_tip' );
add_action( 'admin_init', __NAMESPACE__ . '\action_dismiss' );

/**
 * Returns the plugins we suggest, keyed by WordPress.org slug.
 *
 * @return array<string,array{name:string,file:string,text:string,active:bool}>
 */
function get_plugins() {
	/**
	 * Filters the suggested plugins.
	 *
	 * @param array<string,array{name:string,file:string,text:string,active:bool}> $plugins Plugins keyed by WordPress.org slug.
	 */
	return apply_filters( 'crontrol/promos/plugins', array(
		'vibe-ai'                    => array(
			'name'   => 'WPVibe',
			'file'   => 'vibe-ai/vibe-ai.php',
			'text'   => __( 'Manage your site from Claude or ChatGPT. Ask your AI assistant which cron events are failing, run them, and fix the cause.', 'wp-crontrol' ),
			'active' => defined( 'WPVIBE_VERSION' ),
		),
		'duplicator'                 => array(
			'name'   => 'Duplicator',
			'file'   => 'duplicator/duplicator.php',
			'text'   => __( 'Back up, migrate, and clone your site. Scheduled backups run on cron, so you can keep an eye on them right here.', 'wp-crontrol' ),
			'active' => defined( 'DUPLICATOR_VERSION' ) || defined( 'DUPLICATOR_PRO_VERSION' ),
		),
		'wp-mail-smtp'               => array(
			'name'   => 'WP Mail SMTP',
			'file'   => 'wp-mail-smtp/wp_mail_smtp.php',
			'text'   => __( 'Make sure the emails your scheduled tasks send actually arrive, with reliable delivery through a real mail provider.', 'wp-crontrol' ),
			'active' => function_exists( 'wp_mail_smtp' ),
		),
		'insert-headers-and-footers' => array(
			'name'   => 'WPCode',
			'file'   => 'insert-headers-and-footers/ihaf.php',
			'text'   => __( 'Add PHP and other code snippets safely, without editing your theme’s functions.php file.', 'wp-crontrol' ),
			'active' => function_exists( 'wpcode' ) || class_exists( 'WPCode' ),
		),
	) );
}

/**
 * @param string $key Promo key.
 */
function is_dismissed( $key ): bool {
	$dismissed = get_user_meta( get_current_user_id(), DISMISSED_META, true );

	return is_array( $dismissed ) && in_array( $key, $dismissed, true );
}

/**
 * @param string $key Promo key.
 */
function dismiss_url( $key ): string {
	return wp_nonce_url(
		add_query_arg( 'crontrol_dismiss_promo', $key ),
		'crontrol-dismiss-promo-' . $key
	);
}

/**
 * Button that installs and activates a plugin in place, using WordPress's own installer.
 *
 * Without JavaScript the button falls back to WordPress's standard install or activate screen.
 * Users who can't install plugins get a link to the plugin's WordPress.org page instead.
 *
 * @param string                                         $slug   WordPress.org plugin slug.
 * @param array{name:string,file:string,text:string,active:bool} $plugin The plugin.
 * @param string                                         $classes Extra classes for the button.
 */
function plugin_button( $slug, array $plugin, $classes = 'button button-primary' ): string {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$installed = array_key_exists( $plugin['file'], \get_plugins() );

	if ( $installed && current_user_can( 'activate_plugin', $plugin['file'] ) ) {
		$action = 'activate';
		$label  = __( 'Activate', 'wp-crontrol' );
		$url    = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin['file'] ) ), 'activate-plugin_' . $plugin['file'] );
	} elseif ( ! $installed && current_user_can( 'install_plugins' ) ) {
		$action = 'install';
		$label  = __( 'Install for free', 'wp-crontrol' );
		$url    = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug );
	} else {
		return sprintf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ),
			esc_attr( $classes ),
			esc_html__( 'Learn more', 'wp-crontrol' )
		);
	}

	enqueue_installer();

	return sprintf(
		'<a href="%1$s" class="crontrol-promo-install %2$s" data-action="%3$s" data-slug="%4$s" data-plugin="%5$s" data-name="%6$s">%7$s</a>',
		esc_url( $url ),
		esc_attr( $classes ),
		esc_attr( $action ),
		esc_attr( $slug ),
		esc_attr( $plugin['file'] ),
		esc_attr( $plugin['name'] ),
		esc_html( $label )
	);
}

/**
 * Loads WordPress's plugin installer script and the small handler for the install buttons.
 */
function enqueue_installer(): void {
	static $done = false;

	if ( $done ) {
		return;
	}

	$done = true;

	wp_enqueue_script( 'updates' );
	add_action( 'admin_footer', 'wp_print_request_filesystem_credentials_modal' );

	$strings = array(
		'installing' => __( 'Installing…', 'wp-crontrol' ),
		'activating' => __( 'Activating…', 'wp-crontrol' ),
		'active'     => __( 'Active', 'wp-crontrol' ),
		'failed'     => __( 'Installation failed', 'wp-crontrol' ),
	);

	wp_add_inline_script(
		'updates',
		'window.crontrolPromo = ' . wp_json_encode( $strings ) . ';' . <<<'JS'
jQuery( function ( $ ) {
	$( document ).on( 'click', '.crontrol-promo-install', function ( event ) {
		var $button = $( this );

		if ( ! window.wp || ! wp.updates || ! wp.updates.activatePlugin ) {
			return;
		}

		event.preventDefault();

		if ( $button.hasClass( 'updating-message' ) || $button.hasClass( 'button-disabled' ) ) {
			return;
		}

		var done = function () {
			$button.removeClass( 'updating-message' ).addClass( 'button-disabled' ).attr( 'aria-disabled', 'true' ).text( crontrolPromo.active );
		};

		var activate = function () {
			$button.text( crontrolPromo.activating );
			wp.updates.activatePlugin( {
				name: $button.data( 'name' ),
				slug: $button.data( 'slug' ),
				plugin: $button.data( 'plugin' ),
				success: done,
				error: function () {
					// Fall back to the standard activation screen, which shows any error.
					window.location.href = $button.data( 'activate-url' ) || $button.attr( 'href' );
				}
			} );
		};

		$button.addClass( 'updating-message' );

		if ( 'activate' === $button.data( 'action' ) ) {
			activate();
			return;
		}

		$button.text( crontrolPromo.installing );
		wp.updates.installPlugin( {
			slug: $button.data( 'slug' ),
			success: function ( response ) {
				if ( response.activateUrl ) {
					$button.data( 'activate-url', response.activateUrl );
				}
				activate();
			},
			error: function () {
				$button.removeClass( 'updating-message' ).text( crontrolPromo.failed );
			}
		} );
	} );
} );
JS
	);
}

/**
 * Handles the dismiss links.
 */
function action_dismiss(): void {
	if ( ! isset( $_GET['crontrol_dismiss_promo'] ) ) {
		return;
	}

	$key = sanitize_key( wp_unslash( $_GET['crontrol_dismiss_promo'] ) );

	if ( ! in_array( $key, array( 'welcome', 'wpcode' ), true ) ) {
		return;
	}

	check_admin_referer( 'crontrol-dismiss-promo-' . $key );

	$dismissed   = get_user_meta( get_current_user_id(), DISMISSED_META, true );
	$dismissed   = is_array( $dismissed ) ? $dismissed : array();
	$dismissed[] = $key;
	update_user_meta( get_current_user_id(), DISMISSED_META, array_values( array_unique( $dismissed ) ) );

	wp_safe_redirect( remove_query_arg( array( 'crontrol_dismiss_promo', '_wpnonce' ) ) );
	exit;
}

/**
 * One panel at the top of the Cron Events and Cron Schedules screens.
 *
 * @param string $tab The current tab.
 */
function show_welcome_panel( $tab ): void {
	if ( ! in_array( $tab, array( 'events', 'schedules', 'logs', 'insights' ), true ) || is_dismissed( 'welcome' ) ) {
		return;
	}

	$plugins = array_filter(
		get_plugins(),
		function ( $plugin ) {
			return ! $plugin['active'];
		}
	);

	?>
	<style>
		.crontrol-promo { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; margin: 16px 0; padding: 12px 16px 16px; position: relative; }
		.crontrol-promo h2 { margin: 4px 0 6px; font-size: 14px; }
		.crontrol-promo > p { margin: 0 0 12px; max-width: 760px; }
		.crontrol-promo-grid { display: grid; gap: 12px; grid-template-columns: repeat( auto-fill, minmax( 220px, 1fr ) ); }
		.crontrol-promo-card { border: 1px solid #dcdcde; border-radius: 4px; display: flex; flex-direction: column; padding: 12px; }
		.crontrol-promo-card strong { font-size: 13px; }
		.crontrol-promo-card p { color: #50575e; flex: 1; margin: 6px 0 10px; }
		.crontrol-promo-card .button { align-self: flex-start; }
		.crontrol-promo-dismiss { position: absolute; right: 12px; top: 10px; text-decoration: none; }
		.crontrol-promo-quote { border-left: 3px solid #dcdcde; margin: 8px 0 12px; max-width: 760px; padding: 2px 0 2px 12px; }
		.crontrol-promo-quote p { font-style: italic; margin: 0 0 4px; }
		.crontrol-promo-quote cite { color: #50575e; font-style: normal; }
	</style>
	<div class="crontrol-promo">
		<a class="crontrol-promo-dismiss" href="<?php echo esc_url( dismiss_url( 'welcome' ) ); ?>">
			<?php esc_html_e( 'Dismiss', 'wp-crontrol' ); ?>
		</a>
		<h2><?php esc_html_e( 'WP Crontrol has a new home, and Pro features are now free', 'wp-crontrol' ); ?></h2>
		<blockquote class="crontrol-promo-quote">
			<p><?php esc_html_e( 'I’ve always maintained that if I was to hand over ownership of any of my plugins it could only be to a long-standing and respected member of the WordPress ecosystem, in whose hands I know the plugin would be well maintained. The team at Awesome Motive absolutely fit that requirement and I’m pleased that they’ve agreed to take over my WP Crontrol plugin. I’ve enjoyed and learned a lot maintaining it over the last 14 years, but the time has come to pass it on. It’s in good hands.', 'wp-crontrol' ); ?></p>
			<cite><?php esc_html_e( 'John Blackbourn, creator of WP Crontrol', 'wp-crontrol' ); ?></cite>
		</blockquote>
		<p>
			<?php
			printf(
				/* translators: %s: URL of the Cron Logs screen */
				wp_kses( __( 'WP Crontrol stays free, open source, and private by default. Cron event logging and insights, previously part of WP Crontrol Pro, are now included for everyone. <a href="%s">Turn on logging</a> for the events you want to watch.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( admin_url( 'tools.php?page=wp-crontrol-logs' ) )
			);
			?>
		</p>
		<?php if ( $plugins ) : ?>
			<p><?php esc_html_e( 'If you manage WordPress sites, these plugins from our team pair well with it:', 'wp-crontrol' ); ?></p>
		<?php endif; ?>
		<?php if ( $plugins ) : ?>
		<div class="crontrol-promo-grid">
			<?php foreach ( $plugins as $slug => $plugin ) : ?>
				<div class="crontrol-promo-card">
					<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
					<p><?php echo esc_html( $plugin['text'] ); ?></p>
					<?php echo plugin_button( $slug, $plugin ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * One line under the PHP code field on the add and edit PHP event form.
 */
function show_wpcode_tip(): void {
	$plugins = get_plugins();

	if ( $plugins['insert-headers-and-footers']['active'] || is_dismissed( 'wpcode' ) ) {
		return;
	}

	printf(
		'<p class="description">%1$s %2$s &middot; <a href="%3$s">%4$s</a></p>',
		esc_html__( 'Running PHP that isn’t tied to a schedule? WPCode keeps your snippets in one place and lets you switch them off safely.', 'wp-crontrol' ),
		plugin_button( 'insert-headers-and-footers', $plugins['insert-headers-and-footers'], 'button button-small' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_url( dismiss_url( 'wpcode' ) ),
		esc_html__( 'Dismiss', 'wp-crontrol' )
	);
}
