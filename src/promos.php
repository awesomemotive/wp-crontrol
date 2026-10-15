<?php
/**
 * Suggestions for related plugins from the WP Crontrol team.
 *
 * Shown only on WP Crontrol's own screens, never for plugins that are already active,
 * and each one can be dismissed for good per user. No remote assets are loaded.
 */

namespace Crontrol\Promos;

const DISMISSED_META = 'crontrol_dismissed_promos';

/**
 * The suggestions that can be dismissed, each for good, per user.
 */
const DISMISSIBLE = array( 'welcome', 'no-backup', 'wpcode-tip' );

/**
 * The screens that show the new-home panel.
 */
const PANEL_TABS = array( 'events', 'logs', 'insights', 'alerts', 'ai', 'schedules' );

add_action( 'crontrol/tab-header', __NAMESPACE__ . '\show_welcome_panel', 30 );
add_action( 'crontrol/tab-header', __NAMESPACE__ . '\show_backup_notice', 40 );
add_action( 'crontrol/manage/hookcode', __NAMESPACE__ . '\show_wpcode_tip' );
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
 * @return array<string,array{name:string,file:string,text:string,active:bool,installed:bool}>
 */
function get_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = array(
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
	);

	$installed = \get_plugins();
	$plugins   = array_map(
		/**
		 * @param array{name:string,file:string,text:string,active:bool} $plugin
		 * @return array{name:string,file:string,text:string,active:bool,installed:bool}
		 */
		static function ( array $plugin ) use ( $installed ): array {
			$plugin['installed'] = array_key_exists( $plugin['file'], $installed );
			return $plugin;
		},
		$plugins
	);

	/**
	 * Filters the suggested plugins.
	 *
	 * @param array<string,array{name:string,file:string,text:string,active:bool,installed:bool}> $plugins Plugins keyed by WordPress.org slug.
	 */
	return apply_filters( 'crontrol/promos/plugins', $plugins );
}

/**
 * Returns one suggested plugin, or null if it isn't suggested (it can be filtered out).
 *
 * @param string $slug WordPress.org plugin slug.
 * @return array{name:string,file:string,text:string,active:bool,installed:bool}|null
 */
function get_plugin( string $slug ): ?array {
	$plugins = get_plugins();

	if ( ! isset( $plugins[ $slug ] ) ) {
		return null;
	}

	return $plugins[ $slug ];
}

/**
 * Whether to suggest a plugin to the current user: it isn't active, and they can install or activate it.
 *
 * @param array{name:string,file:string,text:string,active:bool,installed:bool}|null $plugin The plugin.
 */
function can_offer( ?array $plugin ): bool {
	if ( ! $plugin || ! empty( $plugin['active'] ) ) {
		return false;
	}

	if ( ! empty( $plugin['installed'] ) ) {
		return current_user_can( 'activate_plugin', $plugin['file'] );
	}

	return current_user_can( 'install_plugins' );
}

/**
 * The plugin's icon, bundled with WP Crontrol so nothing loads from elsewhere. Empty if there isn't one.
 *
 * @param string $slug WordPress.org plugin slug.
 * @param int    $size Width and height in pixels.
 */
