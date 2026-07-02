<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class OEmbed {

	public function register_hooks(): void {
		add_filter( 'oembed_response_data', array( $this, 'filter_response_data' ), 10, 4 );
	}

	/**
	 * Return a rich iframe embed instead of WP's default link/quote embed.
	 *
	 * @param array    $data   oEmbed response data.
	 * @param \WP_Post $post   The post being embedded.
	 * @param int      $width  Requested embed width.
	 * @param int      $height Requested embed height.
	 */
	public function filter_response_data( array $data, \WP_Post $post, int $width, int $height ): array {
		if ( $post->post_type !== PostType::KEY ) {
			return $data;
		}

		$url = get_permalink( $post->ID );
		if ( ! $url ) {
			return $data;
		}

		$embed_width  = max( 200, min( $width ?: 1280, 1280 ) );
		$embed_height = max( 150, min( $height ?: 720, 1080 ) );

		$data['type']   = 'rich';
		$data['width']  = $embed_width;
		$data['height'] = $embed_height;
		$data['html']   = sprintf(
			'<iframe src="%1$s" width="%2$d" height="%3$d" frameborder="0" scrolling="yes" style="border:0;width:%2$dpx;max-width:100%%;height:%3$dpx;" title="%4$s" loading="lazy"></iframe>',
			esc_url( $url ),
			$embed_width,
			$embed_height,
			esc_attr( $data['title'] ?? '' )
		);

		return $data;
	}
}
