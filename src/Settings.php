<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Global settings page — submenu under Artifacts.
 *
 * URL: edit.php?post_type=wm_artifact&page=wmac-settings
 *
 * Options stored (all under the 'wmac_settings' option key):
 *   noindex_default       bool   (true)   — send noindex by default
 *   seo_enabled_default   bool   (false)  — inject SEO meta by default
 *   csp_default           string ('')     — global CSP header value
 *   toolbar_mode          string ('custom') — 'none' | 'custom' | 'core'
 */
class Settings {

	public const OPTION_KEY = 'wmac_settings';

	public function register_hooks(): void {
		add_action( 'admin_menu', [ $this, 'add_menu' ] );
		add_action( 'admin_init', [ $this, 'register_settings' ] );
		add_filter( 'wmac_noindex', [ $this, 'filter_noindex_default' ], 5, 2 );
		add_filter( 'wmac_seo_enabled', [ $this, 'filter_seo_enabled_default' ], 5, 2 );
		add_filter( 'wmac_csp', [ $this, 'filter_csp_default' ], 5, 2 );
		add_filter( 'wmac_artifact_admin_toolbar_mode', [ $this, 'filter_toolbar_mode_default' ], 5, 2 );
	}

	public function add_menu(): void {
		add_submenu_page(
			'edit.php?post_type=' . PostType::KEY,
			__( 'Artifact Canvas Settings', 'webmultipliers-wp-artifact-canvas' ),
			__( 'Settings', 'webmultipliers-wp-artifact-canvas' ),
			'manage_options',
			'wmac-settings',
			[ $this, 'render_page' ]
		);
	}

	public function register_settings(): void {
		register_setting(
			'wmac_settings_group',
			self::OPTION_KEY,
			[
				'sanitize_callback' => [ $this, 'sanitize_options' ],
				'default'           => $this->defaults(),
			]
		);
	}

	public function sanitize_options( $raw ): array {
		if ( ! is_array( $raw ) ) {
			$raw = [];
		}
		$defaults = $this->defaults();

		return [
			'noindex_default'     => ! empty( $raw['noindex_default'] ),
			'seo_enabled_default' => ! empty( $raw['seo_enabled_default'] ),
			'csp_default'         => str_replace( [ "\r", "\n" ], '', sanitize_text_field( $raw['csp_default'] ?? '' ) ),
			'toolbar_mode'        => in_array( $raw['toolbar_mode'] ?? '', [ 'none', 'custom', 'core' ], true )
				? $raw['toolbar_mode']
				: $defaults['toolbar_mode'],
		];
	}

	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'webmultipliers-wp-artifact-canvas' ) );
		}

		$opts = $this->get();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Artifact Canvas Settings', 'webmultipliers-wp-artifact-canvas' ); ?></h1>
			<form method="post" action="options.php">
				<?php settings_fields( 'wmac_settings_group' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">
							<label for="wmac_noindex_default">
								<?php esc_html_e( 'Default noindex', 'webmultipliers-wp-artifact-canvas' ); ?>
							</label>
						</th>
						<td>
							<input
								type="checkbox"
								id="wmac_noindex_default"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[noindex_default]"
								value="1"
								<?php checked( $opts['noindex_default'] ); ?>
							>
							<p class="description">
								<?php esc_html_e( 'Send a noindex header for all artifacts by default. Individual artifacts can override this in their settings panel.', 'webmultipliers-wp-artifact-canvas' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wmac_seo_enabled_default">
								<?php esc_html_e( 'Inject SEO meta by default', 'webmultipliers-wp-artifact-canvas' ); ?>
							</label>
						</th>
						<td>
							<input
								type="checkbox"
								id="wmac_seo_enabled_default"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[seo_enabled_default]"
								value="1"
								<?php checked( $opts['seo_enabled_default'] ); ?>
							>
							<p class="description">
								<?php esc_html_e( 'Inject og: and twitter: meta tags into artifact pages by default. Override per-artifact in the inspector panel.', 'webmultipliers-wp-artifact-canvas' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wmac_csp_default">
								<?php esc_html_e( 'Default Content Security Policy', 'webmultipliers-wp-artifact-canvas' ); ?>
							</label>
						</th>
						<td>
							<input
								type="text"
								id="wmac_csp_default"
								name="<?php echo esc_attr( self::OPTION_KEY ); ?>[csp_default]"
								value="<?php echo esc_attr( $opts['csp_default'] ); ?>"
								class="regular-text"
								placeholder="e.g. default-src 'self'"
							>
							<p class="description">
								<?php esc_html_e( 'A Content-Security-Policy header value applied to all artifacts. Leave blank for no CSP header. Per-artifact CSP overrides this.', 'webmultipliers-wp-artifact-canvas' ); ?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row">
							<label for="wmac_toolbar_mode">
								<?php esc_html_e( 'Admin toolbar', 'webmultipliers-wp-artifact-canvas' ); ?>
							</label>
						</th>
						<td>
							<select id="wmac_toolbar_mode" name="<?php echo esc_attr( self::OPTION_KEY ); ?>[toolbar_mode]">
								<option value="custom" <?php selected( $opts['toolbar_mode'], 'custom' ); ?>>
									<?php esc_html_e( 'Custom (minimal edit/list links)', 'webmultipliers-wp-artifact-canvas' ); ?>
								</option>
								<option value="core" <?php selected( $opts['toolbar_mode'], 'core' ); ?>>
									<?php esc_html_e( 'Core WordPress admin bar', 'webmultipliers-wp-artifact-canvas' ); ?>
								</option>
								<option value="none" <?php selected( $opts['toolbar_mode'], 'none' ); ?>>
									<?php esc_html_e( 'None (hidden for logged-in users)', 'webmultipliers-wp-artifact-canvas' ); ?>
								</option>
							</select>
							<p class="description">
								<?php esc_html_e( 'Controls the toolbar shown to logged-in editors when viewing an artifact on the front end.', 'webmultipliers-wp-artifact-canvas' ); ?>
							</p>
						</td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>
		</div>
		<?php
	}

	// --- Filters ---

	public function filter_noindex_default( bool $noindex, \WP_Post $post ): bool {
		return $this->get()['noindex_default'];
	}

	public function filter_seo_enabled_default( bool $enabled, \WP_Post $post ): bool {
		return $this->get()['seo_enabled_default'];
	}

	public function filter_csp_default( string $csp, \WP_Post $post ): string {
		if ( $csp !== '' ) {
			return $csp;
		}
		return $this->get()['csp_default'];
	}

	public function filter_toolbar_mode_default( string $mode, \WP_Post $post ): string {
		if ( $mode !== 'custom' ) {
			return $mode;
		}
		return $this->get()['toolbar_mode'];
	}

	// --- Helpers ---

	public static function get(): array {
		$saved = get_option( self::OPTION_KEY, [] );
		return array_merge( ( new self() )->defaults(), is_array( $saved ) ? $saved : [] );
	}

	private function defaults(): array {
		return [
			'noindex_default'     => true,
			'seo_enabled_default' => false,
			'csp_default'         => '',
			'toolbar_mode'        => 'custom',
		];
	}
}
