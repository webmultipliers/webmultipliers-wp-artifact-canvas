<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Replaces {{tag_name}} placeholders in rendered HTML at request time.
 *
 * Two modes per tag (configured via _wmac_tag_map post meta):
 *   static  — replaces with a sanitized string value stored in the map. An
 *             optional 'context' field selects the escaping applied at render
 *             time: 'text' (esc_html, default), 'attr' (esc_attr, for
 *             placeholders inside attribute values), or 'url' (esc_url, which
 *             also rejects unsafe protocols like javascript:).
 *   dynamic — fires the `wmac_resolve_tag_{tag_name}` filter; the hooked
 *             function receives ($default = '', $post_id) and is responsible
 *             for escaping its return value for the HTML context it targets.
 *
 * Escaping for client-side template syntax: write \{{tag}} to emit a literal
 * {{tag}} (backslash removed, no server-side substitution) so Vue/Alpine/
 * Mustache placeholders that collide with a configured tag name survive.
 *
 * Built-in tags (no map configuration required):
 *   {{wp_post_title}}         — artifact post title
 *   {{wp_post_id}}            — artifact post ID
 *   {{wp_post_url}}           — artifact permalink
 *   {{wp_post_date}}          — artifact published date (site format)
 *   {{wp_post_modified_date}} — artifact last-modified date (site format)
 *   {{wp_post_excerpt}}       — artifact excerpt
 *   {{wp_post_author}}        — artifact author display name
 *   {{wp_post_slug}}          — artifact post slug
 *   {{wp_site_name}}          — site name (bloginfo 'name')
 *   {{wp_site_url}}           — site home URL
 *   {{wp_site_tagline}}       — site tagline (bloginfo 'description')
 *   {{wp_current_year}}       — current four-digit year
 *   {{wp_current_date}}       — current date (site date format)
 *
 * Built-in resolvers fire via the same wmac_resolve_tag_* filter, so they
 * can be overridden at any priority by a developer-supplied hook.
 *
 * Hooks into wmac_rendered_html at priority 20 (after AssetMapper at 10).
 * The original HTML stored in the database is never modified.
 *
 * Developer API example:
 *
 *   add_filter( 'wmac_resolve_tag_current_user_email', function( $default, $post_id ) {
 *       return is_user_logged_in() ? esc_html( wp_get_current_user()->user_email ) : '';
 *   }, 10, 2 );
 */
class MergeTags {

	/** Tag names resolved automatically without any map configuration. */
	public const BUILT_IN_TAGS = array(
		'wp_post_title',
		'wp_post_id',
		'wp_post_url',
		'wp_post_date',
		'wp_post_modified_date',
		'wp_post_excerpt',
		'wp_post_author',
		'wp_post_slug',
		'wp_site_name',
		'wp_site_url',
		'wp_site_tagline',
		'wp_current_year',
		'wp_current_date',
	);

	public function register_hooks(): void {
		add_filter( 'wmac_rendered_html', array( $this, 'apply_tags' ), 20, 2 );
		$this->register_default_tags();
	}

