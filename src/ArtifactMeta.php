<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Registers per-artifact post meta fields and exposes them in the REST API
 * so the block editor's InspectorControls panel can read and write them.
 *
 * Meta keys:
 *   _wmac_prompt      — generation prompt / notes (private, plain text)
 *   _wmac_description — human description; feeds og:description / meta description
 *   _wmac_noindex     — override noindex: '' = use global, '1' = force on, '0' = force off
 *   _wmac_seo_enabled — override SEO meta injection: '' = use global, '1' = on, '0' = off
 *   _wmac_csp         — per-artifact CSP value (overrides global wmac_csp filter)
 */
class ArtifactMeta {

	public const PROMPT      = '_wmac_prompt';
	public const DESCRIPTION = '_wmac_description';
	public const NOINDEX     = '_wmac_noindex';
	public const SEO_ENABLED = '_wmac_seo_enabled';
	public const CSP         = '_wmac_csp';
	public const ASSET_MAP   = '_wmac_asset_map';
	public const TAG_MAP     = '_wmac_tag_map';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'wmac_noindex', array( $this, 'filter_noindex' ), 10, 2 );
		add_filter( 'wmac_seo_enabled', array( $this, 'filter_seo_enabled' ), 10, 2 );
		add_filter( 'wmac_csp', array( $this, 'filter_csp' ), 10, 2 );
	}

	public function register_meta(): void {
		$shared = array(
			'object_subtype' => PostType::KEY,
			'single'         => true,
			'show_in_rest'   => true,
		);

		register_post_meta(
			PostType::KEY,
			self::PROMPT,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'Generation prompt / notes for this artifact.',
					'sanitize_callback' => static function ( $v ): string {
						// Cap the stored length — unbounded prompt dumps bloat postmeta.
						$max = (int) apply_filters( 'wmac_max_prompt_length', 20000 );
						return mb_substr( sanitize_textarea_field( (string) $v ), 0, $max );
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::DESCRIPTION,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'Human-readable description of the artifact; used for og:description and the meta description tag.',
					'sanitize_callback' => static function ( $v ): string {
						return mb_substr( sanitize_textarea_field( (string) $v ), 0, 5000 );
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::NOINDEX,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'noindex override: empty = global default, "1" = on, "0" = off.',
					'sanitize_callback' => static function ( $v ): string {
						return in_array( (string) $v, array( '', '0', '1' ), true ) ? (string) $v : '';
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::SEO_ENABLED,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'SEO meta override: empty = global default, "1" = on, "0" = off.',
					'sanitize_callback' => static function ( $v ): string {
						return in_array( (string) $v, array( '', '0', '1' ), true ) ? (string) $v : '';
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::CSP,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'Per-artifact Content-Security-Policy header value.',
					'sanitize_callback' => array( self::class, 'sanitize_csp' ),
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::ASSET_MAP,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'Asset map: JSON object mapping relative paths to Media Library URLs.',
					'sanitize_callback' => static function ( $v ): string {
						$decoded = json_decode( (string) $v, true );
						if ( ! is_array( $decoded ) ) {
							return '{}';
						}
						$clean = array();
						foreach ( $decoded as $path => $url ) {
							if ( is_string( $path ) && is_string( $url ) ) {
								$clean[ sanitize_text_field( $path ) ] = esc_url_raw( $url );
							}
						}
						return wp_json_encode( $clean ) ?: '{}';
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::TAG_MAP,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'Tag map: JSON object mapping {{tag}} names to their replacement configurations.',
					'sanitize_callback' => static function ( $v ): string {
						$decoded = json_decode( (string) $v, true );
						if ( ! is_array( $decoded ) ) {
							return '{}';
						}
						$clean = array();
						foreach ( $decoded as $tag => $config ) {
							if ( ! is_string( $tag ) || ! is_array( $config ) ) {
								continue;
							}
							$safe_tag = preg_replace( '/[^a-zA-Z0-9_]/', '', $tag );
							if ( $safe_tag === '' || $safe_tag === null ) {
								continue;
							}
							$mode  = isset( $config['mode'] ) && $config['mode'] === 'dynamic' ? 'dynamic' : 'static';
							$entry = array( 'mode' => $mode );
							if ( $mode === 'static' ) {
								$entry['value'] = sanitize_text_field( (string) ( $config['value'] ?? '' ) );

								$context          = (string) ( $config['context'] ?? 'text' );
								$entry['context'] = in_array( $context, array( 'text', 'attr', 'url' ), true ) ? $context : 'text';
							}
							$clean[ $safe_tag ] = $entry;
						}
						return wp_json_encode( $clean ) ?: '{}';
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);
	}

	/**
	 * Sanitizes a Content-Security-Policy value without corrupting it.
	 * sanitize_text_field strips angle brackets and percent-encoded octets,
	 * which can mangle hash/nonce source expressions and report URIs; a CSP
	 * header only needs printable ASCII with header-splitting characters
	 * removed.
	 *
	 * @param mixed $value Raw CSP input.
	 */
	public static function sanitize_csp( $value ): string {
		$value = preg_replace( '/[^\x20-\x7E]/', '', (string) $value ) ?? '';

		return trim( preg_replace( '/\s+/', ' ', $value ) ?? '' );
	}

	public function filter_noindex( bool $noindex, \WP_Post $post ): bool {
		$value = get_post_meta( $post->ID, self::NOINDEX, true );
		if ( $value === '1' ) {
			return true;
		}
		if ( $value === '0' ) {
			return false;
		}
		return $noindex;
	}

	public function filter_seo_enabled( bool $enabled, \WP_Post $post ): bool {
		$value = get_post_meta( $post->ID, self::SEO_ENABLED, true );
		if ( $value === '1' ) {
			return true;
		}
		if ( $value === '0' ) {
			return false;
		}
		return $enabled;
	}

	public function filter_csp( string $csp, \WP_Post $post ): string {
		$value = get_post_meta( $post->ID, self::CSP, true );
		if ( $value !== '' && $value !== false ) {
			return (string) $value;
		}
		return $csp;
	}
}