function icon( string $slug, int $size = 32 ): string {
	$path = 'images/promos/' . $slug . '.png';

	if ( ! file_exists( dirname( \Crontrol\PLUGIN_FILE ) . '/' . $path ) ) {
		return '';
	}

	return sprintf(
		'<img class="crontrol-promo-icon" src="%1$s" width="%2$d" height="%2$d" alt="" />',
		esc_url( plugin_dir_url( \Crontrol\PLUGIN_FILE ) . $path ),
		$size
	);
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
 * Button that installs and activates a plugin in place, using WordPress's own installer. It names the plugin.
 *
 * Without JavaScript the button falls back to WordPress's standard install or activate screen.
 * Users who can't install plugins get a link to the plugin's WordPress.org page instead.
 *
 * @param string                                                                $slug    WordPress.org plugin slug.
 * @param array{name:string,file:string,text:string,active:bool,installed:bool} $plugin  The plugin.
 * @param string                                                                $classes Extra classes for the button.
 */
function plugin_button( $slug, array $plugin, $classes = 'button' ): string {
	$installed = ! empty( $plugin['installed'] );

	if ( $installed && current_user_can( 'activate_plugin', $plugin['file'] ) ) {
		$action = 'activate';
		/* translators: %s: Plugin name */
		$label = sprintf( __( 'Activate %s', 'wp-crontrol' ), $plugin['name'] );
		$url   = wp_nonce_url( self_admin_url( 'plugins.php?action=activate&plugin=' . rawurlencode( $plugin['file'] ) ), 'activate-plugin_' . $plugin['file'] );
	} elseif ( ! $installed && current_user_can( 'install_plugins' ) ) {
		$action = 'install';
		/* translators: %s: Plugin name */
		$label = sprintf( __( 'Install %s (free)', 'wp-crontrol' ), $plugin['name'] );
		$url   = wp_nonce_url( self_admin_url( 'update.php?action=install-plugin&plugin=' . rawurlencode( $slug ) ), 'install-plugin_' . $slug );
	} else {
		return sprintf(
			'<a href="%1$s" class="%2$s">%3$s</a>',
			esc_url( 'https://wordpress.org/plugins/' . $slug . '/' ),
			esc_attr( $classes ),
			/* translators: %s: Plugin name */
			esc_html( sprintf( __( 'Learn more about %s', 'wp-crontrol' ), $plugin['name'] ) )
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
 * A highlighted card recommending one plugin, for the screen where it helps most.
 *
 * @param string                                                                $slug    WordPress.org plugin slug.
 * @param array{name:string,file:string,text:string,active:bool,installed:bool} $plugin  The plugin.
 * @param string                                                                $heading The card heading.
 * @param string                                                                $text    Why the plugin helps here.
 */
function featured_card( string $slug, array $plugin, string $heading, string $text ): string {
	return sprintf(
		'<div class="crontrol-featured" data-slug="%1$s"><span class="crontrol-featured-badge">%2$s</span><div class="crontrol-featured-head">%3$s<h2>%4$s</h2></div><p>%5$s</p><p>%6$s</p></div>',
		esc_attr( $slug ),
		esc_html__( 'Recommended', 'wp-crontrol' ),
		icon( $slug, 48 ),
		esc_html( $heading ),
		esc_html( $text ),
		plugin_button( $slug, $plugin, 'button button-primary button-hero' )
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
		/* translators: %s: Plugin name */
		'installing' => __( 'Installing %s…', 'wp-crontrol' ),
		/* translators: %s: Plugin name */
		'activating' => __( 'Activating %s…', 'wp-crontrol' ),
		/* translators: %s: Plugin name */
		'active'     => __( '%s is active', 'wp-crontrol' ),
		/* translators: %s: Plugin name */
		'failed'     => __( '%s could not be installed', 'wp-crontrol' ),
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
			$button.removeClass( 'updating-message' ).addClass( 'button-disabled' ).attr( 'aria-disabled', 'true' ).text( crontrolPromo.active.replace( '%s', $button.data( 'name' ) ) );
		};

		var activate = function () {
			$button.text( crontrolPromo.activating.replace( '%s', $button.data( 'name' ) ) );
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

		$button.text( crontrolPromo.installing.replace( '%s', $button.data( 'name' ) ) );
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
				$button.removeClass( 'updating-message' ).text( crontrolPromo.failed.replace( '%s', $button.data( 'name' ) ) );
			}
		} );
	} );
} );
JS
	);
}

/**
 * Saves a dismissal for the current user. Returns whether it's stored, including when it already was.
 *
 * @param string $key Promo key.
 */
function save_dismissal( string $key ): bool {
	if ( is_dismissed( $key ) ) {
		return true;
	}

	$dismissed   = get_user_meta( get_current_user_id(), DISMISSED_META, true );
	$dismissed   = is_array( $dismissed ) ? $dismissed : array();
	$dismissed[] = $key;
	update_user_meta( get_current_user_id(), DISMISSED_META, array_values( array_unique( $dismissed ) ) );

	return is_dismissed( $key );
}

