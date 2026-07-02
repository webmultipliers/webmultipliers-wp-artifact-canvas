<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\PackageValidator;
use WP_Error;
use ZipArchive;

final class PackageValidatorTest extends TestCase {

	/** @var array<int, string> */
	private array $tmp_files = [];

	protected function setUp(): void {
		parent::setUp();

		if ( ! class_exists( \ZipArchive::class ) ) {
			$this->markTestSkipped( 'The zip PHP extension is not available in this environment.' );
		}

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default ) {
				return $default;
			}
		);
	}

	protected function tearDown(): void {
		foreach ( $this->tmp_files as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$this->tmp_files = [];
		parent::tearDown();
	}

	/** @param array<string, string> $entries relative path => contents */
	private function build_zip( array $entries ): string {
		$path = sys_get_temp_dir() . '/wmac-test-' . uniqid( '', true ) . '.zip';
		$this->tmp_files[] = $path;

		$zip = new ZipArchive();
		$zip->open( $path, ZipArchive::CREATE );
		foreach ( $entries as $name => $contents ) {
			$zip->addFromString( $name, $contents );
		}
		$zip->close();

		return $path;
	}

	public function test_extracts_root_index_html(): void {
		$zip = $this->build_zip( [ 'index.html' => '<!doctype html><html><body>Hi</body></html>' ] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertSame( '<!doctype html><html><body>Hi</body></html>', $result );
	}

	public function test_strips_github_style_prefix_directory(): void {
		$zip = $this->build_zip( [ 'repo-main/index.html' => '<html><body>Prefixed</body></html>' ] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertSame( '<html><body>Prefixed</body></html>', $result );
	}

	public function test_missing_index_html_is_rejected(): void {
		$zip = $this->build_zip( [ 'readme.txt' => 'no html here' ] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_zip_no_index', $result->get_error_code() );
	}

	public function test_blocked_extension_is_rejected(): void {
		$zip = $this->build_zip( [
			'index.html' => '<html></html>',
			'shell.php'  => '<?php system($_GET["c"]); ?>',
		] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_zip_blocked_file', $result->get_error_code() );
	}

	public function test_excessive_depth_is_rejected(): void {
		$zip = $this->build_zip( [
			'index.html'         => '<html></html>',
			'a/b/c/d/e/f/deep.txt' => 'too deep',
		] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_zip_depth', $result->get_error_code() );
	}

	public function test_oversized_archive_is_rejected(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default ) {
				return $tag === 'wmac_max_zip_size' ? 10 : $default;
			}
		);

		$zip = $this->build_zip( [ 'index.html' => str_repeat( 'x', 1000 ) ] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_zip_too_large', $result->get_error_code() );
	}

	public function test_non_html_index_is_rejected(): void {
		$zip = $this->build_zip( [ 'index.html' => 'just plain text, not a document' ] );

		$result = ( new PackageValidator() )->extract_entry_html( $zip );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_zip_not_html', $result->get_error_code() );
	}
}
