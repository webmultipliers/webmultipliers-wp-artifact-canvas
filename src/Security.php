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

	/**
	 * True while core's content_save_pre KSES pass is filtering content that
	 * contains an artifact block (see begin/end_artifact_kses_scope).
	 */
	private static bool $artifact_kses_scope = false;

	public function register_hooks(): void {
		add_action( 'save_post_' . PostType::KEY, array( $this, 'sanitize_on_save' ), 10, 2 );

		// Core runs wp_filter_post_kses on content_save_pre at priority 10 for
		// users without unfiltered_html, applying the restrictive post
		// allowlist to every block attribute — which strips the document
		// skeleton (<html>, <head>, <body>, <meta>, …) out of the artifact
		// html attribute before our tailored profile ever sees it. Bracket
		// that pass and extend the allowlist for artifact content only.
		add_filter( 'content_save_pre', array( $this, 'begin_artifact_kses_scope' ), 9 );
		add_filter( 'content_save_pre', array( $this, 'end_artifact_kses_scope' ), 11 );
		add_filter( 'wp_kses_allowed_html', array( $this, 'extend_kses_for_artifacts' ), 10, 2 );
		add_filter( 'pre_kses', array( $this, 'strip_scripts_pre_kses' ), 9, 2 );
	}

	/**
	 * Before core KSES processes artifact content on save, remove <script>
	 * elements *including their bodies* from the artifact html attribute.
	 * KSES alone strips only the tags, leaving the script source visible as
	 * page text in the sanitized document.
	 *
	 * @param mixed              $content      Content wp_kses is about to filter.
	 * @param array<mixed>|string $allowed_html KSES allowlist or context name.
	 * @return mixed
	 */
	public function strip_scripts_pre_kses( $content, $allowed_html ) {
		// 'post' (string) identifies the whole-content pass from
		// wp_filter_post_kses; recursive per-attribute passes get arrays.
		if ( ! self::$artifact_kses_scope || $allowed_html !== 'post' || ! is_string( $content ) ) {
			return $content;
		}

		$blocks  = parse_blocks( $content );
		$changed = false;

		foreach ( $blocks as &$block ) {
			if ( $block['blockName'] !== 'wmac/artifact' ) {
				continue;
			}

			$raw = (string) ( $block['attrs']['html'] ?? '' );
			if ( $raw === '' ) {
				continue;
			}

			$stripped = self::strip_script_elements( $raw );
			if ( $stripped !== $raw ) {
				$block['attrs']['html'] = $stripped;
				$changed                = true;
			}
		}
		unset( $block );

		return $changed ? serialize_blocks( $blocks ) : $content;
	}

	/** Removes <script> elements and their contents. */
	private static function strip_script_elements( string $html ): string {
		$result = preg_replace( '#<script\b[^>]*>.*?</script\s*>#is', '', $html );

		return is_string( $result ) ? $result : $html;
	}

	/**
	 * Marks the start of core's KSES pass over content containing an artifact
	 * block. content_save_pre receives slashed data.
	 *
	 * @param mixed $content Slashed post content.
	 * @return mixed
	 */
	public function begin_artifact_kses_scope( $content ) {
		if ( is_string( $content ) && has_block( 'wmac/artifact', wp_unslash( $content ) ) ) {
			self::$artifact_kses_scope = true;
		}

		return $content;
	}

	/**
	 * Ends the artifact KSES scope opened at priority 9.
	 *
	 * @param mixed $content Slashed post content.
	 * @return mixed
	 */
	public function end_artifact_kses_scope( $content ) {
		self::$artifact_kses_scope = false;

		return $content;
	}

	/**
	 * While core KSES is filtering artifact content, allow the standalone
	 * document skeleton in the 'post' context. Scripts, on* handlers, and
	 * unsafe protocols are still removed — same bar as kses_artifact_html.
	 *
	 * @param mixed  $tags    Allowed elements/attributes.
	 * @param string $context KSES context name.
	 * @return mixed
	 */
	public function extend_kses_for_artifacts( $tags, $context ) {
		if ( ! self::$artifact_kses_scope || $context !== 'post' || ! is_array( $tags ) ) {
			return $tags;
		}

		return array_merge( $tags, self::artifact_document_tags() );
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
		// wp_update_post expects slashed data; serialize_blocks output contains
		// backslash escapes (<, ") that wp_unslash would strip,
		// corrupting the block JSON and blanking the artifact.
		wp_update_post( wp_slash( $post_update ) );
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

		// Script bodies must go with their tags — KSES alone strips only the
		// tags and would leave the source code as visible page text.
		$html = self::strip_script_elements( $html );

		$allowed = array_merge(
			(array) wp_kses_allowed_html( 'post' ),
			self::artifact_document_tags()
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

	/**
	 * The standalone-document elements a full-page artifact needs on top of
	 * the body-copy 'post' allowlist.
	 *
	 * @return array<string, array<string, bool>>
	 */
	private static function artifact_document_tags(): array {
		return array(
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
		);
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
