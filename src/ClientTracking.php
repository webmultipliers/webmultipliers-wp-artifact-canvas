<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Injects per-artifact analytics / tracking snippets into the served HTML.
 *
 * Stores a raw snippet string in _wmac_tracking_snippet and injects it
 * before </head> via the wmac_rendered_html filter at priority 30
 * (after AssetMapper:10 and MergeTags:20, before SeoMeta:40).
 *
 * Saving the snippet requires unfiltered_html capability (admin/super-admin)
 * since arbitrary <script> tags are allowed.
 *
 * Compatible with Plausible (<script defer data-domain="…" src="…/plausible.js">),
 * Fathom (<script src="…/script.js" data-site="…" defer>), or any tag-based snippet.
 */
class ClientTracking {

	const META_KEY = '_wmac_tracking_snippet';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'wmac_rendered_html', array( $this, 'inject_snippet' ), 30, 2 );
	}

	public function register_meta(): void {
		register_post_meta(
			PostType::KEY,
			self::META_KEY,
			array(
				'type'              => 'string',
				'description'       => 'Analytics / tracking snippet injected into the artifact <head> before serving.',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => static function ( $v ): string {
					// Strip PHP open tags only — <script> tags are intentional here.
					return (string) preg_replace( '/<\?(?:php|=)?/i', '', (string) $v );
				},
				'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
					return current_user_can( 'unfiltered_html' );
				},
			)
		);
	}

	public function inject_snippet( string $html, \WP_Post $post ): string {
		$snippet = get_post_meta( $post->ID, self::META_KEY, true );
		if ( ! $snippet || trim( $snippet ) === '' ) {
			return $html;
		}

		$injection = "\n" . trim( $snippet ) . "\n";

		$count  = 0;
		$result = preg_replace_callback(
			'/<\/head>/i',
			static function ( array $m ) use ( $injection ): string {
				return $injection . $m[0];
			},
			$html,
			1,
			$count
		);

		if ( $count > 0 && is_string( $result ) ) {
			return $result;
		}

		return $html . $injection;
	}
}
