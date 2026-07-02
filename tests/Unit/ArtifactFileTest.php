<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\ArtifactFile;
use WebMultipliers\ArtifactCanvas\PostType;
use WP_Error;
use WP_Post;
use WP_REST_Request;

final class ArtifactFileTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	private function upload_request( array $file, int $id = 5 ): WP_REST_Request {
		return new WP_REST_Request( [ 'id' => $id ], [], '', null, [ 'file' => $file ] );
	}

	// -------------------------------------------------------------------
	// check_permission
	// -------------------------------------------------------------------

	public function test_check_permission_rejects_unknown_post(): void {
		Functions\expect( 'get_post' )->once()->with( 5 )->andReturn( null );

		$result = ( new ArtifactFile() )->check_permission( $this->upload_request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_not_found', $result->get_error_code() );
	}

	public function test_check_permission_rejects_wrong_post_type(): void {
		Functions\expect( 'get_post' )->once()->andReturn( new WP_Post( [ 'ID' => 5, 'post_type' => 'post' ] ) );

		$result = ( new ArtifactFile() )->check_permission( $this->upload_request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_not_found', $result->get_error_code() );
	}

	public function test_check_permission_rejects_without_edit_cap(): void {
		Functions\expect( 'get_post' )->once()->andReturn( new WP_Post( [ 'ID' => 5, 'post_type' => PostType::KEY ] ) );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 5 )->andReturn( false );

		$result = ( new ArtifactFile() )->check_permission( $this->upload_request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_forbidden', $result->get_error_code() );
	}

	public function test_check_permission_allows_editor(): void {
		Functions\expect( 'get_post' )->once()->andReturn( new WP_Post( [ 'ID' => 5, 'post_type' => PostType::KEY ] ) );
		Functions\expect( 'current_user_can' )->once()->with( 'edit_post', 5 )->andReturn( true );

		$this->assertTrue( ( new ArtifactFile() )->check_permission( $this->upload_request( [] ) ) );
	}

	// -------------------------------------------------------------------
	// rest_upload validation
	// -------------------------------------------------------------------

	public function test_upload_rejects_missing_file(): void {
		$result = ( new ArtifactFile() )->rest_upload( $this->upload_request( [] ) );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_no_file', $result->get_error_code() );
	}

	public function test_upload_rejects_wrong_extension(): void {
		$request = $this->upload_request( [
			'name'     => 'artifact.exe',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 10,
			'tmp_name' => '/tmp/whatever',
		] );

		$result = ( new ArtifactFile() )->rest_upload( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_invalid_type', $result->get_error_code() );
	}

	public function test_upload_accepts_htm_extension_but_rejects_oversize(): void {
		Functions\expect( 'apply_filters' )->once()
			->with( 'wmac_max_file_size', 10 * 1024 * 1024 )
			->andReturn( 5 );

		$request = $this->upload_request( [
			'name'     => 'artifact.htm',
			'error'    => UPLOAD_ERR_OK,
			'size'     => 100,
			'tmp_name' => '/tmp/whatever',
		] );

		$result = ( new ArtifactFile() )->rest_upload( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_file_too_large', $result->get_error_code() );
	}
}
