<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\AssetMapper;
use WP_Post;

final class AssetMapperTest extends TestCase {

	private function post(): WP_Post {
		return new WP_Post( [ 'ID' => 11 ] );
	}

	private function with_map( array $map ): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( (string) json_encode( $map ) );
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	public function test_replaces_quoted_attributes_with_flexible_spacing_and_casing(): void {
		$this->with_map( [ 'images/logo.png' => 'https://cdn.example.com/logo.png' ] );

		$html = '<img SRC = "images/logo.png"><a href=\'images/logo.png\'>x</a>';
		$out  = ( new AssetMapper() )->apply_map( $html, $this->post() );

		$this->assertSame(
			'<img SRC = "https://cdn.example.com/logo.png"><a href=\'https://cdn.example.com/logo.png\'>x</a>',
			$out
		);
	}

	public function test_replaces_quote_less_html5_attribute(): void {
		$this->with_map( [ 'images/hero.jpg' => 'https://cdn.example.com/hero.jpg' ] );

		$out = ( new AssetMapper() )->apply_map( '<img src=images/hero.jpg alt=x>', $this->post() );

		$this->assertSame( '<img src="https://cdn.example.com/hero.jpg" alt=x>', $out );
	}

	public function test_replaces_matching_srcset_entry_only(): void {
		$this->with_map( [ 'img/a.png' => 'https://cdn.example.com/a.png' ] );

		$out = ( new AssetMapper() )->apply_map( '<img srcset="img/a.png 1x, img/b.png 2x">', $this->post() );

		$this->assertSame( '<img srcset="https://cdn.example.com/a.png 1x, img/b.png 2x">', $out );
	}

	public function test_replaces_css_url_references(): void {
		$this->with_map( [ 'assets/bg.jpg' => 'https://cdn.example.com/bg.jpg' ] );

		$out = ( new AssetMapper() )->apply_map(
			"<style>.x{background:url('assets/bg.jpg')}</style><div style=\"background:url(assets/bg.jpg)\"></div>",
			$this->post()
		);

		$this->assertSame(
			"<style>.x{background:url('https://cdn.example.com/bg.jpg')}</style><div style=\"background:url(https://cdn.example.com/bg.jpg)\"></div>",
			$out
		);
	}

	public function test_does_not_touch_partial_path_matches(): void {
		$this->with_map( [ 'a.png' => 'https://cdn.example.com/a.png' ] );

		$html = '<img src="media/a.png">';
		$out  = ( new AssetMapper() )->apply_map( $html, $this->post() );

		$this->assertSame( $html, $out );
	}

	public function test_empty_map_is_a_noop(): void {
		Functions\expect( 'get_post_meta' )->once()->andReturn( '{}' );

		$html = '<img src="images/logo.png">';

		$this->assertSame( $html, ( new AssetMapper() )->apply_map( $html, $this->post() ) );
	}
}
