<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

/**
 * Validates and extracts the entry-point HTML from a zip archive.
 *
 * Used by GitWebhook after downloading a repository archive. Rules:
 *   • ZipArchive PHP extension must be available
 *   • Archive total uncompressed size ≤ 50 MB (filterable via wmac_max_zip_size)
 *   • No files nested deeper than 5 directories (filterable via wmac_max_zip_depth)
 *   • No server-side executable extensions (.php, .py, .rb, .pl, .sh, .cgi, …)
 *   • Archive must contain an index.html (or index.htm) entry
 *
 * MVP: extracts and returns index.html content only. Multi-file sub-asset serving
 * is deferred; complex builds should be bundled to a single self-contained HTML
 * before pushing (e.g. Vite --single-file, Parcel --inline-scripts).
 *
 * GitHub archives wrap content in a top-level "{repo}-{branch}/" directory;
 * this class detects and strips that prefix transparently.
 */
class PackageValidator {

	const DEFAULT_MAX_DEPTH    = 5;
	const DEFAULT_MAX_ZIP_SIZE = 50 * 1024 * 1024; // 50 MB

	const BLOCKED_EXTENSIONS = array(
		'php',
		'php3',
		'php4',
		'php5',
		'php7',
		'php8',
		'phtml',
		'phar',
		'py',
		'pyc',
		'rb',
		'pl',
		'cgi',
		'sh',
		'bash',
		'zsh',
		'exe',
		'bat',
		'cmd',
		'htaccess',
		'htpasswd',
	);

	/**
	 * Opens a zip archive, validates its contents, and returns the index.html string.
	 *
	 * @param string $zip_path Absolute path to a local zip file.
	 * @return string|\WP_Error HTML content on success, WP_Error on failure.
	 */
	public function extract_entry_html( string $zip_path ): string|\WP_Error {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'wmac_zip_unavailable',
				__( 'The ZipArchive PHP extension is required for Git-driven ingestion.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 500 )
			);
		}

		$zip    = new \ZipArchive();
		$result = $zip->open( $zip_path );

		if ( $result !== true ) {
			return new \WP_Error(
				'wmac_zip_open',
				sprintf(
					/* translators: %d: ZipArchive error code */
					__( 'Could not open archive (ZipArchive error %d).', 'webmultipliers-wp-artifact-canvas' ),
					$result
				),
				array( 'status' => 422 )
			);
		}

		$max_size  = (int) apply_filters( 'wmac_max_zip_size', self::DEFAULT_MAX_ZIP_SIZE );
		$max_depth = (int) apply_filters( 'wmac_max_zip_depth', self::DEFAULT_MAX_DEPTH );

		$entry_name  = null;
		$entry_depth = PHP_INT_MAX;
		$total_size  = 0;

		for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive core property.
			$stat = $zip->statIndex( $i );
			if ( $stat === false ) {
				continue;
			}

			$name        = $stat['name'];
			$total_size += (int) $stat['size'];

			if ( $total_size > $max_size ) {
				$zip->close();
				return new \WP_Error(
					'wmac_zip_too_large',
					__( 'Archive exceeds the maximum allowed uncompressed size (50 MB).', 'webmultipliers-wp-artifact-canvas' ),
					array( 'status' => 413 )
				);
			}

			// Directory entries end with '/'.
			if ( str_ends_with( $name, '/' ) ) {
				continue;
			}

			// Extension check.
			$ext = strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) );
			if ( in_array( $ext, self::BLOCKED_EXTENSIONS, true ) ) {
				$zip->close();
				return new \WP_Error(
					'wmac_zip_blocked_file',
					sprintf(
						/* translators: %s: file path inside the archive */
						__( 'Archive contains a disallowed file type: %s', 'webmultipliers-wp-artifact-canvas' ),
						esc_html( $name )
					),
					array( 'status' => 422 )
				);
			}

			// Depth check (parts − 1 because the last part is the file name).
			$parts = explode( '/', trim( $name, '/' ) );
			$depth = count( $parts ) - 1;

			if ( $depth > $max_depth ) {
				$zip->close();
				return new \WP_Error(
					'wmac_zip_depth',
					sprintf(
						/* translators: %d: max depth */
						__( 'Archive contains files nested deeper than the allowed maximum (%d levels).', 'webmultipliers-wp-artifact-canvas' ),
						$max_depth
					),
					array( 'status' => 422 )
				);
			}

			// Track the shallowest index.html (GitHub prefix dir is 1 level deep).
			$basename = strtolower( basename( $name ) );
			if ( ( $basename === 'index.html' || $basename === 'index.htm' ) && $depth < $entry_depth ) {
				$entry_name  = $name;
				$entry_depth = $depth;
			}
		}

		if ( $entry_name === null ) {
			$zip->close();
			return new \WP_Error(
				'wmac_zip_no_index',
				__( 'Archive does not contain an index.html file.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 422 )
			);
		}

		$html = $zip->getFromName( $entry_name );
		$zip->close();

		if ( $html === false ) {
			return new \WP_Error(
				'wmac_zip_read',
				__( 'Could not read index.html from the archive.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 500 )
			);
		}

		if ( stripos( $html, '<html' ) === false && stripos( $html, '<!doctype' ) === false ) {
			return new \WP_Error(
				'wmac_zip_not_html',
				__( 'index.html does not appear to be a valid HTML document.', 'webmultipliers-wp-artifact-canvas' ),
				array( 'status' => 422 )
			);
		}

		return $html;
	}
}
