<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Real WordPress metaboxes for Merge Tags and Asset Mapping management.
 *
 * Registers two metaboxes via add_meta_box() that appear below the
 * Gutenberg block editor. Each metabox renders a PHP div container;
 * management-metaboxes.js mounts an interactive React app into it using
 * the same wp.data store that powers the rest of the editor, so changes
 * are captured in the entity store and saved when the user clicks Update.
 *
 * The JS also registers a PluginSidebar so the same management UI is
 * accessible from the editor header toolbar (More tools & options → …).
 */
class ManagementMetaboxes {

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::KEY, [ $this, 'register_metaboxes' ] );
		add_action( 'enqueue_block_editor_assets',     [ $this, 'enqueue_assets' ] );
	}

	public function register_metaboxes(): void {
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

	public function render_merge_tags( \WP_Post $post ): void {
		// React mounts into this div via management-metaboxes.js.
		echo '<div id="wmac-merge-tags-root" data-post-id="' . esc_attr( (string) $post->ID ) . '">'
		   . '<p class="wmac-metabox-loading">' . esc_html__( 'Loading…', 'webmultipliers-wp-artifact-canvas' ) . '</p>'
		   . '</div>';
	}

	public function render_asset_mapping( \WP_Post $post ): void {
		echo '<div id="wmac-asset-mapping-root" data-post-id="' . esc_attr( (string) $post->ID ) . '">'
		   . '<p class="wmac-metabox-loading">' . esc_html__( 'Loading…', 'webmultipliers-wp-artifact-canvas' ) . '</p>'
		   . '</div>';
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
			[
				'wp-plugins',
				'wp-edit-post',
				'wp-editor',
				'wp-element',
				'wp-components',
				'wp-data',
				'wp-core-data',
				'wp-block-editor',
				'wp-i18n',
				'wp-dom-ready',
			],
			WMAC_VERSION,
			true
		);
	}
}
