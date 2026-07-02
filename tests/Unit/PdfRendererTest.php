<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionMethod;
use WebMultipliers\ArtifactCanvas\PdfRenderer;
use WP_Error;
use WP_Post;
use WP_REST_Request;

final class PdfRendererTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	private function password_gate_passed( \WP_Post $post ): bool {
		$method = new ReflectionMethod( PdfRenderer::class, 'password_gate_passed' );
		$method->setAccessible( true );
		return $method->invoke( new PdfRenderer(), $post );
	}

	// -------------------------------------------------------------------
	// Password gate
	// -------------------------------------------------------------------

	public function test_password_gate_passes_when_no_password_required(): void {
		Functions\expect( 'post_password_required' )->once()->andReturn( false );

		$this->assertTrue( $this->password_gate_passed( new WP_Post( [ 'ID' => 1 ] ) ) );
	}

	public function test_password_gate_fails_with_no_cookie(): void {
		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
		Functions\expect( 'post_password_required' )->once()->andReturn( true );

		$this->assertFalse( $this->password_gate_passed( new WP_Post( [ 'ID' => 1, 'post_password' => 'hash' ] ) ) );
	}

	public function test_password_gate_fails_with_wrong_cookie(): void {
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = 'wrong-value';
		Functions\expect( 'post_password_required' )->once()->andReturn( true );
		Functions\expect( 'wp_unslash' )->once()->andReturnFirstArg();
		Functions\expect( 'wp_check_password' )->once()->with( 'wrong-value', 'hash' )->andReturn( false );

		$this->assertFalse( $this->password_gate_passed( new WP_Post( [ 'ID' => 1, 'post_password' => 'hash' ] ) ) );

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	public function test_password_gate_passes_with_valid_cookie(): void {
		$_COOKIE[ 'wp-postpass_' . COOKIEHASH ] = 'correct-value';
		Functions\expect( 'post_password_required' )->once()->andReturn( true );
		Functions\expect( 'wp_unslash' )->once()->andReturnFirstArg();
		Functions\expect( 'wp_check_password' )->once()->with( 'correct-value', 'hash' )->andReturn( true );

		$this->assertTrue( $this->password_gate_passed( new WP_Post( [ 'ID' => 1, 'post_password' => 'hash' ] ) ) );

		unset( $_COOKIE[ 'wp-postpass_' . COOKIEHASH ] );
	}

	// -------------------------------------------------------------------
	// Upload validation
	// -------------------------------------------------------------------

	private function upload_request( array $file ): WP_REST_Request {
		return new WP_REST_Request( [ 'id' => 5 ], [], '', null, [ 'file' => $file ] );
	}

	public function test_upload_rejects_missing_file(): void {
		$result = ( new PdfRenderer() )->rest_upload_pdf( $this->upload_request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_no_file', $result->get_error_code() );
	}

	public function test_upload_rejects_wrong_extension(): void {
		$request = $this->upload_request( [
			'name'     => 'artifact.txt',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 10,
			'tmp_name' => '/tmp/whatever',
		] );

		$result = ( new PdfRenderer() )->rest_upload_pdf( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_invalid_type', $result->get_error_code() );
	}

	public function test_upload_rejects_oversized_file(): void {
		Functions\expect( 'apply_filters' )->once()
			->with( 'wmac_max_pdf_size', 50 * 1024 * 1024 )
			->andReturn( 100 );

		$request = $this->upload_request( [
			'name'     => 'artifact.pdf',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 200,
			'tmp_name' => '/tmp/whatever',
		] );

		$result = ( new PdfRenderer() )->rest_upload_pdf( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_file_too_large', $result->get_error_code() );
	}

	public function test_upload_rejects_invalid_magic_bytes(): void {
		Functions\expect( 'apply_filters' )->once()->andReturn( 50 * 1024 * 1024 );

		$tmp = sys_get_temp_dir() . '/wmac-notpdf-' . uniqid( '', true );
		file_put_contents( $tmp, 'NOT A PDF FILE CONTENT' );

		$request = $this->upload_request( [
			'name'     => 'artifact.pdf',
			'error'    => UPLOAD_ERR_OK,
			'size'     => filesize( $tmp ),
			'tmp_name' => $tmp,
		] );

		$result = ( new PdfRenderer() )->rest_upload_pdf( $request );

		unlink( $tmp );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_invalid_pdf', $result->get_error_code() );
	}
}
