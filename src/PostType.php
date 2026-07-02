<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class PostType {

	const KEY = 'wm_artifact';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		register_post_type(
			self::KEY,
			array(
				'labels'          => array(
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
				),
				'public'          => true,
				'show_in_rest'    => true,
				'menu_icon'       => 'dashicons-media-code',
				// 'custom-fields' is load-bearing: without it the REST posts
				// controller omits `meta` from the item schema and silently
				// discards every meta write — the metabox autosave, the merge
				// tag / asset map repeaters, and the sidebar panels all save
				// through that path. The Custom Fields UI panel stays hidden
				// regardless because all plugin keys are underscore-protected.
				'supports'        => array( 'title', 'editor', 'author', 'revisions', 'custom-fields' ),
				'rewrite'         => array( 'slug' => 'artifact' ),
				'template'        => array( array( 'wmac/artifact' ) ),
				'template_lock'   => 'all',
				'has_archive'     => false,
				// Dedicated capability set (edit_wm_artifacts, …). Administrators are
				// granted the full set on activation (Capabilities::grant_to_administrator);
				// grant individual caps to other roles to delegate authoring. Authors
				// without unfiltered_html still get wp_kses_post on save (Security).
				'capability_type' => array( 'wm_artifact', 'wm_artifacts' ),
				'map_meta_cap'    => true,
			)
		);
	}
}
