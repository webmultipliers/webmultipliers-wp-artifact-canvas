<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class BlockType {

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_filter( 'allowed_block_types_all', array( $this, 'restrict_block_types' ), 10, 2 );
	}

	/**
	 * The artifact template locks to a single wmac/artifact block, so
	 * shipping the whole core block library to that editor screen is pure
	 * overhead. Restricting the allowed types keeps the inserter and its
	 * asset payload minimal.
	 *
	 * @param bool|string[]            $allowed Allowed block types.
	 * @param \WP_Block_Editor_Context $context Editor context.
	 * @return bool|string[]
	 */
	public function restrict_block_types( $allowed, $context ) {
		if ( $context instanceof \WP_Block_Editor_Context
			&& $context->post instanceof \WP_Post
			&& $context->post->post_type === PostType::KEY
		) {
			return array( 'wmac/artifact' );
		}

		return $allowed;
	}

	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== PostType::KEY ) {
			return;
		}

		wp_enqueue_code_editor( array( 'type' => 'text/html' ) );
		wp_enqueue_media();

		wp_enqueue_script(
			'wmac-document-panels',
			WMAC_URL . 'blocks/artifact/document-panels.js',
			array( 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-core-data' ),
			WMAC_VERSION,
			true
		);
	}

	public function register(): void {
		register_block_type(
			WMAC_PATH . 'blocks/artifact',
			array(
				'render_callback' => array( $this, 'render' ),
			)
		);
	}

	/**
	 * The passthrough renderer intercepts single-artifact requests before this runs.
	 * In any other context (archive, embed, REST-rendered content) we output nothing —
	 * dumping a full HTML document mid-page would break the surrounding layout.
	 */
	public function render( array $attributes ): string {
		return '';
	}
}
