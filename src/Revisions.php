<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class Revisions {

	public function register_hooks(): void {
		add_filter( 'wp_get_revision_ui_diff', array( $this, 'filter_revision_ui_diff' ), 10, 3 );
	}

	/**
	 * Replace the raw block-JSON diff for post_content with a clean HTML diff.
	 *
	 * WP only includes a field in $return when the content actually changed, so
	 * the foreach is a no-op on identical revisions — no extra work done.
	 */
	public function filter_revision_ui_diff( array $return, \WP_Post $compare_from, \WP_Post $compare_to ): array {
		if ( ! $this->is_artifact_revision( $compare_from, $compare_to ) ) {
			return $return;
		}

		foreach ( $return as $i => $entry ) {
			if ( ( $entry['id'] ?? '' ) !== 'post_content' ) {
				continue;
			}

			$from_html = $this->split_for_diff( $this->extract_html( $compare_from ) );
			$to_html   = $this->split_for_diff( $this->extract_html( $compare_to ) );
			$diff      = wp_text_diff( $from_html, $to_html, array( 'show_split_view' => true ) );

			if ( $diff ) {
				$return[ $i ] = array(
					'id'   => 'post_content',
					'name' => __( 'HTML', 'webmultipliers-wp-artifact-canvas' ),
					'diff' => $diff,
				);
			} else {
				// Block JSON changed but artifact HTML is identical — nothing meaningful to show.
				array_splice( $return, $i, 1 );
			}

			break;
		}

		return $return;
	}

	private function is_artifact_revision( \WP_Post $compare_from, \WP_Post $compare_to ): bool {
		foreach ( array( $compare_to, $compare_from ) as $p ) {
			if ( $p->post_type !== 'revision' ) {
				if ( $p->post_type === PostType::KEY ) {
					return true;
				}
				continue;
			}

			if ( ! $p->post_parent ) {
				continue;
			}

			$parent = get_post( $p->post_parent );
			if ( $parent && $parent->post_type === PostType::KEY ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * wp_text_diff() compares line-by-line, so a minified single-line
	 * document diffs as "everything deleted, everything added". When the
	 * HTML is effectively unlined, break it at tag boundaries first — both
	 * sides get the identical transform, so the diff stays faithful.
	 */
	private function split_for_diff( string $html ): string {
		if ( $html === '' ) {
			return $html;
		}

		// Average > 400 chars per line ≈ minified output.
		$lines = substr_count( $html, "\n" ) + 1;
		if ( strlen( $html ) / $lines <= 400 ) {
			return $html;
		}

		return (string) preg_replace( '/>\s*</', ">\n<", $html );
	}

	private function extract_html( \WP_Post $post ): string {
		$blocks = parse_blocks( $post->post_content );
		foreach ( $blocks as $block ) {
			if ( $block['blockName'] === 'wmac/artifact' ) {
				return $block['attrs']['html'] ?? '';
			}
		}
		return '';
	}
}
