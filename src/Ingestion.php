<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * REST endpoint for programmatic artifact creation.
 *
 * POST /wmac/v1/artifacts
 *
 * Accepts raw HTML (or a JSON body with an "html" key) and creates a Draft
 * artifact post. Returns the edit URL and the front-end preview URL so the
 * caller (an AI agent, a CI script, etc.) can hand the live URL back to the
 * user immediately.
 *
 * Authentication: standard WordPress cookie auth or Application Passwords.
 * Required capability: edit_posts on the wm_artifact post type.
 *
 * Request body (JSON):
 *   { "html": "<full HTML document>", "title": "optional title" }
 *
 * Or multipart form data:
 *   html=<full HTML document>&title=optional title
 *
 * Response 201:
 *   {
 *     "id":          123,
 *     "title":       "Artifact (2025-06-26 14:32)",
 *     "status":      "draft",
 *     "edit_url":    "https://example.com/wp-admin/post.php?post=123&action=edit",
 *     "preview_url": "https://example.com/artifact/artifact-2025-06-26/?preview=true&preview_nonce=abc123"
 *   }
 */
class Ingestion {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'wmac/v1',
			'/artifacts',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_create' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'html'   => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => static function ( $v ): string {
							return (string) $v;
						},
						'validate_callback' => static function ( $v ): bool {
							return is_string( $v ) && trim( $v ) !== '';
						},
					),
					'title'  => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_text_field',
						'validate_callback' => static function ( $v ): bool {
							return is_string( $v ) && mb_strlen( $v ) <= 200;
						},
					),
					'status' => array(
						'required'          => false,
						'type'              => 'string',
						'default'           => 'draft',
						'enum'              => array( 'draft', 'pending', 'publish' ),
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
	}

	public function check_permission(): bool|\WP_Error {
		if ( ! is_user_logged_in() ) {
			return new \WP_Error(
				'wmac_unauthorized',
				__( 'Authentication is required.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 401 )
			);
		}

		$post_type_object = get_post_type_object( PostType::KEY );
		$cap              = $post_type_object ? $post_type_object->cap->edit_posts : 'edit_posts';

		if ( ! current_user_can( $cap ) ) {
			return new \WP_Error(
				'wmac_forbidden',
				__( 'You do not have permission to create artifacts.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function rest_create( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$html   = (string) $request->get_param( 'html' );
		$title  = (string) $request->get_param( 'title' );
		$status = (string) $request->get_param( 'status' );

		if ( ! in_array( $status, array( 'draft', 'pending', 'publish' ), true ) ) {
			$status = 'draft';
		}

		// Publishing straight from the pipeline needs the explicit publish
		// capability, not just edit_posts.
		if ( $status === 'publish' ) {
			$post_type_object = get_post_type_object( PostType::KEY );
			$publish_cap      = $post_type_object ? $post_type_object->cap->publish_posts : 'publish_posts';

			if ( ! current_user_can( $publish_cap ) ) {
				return new \WP_Error(
					'wmac_cannot_publish',
					__( 'You do not have permission to publish artifacts; omit status or use "draft".', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 403 )
				);
			}
		}

		if ( $title === '' ) {
			$title = sprintf(
				/* translators: %s: date and time */
				__( 'Artifact (%s)', 'webmultipliers-wp-artifact-canvas' ),
				wp_date( 'Y-m-d H:i' )
			);
		}

		$block_content = serialize_block(
			array(
				'blockName'    => 'wmac/artifact',
				'attrs'        => array(
					'html'       => $html,
					'fileStored' => false,
				),
				'innerBlocks'  => array(),
				'innerHTML'    => '',
				'innerContent' => array(),
			)
		);

		// wp_insert_post expects slashed data; the block-attribute JSON is
		// full of backslash escapes that wp_unslash would otherwise strip,
		// corrupting the serialized block.
		$post_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'    => PostType::KEY,
					'post_title'   => $title,
					'post_content' => $block_content,
					'post_status'  => $status,
				)
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return new \WP_Error(
				'wmac_insert_failed',
				$post_id->get_error_message(),
				array( 'status' => 500 )
			);
		}

		$post        = get_post( $post_id );
		$edit_url    = get_edit_post_link( $post_id, '' ) ?: admin_url( 'post.php?post=' . $post_id . '&action=edit' );
		$preview_url = get_preview_post_link( $post );

		return new \WP_REST_Response(
			array(
				'id'          => $post_id,
				'title'       => get_the_title( $post_id ),
				'status'      => $status,
				'edit_url'    => $edit_url,
				'preview_url' => $preview_url,
				'url'         => $status === 'publish' ? ( get_permalink( $post_id ) ?: '' ) : '',
			),
			201
		);
	}
}
