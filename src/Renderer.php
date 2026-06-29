<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Renderer {

	public function register_hooks(): void {
		add_action( 'template_redirect', [ $this, 'maybe_render' ], 1 );
	}

	public function maybe_render(): void {
		if ( ! is_singular( PostType::KEY ) ) {
			return;
		}

		// Let WP handle /embed/ requests for oEmbed support.
		if ( is_embed() ) {
			return;
		}

		$post = get_queried_object();

		if ( post_password_required( $post ) ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
			( new PasswordView() )->render( $post );
			exit;
		}

		// Expiry / view-count gate (public visitors only; editors always pass through).
		$governance = new LinkGovernance();
		if ( $governance->is_expired( $post ) ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
			$governance->render_expired_view( $post );
			exit;
		}

		// PDF format dispatch — different render path but same gate chain above.
		$pdf_renderer = new PdfRenderer();
		if ( $pdf_renderer->is_pdf_artifact( $post ) ) {
			remove_action( 'template_redirect', 'redirect_canonical' );
			do_action( 'wmac_artifact_served', $post );
			$pdf_renderer->render( $post );
			exit;
		}

		// For previews, serve the autosave content instead of the last published version.
		$content_source = $post;
		if ( is_preview() && current_user_can( 'edit_post', $post->ID ) ) {
			$autosave = wp_get_post_autosave( $post->ID, get_current_user_id() );
			if ( $autosave instanceof \WP_Post ) {
				$content_source = $autosave;
			}
		}

		$html = $this->get_artifact_html( $post, $content_source );

		if ( $html === '' ) {
			return;
		}

		// Only suppress canonical redirect once we know we own this response.
		remove_action( 'template_redirect', 'redirect_canonical' );

		// Notify view-count and webhook listeners that we are about to serve this artifact.
		do_action( 'wmac_artifact_served', $post );

		$html = $this->maybe_inject_oembed_discovery( $html, $post );
		$html = $this->maybe_inject_admin_toolbar( $html, $post );
		$html = (string) apply_filters( 'wmac_rendered_html', $html, $post );

		$this->send_headers( $post );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private function get_artifact_html( \WP_Post $post, \WP_Post $content_source ): string {
		// File lookup always uses the canonical post ID — never the autosave's ID.
		$file_path = ArtifactFile::get_file_path( $post->ID );
		if ( $file_path !== null && is_readable( $file_path ) ) {
			$content = file_get_contents( $file_path );
			if ( $content !== false ) {
				return $content;
			}
		}

		// For non-file-attached artifacts, extract HTML from the autosave (preview) or published content.
		$blocks = parse_blocks( $content_source->post_content );
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'wmac/artifact' ) {
				return $block['attrs']['html'] ?? '';
			}
		}
		return '';
	}

	private function send_headers( \WP_Post $post ): void {
		$charset = esc_attr( get_option( 'blog_charset' ) ?: 'UTF-8' );

		status_header( 200 );
		header( "Content-Type: text/html; charset={$charset}" );
		header( 'X-Content-Type-Options: nosniff' );

		$is_public = ( $post->post_status === 'publish' && empty( $post->post_password ) );

		if ( $is_public ) {
			header( 'Cache-Control: public, max-age=3600, s-maxage=86400' );
		} else {
			header( 'Cache-Control: no-store, no-cache, must-revalidate' );
			header( 'Pragma: no-cache' );
		}

		$noindex = (bool) apply_filters( 'wmac_noindex', true, $post );
		if ( $noindex ) {
			header( 'X-Robots-Tag: noindex, nofollow' );
		}

		$csp = (string) apply_filters( 'wmac_csp', '', $post );
		if ( $csp !== '' ) {
			header( 'Content-Security-Policy: ' . str_replace( [ "\r", "\n" ], '', $csp ) );
		}
	}

	private function maybe_inject_oembed_discovery( string $html, \WP_Post $post ): string {
		if ( $post->post_status !== 'publish' || ! empty( $post->post_password ) ) {
			return $html;
		}

		$permalink = get_permalink( $post->ID );
		if ( ! $permalink ) {
			return $html;
		}

		$oembed_url = add_query_arg(
			[ 'url' => $permalink, 'format' => 'json' ],
			rest_url( 'oembed/1.0/embed' )
		);

		$link = sprintf(
			'<link rel="alternate" type="application/json+oembed" href="%s" title="%s" />' . "\n",
			esc_url( $oembed_url ),
			esc_attr( get_the_title( $post->ID ) )
		);

		return $this->inject_into_head( $html, $link );
	}

	private function inject_into_head( string $html, string $injection ): string {
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
		return $injection . $html;
	}

	private function maybe_inject_admin_toolbar( string $html, \WP_Post $post ): string {
		if ( ! is_user_logged_in() || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $html;
		}

		$mode = (string) apply_filters( 'wmac_artifact_admin_toolbar_mode', 'custom', $post );
		if ( ! in_array( $mode, [ 'none', 'custom', 'core' ], true ) ) {
			$mode = 'custom';
		}

		if ( $mode === 'none' ) {
			return $html;
		}

		$toolbar = '';
		if ( $mode === 'core' ) {
			$toolbar = $this->build_core_admin_toolbar();
		}

		if ( $toolbar === '' ) {
			$toolbar = $this->build_custom_admin_toolbar( $post );
		}

		$toolbar = (string) apply_filters( 'wmac_artifact_admin_toolbar_html', $toolbar, $post, $mode );

		if ( trim( $toolbar ) === '' ) {
			return $html;
		}

		return $this->inject_after_body_open( $html, $toolbar );
	}

	private function build_core_admin_toolbar(): string {
		if ( ! function_exists( 'is_admin_bar_showing' ) || ! is_admin_bar_showing() ) {
			return '';
		}

		ob_start();
		wp_admin_bar_render();
		$bar_markup = (string) ob_get_clean();

		if ( trim( $bar_markup ) === '' ) {
			return '';
		}

		$assets = sprintf(
			'<link rel="stylesheet" id="admin-bar-css" href="%1$s" media="all" /><script src="%2$s" defer></script>',
			esc_url( includes_url( 'css/admin-bar.min.css' ) ),
			esc_url( includes_url( 'js/admin-bar.min.js' ) )
		);

		return $this->get_admin_toolbar_offset_markup() . $assets . $bar_markup;
	}

	private function build_custom_admin_toolbar( \WP_Post $post ): string {
		$links = [
			[
				'label' => __( 'Edit Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'url'   => get_edit_post_link( $post->ID, '' ) ?: admin_url( 'post.php?post=' . $post->ID . '&action=edit' ),
			],
			[
				'label' => __( 'Artifacts', 'webmultipliers-wp-artifact-canvas' ),
				'url'   => admin_url( 'edit.php?post_type=' . PostType::KEY ),
			],
			[
				'label' => __( 'New Artifact', 'webmultipliers-wp-artifact-canvas' ),
				'url'   => admin_url( 'post-new.php?post_type=' . PostType::KEY ),
			],
		];

		$links = (array) apply_filters( 'wmac_artifact_admin_toolbar_links', $links, $post );

		$items = '';
		foreach ( $links as $link ) {
			$label = isset( $link['label'] ) ? (string) $link['label'] : '';
			$url   = isset( $link['url'] ) ? (string) $link['url'] : '';

			if ( $label === '' || $url === '' ) {
				continue;
			}

			$items .= sprintf(
				'<a class="wmac-admin-toolbar__link" href="%1$s">%2$s</a>',
				esc_url( $url ),
				esc_html( $label )
			);
		}

		if ( $items === '' ) {
			return '';
		}

		return $this->get_admin_toolbar_offset_markup() . sprintf(
			'<style>#wmac-admin-toolbar{position:fixed;top:0;left:0;right:0;z-index:2147483647;height:32px;background:#111827;color:#fff;display:flex;align-items:center;padding:0 12px;box-sizing:border-box;font:600 13px/1.2 -apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif}#wmac-admin-toolbar .wmac-admin-toolbar__links{display:flex;gap:12px;align-items:center}#wmac-admin-toolbar .wmac-admin-toolbar__link{color:#fff;text-decoration:none;opacity:.95}#wmac-admin-toolbar .wmac-admin-toolbar__link:hover{opacity:1;text-decoration:underline}@media screen and (max-width:782px){#wmac-admin-toolbar{height:46px;padding:0 10px;font-size:14px}}</style><div id="wmac-admin-toolbar"><nav class="wmac-admin-toolbar__links" aria-label="%1$s">%2$s</nav></div>',
			esc_attr__( 'Artifact admin toolbar', 'webmultipliers-wp-artifact-canvas' ),
			$items
		);
	}

	private function get_admin_toolbar_offset_markup(): string {
		return '<style>html{margin-top:0!important}#wmac-admin-toolbar-offset{display:block;height:32px;min-height:32px;pointer-events:none}html{scroll-padding-top:32px}@media screen and (max-width:782px){#wmac-admin-toolbar-offset{height:46px;min-height:46px}html{scroll-padding-top:46px}}</style><div id="wmac-admin-toolbar-offset" aria-hidden="true"></div>';
	}

	private function inject_after_body_open( string $html, string $injection ): string {
		$count     = 0;
		$with_body = preg_replace_callback(
			'/<body\b[^>]*>/i',
			static function ( array $m ) use ( $injection ): string {
				return $m[0] . $injection;
			},
			$html,
			1,
			$count
		);
		if ( $count > 0 && is_string( $with_body ) ) {
			return $with_body;
		}

		return $injection . $html;
	}
}
