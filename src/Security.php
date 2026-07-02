<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Security {

	private bool $saving = false;

	/**
	 * When true, sanitize_on_save short-circuits. Trusted non-user pipelines
	 * (GitWebhook — authenticated by HMAC, not a WP user) set this around
	 * their wp_update_post calls; otherwise the anonymous request context
	 * would fail the unfiltered_html check and kses-mangle content that the
	 * pipeline is trusted to deploy verbatim.
	 */
	private static bool $suspended = false;

	public static function suspend(): void {
		self::$suspended = true;
	}

	public static function resume(): void {
		self::$suspended = false;
	}

	public function register_hooks(): void {
		add_action( 'save_post_' . PostType::KEY, array( $this, 'sanitize_on_save' ), 10, 2 );
	}

	public function sanitize_on_save( int $post_id, \WP_Post $post ): void {
		if ( $this->saving || self::$suspended ) {
			return;
		}

		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		$blocks      = parse_blocks( $post->post_content );
		$post_update = array( 'ID' => $post_id );
		$changed     = false;

		if ( trim( $post->post_title ) === '' ) {
			$artifact_html = $this->get_artifact_html_from_blocks( $blocks );
			$title         = $this->extract_title_from_html( $artifact_html );

			if ( $title !== '' ) {
				$post_update['post_title'] = $title;
				$changed                   = true;
			}
		}

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			$content_changed = false;

			foreach ( $blocks as &$block ) {
				if ( $block['blockName'] !== 'wmac/artifact' ) {
					continue;
				}
				$raw      = $block['attrs']['html'] ?? '';
				$filtered = self::kses_artifact_html( $raw );
				if ( $filtered !== $raw ) {
					$block['attrs']['html'] = $filtered;
					$content_changed        = true;
				}
			}
			unset( $block );

			if ( $content_changed ) {
				$post_update['post_content'] = serialize_blocks( $blocks );
				$changed                     = true;
			}
		}

		if ( ! $changed ) {
			return;
		}

		$this->saving = true;
		wp_update_post( $post_update );
		$this->saving = false;
	}

	/**
	 * KSES profile for full-page artifact documents.
	 *
	 * wp_kses_post targets body-copy fragments and strips the structural
	 * elements a standalone document needs (<html>, <head>, <body>, <meta>,
	 * <link>, <style>, <title>), leaving artifacts saved by authors without
	 * unfiltered_html unstyled and broken. This profile allowlists the
	 * document skeleton while KSES still removes <script>, on* event
	 * handlers, and unsafe URL protocols exactly as before.
	 *
	 * The doctype is carried around KSES separately — KSES treats it as an
	 * unknown tag and would strip it, forcing quirks mode.
	 */
	public static function kses_artifact_html( string $html ): string {
		$doctype = '';
		if ( preg_match( '/^\s*<!doctype[^>]*>/i', $html, $m ) === 1 ) {
			$doctype = $m[0];
			$html    = (string) substr( $html, strlen( $m[0] ) );
		}

		$allowed = array_merge(
			(array) wp_kses_allowed_html( 'post' ),
			array(
				'html'  => array(
					'lang'  => true,
					'dir'   => true,
					'class' => true,
					'id'    => true,
					'xmlns' => true,
				),
				'head'  => array(),
				'title' => array(),
				'body'  => array(
					'class' => true,
					'id'    => true,
					'style' => true,
					'dir'   => true,
				),
				'meta'  => array(
					'charset'    => true,
					'name'       => true,
					'content'    => true,
					'property'   => true,
					'http-equiv' => true,
				),
				'link'  => array(
					'rel'            => true,
					'href'           => true,
					'type'           => true,
					'media'          => true,
					'sizes'          => true,
					'as'             => true,
					'crossorigin'    => true,
					'integrity'      => true,
					'referrerpolicy' => true,
				),
				'style' => array(
					'media' => true,
				),
			)
		);

		/**
		 * Filters the KSES allowlist applied to artifact HTML saved or
		 * uploaded by authors without the unfiltered_html capability.
		 *
		 * @param array<string, array<string, bool>> $allowed KSES element/attribute allowlist.
		 */
		$allowed = (array) apply_filters( 'wmac_kses_allowed_html', $allowed );

		return $doctype . wp_kses( $html, $allowed );
	}

	private function get_artifact_html_from_blocks( array $blocks ): string {
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'wmac/artifact' ) {
				return $block['attrs']['html'] ?? '';
			}
		}

		return '';
	}

	private function extract_title_from_html( string $html ): string {
		if ( $html === '' ) {
			return '';
		}

		if ( ! preg_match( '/<title\\b[^>]*>(.*?)<\\/title>/is', $html, $matches ) ) {
			return '';
		}

		$decoded = html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, get_option( 'blog_charset' ) ?: 'UTF-8' );
		$title   = trim( wp_strip_all_tags( $decoded ) );

		return $title;
	}
}
