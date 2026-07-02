<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Security {

	private bool $saving = false;

	public function register_hooks(): void {
		add_action( 'save_post_' . PostType::KEY, array( $this, 'sanitize_on_save' ), 10, 2 );
	}

	public function sanitize_on_save( int $post_id, \WP_Post $post ): void {
		if ( $this->saving ) {
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
				$filtered = wp_kses_post( $raw );
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
