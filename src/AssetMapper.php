<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Replaces unresolved relative asset paths in rendered HTML with their
 * Media Library URL equivalents stored in _wmac_asset_map post meta.
 *
 * Hooks into wmac_rendered_html at priority 10 so it runs before MergeTags (20).
 * The original HTML stored in the database is never modified.
 */
class AssetMapper {

	public function register_hooks(): void {
		add_filter( 'wmac_rendered_html', array( $this, 'apply_map' ), 10, 2 );
	}

	public function apply_map( string $html, \WP_Post $post ): string {
		$map_json = get_post_meta( $post->ID, ArtifactMeta::ASSET_MAP, true );
		if ( ! $map_json || $map_json === '{}' ) {
			return $html;
		}

		$map = json_decode( $map_json, true );
		if ( ! is_array( $map ) || empty( $map ) ) {
			return $html;
		}

		foreach ( $map as $relative_path => $absolute_url ) {
			if ( ! is_string( $relative_path ) || ! is_string( $absolute_url ) || $relative_path === '' || $absolute_url === '' ) {
				continue;
			}

			$html = $this->replace_path( $html, $relative_path, esc_url( $absolute_url ) );
		}

		return $html;
	}

	/**
	 * Replaces one relative path with its mapped URL across every reference
	 * form the mapper supports:
	 *   • src/href/poster attributes — quoted, with any attribute casing and
	 *     flexible spacing around '=' (mirroring the detection regex in
	 *     ManagementMetaboxes), plus quote-less HTML5 syntax
	 *   • srcset attribute entries (path plus optional width/density descriptor)
	 *   • CSS url(...) references in <style> blocks and style attributes
	 */
	private function replace_path( string $html, string $path, string $url ): string {
		$quoted      = preg_quote( $path, '#' );
		$replacement = addcslashes( $url, '\\$' ); // Keep the URL literal in replacement context.

		// Quoted attribute values: src="path", HREF = 'path', poster="path".
		$html = (string) preg_replace(
			'#(\b(?:src|href|poster)\s*=\s*)(["\'])' . $quoted . '\2#i',
			'$1$2' . $replacement . '$2',
			$html
		);

		// Quote-less HTML5 attributes: <img src=images/hero.jpg>.
		$html = (string) preg_replace(
			'#(\b(?:src|href|poster)\s*=\s*)' . $quoted . '(?=[\s>])#i',
			'$1"' . $replacement . '"',
			$html
		);

		// srcset entries: each comma-separated candidate is a URL optionally
		// followed by a descriptor; only exact path matches are rewritten.
		$html = (string) preg_replace_callback(
			'#(\bsrcset\s*=\s*)(["\'])(.*?)\2#is',
			static function ( array $m ) use ( $path, $url ): string {
				$entries = explode( ',', $m[3] );
				foreach ( $entries as &$entry ) {
					$parts = preg_split( '/\s+/', trim( $entry ), 2 );
					if ( is_array( $parts ) && isset( $parts[0] ) && $parts[0] === $path ) {
						$parts[0] = $url;
						$entry    = implode( ' ', $parts );
					}
				}
				unset( $entry );

				return $m[1] . $m[2] . implode( ', ', array_map( 'trim', $entries ) ) . $m[2];
			},
			$html
		);

		// CSS url(...) in <style> blocks and inline style attributes.
		$html = (string) preg_replace(
			'#url\(\s*(["\']?)' . $quoted . '\1\s*\)#i',
			'url($1' . $replacement . '$1)',
			$html
		);

		return $html;
	}
}