/**
 * Handles the dismiss links.
 */
function action_dismiss(): void {
	if ( ! isset( $_GET['crontrol_dismiss_promo'] ) ) {
		return;
	}

	$key = sanitize_key( wp_unslash( $_GET['crontrol_dismiss_promo'] ) );

	if ( ! in_array( $key, DISMISSIBLE, true ) ) {
		return;
	}

	check_admin_referer( 'crontrol-dismiss-promo-' . $key );

	$saved = save_dismissal( $key );

	// The Dismiss links hide their suggestion in place once the server confirms it's saved.
	if ( isset( $_GET['crontrol_dismiss_background'] ) ) {
		if ( ! $saved ) {
			wp_send_json_error( null, 500 );
		}

		wp_send_json_success();
	}

	wp_safe_redirect( remove_query_arg( array( 'crontrol_dismiss_promo', 'crontrol_dismiss_background', '_wpnonce' ) ) );
	exit;
}

/**
 * Whether the new-home panel shows on the given tab for the current user. One dismissal hides it on every tab.
 *
 * @param string $tab The current tab.
 */
function is_welcome_panel_visible( $tab ): bool {
	return in_array( $tab, PANEL_TABS, true ) && ! is_dismissed( 'welcome' );
}

/**
 * One panel at the top of each WP Crontrol screen, until it's dismissed.
 *
 * @param string $tab The current tab.
 */
