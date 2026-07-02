<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use RuntimeException;

abstract class TestCase extends PHPUnitTestCase {

	// Counts Mockery/Brain-Monkey expectations as PHPUnit assertions so
	// expectation-only tests are not flagged as risky.
	use MockeryPHPUnitIntegration;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $tokens
	 */
	protected function artifact_fixture( string $file = 'hello-world.html', array $tokens = [] ): string {
		$path = dirname( __DIR__ ) . '/Artifacts/' . ltrim( $file, '/' );

		if ( ! is_file( $path ) ) {
			throw new RuntimeException( 'Artifact fixture not found: ' . $path );
		}

		$contents = (string) file_get_contents( $path );

		foreach ( $tokens as $token => $value ) {
			$contents = str_replace( '{{' . $token . '}}', $value, $contents );
		}

		return $contents;
	}

	/**
	 * @param array<string, string> $tokens
	 */
	protected function artifact_fixture_inner_content( string $file = 'hello-world.html', array $tokens = [] ): string {
		$html = $this->artifact_fixture( $file, $tokens );

		if ( ! preg_match( '/<body\b[^>]*>(.*)<\/body>/is', $html, $matches ) ) {
			return trim( $html );
		}

		return trim( $matches[1] );
	}
}
