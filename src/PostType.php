<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class PostType {

	const KEY = 'wm_artifact';

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_post_type( self::KEY, [
			'labels'        => [
				'name'               => __( 'Artifacts', 'webmultipliers-wp-artifact-canvas' ),
				'singular_name'      => __( 'Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'add_new'            => __( 'Add New Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'add_new_item'       => __( 'Add New Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'edit_item'          => __( 'Edit Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'new_item'           => __( 'New Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'view_item'          => __( 'View Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'search_items'       => __( 'Search Artifacts', 'webmultipliers-wp-artifact-canvas' ),
				'not_found'          => __( 'No artifacts found.', 'webmultipliers-wp-artifact-canvas' ),
				'not_found_in_trash' => __( 'No artifacts found in trash.', 'webmultipliers-wp-artifact-canvas' ),
				'menu_name'          => __( 'Artifacts', 'webmultipliers-wp-artifact-canvas' ),
			],
			'public'        => true,
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-media-code',
			'supports'      => [ 'title', 'editor', 'author', 'revisions' ],
			'rewrite'       => [ 'slug' => 'artifact' ],
			'template'      => [ [ 'wmac/artifact' ] ],
			'template_lock' => 'all',
			'has_archive'   => false,
			// Gate editing to trusted users; low-privilege saves can break block comment delimiters.
			'capabilities'  => [
				'edit_post'          => 'unfiltered_html',
				'read_post'          => 'read',
				'delete_post'        => 'unfiltered_html',
				'edit_posts'         => 'unfiltered_html',
				'edit_others_posts'  => 'unfiltered_html',
				'publish_posts'      => 'unfiltered_html',
				'read_private_posts' => 'unfiltered_html',
			],
		] );
	}
}
