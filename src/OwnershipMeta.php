<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Developer / project-manager ownership assignment per artifact.
 *
 * Stores a WordPress user ID in _wmac_owner_id and surfaces it as an
 * admin column and a REST-accessible meta field (for the sidebar panel).
 */
class OwnershipMeta {

	const META_KEY = '_wmac_owner_id';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'manage_' . PostType::KEY . '_posts_columns', array( $this, 'add_column' ) );
		add_action( 'manage_' . PostType::KEY . '_posts_custom_column', array( $this, 'render_column' ), 10, 2 );
		add_filter( 'manage_edit-' . PostType::KEY . '_sortable_columns', array( $this, 'sortable_column' ) );
	}

	public function register_meta(): void {
		register_post_meta(
			PostType::KEY,
			self::META_KEY,
			array(
				'type'              => 'integer',
				'description'       => 'WP user ID of the responsible owner / project manager for this artifact.',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	public function add_column( array $columns ): array {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( $key === 'author' ) {
				$new['wmac_owner'] = __( 'Owner', 'webmultipliers-wp-artifact-canvas' );
			}
		}
		if ( ! isset( $new['wmac_owner'] ) ) {
			$new['wmac_owner'] = __( 'Owner', 'webmultipliers-wp-artifact-canvas' );
		}
		return $new;
	}

	public function render_column( string $column, int $post_id ): void {
		if ( $column !== 'wmac_owner' ) {
			return;
		}
		$owner_id = (int) get_post_meta( $post_id, self::META_KEY, true );
		if ( ! $owner_id ) {
			echo '&mdash;';
			return;
		}
		$user = get_user_by( 'ID', $owner_id );
		echo $user ? esc_html( $user->display_name ) : '&mdash;';
	}

	public function sortable_column( array $columns ): array {
		$columns['wmac_owner'] = 'wmac_owner';
		return $columns;
	}
}
