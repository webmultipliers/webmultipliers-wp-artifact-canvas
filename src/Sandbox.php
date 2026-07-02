<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Sandbox origin isolation — serves published artifacts from a dedicated host
 * so artifact JavaScript cannot reach main-site auth cookies or authenticated
 * same-origin REST routes.
 *
 * Configuration: point a subdomain or separate domain at this WordPress
 * install (same DocumentRoot), then set the host in Artifacts → Settings
 * (option key sandbox_host) or via the wmac_sandbox_host filter. Empty =
 * disabled; artifacts serve same-origin exactly as before.
 *
 * Topology guidance:
 *   • Separate registrable domain (artifacts-example.com): full cookie
 *     isolation regardless of COOKIE_DOMAIN. Strongest option.
 *   • Subdomain (artifacts.example.com): isolated as long as COOKIE_DOMAIN
 *     is unset/host-only (the WP default). Do not use a wildcard
 *     COOKIE_DOMAIN (.example.com) with this topology.
 *
 * Defense in depth on the sandbox host (all requests):
 *   1. determine_current_user forced to 0 — authentication is ignored
 *      server-side even if a cookie does arrive.
 *   2. Non-artifact front-end requests 404.
 *   3. REST is allowlisted to the artifact PDF stream and oEmbed routes.
 *   4. wp-login.php allows only action=postpass (the post-password form
 *      posts there; the postpass cookie must be set for the sandbox host).
 *
 * On the main origin (when enabled):
 *   • Artifact permalinks are rewritten to the sandbox host, so all links,
 *     embeds, and oEmbed iframes point at the isolated origin.
 *   • A direct artifact request 301s to the sandbox URL (previews by
 *     authorized editors are exempt and serve main-origin).
 *   • The PDF byte stream refuses public main-origin requests.
 *
 * PageUsurpation intentionally bypasses this class — it serves at a main-site
 * Page URL by design and is separately restricted to unfiltered_html authors
 * with a strict CSP (see PageUsurpation).
 */
class Sandbox {

	public function register_hooks(): void {
		if ( self::host() === '' ) {
			return;
		}

		// URL generation.
		add_filter( 'post_type_link', array( $this, 'filter_permalink' ), 10, 2 );
		add_filter( 'preview_post_link', array( $this, 'filter_preview_link' ), 20 );
		add_filter( 'rest_url', array( $this, 'filter_rest_url' ), 10, 2 );

		// Main-origin artifact requests → sandbox. Hooked to 'wp' — the
		// earliest point where the main query is resolved — instead of
		// template_redirect priority 0, where security and redirect plugins
		// commonly register and could race ahead of the origin gate.
		add_action( 'wp', array( $this, 'redirect_main_origin_requests' ), 0 );

		// Sandbox-host lockdown.
		if ( self::is_sandbox_request() ) {
			add_filter( 'determine_current_user', '__return_zero', 100 );
			add_action( 'wp', array( $this, 'block_non_artifact_requests' ), 0 );
			add_action( 'login_init', array( $this, 'restrict_login' ), 0 );
			add_filter( 'rest_pre_dispatch', array( $this, 'restrict_rest_routes' ), 0, 3 );
			add_filter( 'the_password_form', array( $this, 'rewrite_password_form_action' ), 20 );
			add_action( 'wmac_artifact_served', array( $this, 'send_baseline_headers' ) );
		} else {
			add_filter( 'rest_pre_dispatch', array( $this, 'restrict_main_origin_pdf_stream' ), 0, 3 );
		}
	}

	// -------------------------------------------------------------------------
	// Host resolution
	// -------------------------------------------------------------------------

	/** The configured sandbox host ('' = feature disabled). Filterable. */
	public static function host(): string {
		$host = Settings::get()['sandbox_host'] ?? '';
		$host = (string) apply_filters( 'wmac_sandbox_host', $host );

		return self::normalize_host( $host );
	}

	/** True when the current request arrived on the sandbox host. */
	public static function is_sandbox_request(): bool {
		$sandbox = self::host();
		if ( $sandbox === '' ) {
			return false;
		}

		$request_host = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

		return self::normalize_host( $request_host ) === $sandbox;
	}

	/** Lowercased host[:port]; accepts full URLs and bare hosts. */
	public static function normalize_host( string $value ): string {
		$value = strtolower( trim( $value ) );
		if ( $value === '' ) {
			return '';
		}

		// Accept "https://host[:port]/path" input and reduce it to host[:port].
		$parsed = str_contains( $value, '//' ) ? wp_parse_url( $value ) : wp_parse_url( '//' . $value );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return '';
		}

