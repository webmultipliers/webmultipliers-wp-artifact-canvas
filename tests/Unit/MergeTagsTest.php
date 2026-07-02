<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\ArtifactMeta;
use WebMultipliers\ArtifactCanvas\MergeTags;
use WP_Post;

final class MergeTagsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Functions\when( 'esc_html' )->alias(
			static function ( string $s ) {
				return htmlspecialchars( $s, ENT_QUOTES );
			}
		);
	}

	private function post(): WP_Post {
		return new WP_Post( [ 'ID' => 7 ] );
	}

	public function test_static_tag_value_is_html_escaped(): void {
		$map = (string) json_encode( [
			'client_name' => [ 'mode' => 'static', 'value' => '<b>Acme</b> & Co' ],
		] );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 7, ArtifactMeta::TAG_MAP, true )
			->andReturn( $map );

		$html = ( new MergeTags() )->apply_tags( 'Hello {{client_name}}!', $this->post() );

		$this->assertSame( 'Hello &lt;b&gt;Acme&lt;/b&gt; &amp; Co!', $html );
	}

	public function test_dynamic_tag_dispatches_to_resolver_filter_unescaped(): void {
		$map = (string) json_encode( [
			'raw_widget' => [ 'mode' => 'dynamic' ],
		] );

		Functions\expect( 'get_post_meta' )
			->once()
			->with( 7, ArtifactMeta::TAG_MAP, true )
			->andReturn( $map );

		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wmac_resolve_tag_raw_widget', '', 7 )
			->andReturn( '<svg>trusted-dev-output</svg>' );

		$html = ( new MergeTags() )->apply_tags( '{{raw_widget}}', $this->post() );

		$this->assertSame( '<svg>trusted-dev-output</svg>', $html );
	}

	public function test_unmapped_tag_with_registered_resolver_is_resolved(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'has_filter' )->once()->with( 'wmac_resolve_tag_wp_current_year' )->andReturn( true );
		Functions\expect( 'apply_filters' )
			->once()
			->with( 'wmac_resolve_tag_wp_current_year', '', 7 )
			->andReturn( '2026' );

		$html = ( new MergeTags() )->apply_tags( 'Year: {{wp_current_year}}', $this->post() );

		$this->assertSame( 'Year: 2026', $html );
	}

	public function test_unmapped_unknown_tag_is_left_untouched(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'has_filter' )->once()->with( 'wmac_resolve_tag_totally_unknown' )->andReturn( false );
		Functions\expect( 'apply_filters' )->never();

		$html = ( new MergeTags() )->apply_tags( 'X: {{totally_unknown}}', $this->post() );

		$this->assertSame( 'X: {{totally_unknown}}', $html );
	}

	public function test_backslash_escaped_tag_emits_literal_placeholder(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '' );
		Functions\expect( 'has_filter' )->never();
		Functions\expect( 'apply_filters' )->never();

		$html = ( new MergeTags() )->apply_tags( 'X: \{{wp_current_year}}', $this->post() );

		$this->assertSame( 'X: {{wp_current_year}}', $html );
	}

	public function test_static_attr_context_uses_esc_attr(): void {
		Functions\when( 'esc_attr' )->alias(
			static function ( string $s ) {
				return 'ATTR(' . $s . ')';
			}
		);

		$map = (string) json_encode( [
			'cls' => [ 'mode' => 'static', 'value' => 'a b', 'context' => 'attr' ],
		] );

		Functions\expect( 'get_post_meta' )->once()->andReturn( $map );

		$html = ( new MergeTags() )->apply_tags( '<div class="{{cls}}">', $this->post() );

		$this->assertSame( '<div class="ATTR(a b)">', $html );
	}

	public function test_static_url_context_uses_esc_url_which_can_reject_unsafe_protocols(): void {
		Functions\when( 'esc_url' )->alias(
			static function ( string $s ) {
				return str_starts_with( $s, 'javascript:' ) ? '' : $s;
			}
		);

		$map = (string) json_encode( [
			'link' => [ 'mode' => 'static', 'value' => 'javascript:alert(1)', 'context' => 'url' ],
		] );

		Functions\expect( 'get_post_meta' )->once()->andReturn( $map );

		$html = ( new MergeTags() )->apply_tags( '<a href="{{link}}">x</a>', $this->post() );

		$this->assertSame( '<a href="">x</a>', $html );
	}
}
