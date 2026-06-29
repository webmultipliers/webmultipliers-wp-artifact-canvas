<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Hierarchical client/project taxonomy for wm_artifact posts.
 *
 * Taxonomy: wm_client
 * Non-public (no archive URLs) — purely an organisational tool in the admin.
 * Agencies can nest clients → projects → sub-projects as deeply as needed.
 */
class ClientTaxonomy {

	const TAXONOMY = 'wm_client';

	public function register_hooks(): void {
		add_action( 'init', [ $this, 'register' ] );
	}

	public function register(): void {
		register_taxonomy( self::TAXONOMY, PostType::KEY, [
			'labels'            => [
				'name'                       => __( 'Clients', 'webmultipliers-wp-artifact-canvas' ),
				'singular_name'              => __( 'Client', 'webmultipliers-wp-artifact-canvas' ),
				'search_items'               => __( 'Search Clients', 'webmultipliers-wp-artifact-canvas' ),
				'all_items'                  => __( 'All Clients', 'webmultipliers-wp-artifact-canvas' ),
				'parent_item'                => __( 'Parent Client', 'webmultipliers-wp-artifact-canvas' ),
				'parent_item_colon'          => __( 'Parent Client:', 'webmultipliers-wp-artifact-canvas' ),
				'edit_item'                  => __( 'Edit Client', 'webmultipliers-wp-artifact-canvas' ),
				'update_item'                => __( 'Update Client', 'webmultipliers-wp-artifact-canvas' ),
				'add_new_item'               => __( 'Add New Client', 'webmultipliers-wp-artifact-canvas' ),
				'new_item_name'              => __( 'New Client Name', 'webmultipliers-wp-artifact-canvas' ),
				'menu_name'                  => __( 'Clients', 'webmultipliers-wp-artifact-canvas' ),
				'not_found'                  => __( 'No clients found.', 'webmultipliers-wp-artifact-canvas' ),
				'back_to_items'              => __( '&larr; Back to Clients', 'webmultipliers-wp-artifact-canvas' ),
				'separate_items_with_commas' => null,
				'choose_from_most_used'      => __( 'Most used clients', 'webmultipliers-wp-artifact-canvas' ),
			],
			'hierarchical'          => true,
			'show_ui'               => true,
			'show_in_rest'          => true,
			'show_admin_column'     => true,
			'show_in_nav_menus'     => false,
			'show_tagcloud'         => false,
			'query_var'             => false,
			'rewrite'               => false,
			'public'                => false,
			'publicly_queryable'    => false,
			'capabilities'          => [
				'manage_terms' => 'unfiltered_html',
				'edit_terms'   => 'unfiltered_html',
				'delete_terms' => 'unfiltered_html',
				'assign_terms' => 'edit_posts',
			],
		] );
	}
}
