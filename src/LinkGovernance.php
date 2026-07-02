<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Granular link governance: expiry by date, view count, or click limit.
 *
 * Meta fields (all registered here):
 *   _wmac_expires_at  — ISO 8601 datetime string (UTC); empty = no date expiry
 *   _wmac_max_views   — int ≥ 1; 0 = unlimited
 *   _wmac_view_count  — running tally incremented on each public serve (via wmac_artifact_served)
 *
 * Renderer calls is_expired() before serving. If true it calls render_expired_view() and exits.
 * View count is incremented via the wmac_artifact_served action hook so it fires for both
 * HTML and PDF artifacts without duplicating logic in Renderer.
 */
class LinkGovernance {

	const EXPIRES_AT = '_wmac_expires_at';
	const MAX_VIEWS  = '_wmac_max_views';
	const VIEW_COUNT = '_wmac_view_count';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_action( 'wmac_artifact_served', array( $this, 'increment_view_count' ) );
	}

	public function register_meta(): void {
		$shared = array(
			'object_subtype' => PostType::KEY,
			'single'         => true,
			'show_in_rest'   => true,
		);

		register_post_meta(
			PostType::KEY,
			self::EXPIRES_AT,
			array_merge(
				$shared,
				array(
					'type'              => 'string',
					'description'       => 'Artifact expires at this UTC datetime (ISO 8601). Empty = no expiry.',
					'sanitize_callback' => static function ( $v ): string {
						$v = sanitize_text_field( (string) $v );
						if ( $v !== '' && strtotime( $v ) === false ) {
							return '';
						}
						return $v;
					},
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::MAX_VIEWS,
			array_merge(
				$shared,
				array(
					'type'              => 'integer',
					'description'       => 'Maximum public view count before the link expires. 0 = unlimited.',
					'sanitize_callback' => 'absint',
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::VIEW_COUNT,
			array_merge(
				$shared,
				array(
					'type'              => 'integer',
					'description'       => 'Running total of public serves. Read-only in the editor.',
					'sanitize_callback' => 'absint',
					'auth_callback'     => static function ( bool $allowed, string $meta_key, int $post_id ): bool {
						return current_user_can( 'edit_post', $post_id );
					},
				)
			)
		);
	}

	/**
	 * Returns true if the artifact should no longer be served publicly.
	 * Logged-in editors are never blocked.
	 */
	public function is_expired( \WP_Post $post ): bool {
		if ( is_user_logged_in() ) {
			return false;
		}

		$expires_at = get_post_meta( $post->ID, self::EXPIRES_AT, true );
		if ( $expires_at !== '' && $expires_at !== false ) {
			$ts = strtotime( (string) $expires_at );
			if ( $ts !== false && time() > $ts ) {
				return true;
			}
		}

		$max_views = (int) get_post_meta( $post->ID, self::MAX_VIEWS, true );
		if ( $max_views > 0 ) {
			$view_count = (int) get_post_meta( $post->ID, self::VIEW_COUNT, true );
			if ( $view_count >= $max_views ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Increments view count for non-preview, non-admin serves.
	 *
	 * The increment runs as a single atomic SQL UPDATE rather than a
	 * read-then-write through the meta API: concurrent visitors hitting the
	 * same artifact would otherwise read the same stale count and commit the
	 * same total, under-counting views and letting traffic slip past the
	 * max-view gate.
	 */
	public function increment_view_count( \WP_Post $post ): void {
		if ( is_preview() || is_user_logged_in() ) {
			return;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- atomic counter; the meta API cannot express this without a race.
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->postmeta} SET meta_value = meta_value + 1 WHERE post_id = %d AND meta_key = %s",
				$post->ID,
				self::VIEW_COUNT
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery

		if ( ! $updated ) {
			// No row yet — first public view. unique=true keeps a concurrent
			// first view from creating a second row.
			add_post_meta( $post->ID, self::VIEW_COUNT, 1, true );
		}

		// The direct UPDATE bypasses the meta cache; drop it so is_expired()
		// and the editor read the fresh count.
		wp_cache_delete( $post->ID, 'post_meta' );
	}

	/** Renders the "link expired" gate page (HTTP 410). */
	public function render_expired_view( \WP_Post $post ): void {
		$charset = esc_attr( get_option( 'blog_charset' ) ?: 'UTF-8' );
		$title   = esc_html( get_the_title( $post ) );
		$lang    = esc_attr( get_bloginfo( 'language' ) );

		status_header( 410 );
		header( "Content-Type: text/html; charset={$charset}" );
		header( 'Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex,nofollow' );

		// phpcs:disable WordPress.Security.EscapeOutput -- all variables in this template are pre-escaped above.
		echo <<<HTML
		<!doctype html>
		<html lang="{$lang}">
		<head>
		<meta charset="{$charset}">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="robots" content="noindex,nofollow">
		<title>{$title}</title>
		<style>
		*{box-sizing:border-box}body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f6f7f7}.card{background:#fff;padding:2.5rem 2rem;border-radius:10px;box-shadow:0 2px 16px rgba(0,0,0,.1);max-width:420px;width:100%;text-align:center}.icon{font-size:2.5rem;margin-bottom:1rem}h1{font-size:1.2rem;margin:0 0 .5rem;color:#111}p{color:#666;margin:0;font-size:.9rem}
		</style>
		</head>
		<body><div class="card">
		<div class="icon">&#128274;</div>
		<h1>{$title}</h1>
		<p>This preview link is no longer available.</p>
		</div></body></html>
		HTML;
		// phpcs:enable
	}
}