		$host = $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$host .= ':' . $parsed['port'];
		}

		return $host;
	}

	/** Swaps the host of a URL for the sandbox host, preserving scheme + path. */
	public static function to_sandbox_url( string $url ): string {
		$sandbox = self::host();
		if ( $sandbox === '' ) {
			return $url;
		}

		$parsed = wp_parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return $url;
		}

		$original = $parsed['host'] . ( empty( $parsed['port'] ) ? '' : ':' . $parsed['port'] );

		return str_replace( '://' . $original, '://' . $sandbox, $url );
	}

	// -------------------------------------------------------------------------
	// URL generation
	// -------------------------------------------------------------------------

	/**
	 * Published artifact permalinks point at the sandbox origin so every link,
	 * embed, and share URL lands on the isolated host.
	 *
	 * @param string   $permalink The artifact permalink.
	 * @param \WP_Post $post      The artifact post.
	 */
	public function filter_permalink( string $permalink, \WP_Post $post ): string {
		if ( $post->post_type !== PostType::KEY || $post->post_status !== 'publish' ) {
			return $permalink;
		}

		return self::to_sandbox_url( $permalink );
	}

	/**
	 * Previews must stay on the main origin: editors are authenticated there,
	 * and the sandbox host ignores authentication entirely.
	 *
	 * @param string $link The preview URL.
	 */
	public function filter_preview_link( string $link ): string {
		$home    = self::normalize_host( home_url() );
		$sandbox = self::host();
		if ( $home === '' || $sandbox === '' ) {
			return $link;
		}

		// Structured host comparison instead of literal '://host' matching so
		// protocol-relative links and non-default ports translate too.
		$parsed = wp_parse_url( $link );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return $link; // Relative link — already main-origin.
		}

		$link_host = $parsed['host'] . ( empty( $parsed['port'] ) ? '' : ':' . $parsed['port'] );
		if ( self::normalize_host( $link_host ) !== $sandbox ) {
			return $link;
		}

		return str_replace( '//' . $link_host, '//' . $home, $link );
	}

	/**
	 * During a sandbox-host request, REST URLs for the artifact PDF stream and
	 * oEmbed discovery are rewritten to the sandbox host so the PDF.js shell
	 * fetches same-origin.
	 *
	 * @param string $url  The REST URL.
	 * @param string $path The requested REST path.
	 */
	public function filter_rest_url( string $url, string $path ): string {
		if ( ! self::is_sandbox_request() ) {
			return $url;
		}

		if ( ! self::is_allowed_sandbox_rest_path( $path ) ) {
			return $url;
		}

		return self::to_sandbox_url( $url );
	}

	// -------------------------------------------------------------------------
	// Main-origin gate
	// -------------------------------------------------------------------------

	/**
	 * A public artifact request on the main origin redirects (301) to the
	 * sandbox URL. Authorized editor previews are exempt.
	 */
	public function redirect_main_origin_requests(): void {
		if ( self::is_sandbox_request() || ! is_singular( PostType::KEY ) ) {
			return;
		}

		$post = get_queried_object();
		if ( ! $post instanceof \WP_Post ) {
			return;
		}

		// Editors previewing drafts or pending changes stay on the main origin.
		if ( is_preview() && current_user_can( 'edit_post', $post->ID ) ) {
			return;
		}

		if ( $post->post_status !== 'publish' ) {
			return; // Non-public statuses 404 downstream as usual.
		}

		$target = get_permalink( $post );
		if ( ! $target || self::normalize_host( $target ) !== self::host() ) {
			return;
		}

		remove_action( 'template_redirect', 'redirect_canonical' );
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * The PDF byte stream refuses public main-origin requests once the
	 * sandbox is active; editors with edit rights keep main-origin access
	 * for previews.
	 *
	 * @param mixed            $result  Dispatch short-circuit value.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public function restrict_main_origin_pdf_stream( $result, $server, $request ) {
		if ( $result !== null ) {
			return $result;
		}

		$route = $request->get_route();
		if ( preg_match( '#^/wmac/v1/artifacts/(\d+)/pdf$#', $route, $m ) !== 1 ) {
			return $result;
		}

		if ( current_user_can( 'edit_post', (int) $m[1] ) ) {
			return $result;
		}

		return new \WP_Error(
			'wmac_sandbox_only',
			__( 'This artifact is served from its sandbox origin.', 'webmultipliers-wp-artifact-canvas' ),
			array( 'status' => 404 )
		);
	}

	// -------------------------------------------------------------------------
	// Sandbox-host lockdown
	// -------------------------------------------------------------------------

	/** Front-end requests on the sandbox host that are not artifacts 404. */
	public function block_non_artifact_requests(): void {
		if ( is_singular( PostType::KEY ) ) {
			return;
		}

		global $wp_query;
		$wp_query->set_404();
		status_header( 404 );
		nocache_headers();

		// Minimal 404 body — never render the theme on the sandbox origin.
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo 'Not Found';
		exit;
	}

	/** wp-login.php on the sandbox host serves only the post-password action. */
	public function restrict_login(): void {
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( (string) wp_unslash( $_REQUEST['action'] ) ) : 'login'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $action === 'postpass' ) {
			return;
		}

		$target = wp_login_url();

		// If site_url resolves to the sandbox host (misconfiguration), the
		// redirect would land right back in this hook and loop the browser
		// to death — refuse the request instead.
		if ( self::normalize_host( $target ) === self::host() ) {
			status_header( 403 );
			nocache_headers();
			header( 'Content-Type: text/plain; charset=utf-8' );
			echo 'Login is disabled on the artifact sandbox origin.';
			exit;
		}

		wp_safe_redirect( $target, 302 );
		exit;
	}

	/**
	 * REST on the sandbox host is limited to the artifact PDF stream and
	 * oEmbed; every other route 404s.
	 *
	 * @param mixed            $result  Dispatch short-circuit value.
	 * @param \WP_REST_Server  $server  REST server.
	 * @param \WP_REST_Request $request Current request.
	 * @return mixed
	 */
	public function restrict_rest_routes( $result, $server, $request ) {
		if ( $result !== null ) {
			return $result;
		}

		if ( self::is_allowed_sandbox_rest_path( $request->get_route() ) ) {
			return $result;
		}

		return new \WP_Error(
			'wmac_sandbox_rest_disabled',
			__( 'REST API is not available on the artifact sandbox origin.', 'webmultipliers-wp-artifact-canvas' ),
			array( 'status' => 404 )
		);
	}

	/** Routes an artifact page legitimately needs on the sandbox origin. */
	private static function is_allowed_sandbox_rest_path( string $path ): bool {
		$path = '/' . ltrim( $path, '/' );

		return preg_match( '#^/wmac/v1/artifacts/\d+/pdf$#', $path ) === 1
			|| str_starts_with( $path, '/oembed/1.0/' );
	}

	/**
	 * The password form posts to wp-login.php?action=postpass on the main
	 * origin by default; rewrite it so the postpass cookie is set for the
	 * sandbox host the visitor is actually on.
	 *
	 * Rewrites the form's action attribute structurally (absolute,
	 * protocol-relative, and root-relative forms) rather than relying on a
	 * literal '://host/' substring, which silently misses relative actions
	 * introduced by other plugins and leaks the password POST to the main
	 * origin.
	 *
	 * @param string $form The password form markup.
	 */
	public function rewrite_password_form_action( string $form ): string {
		$sandbox = self::host();
		if ( $sandbox === '' ) {
			return $form;
		}

		$scheme = is_ssl() ? 'https' : 'http';

		return (string) preg_replace_callback(
			'#(<form\b[^>]*\baction\s*=\s*)(["\'])([^"\']*)\2#i',
			static function ( array $m ) use ( $sandbox, $scheme ): string {
				$action = html_entity_decode( $m[3], ENT_QUOTES );
				$parsed = wp_parse_url( $action );

				if ( is_array( $parsed ) && ! empty( $parsed['host'] ) ) {
					$original = $parsed['host'] . ( empty( $parsed['port'] ) ? '' : ':' . $parsed['port'] );
					$action   = str_replace( '//' . $original, '//' . $sandbox, $action );
				} elseif ( str_starts_with( $action, '/' ) ) {
					$action = $scheme . '://' . $sandbox . $action;
				} else {
					return $m[0]; // Empty or unrecognised action — leave untouched.
				}

				return $m[1] . $m[2] . esc_url( $action ) . $m[2];
			},
			$form
		);
	}

	/**
	 * Baseline isolation headers on every sandbox-origin artifact response.
	 * The per-artifact CSP override (wmac_csp) still applies on top.
	 *
	 * @param \WP_Post $post The artifact being served.
	 */
	public function send_baseline_headers( \WP_Post $post ): void {
		if ( headers_sent() ) {
			return;
		}

		// No X-Frame-Options here: artifacts are embeddable via oEmbed iframes
		// by design. Use the per-artifact CSP (frame-ancestors) to restrict.
		header( 'Referrer-Policy: no-referrer' );
	}
}
