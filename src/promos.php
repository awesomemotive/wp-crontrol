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
add_action( 'admin_init', __NAMESPACE__ . '\action_dismiss' );
add_action( 'wp_ajax_crontrol_record_referral', __NAMESPACE__ . '\ajax_record_referral' );

/**
 * Option that records, on this site only, which plugins were installed or activated from WP Crontrol.
 * It's never sent anywhere.
 */
const REFERRED_OPTION = 'wp_crontrol_referred_plugins';

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
			'text'   => __( 'Connect Claude or ChatGPT to this site. Ask which cron events failed and why, run one again, or fix the code behind it. Free to start.', 'wp-crontrol' ),
			'active' => defined( 'WPVIBE_VERSION' ),
		),
		'duplicator'                 => array(
			'name'   => 'Duplicator',
			'file'   => 'duplicator/duplicator.php',
			'text'   => __( 'Back up, migrate, or clone this site. Take a full backup before you change cron events you don’t recognise.', 'wp-crontrol' ),
			'active' => defined( 'DUPLICATOR_VERSION' ) || defined( 'DUPLICATOR_PRO_VERSION' ),
		),
		'wp-mail-smtp'               => array(
			'name'   => 'WP Mail SMTP',
			'file'   => 'wp-mail-smtp/wp_mail_smtp.php',
			'text'   => __( 'Order receipts, password resets, and other emails your scheduled tasks send often never arrive. WP Mail SMTP sends them through a real mail provider.', 'wp-crontrol' ),
			'active' => function_exists( 'wp_mail_smtp' ),
		),
		'insert-headers-and-footers' => array(
			'name'   => 'WPCode',
			'file'   => 'insert-headers-and-footers/ihaf.php',
			'text'   => __( 'Keep custom PHP in snippets you can switch off, without editing your theme’s functions.php file.', 'wp-crontrol' ),
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
function plugin_button( $slug, array $plugin, $classes = 'button' ): string {
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
		'<a href="%1$s" class="crontrol-promo-install %2$s" data-action="%3$s" data-slug="%4$s" data-plugin="%5$s" data-name="%6$s" data-setup="%8$s">%7$s</a>',
		esc_url( $url ),
		esc_attr( $classes ),
		esc_attr( $action ),
		esc_attr( $slug ),
		esc_attr( $plugin['file'] ),
		esc_attr( $plugin['name'] ),
		esc_html( $label ),
		esc_url( 'vibe-ai' === $slug ? admin_url( 'admin.php?page=vibe-ai' ) : '' )
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
		'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
		'nonce'      => wp_create_nonce( 'crontrol-referral' ),
		'installing' => __( 'Installing…', 'wp-crontrol' ),
		'activating' => __( 'Activating…', 'wp-crontrol' ),
		'active'     => __( 'Active', 'wp-crontrol' ),
		'failed'     => __( 'Installation failed', 'wp-crontrol' ),
		/* translators: %s: Plugin name */
		'setup'      => __( 'Set up %s →', 'wp-crontrol' ),
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
			// Remembered on this site only, so the site owner can see what came from WP Crontrol.
			$.post( crontrolPromo.ajaxUrl, { action: 'crontrol_record_referral', slug: $button.data( 'slug' ), _ajax_nonce: crontrolPromo.nonce } );

			var setup = $button.data( 'setup' );
			if ( setup ) {
				// Point straight at the plugin's setup screen instead of a dead "Active" button.
				$button.removeClass( 'crontrol-promo-install updating-message' ).addClass( 'button-primary' ).attr( 'href', setup ).text( crontrolPromo.setup.replace( '%s', $button.data( 'name' ) ) );
				return;
			}
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
				// wp.updates runs one request at a time and is still locked inside this callback.
				var whenUnlocked = function () {
					if ( wp.updates.ajaxLocked ) {
						setTimeout( whenUnlocked, 100 );
						return;
					}
					activate();
				};
				whenUnlocked();
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

	if ( 'welcome' !== $key ) {
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
 * Whether the new-home panel shows on the given tab for the current user.
 *
 * @param string $tab The current tab.
 */
function is_welcome_panel_visible( $tab ): bool {
	return 'events' === $tab && ! is_dismissed( 'welcome' );
}

/**
 * One panel at the top of the Cron Events and Cron Schedules screens.
 *
 * @param string $tab The current tab.
 */
function show_welcome_panel( $tab ): void {
	if ( ! is_welcome_panel_visible( $tab ) ) {
		return;
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$installed = \get_plugins();

	// Only suggest plugins the site doesn't have. One that's installed but inactive was switched off on purpose.
	$plugins = array_filter(
		get_plugins(),
		function ( $plugin ) use ( $installed ) {
			return ! $plugin['active'] && ! array_key_exists( $plugin['file'], $installed );
		}
	);

	?>
	<style>
		.crontrol-promo { background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; margin: 16px 0; padding: 12px 16px 16px; position: relative; }
		.crontrol-promo h2 { margin: 4px 0 6px; font-size: 14px; }
		.crontrol-promo > p { margin: 0 0 12px; max-width: 760px; }
		.crontrol-promo-grid { display: grid; gap: 12px; grid-template-columns: repeat( auto-fit, minmax( 200px, 1fr ) ); }
		.crontrol-promo-card { border: 1px solid #dcdcde; border-radius: 4px; display: flex; flex-direction: column; padding: 12px; }
		.crontrol-promo-card strong { font-size: 13px; }
		.crontrol-promo-card p { color: #50575e; flex: 1; margin: 6px 0 10px; }
		.crontrol-promo-card .button { align-self: flex-start; }
		.crontrol-promo-dismiss { position: absolute; right: 12px; top: 10px; text-decoration: none; }
		.crontrol-promo-quote { border-left: 3px solid #dcdcde; margin: 8px 0 12px; max-width: 760px; padding: 2px 0 2px 12px; }
		.crontrol-promo-quote p { font-style: italic; margin: 0 0 4px; }
		.crontrol-promo-quote cite { color: #50575e; font-style: normal; }
		.crontrol-promo-label { color: #50575e; font-weight: 600; margin: 0 !important; }
		.crontrol-promo-divider { border: 0; border-top: 1px solid #dcdcde; margin: 4px 0 12px; }
	</style>
	<div class="crontrol-promo">
		<a class="crontrol-promo-dismiss" href="<?php echo esc_url( dismiss_url( 'welcome' ) ); ?>">
			<?php esc_html_e( 'Dismiss', 'wp-crontrol' ); ?>
		</a>
		<h2><?php esc_html_e( 'WP Crontrol Pro is now free for everyone', 'wp-crontrol' ); ?></h2>
		<p>
			<?php
			printf(
				/* translators: %s: URL of the Cron Logs screen */
				wp_kses( __( 'Cron logs and insights, previously part of WP Crontrol Pro, are now included in WP Crontrol. Logging stays off until you <a href="%s">turn it on</a> for an event. WP Crontrol is still free, open source, and private by default, and is now looked after by the Duplicator team.', 'wp-crontrol' ), array( 'a' => array( 'href' => array() ) ) ),
				esc_url( admin_url( 'tools.php?page=wp-crontrol-logs' ) )
			);
			?>
		</p>
		<p class="crontrol-promo-label"><?php esc_html_e( 'A note from John Blackbourn', 'wp-crontrol' ); ?></p>
		<blockquote class="crontrol-promo-quote">
			<p><?php esc_html_e( 'I’ve always maintained that if I was to hand over ownership of any of my plugins it could only be to a long-standing and respected member of the WordPress ecosystem, in whose hands I know the plugin would be well maintained. The team at Awesome Motive absolutely fit that requirement and I’m pleased that they’ve agreed to take over my WP Crontrol plugin. I’ve enjoyed and learned a lot maintaining it over the last 14 years, but the time has come to pass it on. It’s in good hands.', 'wp-crontrol' ); ?></p>
			<cite><?php esc_html_e( 'John Blackbourn, creator of WP Crontrol', 'wp-crontrol' ); ?></cite>
		</blockquote>
		<?php if ( $plugins ) : ?>
			<hr class="crontrol-promo-divider" />
			<p><?php esc_html_e( 'Also from the team now looking after WP Crontrol:', 'wp-crontrol' ); ?></p>
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
 * Records that a plugin was installed or activated from a WP Crontrol button. Stored on this site only.
 */
function ajax_record_referral(): void {
	check_ajax_referer( 'crontrol-referral' );

	if ( ! current_user_can( 'activate_plugins' ) ) {
		wp_send_json_error( null, 403 );
	}

	$slug = isset( $_POST['slug'] ) ? sanitize_key( wp_unslash( $_POST['slug'] ) ) : '';

	if ( ! array_key_exists( $slug, get_plugins() ) ) {
		wp_send_json_error( null, 400 );
	}

	record_referral( $slug );
	wp_send_json_success();
}

/**
 * Records the first time a plugin was installed or activated from WP Crontrol.
 *
 * @param string $slug WordPress.org plugin slug.
 */
function record_referral( string $slug ): void {
	$referred = get_option( REFERRED_OPTION, array() );
	$referred = is_array( $referred ) ? $referred : array();

	if ( ! isset( $referred[ $slug ] ) ) {
		$referred[ $slug ] = time();
		update_option( REFERRED_OPTION, $referred, false );
	}
}
