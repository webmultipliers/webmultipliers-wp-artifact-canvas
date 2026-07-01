<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Five standalone metaboxes below the block editor: Artifact Settings,
 * Link Governance, Tracking & Webhooks, Merge Tags, Asset Mapping.
 *
 * Each metabox renders plain server-side form markup populated from the
 * current post meta (and, for Merge Tags / Asset Mapping, from the artifact
 * block's stored HTML, parsed server-side via parse_blocks()).
 *
 * A vanilla-JS file (management-metaboxes.js — no wp.data, no React) wires
 * up autosave: every field PATCHes its value straight to the REST API
 * (wp/v2/wm_artifact/{id}) on change, debounced for text inputs. This is
 * deliberate — the block editor renders PHP metaboxes inside a separate
 * iframe with no block editor instance, so the wp.data block-editor and
 * editor stores are never populated there. A direct REST call has no such
 * dependency and works regardless of which frame the script runs in.
 */
class ManagementMetaboxes {

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::KEY, [ $this, 'register_metaboxes' ] );
		add_action( 'enqueue_block_editor_assets',     [ $this, 'enqueue_assets' ] );
	}

	public function register_metaboxes(): void {
		add_meta_box(
			'wmac-settings',
			__( 'Artifact Settings', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_settings' ],
			PostType::KEY,
			'normal',
			'high'
		);
		add_meta_box(
			'wmac-governance',
			__( 'Link Governance', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_governance' ],
			PostType::KEY,
			'normal',
			'high'
		);
		add_meta_box(
			'wmac-tracking',
			__( 'Tracking & Webhooks', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_tracking' ],
			PostType::KEY,
			'normal',
			'high'
		);
		add_meta_box(
			'wmac-merge-tags',
			__( 'Merge Tags', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_merge_tags' ],
			PostType::KEY,
			'normal',
			'high'
		);
		add_meta_box(
			'wmac-asset-mapping',
			__( 'Asset Mapping', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_asset_mapping' ],
			PostType::KEY,
			'normal',
			'high'
		);
	}

	public function enqueue_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== PostType::KEY ) {
			return;
		}

		wp_enqueue_style(
			'wmac-management-metaboxes',
			WMAC_URL . 'blocks/artifact/management-metaboxes.css',
			[],
			WMAC_VERSION
		);

		wp_enqueue_script(
			'wmac-management-metaboxes',
			WMAC_URL . 'blocks/artifact/management-metaboxes.js',
			[ 'wp-api-fetch', 'wp-i18n', 'wp-dom-ready' ],
			WMAC_VERSION,
			true
		);

		global $post;
		$post_id = ( $post instanceof \WP_Post ) ? $post->ID : 0;

		wp_add_inline_script(
			'wmac-management-metaboxes',
			'window.wmacArtifactPostId = ' . wp_json_encode( $post_id ) . ';',
			'before'
		);
	}

	// -----------------------------------------------------------------
	// Shared helpers
	// -----------------------------------------------------------------

	/** Extracts the wmac/artifact block's html + fileStored attrs from post content. */
	private function get_artifact_block( \WP_Post $post ): array {
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( ( $block['blockName'] ?? '' ) === 'wmac/artifact' ) {
				return [
					(string) ( $block['attrs']['html'] ?? '' ),
					! empty( $block['attrs']['fileStored'] ),
				];
			}
		}
		return [ '', false ];
	}

	/** @return string[] */
	private function detect_merge_tags( string $html ): array {
		if ( $html === '' ) {
			return [];
		}
		preg_match_all( '/\{\{([a-zA-Z0-9_]+)\}\}/', $html, $matches );
		return array_values( array_unique( $matches[1] ) );
	}

	/** @return string[] */
	private function detect_asset_paths( string $html ): array {
		if ( $html === '' ) {
			return [];
		}
		preg_match_all( '/(?:src|href)\s*=\s*["\']([^"\']+)["\']/i', $html, $matches );
		$found = [];
		foreach ( $matches[1] as $path ) {
			if ( preg_match( '#^(?:https?://|//|data:|#|mailto:|tel:)#i', $path ) || str_starts_with( $path, '/' ) ) {
				continue;
			}
			$found[ $path ] = true;
		}
		return array_keys( $found );
	}

	private function field_text( string $key, string $label, string $value, string $help = '', string $type = 'text', string $placeholder = '' ): void {
		?>
		<div class="wmac-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="wmac-field__label"><?php echo esc_html( $label ); ?></label>
			<input
				type="<?php echo esc_attr( $type ); ?>"
				id="<?php echo esc_attr( $key ); ?>"
				class="wmac-field__input"
				data-wmac-meta="<?php echo esc_attr( $key ); ?>"
				value="<?php echo esc_attr( $value ); ?>"
				placeholder="<?php echo esc_attr( $placeholder ); ?>"
			/>
			<?php if ( $help ) : ?>
				<p class="wmac-field__help"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function field_textarea( string $key, string $label, string $value, string $help = '', bool $disabled = false ): void {
		?>
		<div class="wmac-field">
			<label for="<?php echo esc_attr( $key ); ?>" class="wmac-field__label"><?php echo esc_html( $label ); ?></label>
			<textarea
				id="<?php echo esc_attr( $key ); ?>"
				class="wmac-field__input wmac-field__textarea"
				data-wmac-meta="<?php echo esc_attr( $key ); ?>"
				rows="5"
				<?php disabled( $disabled ); ?>
			><?php echo esc_textarea( $value ); ?></textarea>
			<?php if ( $help ) : ?>
				<p class="wmac-field__help"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function field_checkbox( string $key, string $label, bool $checked, string $help = '' ): void {
		?>
		<div class="wmac-field wmac-field--checkbox">
			<label for="<?php echo esc_attr( $key ); ?>">
				<input
					type="checkbox"
					id="<?php echo esc_attr( $key ); ?>"
					data-wmac-meta="<?php echo esc_attr( $key ); ?>"
					<?php checked( $checked ); ?>
				/>
				<?php echo esc_html( $label ); ?>
			</label>
			<?php if ( $help ) : ?>
				<p class="wmac-field__help"><?php echo esc_html( $help ); ?></p>
			<?php endif; ?>
		</div>
		<?php
	}

	private function status_indicator(): void {
		echo '<p class="wmac-save-status" aria-live="polite"></p>';
	}

	// -----------------------------------------------------------------
	// Settings
	// -----------------------------------------------------------------

	public function render_settings( \WP_Post $post ): void {
		$alias   = (string) get_post_meta( $post->ID, ArtifactAlias::META_KEY, true );
		$noindex = (string) get_post_meta( $post->ID, ArtifactMeta::NOINDEX, true );
		$seo     = (string) get_post_meta( $post->ID, ArtifactMeta::SEO_ENABLED, true );
		$csp     = (string) get_post_meta( $post->ID, ArtifactMeta::CSP, true );
		$prompt  = (string) get_post_meta( $post->ID, ArtifactMeta::PROMPT, true );
		$site_url = untrailingslashit( (string) get_bloginfo( 'url' ) );

		echo '<div class="wmac-metabox">';

		$this->field_text(
			ArtifactAlias::META_KEY,
			__( 'Custom URL Alias', 'webmultipliers-wp-artifact-canvas' ),
			$alias,
			$alias !== ''
				? $site_url . '/' . $alias
				: __( 'Optional. Enter a path (e.g. "pricing") to serve this artifact at that URL on the front end.', 'webmultipliers-wp-artifact-canvas' ),
			'text',
			'e.g. pricing'
		);

		$this->field_checkbox(
			ArtifactMeta::NOINDEX,
			__( 'Discourage search engines (noindex)', 'webmultipliers-wp-artifact-canvas' ),
			$noindex !== '0',
			__( 'Sends a noindex header. Default is on; uncheck to allow indexing.', 'webmultipliers-wp-artifact-canvas' )
		);

		$this->field_checkbox(
			ArtifactMeta::SEO_ENABLED,
			__( 'Inject SEO meta tags', 'webmultipliers-wp-artifact-canvas' ),
			$seo === '1',
			__( 'Adds og: / twitter: tags from the post title, excerpt, and featured image.', 'webmultipliers-wp-artifact-canvas' )
		);

		$this->field_text(
			ArtifactMeta::CSP,
			__( 'Content Security Policy', 'webmultipliers-wp-artifact-canvas' ),
			$csp,
			__( 'Per-artifact CSP header. Overrides the global wmac_csp filter. Leave blank to inherit.', 'webmultipliers-wp-artifact-canvas' )
		);

		$this->field_textarea(
			ArtifactMeta::PROMPT,
			__( 'Generation Prompt', 'webmultipliers-wp-artifact-canvas' ),
			$prompt,
			__( 'Paste the prompt used to generate this artifact. Private — not published.', 'webmultipliers-wp-artifact-canvas' )
		);

		$this->status_indicator();
		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Governance
	// -----------------------------------------------------------------

	public function render_governance( \WP_Post $post ): void {
		$expires_at = (string) get_post_meta( $post->ID, LinkGovernance::EXPIRES_AT, true );
		$max_views  = (int) get_post_meta( $post->ID, LinkGovernance::MAX_VIEWS, true );
		$view_count = (int) get_post_meta( $post->ID, LinkGovernance::VIEW_COUNT, true );

		echo '<div class="wmac-metabox">';

		$this->field_text(
			LinkGovernance::EXPIRES_AT,
			__( 'Expire after date (UTC)', 'webmultipliers-wp-artifact-canvas' ),
			$expires_at,
			__( 'ISO 8601: 2025-12-31T23:59:59. Leave blank for no date expiry.', 'webmultipliers-wp-artifact-canvas' ),
			'text',
			'2025-12-31T23:59:59'
		);

		$this->field_text(
			LinkGovernance::MAX_VIEWS,
			__( 'Max public views', 'webmultipliers-wp-artifact-canvas' ),
			$max_views > 0 ? (string) $max_views : '',
			__( 'Link expires after this many public views. Leave blank or 0 for unlimited.', 'webmultipliers-wp-artifact-canvas' ),
			'number'
		);

		printf(
			'<p class="wmac-view-count">%s <strong>%s</strong></p>',
			esc_html__( 'Public views:', 'webmultipliers-wp-artifact-canvas' ),
			esc_html( (string) $view_count )
		);

		$this->status_indicator();
		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Tracking
	// -----------------------------------------------------------------

	public function render_tracking( \WP_Post $post ): void {
		$snippet        = (string) get_post_meta( $post->ID, ClientTracking::META_KEY, true );
		$webhook_url    = (string) get_post_meta( $post->ID, ViewWebhook::META_KEY, true );
		$can_save_snippet = current_user_can( 'unfiltered_html' );

		echo '<div class="wmac-metabox">';

		if ( ! $can_save_snippet ) {
			printf(
				'<p class="wmac-field-hint--warning">%s</p>',
				esc_html__( 'Analytics snippets require administrator (unfiltered_html) privileges to save.', 'webmultipliers-wp-artifact-canvas' )
			);
		}

		$this->field_textarea(
			ClientTracking::META_KEY,
			__( 'Analytics snippet', 'webmultipliers-wp-artifact-canvas' ),
			$snippet,
			__( 'Injected before </head> on every serve. Paste a Plausible, Fathom, or custom <script> tag. Requires administrator privileges.', 'webmultipliers-wp-artifact-canvas' ),
			! $can_save_snippet
		);

		$this->field_text(
			ViewWebhook::META_KEY,
			__( 'View alert webhook URL', 'webmultipliers-wp-artifact-canvas' ),
			$webhook_url,
			__( 'Receives a JSON POST each time a public visitor views this artifact. Leave blank to disable.', 'webmultipliers-wp-artifact-canvas' ),
			'url',
			'https://hooks.slack.com/…'
		);

		$this->status_indicator();
		echo '</div>';
	}

	// -----------------------------------------------------------------
	// Merge Tags
	// -----------------------------------------------------------------

	public function render_merge_tags( \WP_Post $post ): void {
		[ $html, $file_stored ] = $this->get_artifact_block( $post );

		$tag_map_json = (string) get_post_meta( $post->ID, ArtifactMeta::TAG_MAP, true );
		$tag_map      = json_decode( $tag_map_json ?: '{}', true );
		$tag_map      = is_array( $tag_map ) ? $tag_map : [];

		$detected = $this->detect_merge_tags( $html );
		$all_tags = array_values( array_unique( array_merge( $detected, array_keys( $tag_map ) ) ) );

		echo '<div class="wmac-metabox wmac-mgmt" data-wmac-map-meta="' . esc_attr( ArtifactMeta::TAG_MAP ) . '">';

		if ( $file_stored ) {
			printf(
				'<p class="wmac-mgmt__note">%s</p>',
				esc_html__( 'HTML is stored on the server — tags below apply to {{placeholders}} in that file.', 'webmultipliers-wp-artifact-canvas' )
			);
		}

		printf(
			'<p class="wmac-mgmt__empty"%s>%s</p>',
			$all_tags ? ' style="display:none"' : '',
			$file_stored
				? esc_html__( 'No tags configured yet. Enter a tag name below to get started.', 'webmultipliers-wp-artifact-canvas' )
				: esc_html__( 'No {{tags}} detected in the artifact HTML. Add placeholders like {{customer_name}} to the HTML, or enter a name below to configure one manually.', 'webmultipliers-wp-artifact-canvas' )
		);

		echo '<table class="wmac-mgmt__table" id="wmac-tagmap-table"' . ( $all_tags ? '' : ' style="display:none"' ) . '>';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Tag', 'webmultipliers-wp-artifact-canvas' ) . '</th>'
			. '<th>' . esc_html__( 'Type', 'webmultipliers-wp-artifact-canvas' ) . '</th>'
			. '<th>' . esc_html__( 'Value / Hook', 'webmultipliers-wp-artifact-canvas' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		foreach ( $all_tags as $tag ) {
			$this->render_tag_row( $tag, in_array( $tag, $detected, true ), is_array( $tag_map[ $tag ] ?? null ) ? $tag_map[ $tag ] : [ 'mode' => 'static', 'value' => '' ] );
		}

		echo '</tbody></table>';

		?>
		<div class="wmac-mgmt__add">
			<input type="text" id="wmac-new-tag" placeholder="<?php esc_attr_e( 'tag_name', 'webmultipliers-wp-artifact-canvas' ); ?>" />
			<button type="button" class="button button-secondary" id="wmac-add-tag" disabled><?php esc_html_e( '+ Add Tag', 'webmultipliers-wp-artifact-canvas' ); ?></button>
		</div>
		<?php

		$this->status_indicator();
		echo '</div>';

		// Hidden row template used by JS when adding a new tag.
		echo '<template id="wmac-tag-row-template">';
		$this->render_tag_row( '__TAG__', false, [ 'mode' => 'static', 'value' => '' ] );
		echo '</template>';
	}

	private function render_tag_row( string $tag, bool $in_html, array $config ): void {
		$mode  = ( $config['mode'] ?? 'static' ) === 'dynamic' ? 'dynamic' : 'static';
		$value = (string) ( $config['value'] ?? '' );
		?>
		<tr data-tag="<?php echo esc_attr( $tag ); ?>" class="wmac-mgmt__row<?php echo $in_html ? '' : ' wmac-mgmt__row--orphan'; ?>">
			<td>
				<code class="wmac-mgmt__tag">{{<?php echo esc_html( $tag ); ?>}}</code>
				<?php if ( ! $in_html ) : ?>
					<span class="wmac-mgmt__orphan"><?php esc_html_e( ' (not in HTML)', 'webmultipliers-wp-artifact-canvas' ); ?></span>
				<?php endif; ?>
			</td>
			<td>
				<select class="wmac-tag-mode">
					<option value="static" <?php selected( $mode, 'static' ); ?>><?php esc_html_e( 'Static', 'webmultipliers-wp-artifact-canvas' ); ?></option>
					<option value="dynamic" <?php selected( $mode, 'dynamic' ); ?>><?php esc_html_e( 'Dynamic', 'webmultipliers-wp-artifact-canvas' ); ?></option>
				</select>
			</td>
			<td>
				<input
					type="text"
					class="wmac-tag-value"
					value="<?php echo esc_attr( $value ); ?>"
					placeholder="<?php esc_attr_e( 'Replacement value…', 'webmultipliers-wp-artifact-canvas' ); ?>"
					<?php echo $mode === 'dynamic' ? ' style="display:none"' : ''; ?>
				/>
				<code class="wmac-mgmt__hook" <?php echo $mode === 'static' ? ' style="display:none"' : ''; ?>>wmac_resolve_tag_<?php echo esc_html( $tag ); ?></code>
			</td>
			<td>
				<button type="button" class="button-link wmac-tag-remove" aria-label="<?php esc_attr_e( 'Remove tag', 'webmultipliers-wp-artifact-canvas' ); ?>">&times;</button>
			</td>
		</tr>
		<?php
	}

	// -----------------------------------------------------------------
	// Asset Mapping
	// -----------------------------------------------------------------

	public function render_asset_mapping( \WP_Post $post ): void {
		[ $html, $file_stored ] = $this->get_artifact_block( $post );

		$asset_map_json = (string) get_post_meta( $post->ID, ArtifactMeta::ASSET_MAP, true );
		$asset_map      = json_decode( $asset_map_json ?: '{}', true );
		$asset_map      = is_array( $asset_map ) ? $asset_map : [];

		$detected  = $this->detect_asset_paths( $html );
		$all_paths = array_values( array_unique( array_merge( $detected, array_keys( $asset_map ) ) ) );

		echo '<div class="wmac-metabox wmac-mgmt" data-wmac-map-meta="' . esc_attr( ArtifactMeta::ASSET_MAP ) . '">';

		if ( $file_stored ) {
			printf(
				'<p class="wmac-mgmt__note">%s</p>',
				esc_html__( 'HTML is stored on the server. Enter relative paths from that file to map them to Media Library URLs.', 'webmultipliers-wp-artifact-canvas' )
			);
		}

		printf(
			'<p class="wmac-mgmt__empty"%s>%s</p>',
			$all_paths ? ' style="display:none"' : '',
			$file_stored
				? esc_html__( 'No paths mapped yet. Enter a relative path below to map it to a Media Library file.', 'webmultipliers-wp-artifact-canvas' )
				: esc_html__( 'No unresolved relative asset paths detected in the artifact HTML.', 'webmultipliers-wp-artifact-canvas' )
		);

		echo '<table class="wmac-mgmt__table" id="wmac-assetmap-table"' . ( $all_paths ? '' : ' style="display:none"' ) . '>';
		echo '<thead><tr>'
			. '<th>' . esc_html__( 'Path', 'webmultipliers-wp-artifact-canvas' ) . '</th>'
			. '<th>' . esc_html__( 'Mapped URL', 'webmultipliers-wp-artifact-canvas' ) . '</th>'
			. '<th></th>'
			. '</tr></thead><tbody>';

		foreach ( $all_paths as $path ) {
			$this->render_asset_row( $path, in_array( $path, $detected, true ), (string) ( $asset_map[ $path ] ?? '' ) );
		}

		echo '</tbody></table>';

		?>
		<div class="wmac-mgmt__add">
			<input type="text" id="wmac-new-path" placeholder="<?php esc_attr_e( 'images/hero.jpg', 'webmultipliers-wp-artifact-canvas' ); ?>" />
			<button type="button" class="button button-secondary" id="wmac-add-path" disabled><?php esc_html_e( '+ Map Path', 'webmultipliers-wp-artifact-canvas' ); ?></button>
		</div>
		<?php

		$this->status_indicator();
		echo '</div>';

		// Hidden row template used by JS when adding a new path.
		echo '<template id="wmac-asset-row-template">';
		$this->render_asset_row( '__PATH__', false, '' );
		echo '</template>';
	}

	private function render_asset_row( string $path, bool $in_html, string $url ): void {
		?>
		<tr data-path="<?php echo esc_attr( $path ); ?>" class="wmac-mgmt__row<?php echo $in_html ? '' : ' wmac-mgmt__row--orphan'; ?>">
			<td>
				<code class="wmac-mgmt__tag"><?php echo esc_html( $path ); ?></code>
				<?php if ( ! $in_html ) : ?>
					<span class="wmac-mgmt__orphan"><?php esc_html_e( ' (not in HTML)', 'webmultipliers-wp-artifact-canvas' ); ?></span>
				<?php endif; ?>
			</td>
			<td class="wmac-mgmt__asset-url-cell">
				<a
					href="<?php echo esc_url( $url ); ?>"
					target="_blank"
					rel="noreferrer"
					class="wmac-mgmt__asset-url"
					title="<?php echo esc_attr( $url ); ?>"
					<?php echo $url === '' ? ' style="display:none"' : ''; ?>
				><?php echo esc_html( $url ); ?></a>
				<span class="wmac-mgmt__unmapped" <?php echo $url !== '' ? ' style="display:none"' : ''; ?>><?php esc_html_e( 'Not mapped', 'webmultipliers-wp-artifact-canvas' ); ?></span>
			</td>
			<td class="wmac-mgmt__actions">
				<button type="button" class="button button-secondary wmac-asset-select"><?php echo $url === '' ? esc_html__( 'Select', 'webmultipliers-wp-artifact-canvas' ) : esc_html__( 'Change', 'webmultipliers-wp-artifact-canvas' ); ?></button>
				<button type="button" class="button-link wmac-asset-remove" <?php echo $url === '' ? ' style="display:none"' : ''; ?>><?php esc_html_e( 'Remove', 'webmultipliers-wp-artifact-canvas' ); ?></button>
			</td>
		</tr>
		<?php
	}
}
