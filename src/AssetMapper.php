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

			$escaped_url = esc_url( $absolute_url );

			// Replace in both double- and single-quoted attribute values.
			$html = str_replace(
				array(
					'src="' . $relative_path . '"',
					"src='" . $relative_path . "'",
					'href="' . $relative_path . '"',
					"href='" . $relative_path . "'",
				),
				array(
					'src="' . $escaped_url . '"',
					"src='" . $escaped_url . "'",
					'href="' . $escaped_url . '"',
					"href='" . $escaped_url . "'",
				),
				$html
			);
		}

		return $html;
	}
}
