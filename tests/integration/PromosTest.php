<?php declare(strict_types = 1);

namespace Crontrol\Tests;

use Crontrol\Promos;

/**
 * When the suggestions for our other plugins show, and to whom.
 */
final class PromosTest extends Test {
	/**
	 * @var array<string,array{active?:bool,installed?:bool}>
	 */
	private array $overrides = array();

	#[\Override]
	public function set_up() {
		parent::set_up();

		$this->overrides = array();
		add_filter( 'crontrol/promos/plugins', array( $this, 'filter_plugins' ) );
		delete_option( 'active_plugins' );
	}

	#[\Override]
	public function tear_down() {
		remove_filter( 'crontrol/promos/plugins', array( $this, 'filter_plugins' ) );
		remove_all_filters( 'crontrol/promos/backup_hooks' );
		remove_all_filters( 'crontrol/promos/backup_plugins' );
		remove_all_filters( 'user_has_cap' );
		remove_all_filters( 'map_meta_cap' );
		wp_clear_scheduled_hook( 'updraft_backup' );
		wp_clear_scheduled_hook( 'my_backup_run' );

		parent::tear_down();
	}

	/**
	 * Every suggested plugin starts out not installed, then takes the test's overrides.
	 *
	 * @param array<string,array<string,mixed>> $plugins The suggested plugins.
	 * @return array<string,array<string,mixed>>
	 */
	public function filter_plugins( array $plugins ): array {
		foreach ( $plugins as $slug => $plugin ) {
			$plugins[ $slug ] = array_merge(
				$plugin,
				array(
					'active'    => false,
					'installed' => false,
				),
				$this->overrides[ $slug ] ?? array()
			);
		}

		return $plugins;
	}

	private function admin(): int {
		$user = self::factory()->user->create( array( 'role' => 'administrator' ) );
		self::assertIsInt( $user );

		if ( is_multisite() ) {
			grant_super_admin( $user );
		}

		wp_set_current_user( $user );

		return $user;
	}

	private function without_cap( string $cap ): void {
		add_filter(
			'user_has_cap',
			static function ( array $allcaps ) use ( $cap ): array {
				$allcaps[ $cap ] = false;
				return $allcaps;
			}
		);

		// Super admins on multisite skip user_has_cap, so deny the capability outright.
		add_filter(
			'map_meta_cap',
			static function ( array $caps, string $requested ) use ( $cap ): array {
				return $requested === $cap ? array( 'do_not_allow' ) : $caps;
			},
			10,
			2
		);
	}

	private function dismiss( string $key ): void {
		update_user_meta( get_current_user_id(), Promos\DISMISSED_META, array( $key ) );
	}

	private function render( callable $callback, string ...$args ): string {
		ob_start();
		$callback( ...$args );
		return (string) ob_get_clean();
	}

	public function testPanelShowsOnEveryCronScreen(): void {
		$this->admin();

		foreach ( array( 'events', 'logs', 'insights', 'alerts', 'ai', 'schedules' ) as $tab ) {
			self::assertTrue( Promos\is_welcome_panel_visible( $tab ), $tab );
		}

		// Not on the add and edit forms.
		self::assertFalse( Promos\is_welcome_panel_visible( 'add-event' ) );
		self::assertFalse( Promos\is_welcome_panel_visible( 'edit-event' ) );
	}

	public function testOneDismissalHidesThePanelEverywhere(): void {
		$this->admin();
		$this->dismiss( 'welcome' );

		foreach ( array( 'events', 'logs', 'insights', 'alerts', 'ai', 'schedules' ) as $tab ) {
			self::assertFalse( Promos\is_welcome_panel_visible( $tab ), $tab );
		}

		self::assertSame( '', $this->render( 'Crontrol\Promos\show_welcome_panel', 'logs' ) );
	}

	public function testPanelOffersEveryPluginThatIsNotActive(): void {
		$this->admin();
		$this->overrides = array(
			'duplicator'   => array( 'installed' => true ),
			'wp-mail-smtp' => array(
				'active'    => true,
				'installed' => true,
			),
		);

		$html = $this->render( 'Crontrol\Promos\show_welcome_panel', 'insights' );

		self::assertStringContainsString( 'Install WPVibe (free)', $html );
		self::assertStringContainsString( 'Activate Duplicator', $html );
		self::assertStringContainsString( 'Install WPCode (free)', $html );
		self::assertStringNotContainsString( 'WP Mail SMTP', $html );
		self::assertStringContainsString( 'images/promos/vibe-ai.png', $html );
		self::assertStringNotContainsString( 'https://ps.w.org', $html );
	}

	public function testPanelOffersNothingToUsersWhoCannotInstallPlugins(): void {
		$this->admin();
		$this->without_cap( 'install_plugins' );

		$html = $this->render( 'Crontrol\Promos\show_welcome_panel', 'events' );

		// The news still shows, without the cards.
		self::assertStringContainsString( 'John Blackbourn', $html );
		self::assertStringNotContainsString( 'crontrol-promo-card', $html );
	}

	public function testButtonsNameThePlugin(): void {
		$this->admin();
		$smtp = Promos\get_plugin( 'wp-mail-smtp' );
		self::assertNotNull( $smtp );

		self::assertStringContainsString( 'Install WP Mail SMTP (free)', Promos\plugin_button( 'wp-mail-smtp', $smtp ) );

		$smtp['installed'] = true;
		self::assertStringContainsString( 'Activate WP Mail SMTP', Promos\plugin_button( 'wp-mail-smtp', $smtp ) );

		$this->without_cap( 'activate_plugin' );
		$this->without_cap( 'activate_plugins' );
		self::assertStringContainsString( 'Learn more about WP Mail SMTP', Promos\plugin_button( 'wp-mail-smtp', $smtp ) );
	}

