<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Single full-width tabbed metabox below the block editor.
 *
 * Registers one metabox ("Artifact") with five tabs rendered by React:
 * Settings | Governance | Tracking | Merge Tags | Asset Mapping.
 *
 * The React app reads and writes post meta via the wp.data entity store
 * (getEditedEntityRecord / editEntityRecord), so all changes save atomically
 * when the user clicks Update — identical to how the block editor handles meta.
 */
class ManagementMetaboxes {

	public function register_hooks(): void {
		add_action( 'add_meta_boxes_' . PostType::KEY, [ $this, 'register_metaboxes' ] );
		add_action( 'enqueue_block_editor_assets',     [ $this, 'enqueue_assets' ] );
	}

	public function register_metaboxes(): void {
		add_meta_box(
			'wmac-artifact',
			__( 'Artifact', 'webmultipliers-wp-artifact-canvas' ),
			[ $this, 'render_metabox' ],
			PostType::KEY,
			'normal',
			'high'
		);
	}

	public function render_metabox( \WP_Post $post ): void {
		echo '<div id="wmac-artifact-root" data-post-id="' . esc_attr( (string) $post->ID ) . '">'
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
