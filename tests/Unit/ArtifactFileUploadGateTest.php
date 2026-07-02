<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionMethod;
use WebMultipliers\ArtifactCanvas\ArtifactFile;

/**
 * Guards the kses gate on the file-upload path: uploaded HTML from an author
 * without unfiltered_html is filtered in place, matching the block-content
 * behavior in Security::sanitize_on_save. rest_upload delegates that filtering
 * to sanitize_stored_file() after move_uploaded_file() validates the upload;
 * we exercise the helper directly since move_uploaded_file requires a real
 * HTTP upload that unit tests cannot produce.
 */
final class ArtifactFileUploadGateTest extends TestCase {

	private function sanitize( string $path ): void {
		$method = new ReflectionMethod( ArtifactFile::class, 'sanitize_stored_file' );
		$method->setAccessible( true );
		$method->invoke( new ArtifactFile(), $path );
	}

	public function test_stored_file_is_kses_filtered_in_place(): void {
		Functions\when( 'wp_kses_post' )->alias(
			static function ( string $s ) {
				return (string) preg_replace( '#<script.*?</script>#is', '', $s );
			}
		);

		$path = sys_get_temp_dir() . '/wmac-store-' . uniqid( '', true ) . '.html';
		file_put_contents( $path, '<p>ok</p><script>evil()</script>' );

		$this->sanitize( $path );

		$stored = (string) file_get_contents( $path );
		unlink( $path );

		$this->assertStringNotContainsString( '<script>', $stored );
		$this->assertStringContainsString( '<p>ok</p>', $stored );
	}

	public function test_clean_file_is_left_untouched(): void {
		Functions\when( 'wp_kses_post' )->returnArg( 1 );

		$path = sys_get_temp_dir() . '/wmac-store-' . uniqid( '', true ) . '.html';
		$html = '<!doctype html><html><body><p>clean</p></body></html>';
		file_put_contents( $path, $html );

		$this->sanitize( $path );

		$stored = (string) file_get_contents( $path );
		unlink( $path );

		$this->assertSame( $html, $stored );
	}
}