	public function testBackupsAreFoundFromScheduledEventsAndActivePlugins(): void {
		self::assertFalse( Promos\has_backups() );

		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'updraft_backup' );
		self::assertTrue( Promos\has_backups() );
		wp_clear_scheduled_hook( 'updraft_backup' );

		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'my_backup_run' );
		self::assertFalse( Promos\has_backups() );

		add_filter( 'crontrol/promos/backup_hooks', static function ( array $prefixes ): array {
			$prefixes[] = 'my_backup_';
			return $prefixes;
		} );
		self::assertTrue( Promos\has_backups() );
		remove_all_filters( 'crontrol/promos/backup_hooks' );
		wp_clear_scheduled_hook( 'my_backup_run' );

		update_option( 'active_plugins', array( 'my-backup/my-backup.php' ) );
		self::assertFalse( Promos\has_backups() );

		add_filter( 'crontrol/promos/backup_plugins', static function ( array $files ): array {
			$files[] = 'my-backup/my-backup.php';
			return $files;
		} );
		self::assertTrue( Promos\has_backups() );
	}

	public function testBackupNoticeWaitsForThePanelAndCanBeDismissed(): void {
		$this->admin();

		// One suggestion at a time: not while the panel shows.
		self::assertFalse( Promos\is_backup_notice_visible( 'events' ) );

		$this->dismiss( 'welcome' );
		self::assertTrue( Promos\is_backup_notice_visible( 'events' ) );
		self::assertFalse( Promos\is_backup_notice_visible( 'logs' ) );

		$html = $this->render( 'Crontrol\Promos\show_backup_notice', 'events' );
		self::assertStringContainsString( 'No scheduled backups found on this site.', $html );
		self::assertStringContainsString( 'Install Duplicator (free)', $html );
		self::assertStringContainsString( 'crontrol_dismiss_promo=no-backup', $html );

		update_user_meta( get_current_user_id(), Promos\DISMISSED_META, array( 'welcome', 'no-backup' ) );
		self::assertFalse( Promos\is_backup_notice_visible( 'events' ) );
	}

	public function testBackupNoticeHidesWhenThereAreBackupsOrDuplicatorIsActive(): void {
		$this->admin();
		$this->dismiss( 'welcome' );

		wp_schedule_event( time() + DAY_IN_SECONDS, 'daily', 'updraft_backup' );
		self::assertFalse( Promos\is_backup_notice_visible( 'events' ) );
		wp_clear_scheduled_hook( 'updraft_backup' );

		$this->overrides = array(
			'duplicator' => array(
				'active'    => true,
				'installed' => true,
			),
		);
		self::assertFalse( Promos\is_backup_notice_visible( 'events' ) );

		$this->overrides = array();
		$this->without_cap( 'install_plugins' );
		self::assertFalse( Promos\is_backup_notice_visible( 'events' ) );
	}

	public function testWPCodeTipShowsUntilDismissedOrActive(): void {
		$this->admin();

		$html = $this->render( 'Crontrol\Promos\show_wpcode_tip' );
		self::assertStringContainsString( 'WPCode', $html );
		self::assertStringContainsString( 'Install WPCode (free)', $html );

		$this->overrides = array(
			'insert-headers-and-footers' => array(
				'active'    => true,
				'installed' => true,
			),
		);
		self::assertFalse( Promos\is_wpcode_tip_visible() );

		$this->overrides = array();
		$this->dismiss( 'wpcode-tip' );
		self::assertFalse( Promos\is_wpcode_tip_visible() );
	}

	public function testWPCodeTipNeedsPermissionToInstallPlugins(): void {
		$this->admin();
		$this->without_cap( 'install_plugins' );

		self::assertFalse( Promos\is_wpcode_tip_visible() );
		self::assertSame( '', $this->render( 'Crontrol\Promos\show_wpcode_tip' ) );
	}

	public function testDismissalReportsWhetherItWasSaved(): void {
		$this->admin();

		self::assertTrue( Promos\save_dismissal( 'welcome' ) );
		self::assertTrue( Promos\save_dismissal( 'welcome' ), 'Already dismissed counts as saved.' );

		// A write that fails is reported, so the suggestion isn't hidden as though it was dismissed.
		add_filter( 'update_user_metadata', '__return_false' );
		add_filter( 'add_user_metadata', '__return_false' );
		self::assertFalse( Promos\save_dismissal( 'wpcode-tip' ) );
		remove_filter( 'update_user_metadata', '__return_false' );
		remove_filter( 'add_user_metadata', '__return_false' );

		self::assertFalse( Promos\is_dismissed( 'wpcode-tip' ) );
		self::assertTrue( Promos\is_dismissed( 'welcome' ) );
	}

	public function testInstalledKeyComesFromTheSitesPlugins(): void {
		remove_filter( 'crontrol/promos/plugins', array( $this, 'filter_plugins' ) );

		$installed = get_plugins();

		foreach ( Promos\get_plugins() as $slug => $plugin ) {
			self::assertSame( array_key_exists( $plugin['file'], $installed ), $plugin['installed'], $slug );
		}
	}
}
