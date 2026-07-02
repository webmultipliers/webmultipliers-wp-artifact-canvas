<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Serves artifact posts at arbitrary custom URL paths.
 *
 * Editors store a relative path in `_wmac_alias` post meta (e.g. "pricing").
 * On every request we load the alias→post_id map from a transient, and if
 * the current path matches we hijack WP's request parsing before it runs,
 * pointing the query at the artifact post. The existing Renderer then
 * intercepts `template_redirect` and serves the raw HTML as normal.
 *
 * No rewrite-rule flush is required; the filter runs in-process each request.
 */
class ArtifactAlias {

	const META_KEY  = '_wmac_alias';
	const TRANSIENT = 'wmac_alias_map';

	/**
	 * First path segments that may never be aliased: shadowing them would
	 * intercept core endpoints or existing plugin routes.
	 */
	const RESERVED_SEGMENTS = array(
		'wp-admin',
		'wp-content',
		'wp-includes',
		'wp-json',
		'wp-login.php',
		'artifact',
		'feed',
		'embed',
		'sitemap.xml',
		'robots.txt',
		'favicon.ico',
	);

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'do_parse_request', array( $this, 'maybe_hijack_request' ), 1, 2 );
		add_action( 'added_post_meta', array( $this, 'bust_cache' ), 10, 3 );
		add_action( 'updated_post_meta', array( $this, 'bust_cache' ), 10, 3 );
		add_action( 'deleted_post_meta', array( $this, 'bust_cache' ), 10, 3 );
		add_action( 'transition_post_status', array( $this, 'on_status_change' ), 10, 3 );
	}

	public function register_meta(): void {
		register_post_meta(
			PostType::KEY,
			self::META_KEY,
			array(
				'type'              => 'string',
				'description'       => 'Custom URL alias for this artifact (relative path, e.g. "pricing").',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( self::class, 'sanitize_alias' ),
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
					return current_user_can( 'edit_post', $post_id );
				},
			)
		);
	}

	/**
	 * Normalises an alias value: lowercase, only a-z 0-9 - _ /, no
	 * leading/trailing slashes. Aliases whose first segment would shadow a
	 * core endpoint are rejected (stored as '').
	 *
	 * @param mixed $value Raw meta value.
	 */
	public static function sanitize_alias( $value ): string {
		$value = trim( (string) $value, '/ ' );
		$value = strtolower( $value );
		$value = preg_replace( '/[^a-z0-9\-_\/]/', '-', $value ) ?? '';
		$value = preg_replace( '/\/+/', '/', $value ) ?? '';
		$value = preg_replace( '/-+/', '-', $value ) ?? '';
		$value = trim( $value, '-/ ' );

		$first = explode( '/', $value )[0];
		if ( in_array( $first, self::RESERVED_SEGMENTS, true ) ) {
			return '';
		}

		return $value;
	}

	/**
	 * Intercepts `do_parse_request`. If the request path matches a stored alias
	 * for a published artifact, sets WP's query vars directly and returns false
	 * so WP skips its normal permalink parsing. The Renderer takes over from there.
	 */
	public function maybe_hijack_request( bool $do_parse, \WP $wp ): bool {
		if ( ! $do_parse ) {
			return false;
		}

		// Stored aliases are lowercased by sanitize_alias(); lowercase the
		// incoming path too so /Pricing and /PRICING resolve like /pricing.
		$path = strtolower( $this->get_request_path() );
		if ( $path === '' ) {
			return true;
		}

		$map = $this->get_alias_map();
		if ( ! isset( $map[ $path ] ) ) {
			return true;
		}

		$wp->query_vars = array(
			'p'         => $map[ $path ],
			'post_type' => PostType::KEY,
		);

		return false; // skip normal parsing; $wp->query() uses our vars
	}

	/**
	 * Returns the current request's path relative to the WP home URL,
	 * with leading and trailing slashes stripped.
	 * e.g. a request to https://example.com/pricing/ returns "pricing".
	 */
	private function get_request_path(): string {
		$request_uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) $_SERVER['REQUEST_URI'] : '';
		$path        = (string) ( wp_parse_url( $request_uri, PHP_URL_PATH ) ?: '' );

		// Strip any site subdirectory prefix (e.g. WP installed at /mysite/).
		$home_path = rtrim( (string) ( wp_parse_url( home_url(), PHP_URL_PATH ) ?: '' ), '/' );
		if ( $home_path !== '' && strncmp( $path, $home_path, strlen( $home_path ) ) === 0 ) {
			$path = (string) substr( $path, strlen( $home_path ) );
		}

		return trim( $path, '/' );
	}

	/**
	 * Returns the alias → post_id map for all published artifacts that have
	 * a non-empty `_wmac_alias` meta value. Cached in a transient.
	 *
	 * @return array<string, int>
	 */
	private function get_alias_map(): array {
		$cached = get_transient( self::TRANSIENT );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value
			   FROM {$wpdb->postmeta} pm
		 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
			  WHERE pm.meta_key   = %s
			    AND pm.meta_value != ''
			    AND p.post_type   = %s
			    AND p.post_status = 'publish'",
				self::META_KEY,
				PostType::KEY
			)
		);

		$map = array();
		foreach ( $rows as $row ) {
			$alias = (string) $row->meta_value;
			$id    = (int) $row->post_id;
			// First alias registered wins if duplicates exist.
			if ( $alias !== '' && $id > 0 && ! isset( $map[ $alias ] ) ) {
				$map[ $alias ] = $id;
			}
		}

		set_transient( self::TRANSIENT, $map, HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Bust the transient whenever the alias meta is created, updated, or deleted.
	 *
	 * @param int|int[] $meta_id  Meta ID (deleted_post_meta passes an array of IDs).
	 * @param int       $post_id  Post ID.
	 * @param string    $meta_key Meta key.
	 */
	public function bust_cache( $meta_id, int $post_id, string $meta_key ): void {
		if ( $meta_key === self::META_KEY && get_post_type( $post_id ) === PostType::KEY ) {
			delete_transient( self::TRANSIENT );
		}
	}

	/** Bust the transient when a post is published or unpublished. */
	public function on_status_change( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $post->post_type === PostType::KEY && $new_status !== $old_status ) {
			delete_transient( self::TRANSIENT );
		}
	}
}
