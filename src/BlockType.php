<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class BlockType {

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
		add_action( 'enqueue_block_editor_assets', [ $this, 'enqueue_editor_assets' ] );
	}

	public function enqueue_editor_assets(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || $screen->base !== 'post' || $screen->post_type !== PostType::KEY ) {
			return;
		}

		wp_enqueue_code_editor( [ 'type' => 'text/html' ] );
		wp_enqueue_media();

		wp_enqueue_script(
			'wmac-document-panels',
			WMAC_URL . 'blocks/artifact/document-panels.js',
			[ 'wp-plugins', 'wp-edit-post', 'wp-editor', 'wp-components', 'wp-element', 'wp-data', 'wp-i18n', 'wp-core-data' ],
			WMAC_VERSION,
			true
		);
	}

	public function register(): void {
		register_block_type( WMAC_PATH . 'blocks/artifact', [
			'render_callback' => [ $this, 'render' ],
		] );

		// editor.js uses wp.coreData.useEntityProp for the bottom management panel.
		// Formally declare the dependency so WP outputs wp-core-data before the block script.
		global $wp_scripts;
		$handle = 'webmultipliers-wp-artifact-canvas-editor-script';
		if ( isset( $wp_scripts->registered[ $handle ] ) &&
		     ! in_array( 'wp-core-data', $wp_scripts->registered[ $handle ]->deps, true ) ) {
			$wp_scripts->registered[ $handle ]->deps[] = 'wp-core-data';
		}
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