	private function register_default_tags(): void {
		add_filter( 'wmac_resolve_tag_wp_post_title', array( $this, 'resolve_wp_post_title' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_id', array( $this, 'resolve_wp_post_id' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_url', array( $this, 'resolve_wp_post_url' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_date', array( $this, 'resolve_wp_post_date' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_modified_date', array( $this, 'resolve_wp_post_modified_date' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_excerpt', array( $this, 'resolve_wp_post_excerpt' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_author', array( $this, 'resolve_wp_post_author' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_post_slug', array( $this, 'resolve_wp_post_slug' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_site_name', array( $this, 'resolve_wp_site_name' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_site_url', array( $this, 'resolve_wp_site_url' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_site_tagline', array( $this, 'resolve_wp_site_tagline' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_current_year', array( $this, 'resolve_wp_current_year' ), 10, 2 );
		add_filter( 'wmac_resolve_tag_wp_current_date', array( $this, 'resolve_wp_current_date' ), 10, 2 );
	}

	public function apply_tags( string $html, \WP_Post $post ): string {
		$map_json = get_post_meta( $post->ID, ArtifactMeta::TAG_MAP, true );
		$map      = array();

		if ( $map_json && $map_json !== '{}' ) {
			$decoded = json_decode( $map_json, true );
			if ( is_array( $decoded ) ) {
				$map = $decoded;
			}
		}

		$result = preg_replace_callback(
			'/(\\\\?)\{\{([a-zA-Z0-9_]+)\}\}/',
			function ( array $matches ) use ( $map, $post ): string {
				// \{{tag}} escapes client-side template syntax: emit the
				// literal placeholder (backslash removed), never substitute.
				if ( $matches[1] !== '' ) {
					return '{{' . $matches[2] . '}}';
				}

				$tag    = $matches[2];
				$config = isset( $map[ $tag ] ) && is_array( $map[ $tag ] ) ? $map[ $tag ] : null;

				if ( $config !== null ) {
					$mode = isset( $config['mode'] ) && $config['mode'] === 'dynamic' ? 'dynamic' : 'static';

					if ( $mode === 'static' ) {
						$value   = (string) ( $config['value'] ?? '' );
						$context = (string) ( $config['context'] ?? 'text' );

						// Escape for the HTML context the author placed the
						// placeholder in; esc_url additionally drops unsafe
						// protocols (javascript: etc.).
						switch ( $context ) {
							case 'attr':
								return esc_attr( $value );
							case 'url':
								return esc_url( $value );
							default:
								return esc_html( $value );
						}
					}

					// Dynamic: developer resolves via filter and is responsible for escaping.
					return (string) apply_filters( 'wmac_resolve_tag_' . $tag, '', $post->ID );
				}

				// No map entry — try a built-in or developer-registered default resolver.
				if ( has_filter( 'wmac_resolve_tag_' . $tag ) ) {
					return (string) apply_filters( 'wmac_resolve_tag_' . $tag, '', $post->ID );
				}

				return $matches[0];
			},
			$html
		);

		return $result ?? $html;
	}

	// -------------------------------------------------------------------------
	// Built-in resolvers — all public so WP's hook system can call them.
	// -------------------------------------------------------------------------

	public function resolve_wp_post_title( string $default, int $post_id ): string {
		return esc_html( get_the_title( $post_id ) );
	}

	public function resolve_wp_post_id( string $default, int $post_id ): string {
		return (string) $post_id;
	}

	public function resolve_wp_post_url( string $default, int $post_id ): string {
		return esc_url( (string) get_permalink( $post_id ) );
	}

	public function resolve_wp_post_date( string $default, int $post_id ): string {
		return esc_html( (string) get_the_date( '', $post_id ) );
	}

	public function resolve_wp_post_modified_date( string $default, int $post_id ): string {
		return esc_html( (string) get_the_modified_date( '', $post_id ) );
	}

	public function resolve_wp_post_excerpt( string $default, int $post_id ): string {
		$post = get_post( $post_id );
		return $post ? esc_html( get_the_excerpt( $post ) ) : '';
	}

	public function resolve_wp_post_author( string $default, int $post_id ): string {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		return esc_html( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) );
	}

	public function resolve_wp_post_slug( string $default, int $post_id ): string {
		$post = get_post( $post_id );
		return $post ? esc_html( $post->post_name ) : '';
	}

	public function resolve_wp_site_name( string $default, int $post_id ): string {
		return esc_html( get_bloginfo( 'name' ) );
	}

	public function resolve_wp_site_url( string $default, int $post_id ): string {
		return esc_url( home_url() );
	}

	public function resolve_wp_site_tagline( string $default, int $post_id ): string {
		return esc_html( get_bloginfo( 'description' ) );
	}

	public function resolve_wp_current_year( string $default, int $post_id ): string {
		return esc_html( (string) current_time( 'Y' ) );
	}

	public function resolve_wp_current_date( string $default, int $post_id ): string {
		return esc_html( current_time( (string) get_option( 'date_format' ) ) );
	}
}
