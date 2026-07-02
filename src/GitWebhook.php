<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Webhook-triggered Git ingestion endpoint.
 *
 * REST route: POST /wmac/v1/git-webhook/{artifact_id}
 *
 * Accepts push/release webhooks from GitHub or GitLab. Validates the HMAC
 * signature, downloads the archive for the pushed ref, validates it via
 * PackageValidator, and stores the extracted index.html as the artifact file.
 *
 * Configuration:
 *   option wmac_git_webhook_secret  — HMAC secret shared with the provider
 *   filter wmac_git_webhook_token   — Bearer token for private-repo API calls
 *   filter wmac_git_webhook_allow_unsigned (bool, default false)
 *       — set to true to allow unauthenticated webhooks (dev only)
 *
 * Signature formats supported:
 *   GitHub  : X-Hub-Signature-256: sha256=<hex>
 *   GitLab  : X-Gitlab-Token: <plain token>
 *
 * Custom CI tools can skip Git and POST directly:
 *   { "wmac_archive_url": "https://example.com/build.zip" }
 *   with appropriate Authorization header or signed URL.
 *
 * SSRF protection: the server only fetches archives from allowlisted hosts.
 * Defaults: api.github.com, codeload.github.com, gitlab.com. Add hosts (e.g.
 * a self-hosted GitLab, or your CI's artifact store for wmac_archive_url)
 * via the wmac_git_archive_hosts filter. The wmac_archive_url branch is
 * DISABLED until you allowlist the host it points at.
 */
class GitWebhook {

	const OPTION_SECRET = 'wmac_git_webhook_secret';

	public function register_hooks(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'wmac/v1',
			'/git-webhook/(?P<id>[\d]+)',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => '__return_true', // Auth via HMAC — checked inside handle().
				'args'                => array(
					'id' => array(
						'required'          => true,
						'validate_callback' => static function ( $v ): bool {
							return is_numeric( $v ) && (int) $v > 0;
						},
						'sanitize_callback' => 'absint',
					),
				),
			)
		);
	}

	public function handle( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = absint( $request->get_param( 'id' ) );
		$post    = get_post( $post_id );

		if ( ! $post || $post->post_type !== PostType::KEY ) {
			return new \WP_Error(
				'wmac_not_found',
				__( 'Artifact not found.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 404 )
			);
		}

		$sig_result = $this->verify_signature( $request );
		if ( is_wp_error( $sig_result ) ) {
			return $sig_result;
		}

		$payload     = $request->get_json_params();
		$payload     = is_array( $payload ) ? $payload : array();
		$archive_url = $this->resolve_archive_url( $payload );

		if ( is_wp_error( $archive_url ) ) {
			return $archive_url;
		}

		$zip_path = $this->download_archive( $archive_url );
		if ( is_wp_error( $zip_path ) ) {
			return $zip_path;
		}

		$html = ( new PackageValidator() )->extract_entry_html( $zip_path );
		wp_delete_file( $zip_path );

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		$dest = ArtifactFile::get_write_path( $post_id );
		if ( is_wp_error( $dest ) ) {
			return $dest;
		}

		ArtifactFile::delete_files( $post_id );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		if ( file_put_contents( $dest, $html ) === false ) {
			return new \WP_Error(
				'wmac_write_failed',
				__( 'Could not write the artifact file to disk.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 500 )
			);
		}

		// Leave a breadcrumb in post_excerpt for the Revisions screen.
		$ref  = isset( $payload['ref'] ) ? sanitize_text_field( (string) $payload['ref'] ) : '';
		$sha  = isset( $payload['after'] ) ? substr( sanitize_text_field( (string) $payload['after'] ), 0, 8 ) : '';
		$note = trim( implode( ' @ ', array_filter( array( $ref, $sha ) ) ) );

		if ( $note !== '' ) {
			wp_update_post(
				array(
					'ID'           => $post_id,
					'post_excerpt' => sprintf(
					/* translators: %s: git ref @ short-SHA */
						__( 'Git deploy: %s', 'webmultipliers-wp-artifact-canvas' ),
						$note
					),
				)
			);
		}

		return new \WP_REST_Response(
			array(
				'deployed'    => true,
				'artifact_id' => $post_id,
				'ref'         => $ref,
				'sha'         => $sha,
			),
			200
		);
	}

	// -------------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------------

	private function verify_signature( \WP_REST_Request $request ): bool|\WP_Error {
		$secret = (string) get_option( self::OPTION_SECRET, '' );

		if ( $secret === '' ) {
			if ( ! (bool) apply_filters( 'wmac_git_webhook_allow_unsigned', false ) ) {
				return new \WP_Error(
					'wmac_webhook_unconfigured',
					__( 'Git webhook secret is not configured. Set the wmac_git_webhook_secret option or filter wmac_git_webhook_allow_unsigned to true (dev only).', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 403 )
				);
			}
			return true;
		}

		// GitHub: X-Hub-Signature-256: sha256=<hex>
		$github_sig = $request->get_header( 'x-hub-signature-256' );
		if ( $github_sig ) {
			$expected = 'sha256=' . hash_hmac( 'sha256', $request->get_body(), $secret );
			if ( ! hash_equals( $expected, $github_sig ) ) {
				return new \WP_Error(
					'wmac_webhook_invalid_sig',
					__( 'Invalid webhook signature.', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 401 )
				);
			}
			return true;
		}

		// GitLab: X-Gitlab-Token: <plain>
		$gitlab_token = $request->get_header( 'x-gitlab-token' );
		if ( $gitlab_token !== null ) {
			if ( ! hash_equals( $secret, $gitlab_token ) ) {
				return new \WP_Error(
					'wmac_webhook_invalid_sig',
					__( 'Invalid webhook token.', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 401 )
				);
			}
			return true;
		}

		return new \WP_Error(
			'wmac_webhook_missing_sig',
			__( 'No webhook signature or token header found.', 'webmultipliers-wp-artifact-canvas' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Hosts the server is willing to download archives from. Filterable so
	 * self-hosted GitLab instances or CI artifact stores can be added.
	 *
	 * @return array<int, string>
	 */
	private function allowed_archive_hosts(): array {
		$hosts = (array) apply_filters(
			'wmac_git_archive_hosts',
			array(
				'api.github.com',
				'codeload.github.com',
				'gitlab.com',
			)
		);

		return array_map( 'strtolower', array_filter( $hosts, 'is_string' ) );
	}

	/** Validates a candidate archive URL: https only, allowlisted host. */
	private function validate_archive_url( string $url ): string|\WP_Error {
		$parsed = wp_parse_url( $url );
		$host   = strtolower( (string) ( $parsed['host'] ?? '' ) );

		if ( ( $parsed['scheme'] ?? '' ) !== 'https' || $host === '' ) {
			return new \WP_Error(
				'wmac_archive_url_invalid',
				__( 'Archive URL must be a valid https URL.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 400 )
			);
		}

		if ( ! in_array( $host, $this->allowed_archive_hosts(), true ) ) {
			return new \WP_Error(
				'wmac_archive_host_not_allowed',
				sprintf(
					/* translators: %s: host name */
					__( 'Archive host "%s" is not allowlisted. Add it via the wmac_git_archive_hosts filter.', 'webmultipliers-wp-artifact-canvas' ),
					$host
				),
				array( 'status' => 400 )
			);
		}

		return $url;
	}

	private function resolve_archive_url( array $payload ): string|\WP_Error {
		// Custom CI: explicit archive URL in payload. Disabled unless its host
		// has been explicitly allowlisted (SSRF protection — this is a fully
		// attacker-controlled URL for anyone holding the webhook secret).
		if ( ! empty( $payload['wmac_archive_url'] ) ) {
			return $this->validate_archive_url( esc_url_raw( (string) $payload['wmac_archive_url'] ) );
		}

		// GitHub push/release: repository.full_name + ref → zipball API
		if ( ! empty( $payload['repository']['full_name'] ) && ! empty( $payload['ref'] ) ) {
			$repo = sanitize_text_field( (string) $payload['repository']['full_name'] );
			$ref  = sanitize_text_field( (string) preg_replace( '/^refs\/(?:heads|tags)\//', '', $payload['ref'] ) );

			if ( preg_match( '#^[\w.-]+/[\w.-]+$#', $repo ) !== 1 ) {
				return new \WP_Error(
					'wmac_webhook_bad_repo',
					__( 'Webhook payload contains an invalid repository name.', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 400 )
				);
			}

			return $this->validate_archive_url( "https://api.github.com/repos/{$repo}/zipball/" . rawurlencode( $ref ) );
		}

		// GitLab push: project.http_url_to_repo + ref → archive endpoint
		if ( ! empty( $payload['project']['http_url_to_repo'] ) && ! empty( $payload['ref'] ) ) {
			$repo = rtrim( sanitize_text_field( (string) $payload['project']['http_url_to_repo'] ), '/' );
			$ref  = sanitize_text_field( (string) preg_replace( '/^refs\/(?:heads|tags)\//', '', $payload['ref'] ) );

			return $this->validate_archive_url( "{$repo}/-/archive/" . rawurlencode( $ref ) . '/archive.zip' );
		}

		return new \WP_Error(
			'wmac_webhook_no_archive',
			__( 'Could not determine archive URL from webhook payload. Include wmac_archive_url or use a standard GitHub/GitLab push payload.', 'webmultipliers-wp-artifact-canvas' ),
			array( 'status' => 400 )
		);
	}

	private function download_archive( string $url ): string|\WP_Error {
		$tmp = wp_tempnam( 'wmac-git-' );
		if ( ! $tmp ) {
			return new \WP_Error(
				'wmac_tmp',
				__( 'Could not create a temporary file for the archive download.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 500 )
			);
		}

		$token   = (string) apply_filters( 'wmac_git_webhook_token', '' );
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'WP-Artifact-Canvas/' . WMAC_VERSION,
		);
		if ( $token !== '' ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'  => 30,
				'headers'  => $headers,
				'stream'   => true,
				'filename' => $tmp,
			)
		);

		if ( is_wp_error( $response ) ) {
			wp_delete_file( $tmp );
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			wp_delete_file( $tmp );
			return new \WP_Error(
				'wmac_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Archive download failed (HTTP %d).', 'webmultipliers-wp-artifact-canvas' ),
					$code
				),
				array( 'status' => 502 )
			);
		}

		return $tmp;
	}
}
