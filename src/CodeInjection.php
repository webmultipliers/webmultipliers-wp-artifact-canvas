<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Per-artifact custom code and external assets — the CodePen-style
 * "stuff for <head> / before </body>" and external resource lists.
 *
 * Meta fields (all registered here):
 *   _wmac_external_styles  — URLs, one per line; each becomes a
 *                            <link rel="stylesheet"> in the listed order.
 *   _wmac_external_scripts — URLs, one per line; each becomes a
 *                            <script src> before </body> in the listed order.
 *   _wmac_head_html        — raw markup injected before </head>.
 *   _wmac_body_html        — raw markup appended before </body>.
 *
 * Injection order:
 *   head: stylesheet <link>s first, then the custom head HTML — so inline
 *         <style> in the head HTML can override the external sheets.
 *   body: external <script>s first, then the custom body HTML — so inline
 *         init code can rely on the libraries loaded above it.
 *
 * Runs on wmac_rendered_html at priority 15: after AssetMapper (10) and
 * before MergeTags (20), so {{tags}} inside injected code still resolve.
 *
 * Trust model: head/body HTML and script URLs execute in the artifact page,
 * so saving them requires unfiltered_html — the same bar as the artifact's
 * own unfiltered markup and the tracking snippet. Stylesheet URLs cannot
 * execute script and only require edit_post.
 */
class CodeInjection {

	const EXTERNAL_STYLES  = '_wmac_external_styles';
	const EXTERNAL_SCRIPTS = '_wmac_external_scripts';
	const HEAD_HTML        = '_wmac_head_html';
	const BODY_HTML        = '_wmac_body_html';

	public function register_hooks(): void {
		add_action( 'init', array( $this, 'register_meta' ) );
		add_filter( 'wmac_rendered_html', array( $this, 'inject' ), 15, 2 );
	}

	public function register_meta(): void {
		$shared = array(
			'object_subtype' => PostType::KEY,
			'type'           => 'string',
			'single'         => true,
			'show_in_rest'   => true,
		);

		$edit_auth = static function ( bool $allowed, string $meta_key, int $post_id ): bool {
			return current_user_can( 'edit_post', $post_id );
		};

		$unfiltered_auth = static function ( bool $allowed, string $meta_key, int $post_id ): bool {
			return current_user_can( 'unfiltered_html' );
		};

		// PHP open tags never belong in injected markup; <script> is intentional.
		$strip_php = static function ( $v ): string {
			return (string) preg_replace( '/<\?(?:php|=)?/i', '', (string) $v );
		};

		register_post_meta(
			PostType::KEY,
			self::EXTERNAL_STYLES,
			array_merge(
				$shared,
				array(
					'description'       => 'External stylesheet URLs, one per line, added as <link> tags in order.',
					'sanitize_callback' => array( self::class, 'sanitize_url_list' ),
					'auth_callback'     => $edit_auth,
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::EXTERNAL_SCRIPTS,
			array_merge(
				$shared,
				array(
					'description'       => 'External script URLs, one per line, added as <script> tags before </body> in order.',
					'sanitize_callback' => array( self::class, 'sanitize_url_list' ),
					'auth_callback'     => $unfiltered_auth,
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::HEAD_HTML,
			array_merge(
				$shared,
				array(
					'description'       => 'Raw markup injected before </head> on every serve.',
					'sanitize_callback' => $strip_php,
					'auth_callback'     => $unfiltered_auth,
				)
			)
		);

		register_post_meta(
			PostType::KEY,
			self::BODY_HTML,
			array_merge(
				$shared,
				array(
					'description'       => 'Raw markup appended before </body> on every serve.',
					'sanitize_callback' => $strip_php,
					'auth_callback'     => $unfiltered_auth,
				)
			)
		);
	}

	/**
	 * Normalizes a one-URL-per-line list: trims each line, validates it as a
	 * URL, drops empties, and preserves order. Stored back newline-joined.
	 *
	 * @param mixed $value Raw textarea input.
	 */
	public static function sanitize_url_list( $value ): string {
		$urls = array();

		foreach ( preg_split( '/\r\n|\r|\n/', (string) $value ) ?: array() as $line ) {
			$url = esc_url_raw( trim( $line ) );
			if ( $url !== '' ) {
				$urls[] = $url;
			}
		}

		return implode( "\n", $urls );
	}

	public function inject( string $html, \WP_Post $post ): string {
		$head = $this->build_head_injection( $post );
		if ( $head !== '' ) {
			$html = Renderer::inject_into_head( $html, $head );
		}

		$body = $this->build_body_injection( $post );
		if ( $body !== '' ) {
			$html = Renderer::inject_before_body_close( $html, $body );
		}

		return $html;
	}

	private function build_head_injection( \WP_Post $post ): string {
		$injection = '';

		foreach ( $this->get_url_list( $post->ID, self::EXTERNAL_STYLES ) as $url ) {
			// Injected into a raw passthrough document that never runs
			// wp_head, so the enqueue API cannot deliver these.
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			$injection .= sprintf( '<link rel="stylesheet" href="%s" />' . "\n", esc_url( $url ) );
		}

		$head_html = trim( (string) get_post_meta( $post->ID, self::HEAD_HTML, true ) );
		if ( $head_html !== '' ) {
			// An unclosed <script>/<style> in the fragment would swallow the
			// rest of the document and blank the page.
			$injection .= Renderer::balance_raw_text_elements( $head_html ) . "\n";
		}

		return $injection;
	}

	private function build_body_injection( \WP_Post $post ): string {
		$injection = '';

		foreach ( $this->get_url_list( $post->ID, self::EXTERNAL_SCRIPTS ) as $url ) {
			// Raw passthrough document — wp_footer never runs (see above).
			// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript
			$injection .= sprintf( '<script src="%s"></script>' . "\n", esc_url( $url ) );
		}

		$body_html = trim( (string) get_post_meta( $post->ID, self::BODY_HTML, true ) );
		if ( $body_html !== '' ) {
			// See build_head_injection — unbalanced raw-text elements must not
			// leak past the fragment.
			$injection .= Renderer::balance_raw_text_elements( $body_html ) . "\n";
		}

		return $injection;
	}

	/** @return string[] */
	private function get_url_list( int $post_id, string $meta_key ): array {
		$raw = (string) get_post_meta( $post_id, $meta_key, true );
		if ( trim( $raw ) === '' ) {
			return array();
		}

		$urls = array();
		foreach ( preg_split( '/\r\n|\r|\n/', $raw ) ?: array() as $line ) {
			$line = trim( $line );
			if ( $line !== '' ) {
				$urls[] = $line;
			}
		}

		return $urls;
	}
}
