<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Manages server-side HTML file storage for artifacts.
 *
 * Files live at: {uploads}/wmac-artifacts/{post_id}-{token}.{ext} where the
 * token is an HMAC of the post ID keyed with wp_salt(), so file URLs are not
 * guessable even if the webserver fails to block the directory. Files written
 * by pre-1.0 versions at the legacy {post_id}.{ext} path are still readable.
 *
 * Direct HTTP access is blocked via .htaccess on Apache; Nginx needs a
 * server-level rule (see README). An admin health check probes the directory
 * over HTTP and warns when it is reachable.
 *
 * Renderer::get_artifact_html() checks for a stored file first and falls back
 * to the block's html attribute, so both storage modes coexist gracefully.
 */
class ArtifactFile {

	private const PROBE_TRANSIENT = 'wmac_file_protection_probe';

	/**
	 * Post meta persisting the filename token, so filenames written after
	 * this version survive an auth-salt rotation (see file_token()).
	 */
	public const TOKEN_META = '_wmac_file_token';

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'before_delete_post', array( $this, 'cleanup_on_delete' ) );
		add_action( 'admin_notices', array( $this, 'maybe_show_protection_notice' ) );
	}

	public function register_routes(): void {
		$args = array(
			'id' => array(
				'required'          => true,
				'validate_callback' => static function ( $v ): bool {
					return is_numeric( $v ) && (int) $v > 0;
				},
				'sanitize_callback' => 'absint',
			),
		);

		register_rest_route(
			'wmac/v1',
			'/artifacts/(?P<id>[\d]+)/file',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'rest_get' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $args,
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rest_upload' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $args,
				),
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'rest_delete' ),
					'permission_callback' => array( $this, 'check_permission' ),
					'args'                => $args,
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
				__( 'You do not have permission to edit this artifact.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 403 )
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
				array( 'status' => 404 )
			);
		}

		$content = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local artifact file, not remote.
		if ( $content === false ) {
			return new \WP_Error(
				'wmac_read_failed',
				__( 'Could not read the stored file.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 500 )
			);
		}

		return new \WP_REST_Response( array( 'html' => $content ), 200 );
	}

	public function rest_upload( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$files   = $request->get_file_params();

		if ( empty( $files['file'] ) || $files['file']['error'] !== UPLOAD_ERR_OK ) {
			return new \WP_Error(
				'wmac_no_file',
				__( 'No valid file provided.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 400 )
			);
		}

		$file = $files['file'];

		// Extension check — we control the destination name, so this just validates intent.
		$ext = strtolower( (string) pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'html', 'htm' ), true ) ) {
			return new \WP_Error(
				'wmac_invalid_type',
				__( 'Only .html and .htm files are accepted.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 400 )
			);
		}

		$max_bytes = (int) apply_filters( 'wmac_max_file_size', 10 * 1024 * 1024 );
		if ( (int) $file['size'] > $max_bytes ) {
			return new \WP_Error(
				'wmac_file_too_large',
				__( 'File exceeds the maximum allowed size.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 413 )
			);
		}

		// Uploads from authors without unfiltered_html must pass through KSES
		// in a single memory buffer; running it over multi-megabyte documents
		// risks exhausting the PHP memory limit or the request worker. Cap
		// those uploads lower instead of attempting the transform.
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$kses_max = (int) apply_filters( 'wmac_max_kses_file_size', 2 * 1024 * 1024 );
			if ( (int) $file['size'] > $kses_max ) {
				return new \WP_Error(
					'wmac_file_too_large_to_sanitize',
					__( 'Files this large can only be attached by users with the unfiltered_html capability; uploads from other users must pass through HTML sanitization.', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 413 )
				);
			}
		}

		$dest = self::get_write_path( $post_id );
		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		// A re-upload replaces the file; clear any legacy-named copy so the
		// old content can never be served again. An HTML upload also retires
		// a previous PDF profile — delete the orphaned binary and its format
		// flag so the artifact transitions cleanly back to passthrough HTML.
		self::delete_files( $post_id );
		self::delete_files( $post_id, 'pdf' );
		delete_post_meta( $post_id, PdfRenderer::FORMAT_META );

		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new \WP_Error(
				'wmac_upload_failed',
				__( 'Could not save the uploaded file.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 500 )
			);
		}

		// Uploaded files are served verbatim, so they must clear the same trust
		// bar as block content: authors without unfiltered_html get wp_kses_post
		// applied, mirroring Security::sanitize_on_save. This keys off the
		// uploading user (not the post author), so a delegated author cannot use
		// the file path — or a usurped page owned by an admin — to smuggle raw
		// <script> past the kses gate.
		$sanitized = false;
		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$this->sanitize_stored_file( $dest );
			$sanitized = true;
		}

		self::signal_content_updated( $post_id );

		return new \WP_REST_Response(
			array(
				'stored'    => true,
				'sanitized' => $sanitized,
			),
			200
		);
	}

	/**
	 * Runs the artifact KSES profile in place on a stored HTML file — the
	 * file-path equivalent of Security::sanitize_on_save for the
	 * block-content path.
	 */
	private function sanitize_stored_file( string $path ): void {
		$raw = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local stored file, not remote.
		if ( $raw === false ) {
			return;
		}

		$clean = Security::kses_artifact_html( $raw );
		if ( $clean !== $raw ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $path, $clean, LOCK_EX );
		}
	}

	public function rest_delete( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );

		self::delete_files( $post_id );

		self::signal_content_updated( $post_id );

		return new \WP_REST_Response( array( 'removed' => true ), 200 );
	}

	/**
	 * Signals caching layers that an artifact's served output changed via a
	 * file operation that never touches the post record. Content saved
	 * through the editor already fires the core save_post / clean_post_cache
	 * events that cache and CDN plugins listen to; file attach/replace/remove
	 * paths need an equivalent. Hook CDN or edge purges here.
	 */
	public static function signal_content_updated( int $post_id ): void {
		clean_post_cache( $post_id );

		/**
		 * Fires after a file-level change to an artifact's served content.
		 *
		 * @param int $post_id Artifact post ID.
		 */
		do_action( 'wmac_artifact_content_updated', $post_id );
	}

	public function cleanup_on_delete( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_type !== PostType::KEY ) {
			return;
		}

		self::delete_files( $post_id, 'html' );
		self::delete_files( $post_id, 'pdf' );
	}

	/**
	 * Unguessable per-post filename token. Files written after this version
	 * persist the token in post meta (see get_write_path), so a rotation of
	 * the site's auth salt no longer orphans every stored file. Posts
	 * without a persisted token fall back to the historical salt-derived
	 * value, which still matches their on-disk names while the salt is
	 * unchanged.
	 */
	public static function file_token( int $post_id ): string {
		if ( $post_id > 0 ) {
			$stored = get_post_meta( $post_id, self::TOKEN_META, true );
			if ( is_string( $stored ) && preg_match( '/^[a-f0-9]{16}$/', $stored ) === 1 ) {
				return $stored;
			}
		}

		return self::salt_token( $post_id );
	}

	/** The pre-persistence token derivation, keyed with the auth salt. */
	private static function salt_token( int $post_id ): string {
		return substr( hash_hmac( 'sha256', 'wmac-artifact-' . $post_id, wp_salt( 'auth' ) ), 0, 16 );
	}

	/**
	 * Filenames that may hold this post's file, canonical name first.
	 *
	 * @return string[]
	 */
	private static function candidate_names( int $post_id, string $ext ): array {
		return array_values(
			array_unique(
				array(
					$post_id . '-' . self::file_token( $post_id ) . '.' . $ext, // Persisted token (or salt fallback).
					$post_id . '-' . self::salt_token( $post_id ) . '.' . $ext, // Pre-persistence salt-derived name.
					$post_id . '.' . $ext,                                      // Pre-1.0 legacy name.
				)
			)
		);
	}

	/**
	 * Returns the read path for a post's stored file, preferring the
	 * randomized name and falling back to the salt-derived and legacy
	 * {id}.{ext} names written by earlier versions. Null when the upload dir
	 * is unavailable; the file may not exist — callers should check
	 * is_readable().
	 */
	public static function get_file_path( int $post_id, string $ext = 'html' ): ?string {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return null;
		}

		$base = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'wmac-artifacts' . DIRECTORY_SEPARATOR;

		$candidates = self::candidate_names( $post_id, $ext );
		foreach ( $candidates as $name ) {
			if ( file_exists( $base . $name ) ) {
				return $base . $name;
			}
		}

		return $base . $candidates[0];
	}

	/**
	 * Destination path for new writes — always the randomized name. The
	 * token is persisted to post meta here (write paths run in authorized
	 * contexts) so the filename stays resolvable across salt rotations.
	 */
	public static function get_write_path( int $post_id, string $ext = 'html' ): string|\WP_Error {
		$dir = self::get_or_create_upload_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}

		$token = self::file_token( $post_id );
		if ( $post_id > 0 && get_post_meta( $post_id, self::TOKEN_META, true ) !== $token ) {
			update_post_meta( $post_id, self::TOKEN_META, $token );
		}

		return $dir . DIRECTORY_SEPARATOR . $post_id . '-' . $token . '.' . $ext;
	}

	/** Deletes the randomized (persisted and salt-derived) and legacy files for a post. */
	public static function delete_files( int $post_id, string $ext = 'html' ): void {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return;
		}

		$base = $upload_dir['basedir'] . DIRECTORY_SEPARATOR . 'wmac-artifacts' . DIRECTORY_SEPARATOR;

		foreach ( self::candidate_names( $post_id, $ext ) as $name ) {
			if ( file_exists( $base . $name ) ) {
				wp_delete_file( $base . $name );
			}
		}
	}

	/**
	 * Returns the upload directory path, creating it (and its .htaccess) if needed.
	 *
	 * @return string|\WP_Error
	 */
	public static function get_or_create_upload_dir(): string|\WP_Error {
		$upload_dir = wp_upload_dir();
		if ( $upload_dir['error'] ) {
			return new \WP_Error(
				'wmac_upload_dir',
				$upload_dir['error'],
				array( 'status' => 500 )
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
				array( 'status' => 500 )
			);
		}

		// Block direct HTTP access on Apache (2.4 and 2.2 syntax). Nginx
		// environments need a server-level rule; the admin health check
		// (maybe_show_protection_notice) detects when the directory is
		// reachable and shows the rule to add.
		$htaccess = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n"
			. "<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . DIRECTORY_SEPARATOR . '.htaccess', $htaccess );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		file_put_contents( $dir . DIRECTORY_SEPARATOR . 'index.php', "<?php // Silence is golden.\n" );

		return $dir;
	}

	// -------------------------------------------------------------------------
	// Protection health check
	// -------------------------------------------------------------------------

	/**
	 * Probes whether the storage directory is reachable over HTTP and shows
	 * an admin error with the Nginx rule when it is. Runs at most once per
	 * 12 hours, only for admins on artifact screens.
	 */
	public function maybe_show_protection_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || strpos( (string) $screen->id, PostType::KEY ) === false ) {
			return;
		}

		if ( ! $this->storage_dir_is_reachable() ) {
			return;
		}

		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p><pre>location ^~ %s { deny all; }</pre></div>',
			esc_html__( 'Artifact files are publicly reachable.', 'webmultipliers-wp-artifact-canvas' ),
			esc_html__( 'Your webserver is not blocking direct access to the artifact storage directory (the bundled .htaccess only covers Apache). Add this rule to your Nginx server block and reload:', 'webmultipliers-wp-artifact-canvas' ),
			esc_html( (string) wp_parse_url( self::probe_base_url(), PHP_URL_PATH ) )
		);
	}

	/** URL of the storage directory (for probing and the notice). */
	private static function probe_base_url(): string {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['baseurl'] ) . 'wmac-artifacts/';
	}

	/** True when a probe file in the storage directory can be fetched over HTTP. */
	private function storage_dir_is_reachable(): bool {
		$cached = get_transient( self::PROBE_TRANSIENT );
		if ( $cached !== false ) {
			return $cached === 'reachable';
		}

		$result = 'blocked';

		$dir = self::get_or_create_upload_dir();
		if ( ! is_wp_error( $dir ) ) {
			$marker = 'wmac-probe-' . self::file_token( 0 );
			$probe  = $dir . DIRECTORY_SEPARATOR . $marker . '.html';
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $probe, $marker );

			$response = wp_remote_get(
				self::probe_base_url() . $marker . '.html',
				array(
					'timeout'   => 5,
					'sslverify' => false, // Loopback request to this very host.
				)
			);

			wp_delete_file( $probe );

			if ( ! is_wp_error( $response )
				&& wp_remote_retrieve_response_code( $response ) === 200
				&& strpos( wp_remote_retrieve_body( $response ), $marker ) !== false
			) {
				$result = 'reachable';
			}
		}

		set_transient( self::PROBE_TRANSIENT, $result, 12 * HOUR_IN_SECONDS );

		return $result === 'reachable';
	}
}
