<?php
/**
 * The Cron AI screen: what an AI assistant can do with this site's cron through WP Crontrol's abilities.
 */

namespace Crontrol\Abilities;

/**
 * Renders the Cron AI screen.
 */
function admin_ai_page(): void {
	$definitions = get_definitions();
	$vibe        = \Crontrol\Promos\get_plugin( 'vibe-ai' );

	?>
	<div class="wrap crontrol-ai">
		<?php \Crontrol\do_tabs(); ?>
		<h1 class="wp-heading-inline"><?php esc_html_e( 'Cron AI', 'wp-crontrol' ); ?></h1>
		<hr class="wp-header-end">

		<style>
			.crontrol-ai .crontrol-ai-intro { font-size: 14px; max-width: 760px; }
			.crontrol-ai .crontrol-ai-columns { display: grid; gap: 20px; grid-template-columns: minmax( 0, 2fr ) minmax( 0, 1fr ); max-width: 1200px; }
			@media ( max-width: 960px ) { .crontrol-ai .crontrol-ai-columns { grid-template-columns: 1fr; } }
			.crontrol-ai .card { max-width: none; margin-top: 0; }
			.crontrol-ai .crontrol-ai-prompts li { font-style: italic; }
			.crontrol-ai .crontrol-ai-changes { color: #996800; font-size: 12px; font-weight: 600; margin-left: 4px; }
		</style>

		<p class="crontrol-ai-intro">
			<?php esc_html_e( 'Let an AI assistant check and manage this site’s cron events for you. WP Crontrol makes its features available through the WordPress Abilities API, so a connected assistant can use them with the same permissions as your account.', 'wp-crontrol' ); ?>
		</p>

		<?php if ( ! available() ) : ?>
			<div class="notice notice-warning inline">
				<p>
					<?php
					printf(
						/* translators: %s: WordPress version number */
						esc_html__( 'This site runs WordPress %s. The Abilities API needs WordPress 6.9 or later, so these abilities are not available until WordPress is updated.', 'wp-crontrol' ),
						esc_html( get_bloginfo( 'version' ) )
					);
					?>
				</p>
			</div>
		<?php else : ?>
			<div class="notice notice-success inline">
				<p>
					<?php
					printf(
						/* translators: %s: Number of abilities */
						esc_html( _n( '%s cron ability is available on this site.', '%s cron abilities are available on this site.', count( $definitions ), 'wp-crontrol' ) ),
						esc_html( number_format_i18n( count( $definitions ) ) )
					);
					?>
				</p>
			</div>
		<?php endif; ?>

		<div class="crontrol-ai-columns">
			<div>
				<div class="card">
					<h2><?php esc_html_e( 'Things you can ask', 'wp-crontrol' ); ?></h2>
					<ul class="crontrol-ai-prompts">
						<li><?php esc_html_e( '“Which cron events failed this week, and why?”', 'wp-crontrol' ); ?></li>
						<li><?php esc_html_e( '“Run the backup event now and tell me if it worked.”', 'wp-crontrol' ); ?></li>
						<li><?php esc_html_e( '“Pause the newsletter hook until Monday.”', 'wp-crontrol' ); ?></li>
						<li><?php esc_html_e( '“Which events are late, or have nothing attached to them?”', 'wp-crontrol' ); ?></li>
						<li><?php esc_html_e( '“Turn on logging for the WooCommerce events and show me the slowest ones.”', 'wp-crontrol' ); ?></li>
					</ul>
				</div>

				<h2><?php esc_html_e( 'What an assistant can do', 'wp-crontrol' ); ?></h2>
				<table class="widefat striped">
					<tbody>
						<?php foreach ( $definitions as $name => $definition ) : ?>
							<tr>
								<td>
									<strong><?php echo esc_html( $definition['label'] ); ?></strong>
									<?php if ( empty( $definition['annotations']['readonly'] ) ) : ?>
										<span class="crontrol-ai-changes"><?php esc_html_e( 'Makes changes', 'wp-crontrol' ); ?></span>
									<?php endif; ?>
									<br />
									<?php echo esc_html( $definition['description'] ); ?>
									<br />
									<code><?php echo esc_html( $name ); ?></code>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<p>
					<?php esc_html_e( 'An assistant can’t create, edit, or delete PHP or URL cron events, and running a PHP cron event also needs permission to edit files. Every action uses your account’s permissions.', 'wp-crontrol' ); ?>
				</p>
			</div>

			<div>
				<?php if ( $vibe && ! $vibe['active'] ) : ?>
					<?php
					$card = \Crontrol\Promos\featured_card(
						'vibe-ai',
						$vibe,
						__( 'Connect an AI assistant', 'wp-crontrol' ),
						__( 'WPVibe connects Claude, ChatGPT, and other AI assistants to your site, and can use these abilities. It’s from the same team as WP Crontrol, and free to start.', 'wp-crontrol' )
					);
					echo $card; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in featured_card().
					?>
				<?php else : ?>
					<div class="card">
						<h2><?php esc_html_e( 'Connect an AI assistant', 'wp-crontrol' ); ?></h2>
						<?php if ( $vibe ) : ?>
							<p><?php esc_html_e( 'WPVibe is active on this site. Connect Claude, ChatGPT, or another assistant in WPVibe, then ask it about your cron events.', 'wp-crontrol' ); ?></p>
							<p><a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=vibe-ai' ) ); ?>"><?php esc_html_e( 'Open WPVibe', 'wp-crontrol' ); ?></a></p>
						<?php else : ?>
							<p><?php esc_html_e( 'Connect an AI assistant that supports the WordPress Abilities API, then ask it about your cron events.', 'wp-crontrol' ); ?></p>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<p class="description">
					<?php esc_html_e( 'These abilities also work with any other MCP client or AI tool that supports the WordPress Abilities API.', 'wp-crontrol' ); ?>
				</p>
			</div>
		</div>
	</div>
	<?php
}
