<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\CodeInjection;
use WP_Post;

final class CodeInjectionTest extends TestCase {

	private function post(): WP_Post {
		return new WP_Post( [ 'ID' => 21 ] );
	}

	/** @param array<string, string> $meta */
	private function with_meta( array $meta ): void {
		Functions\when( 'get_post_meta' )->alias(
			static function ( int $id, string $key ) use ( $meta ) {
				return $meta[ $key ] ?? '';
			}
		);
		Functions\when( 'esc_url' )->returnArg( 1 );
	}

	public function test_external_styles_become_links_in_order_before_head_html(): void {
		$this->with_meta( [
			CodeInjection::EXTERNAL_STYLES => "https://cdn.example.com/a.css\nhttps://cdn.example.com/b.css",
			CodeInjection::HEAD_HTML       => '<style>body{color:red}</style>',
		] );

		$out = ( new CodeInjection() )->inject( '<html><head></head><body></body></html>', $this->post() );

		$a      = strpos( $out, 'a.css' );
		$b      = strpos( $out, 'b.css' );
		$custom = strpos( $out, '<style>body{color:red}</style>' );
		$head   = strpos( $out, '</head>' );

		$this->assertNotFalse( $a );
		$this->assertNotFalse( $b );
		$this->assertNotFalse( $custom );
		$this->assertLessThan( $b, $a, 'stylesheets keep their listed order' );
		$this->assertLessThan( $custom, $b, 'custom head HTML comes after the external sheets' );
		$this->assertLessThan( $head, $custom, 'everything lands before </head>' );
		$this->assertStringContainsString( '<link rel="stylesheet" href="https://cdn.example.com/a.css" />', $out );
	}

	public function test_external_scripts_become_script_tags_in_order_before_body_html(): void {
		$this->with_meta( [
			CodeInjection::EXTERNAL_SCRIPTS => "https://cdn.example.com/lib.js\nhttps://cdn.example.com/plugin.js",
			CodeInjection::BODY_HTML        => '<script>init()</script>',
		] );

		$out = ( new CodeInjection() )->inject( '<html><head></head><body><p>x</p></body></html>', $this->post() );

		$lib    = strpos( $out, 'lib.js' );
		$plugin = strpos( $out, 'plugin.js' );
		$init   = strpos( $out, '<script>init()</script>' );
		$close  = strpos( $out, '</body>' );

		$this->assertNotFalse( $lib );
		$this->assertNotFalse( $plugin );
		$this->assertNotFalse( $init );
		$this->assertLessThan( $plugin, $lib, 'scripts keep their listed order' );
		$this->assertLessThan( $init, $plugin, 'inline body HTML runs after the external scripts' );
		$this->assertLessThan( $close, $init, 'everything lands before </body>' );
		$this->assertStringContainsString( '<script src="https://cdn.example.com/lib.js"></script>', $out );
	}

	public function test_documents_without_head_or_body_markers_still_receive_injections(): void {
		$this->with_meta( [
			CodeInjection::EXTERNAL_STYLES  => 'https://cdn.example.com/a.css',
			CodeInjection::EXTERNAL_SCRIPTS => 'https://cdn.example.com/lib.js',
		] );

		$out = ( new CodeInjection() )->inject( '<p>fragment only</p>', $this->post() );

		$this->assertStringContainsString( 'a.css', $out );
		$this->assertStringContainsString( 'lib.js', $out );
		$this->assertStringContainsString( '<p>fragment only</p>', $out );
	}

	public function test_no_meta_is_a_noop(): void {
		$this->with_meta( [] );

		$html = '<html><head></head><body></body></html>';

		$this->assertSame( $html, ( new CodeInjection() )->inject( $html, $this->post() ) );
	}

	public function test_unclosed_script_in_head_html_is_closed_before_injection(): void {
		$this->with_meta( [
			CodeInjection::HEAD_HTML => '<script src="https://cdn.tailwindcss.com">',
		] );

		$out = ( new CodeInjection() )->inject( '<html><head></head><body><h1>Hi</h1></body></html>', $this->post() );

		$this->assertStringContainsString(
			'<script src="https://cdn.tailwindcss.com"></script>',
			$out
		);
	}

	public function test_unclosed_style_in_body_html_is_closed_before_injection(): void {
		$this->with_meta( [
			CodeInjection::BODY_HTML => '<style>body{color:red}',
		] );

		$out = ( new CodeInjection() )->inject( '<html><head></head><body></body></html>', $this->post() );

		$this->assertStringContainsString( '<style>body{color:red}</style>', $out );
	}

	public function test_balanced_fragments_are_injected_unchanged(): void {
		$this->with_meta( [
			CodeInjection::HEAD_HTML => '<script>var a=1;</script><style>p{margin:0}</style>',
		] );

		$out = ( new CodeInjection() )->inject( '<html><head></head><body></body></html>', $this->post() );

		$this->assertStringContainsString( '<script>var a=1;</script><style>p{margin:0}</style>', $out );
		$this->assertSame( 1, substr_count( $out, '</script>' ) );
	}

	public function test_sanitize_url_list_trims_validates_and_preserves_order(): void {
		Functions\when( 'esc_url_raw' )->alias(
			static function ( string $url ) {
				return str_starts_with( $url, 'https://' ) ? $url : '';
			}
		);

		$raw = "  https://a.example.com/x.css  \n\nnot a url\nhttps://b.example.com/y.css\r\n";

		$this->assertSame(
			"https://a.example.com/x.css\nhttps://b.example.com/y.css",
			CodeInjection::sanitize_url_list( $raw )
		);
	}
}
