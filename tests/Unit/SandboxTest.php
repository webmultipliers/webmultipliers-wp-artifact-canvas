<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\Sandbox;

final class SandboxTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	public function test_normalize_host_accepts_bare_host(): void {
		$this->assertSame( 'artifacts.example.com', Sandbox::normalize_host( 'Artifacts.Example.com' ) );
	}

	public function test_normalize_host_strips_scheme_and_path(): void {
		$this->assertSame(
			'artifacts.example.com',
			Sandbox::normalize_host( 'https://artifacts.example.com/some/path' )
		);
	}

	public function test_normalize_host_preserves_port(): void {
		$this->assertSame( 'localhost:8889', Sandbox::normalize_host( 'http://localhost:8889' ) );
	}

	public function test_normalize_host_rejects_garbage(): void {
		$this->assertSame( '', Sandbox::normalize_host( '   ' ) );
		$this->assertSame( '', Sandbox::normalize_host( '///' ) );
	}

	public function test_to_sandbox_url_swaps_host_and_keeps_path(): void {
		Functions\expect( 'get_option' )
			->with( 'wmac_settings', [] )
			->andReturn( [ 'sandbox_host' => 'artifacts.example.com' ] );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				return $value;
			}
		);

		$this->assertSame(
			'https://artifacts.example.com/artifact/my-doc/?x=1',
			Sandbox::to_sandbox_url( 'https://example.com/artifact/my-doc/?x=1' )
		);
	}

	public function test_to_sandbox_url_is_noop_when_disabled(): void {
		Functions\expect( 'get_option' )
			->with( 'wmac_settings', [] )
			->andReturn( [] );
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $value ) {
				return $value;
			}
		);

		$url = 'https://example.com/artifact/my-doc/';

		$this->assertSame( $url, Sandbox::to_sandbox_url( $url ) );
	}
}