function show_welcome_panel( $tab ): void {
	if ( ! is_welcome_panel_visible( $tab ) ) {
		return;
	}

	// Only suggest plugins that aren't active, to people who can install or activate them.
	$plugins = array_filter( get_plugins(), __NAMESPACE__ . '\can_offer' );

	?>
	<div class="crontrol-promo" data-crontrol-promo="welcome">
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
					<div class="crontrol-promo-card-head">
						<?php echo icon( $slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
						<strong><?php echo esc_html( $plugin['name'] ); ?></strong>
					</div>
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
 * Backup plugins, by plugin file. While one is active the site is taken to have backups.
 *
 * @return array<int,string>
 */
function get_backup_plugins(): array {
	/**
	 * Filters the backup plugins that stop the "No scheduled backups" suggestion while they're active.
	 *
	 * @param array<int,string> $files Plugin files, relative to the plugins directory.
	 */
	return (array) apply_filters( 'crontrol/promos/backup_plugins', array(
		'duplicator/duplicator.php',
		'duplicator-pro/duplicator-pro.php',
		'updraftplus/updraftplus.php',
		'backwpup/backwpup.php',
		'backwpup-pro/backwpup.php',
		'backupbuddy/backupbuddy.php',
		'solid-backups/solid-backups.php',
		'wpvivid-backuprestore/wpvivid-backuprestore.php',
		'wpvivid-backup-pro/wpvivid-backup-pro.php',
		'jetpack-backup/jetpack-backup.php',
		'vaultpress/vaultpress.php',
		'blogvault-real-time-backup/blogvault.php',
		'all-in-one-wp-migration/all-in-one-wp-migration.php',
		'boldgrid-backup/boldgrid-backup.php',
		'backup-backup/backup-backup.php',
		'backup/backup.php',
		'xcloner-backup-and-restore/xcloner.php',
		'wp-time-capsule/wp-time-capsule.php',
	) );
}

/**
 * Hook name prefixes of scheduled backups from backup plugins.
 *
 * @return array<int,string>
 */
function get_backup_hook_prefixes(): array {
	/**
	 * Filters the hook name prefixes that count as a scheduled backup.
	 *
	 * @param array<int,string> $prefixes Hook name prefixes.
	 */
	return (array) apply_filters( 'crontrol/promos/backup_hooks', array(
		'duplicator',
		'updraft_backup',
		'backwpup',
		'backupbuddy',
		'pb_backupbuddy',
		'solid_backups',
		'wpvivid',
		'jetpack_backup',
		'vaultpress',
		'blogvault',
		'ai1wm',
		'boldgrid_backup',
		'bmi_',
		'xcloner',
		'wptc_',
	) );
}

/**
 * Whether the site has a backup plugin active, or a backup event scheduled.
 */
function has_backups(): bool {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	foreach ( get_backup_plugins() as $file ) {
		if ( is_plugin_active( (string) $file ) ) {
			return true;
		}
	}

	$prefixes = array_filter( array_map( 'strval', get_backup_hook_prefixes() ) );
	$crons    = _get_cron_array();

	foreach ( $crons as $hooks ) {
		foreach ( array_keys( (array) $hooks ) as $hook ) {
			foreach ( $prefixes as $prefix ) {
				if ( 0 === strpos( (string) $hook, $prefix ) ) {
					return true;
				}
			}
		}
	}

	return false;
}

/**
 * Whether the "No scheduled backups" suggestion shows on the given tab for the current user.
 *
 * @param string $tab The current tab.
 */
function is_backup_notice_visible( $tab ): bool {
	// One suggestion at a time: it waits until the new-home panel is dismissed.
	if ( 'events' !== $tab || is_welcome_panel_visible( $tab ) || is_dismissed( 'no-backup' ) ) {
		return false;
	}

	if ( ! can_offer( get_plugin( 'duplicator' ) ) ) {
		return false;
	}

	return ! has_backups();
}

/**
 * A one-line suggestion on the Cron Events screen when nothing on the site backs it up.
 *
 * @param string $tab The current tab.
 */
function show_backup_notice( $tab ): void {
	if ( ! is_backup_notice_visible( $tab ) ) {
		return;
	}

	$plugin = get_plugin( 'duplicator' );

	if ( ! $plugin ) {
		return;
	}

	printf(
		'<div class="notice notice-info inline crontrol-promo-notice crontrol-promo-media" data-crontrol-promo="no-backup">%1$s<div class="crontrol-promo-body"><p><strong>%2$s</strong> %3$s</p><p>%4$s <a class="crontrol-promo-dismiss" href="%5$s">%6$s</a></p></div></div>',
		icon( 'duplicator', 40 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'No scheduled backups found on this site.', 'wp-crontrol' ),
		esc_html__( 'Unless your host backs it up for you, take a backup with Duplicator before something goes wrong.', 'wp-crontrol' ),
		plugin_button( 'duplicator', $plugin, 'button button-small' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_url( dismiss_url( 'no-backup' ) ),
		esc_html__( 'Dismiss', 'wp-crontrol' )
	);
}

/**
 * Whether the WPCode tip shows under the PHP code field for the current user.
 */
function is_wpcode_tip_visible(): bool {
	return ! is_dismissed( 'wpcode-tip' ) && can_offer( get_plugin( 'insert-headers-and-footers' ) );
}

/**
 * A one-line tip under the PHP code field: code that should run on every page belongs in a snippet.
 */
function show_wpcode_tip(): void {
	if ( ! is_wpcode_tip_visible() ) {
		return;
	}

	$plugin = get_plugin( 'insert-headers-and-footers' );

	if ( ! $plugin ) {
		return;
	}

	printf(
		'<div class="crontrol-promo-tip crontrol-promo-media" data-crontrol-promo="wpcode-tip">%1$s<div class="crontrol-promo-body"><p>%2$s</p><p>%3$s <a class="crontrol-promo-dismiss" href="%4$s">%5$s</a></p></div></div>',
		icon( 'insert-headers-and-footers', 32 ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_html__( 'Want this code to run on every page instead of on a schedule? WPCode keeps PHP snippets you can switch on and off.', 'wp-crontrol' ),
		plugin_button( 'insert-headers-and-footers', $plugin, 'button button-small' ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		esc_url( dismiss_url( 'wpcode-tip' ) ),
		esc_html__( 'Dismiss', 'wp-crontrol' )
	);
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
