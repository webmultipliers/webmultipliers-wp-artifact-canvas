<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Optional OG/Twitter meta tag injection for artifacts.
 *
 * Disabled by default. Opt in per-post or globally via the wmac_seo_enabled filter:
 *
 *   add_filter( 'wmac_seo_enabled', '__return_true' );
 *
 * Tags are generated from native WordPress post data (title, excerpt, featured
 * image). SEO plugin output is intentionally not captured — artifacts manage
 * their own <head> and the passthrough renderer runs outside wp_head().
 */
class SeoMeta {

	public function register_hooks(): void {
		add_filter( 'wmac_rendered_html', array( $this, 'maybe_inject_meta' ), 20, 2 );
	}

	public function maybe_inject_meta( string $html, \WP_Post $post ): string {
		if ( ! (bool) apply_filters( 'wmac_seo_enabled', false, $post ) ) {
			return $html;
		}

		if ( $post->post_status !== 'publish' || ! empty( $post->post_password ) ) {
			return $html;
		}

		$tags = $this->build_meta_tags( $post );
		if ( $tags === '' ) {
			return $html;
		}

		// Same insertion rules as every other head injection: before </head>
		// when present, prepended otherwise — documents without a closing
		// head marker must not silently lose their meta tags.
		return Renderer::inject_into_head( $html, $tags );
	}

	private function build_meta_tags( \WP_Post $post ): string {
		$tags      = '';
		$permalink = get_permalink( $post->ID );
		$title     = get_the_title( $post->ID );
		$site_name = get_bloginfo( 'name' );
		// The dedicated description meta wins; the excerpt is the fallback
		// (GitWebhook repurposes the excerpt as a deploy breadcrumb, so it is
		// not a reliable description source). Search engines truncate
		// descriptions around 160 characters; cap the output accordingly.
		$description = trim( (string) get_post_meta( $post->ID, ArtifactMeta::DESCRIPTION, true ) );
		$excerpt     = $description !== '' ? $description : trim( wp_strip_all_tags( $post->post_excerpt ) );
		$excerpt     = wp_html_excerpt( $excerpt, 160, '…' );

		if ( $title !== '' ) {
			$tags .= sprintf(
				'<meta property="og:title" content="%s" />' . "\n",
				esc_attr( $title )
			);
		}

		$tags .= '<meta property="og:type" content="article" />' . "\n";

		if ( $permalink ) {
			$tags .= sprintf(
				'<meta property="og:url" content="%s" />' . "\n",
				esc_url( $permalink )
			);
		}

		if ( $site_name !== '' ) {
			$tags .= sprintf(
				'<meta property="og:site_name" content="%s" />' . "\n",
				esc_attr( $site_name )
			);
		}

		if ( $excerpt !== '' ) {
			$tags .= sprintf(
				'<meta property="og:description" content="%s" />' . "\n",
				esc_attr( $excerpt )
			);
			$tags .= sprintf(
				'<meta name="description" content="%s" />' . "\n",
				esc_attr( $excerpt )
			);
		}

		$has_image = false;
		if ( has_post_thumbnail( $post->ID ) ) {
			$image_url = get_the_post_thumbnail_url( $post->ID, 'large' );
			if ( $image_url ) {
				$has_image = true;
				$tags     .= sprintf(
					'<meta property="og:image" content="%s" />' . "\n",
					esc_url( $image_url )
				);
			}
		}

		$tags .= sprintf(
			'<meta name="twitter:card" content="%s" />' . "\n",
			esc_attr( $has_image ? 'summary_large_image' : 'summary' )
		);

		return $tags;
	}
}
