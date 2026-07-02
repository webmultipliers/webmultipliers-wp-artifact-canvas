<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Server-processed preview for the block editor.
 *
 * The block's in-editor preview iframe used to render the raw html attribute,
 * which skips everything the front end does on serve — asset mapping, code
 * injection (external styles/scripts, head/body HTML), merge tags, and
 * tracking snippets. This endpoint runs the submitted HTML through the same
 * wmac_rendered_html filter chain the Renderer applies, so the editor preview
 * matches what visitors will actually receive.
 *
 * POST /wmac/v1/artifacts/{id}/preview
 *   html (string, optional) — unsaved editor HTML; falls back to the
 *   artifact's stored content when omitted.
 *
 * The response is rendered in a sandboxed iframe (allow-scripts only, no
 * same-origin), so injected scripts execute in isolation from wp-admin.
 * Editors without unfiltered_html get the same KSES profile applied that a
 * save would apply, so the preview never shows markup they cannot persist.
 */
class EditorPreview {

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'wmac/v1',
			'/artifacts/(?P<id>[\d]+)/preview',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_preview' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'id'   => array(
						'required'          => true,
						'validate_callback' => static function ( $v ): bool {
							return is_numeric( $v ) && (int) $v > 0;
						},
						'sanitize_callback' => 'absint',
					),
					'html' => array(
						'required' => false,
						'type'     => 'string',
					),
				),
			)
		);
	}

	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== PostType::KEY ) {
			return new \WP_Error(
				'wmac_not_found',
				__( 'Artifact not found.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 404 )
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'wmac_forbidden',
				__( 'You do not have permission to preview this artifact.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	public function rest_preview( \WP_REST_Request $request ): \WP_REST_Response {
		$post = get_post( absint( $request->get_param( 'id' ) ) );

		$html = (string) $request->get_param( 'html' );
		if ( $html === '' ) {
			$html = Renderer::get_artifact_html( $post );
		}

		// Same trust bar as saving: markup this user could not persist is not
		// shown in their preview either.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$html = Security::kses_artifact_html( $html );
		}

		/** This filter is documented in src/Renderer.php */
		$html = (string) apply_filters( 'wmac_rendered_html', $html, $post );

		return new \WP_REST_Response( array( 'html' => $html ), 200 );
	}
}
