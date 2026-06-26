<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Manages server-side HTML file storage for artifacts.
 *
 * Files live at: {uploads}/wmac-artifacts/{post_id}.html
 * The directory is protected from direct HTTP access via .htaccess.
 *
 * Renderer::get_artifact_html() checks for a stored file first and falls back
 * to the block's html attribute, so both storage modes coexist gracefully.
 */
class ArtifactFile {

	public function register_hooks(): void {
		add_action( 'rest_api_init', [ $this, 'register_routes' ] );
		add_action( 'before_delete_post', [ $this, 'cleanup_on_delete' ] );
	}

	public function register_routes(): void {
		$args = [
			'id' => [
				'required'          => true,
				'validate_callback' => static function ( $v ): bool {
					return is_numeric( $v ) && (int) $v > 0;
				},
				'sanitize_callback' => 'absint',
			],
		];

		register_rest_route( 'wmac/v1', '/artifacts/(?P<id>[\d]+)/file', [
			[
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => [ $this, 'rest_get' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $args,
			],
			[
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'rest_upload' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $args,
			],
			[
				'methods'             => \WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'rest_delete' ],
				'permission_callback' => [ $this, 'check_permission' ],
				'args'                => $args,
			],
		] );
	}

	public function check_permission( \WP_REST_Request $request ): bool|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== PostType::KEY ) {
			return new \WP_Error(
				'wmac_not_found',
				__( 'Artifact not found.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new \WP_Error(
				'wmac_forbidden',
				__( 'You do not have permission to edit this artifact.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	public function rest_get( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id   = absint( $request->get_param( 'id' ) );
		$file_path = self::get_file_path( $post_id );

		if ( $file_path === null || ! is_readable( $file_path ) ) {
			return new \WP_Error(
				'wmac_no_file',
				__( 'No file is attached to this artifact.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 404 ]
			);
		}

		$content = file_get_contents( $file_path );
		if ( $content === false ) {
			return new \WP_Error(
				'wmac_read_failed',
				__( 'Could not read the stored file.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response( [ 'html' => $content ], 200 );
	}

	public function rest_upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$files   = $request->get_file_params();

		if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error(
				'wmac_no_file',
				__( 'No valid file provided.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 400 ]
			);
		}

		$file = $files['file'];

		// Extension check — we control the destination name, so this just validates intent.
		$ext = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, [ 'html', 'htm' ], true ) ) {
			return new \WP_Error(
				'wmac_invalid_type',
				__( 'Only .html and .htm files are accepted.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 400 ]
			);
		}

		$max_bytes = (int) apply_filters( 'wmac_max_file_size', 10 * 1024 * 1024 );
		if ( (int) $file['size'] > $max_bytes ) {
			return new \WP_Error(
				'wmac_file_too_large',
				__( 'File exceeds the maximum allowed size.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 413 ]
			);
		}

		$dir = $this->get_or_create_upload_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$dest = $dir . DIRECTORY_SEPARATOR . $post_id . '.html';

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new \WP_Error(
				'wmac_upload_failed',
				__( 'Could not save the uploaded file.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 500 ]
			);
		}

		return new \WP_REST_Response( [ 'stored' => true ], 200 );
	}

	public function rest_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id   = absint( $request->get_param( 'id' ) );
		$file_path = self::get_file_path( $post_id );

		if ( $file_path !== null && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		return new \WP_REST_Response( [ 'removed' => true ], 200 );
	}

	public function cleanup_on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== PostType::KEY ) {
			return;
		}

		$file_path = self::get_file_path( $post_id );
		if ( $file_path !== null && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}
	}

	/**
	 * Returns the expected file path for a given post ID.
	 * The file may or may not exist — callers should check is_readable().
	 */
	public static function get_file_path( int $post_id ): ?string {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return null;
		}

		return $upload_dir['basedir']
			. DIRECTORY_SEPARATOR . 'wmac-artifacts'
			. DIRECTORY_SEPARATOR . $post_id . '.html';
	}

	/**
	 * Returns the upload directory path, creating it (and its .htaccess) if needed.
	 *
	 * @return string|\WP_Error
	 */
	private function get_or_create_upload_dir(): string|\WP_Error {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return new \WP_Error(
				'wmac_upload_dir',
				$upload_dir['error'],
				[ 'status' => 500 ]
			);
		}

		$dir = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'wmac-artifacts';

		if ( is_dir( $dir ) ) {
			return $dir;
		}

		if ( ! wp_mkdir_p( $dir ) ) {
			return new \WP_Error(
				'wmac_mkdir',
				__( 'Could not create the upload directory.', 'webmultipliers-wp-artifact-canvas' ),
				[ 'status' => 500 ]
			);
		}

		// Block direct HTTP access on Apache. Nginx environments need a server-level rule.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . DIRECTORY_SEPARATOR . '.htaccess', "Deny from all\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . DIRECTORY_SEPARATOR . 'index.php', "<?php // Silence is golden.\n" );

		return $dir;
	}
}
