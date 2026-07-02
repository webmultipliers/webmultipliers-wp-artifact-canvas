<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\Security;
use WP_Post;

final class SecurityTest extends TestCase {

	private function artifact_post( string $title = 'Existing Title' ): WP_Post {
		return new WP_Post( [
			'ID'         => 42,
			'post_title' => $title,
		] );
	}

	/** @return array<int, array<string, mixed>> */
	private function blocks_with_html( string $html ): array {
		return [
			[
				'blockName' => 'wmac/artifact',
				'attrs'     => [ 'html' => $html ],
			],
		];
	}

	public function test_skips_autosaves(): void {
		Functions\expect( 'wp_is_post_autosave' )->once()->andReturn( true );
		Functions\expect( 'wp_is_post_revision' )->never();
		Functions\expect( 'wp_update_post' )->never();

		( new Security() )->sanitize_on_save( 42, $this->artifact_post() );
	}

	public function test_skips_revisions(): void {
		Functions\expect( 'wp_is_post_autosave' )->once()->andReturn( false );
		Functions\expect( 'wp_is_post_revision' )->once()->andReturn( true );
		Functions\expect( 'wp_update_post' )->never();

		( new Security() )->sanitize_on_save( 42, $this->artifact_post() );
	}

	public function test_unfiltered_html_user_is_not_sanitized(): void {
		$raw = '<p>hi</p><script>evil()</script>';

		Functions\expect( 'wp_is_post_autosave' )->once()->andReturn( false );
		Functions\expect( 'wp_is_post_revision' )->once()->andReturn( false );
		Functions\expect( 'parse_blocks' )->once()->andReturn( $this->blocks_with_html( $raw ) );
		Functions\expect( 'current_user_can' )->once()->with( 'unfiltered_html' )->andReturn( true );
		Functions\expect( 'wp_kses_post' )->never();
		Functions\expect( 'wp_update_post' )->never();

		( new Security() )->sanitize_on_save( 42, $this->artifact_post() );
	}

	public function test_non_privileged_user_script_is_stripped_and_saved(): void {
		$raw      = '<p>hi</p><script>evil()</script>';
		$filtered = '<p>hi</p>';

		Functions\expect( 'wp_is_post_autosave' )->once()->andReturn( false );
		Functions\expect( 'wp_is_post_revision' )->once()->andReturn( false );
		Functions\expect( 'parse_blocks' )->once()->andReturn( $this->blocks_with_html( $raw ) );
		Functions\expect( 'current_user_can' )->once()->with( 'unfiltered_html' )->andReturn( false );
		Functions\expect( 'wp_kses_post' )->once()->with( $raw )->andReturn( $filtered );
		Functions\expect( 'serialize_blocks' )->once()->andReturnUsing(
			static function ( array $blocks ) {
				return $blocks[0]['attrs']['html'];
			}
		);

		Functions\expect( 'wp_update_post' )->once()->with(
			[
				'ID'           => 42,
				'post_content' => $filtered,
			]
		);

		( new Security() )->sanitize_on_save( 42, $this->artifact_post() );
	}

	public function test_non_privileged_user_clean_html_is_not_resaved(): void {
		$clean = '<p>already clean</p>';

		Functions\expect( 'wp_is_post_autosave' )->once()->andReturn( false );
		Functions\expect( 'wp_is_post_revision' )->once()->andReturn( false );
		Functions\expect( 'parse_blocks' )->once()->andReturn( $this->blocks_with_html( $clean ) );
		Functions\expect( 'current_user_can' )->once()->with( 'unfiltered_html' )->andReturn( false );
		Functions\expect( 'wp_kses_post' )->once()->with( $clean )->andReturn( $clean );
		Functions\expect( 'wp_update_post' )->never();

		( new Security() )->sanitize_on_save( 42, $this->artifact_post() );
	}

	public function test_empty_title_is_populated_from_html_title_tag(): void {
		$html = '<html><head><title>Extracted &amp; Title</title></head><body></body></html>';

		Functions\expect( 'wp_is_post_autosave' )->once()->andReturn( false );
		Functions\expect( 'wp_is_post_revision' )->once()->andReturn( false );
		Functions\expect( 'parse_blocks' )->once()->andReturn( $this->blocks_with_html( $html ) );
		Functions\expect( 'current_user_can' )->once()->with( 'unfiltered_html' )->andReturn( true );
		Functions\when( 'get_option' )->justReturn( 'UTF-8' );
		Functions\when( 'wp_strip_all_tags' )->alias(
			static function ( string $s ) {
				return trim( strip_tags( $s ) );
			}
		);

		Functions\expect( 'wp_update_post' )->once()->with(
			[
				'ID'         => 42,
				'post_title' => 'Extracted & Title',
			]
		);

		( new Security() )->sanitize_on_save( 42, $this->artifact_post( '' ) );
	}
}
