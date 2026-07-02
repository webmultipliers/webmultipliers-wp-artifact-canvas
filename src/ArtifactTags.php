<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Flat tagging taxonomy for artifacts (wm_artifact_tag).
 *
 * Organizational only — artifacts are served as raw passthrough documents,
 * so the taxonomy has no public archive. show_in_rest surfaces the native
 * tag panel in the block editor sidebar; show_admin_column adds a Tags
 * column to the artifact list table.
 *
 * Complements the hierarchical wm_client taxonomy (ClientTaxonomy): clients
 * group deliverables by owner, tags label them freely (e.g. "landing-page",
 * "q3-campaign", "prototype").
 */
class ArtifactTags {

	const KEY = 'wm_artifact_tag';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register' ) );
	}

	public function register(): void {
		register_taxonomy(
			self::KEY,
			PostType::KEY,
			array(
				'labels'            => array(
					'name'                       => __( 'Artifact Tags', 'webmultipliers-wp-artifact-canvas' ),
					'singular_name'              => __( 'Artifact Tag', 'webmultipliers-wp-artifact-canvas' ),
					'search_items'               => __( 'Search Tags', 'webmultipliers-wp-artifact-canvas' ),
					'popular_items'              => __( 'Popular Tags', 'webmultipliers-wp-artifact-canvas' ),
					'all_items'                  => __( 'All Tags', 'webmultipliers-wp-artifact-canvas' ),
					'edit_item'                  => __( 'Edit Tag', 'webmultipliers-wp-artifact-canvas' ),
					'update_item'                => __( 'Update Tag', 'webmultipliers-wp-artifact-canvas' ),
					'add_new_item'               => __( 'Add New Tag', 'webmultipliers-wp-artifact-canvas' ),
					'new_item_name'              => __( 'New Tag Name', 'webmultipliers-wp-artifact-canvas' ),
					'separate_items_with_commas' => __( 'Separate tags with commas', 'webmultipliers-wp-artifact-canvas' ),
					'choose_from_most_used'      => __( 'Choose from the most used tags', 'webmultipliers-wp-artifact-canvas' ),
					'not_found'                  => __( 'No tags found.', 'webmultipliers-wp-artifact-canvas' ),
					'menu_name'                  => __( 'Tags', 'webmultipliers-wp-artifact-canvas' ),
				),
				'hierarchical'      => false,
				'public'            => false,
				'show_ui'           => true,
				'show_in_menu'      => true,
				'show_in_rest'      => true,
				'show_admin_column' => true,
				'show_tagcloud'     => false,
				'rewrite'           => false,
			)
		);
	}
}
