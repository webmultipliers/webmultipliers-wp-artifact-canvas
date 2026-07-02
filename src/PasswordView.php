<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas;

class PasswordView {

	public function render( \WP_Post $post ): void {
		$charset = esc_attr( get_option( 'blog_charset' ) ?: 'UTF-8' );

		status_header( 200 );
		header( "Content-Type: text/html; charset={$charset}" );
		header( 'Cache-Control: no-store, no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );

		$title     = esc_html( get_the_title( $post ) );
		$form      = get_the_password_form( $post );
		$lang      = esc_attr( get_bloginfo( 'language' ) );
		$logo_html = function_exists( 'get_custom_logo' ) ? get_custom_logo() : '';

		$logo_block = '';
		if ( $logo_html !== '' ) {
			$logo_block = '<div class="logo">' . $logo_html . '</div>';
		}

		// The gate page bypasses the theme (by design), so wp_head/wp_footer
		// never run. These buffered actions are the integration point for
		// compliance banners, analytics, or translation tooling that must
		// also appear on the password barrier.
		ob_start();
		do_action( 'wmac_password_view_head', $post );
		$head_extra = (string) ob_get_clean();

		ob_start();
		do_action( 'wmac_password_view_footer', $post );
		$footer_extra = (string) ob_get_clean();

		// phpcs:disable WordPress.Security.EscapeOutput -- all variables in this template are pre-escaped above.
		echo <<<HTML
		<!doctype html>
		<html lang="{$lang}">
		<head>
		<meta charset="{$charset}">
		<meta name="viewport" content="width=device-width,initial-scale=1">
		<meta name="robots" content="noindex,nofollow">
		<title>{$title}</title>
		<style>
		body{font-family:system-ui,sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:#f6f7f7}
		.card{background:#fff;padding:2rem;border-radius:8px;box-shadow:0 2px 12px rgba(0,0,0,.1);max-width:400px;width:100%}
		.logo{text-align:center;margin-bottom:1.5rem}.logo img{max-height:60px;width:auto}
		h1{font-size:1.25rem;margin:0 0 1rem}
		input[type=password]{width:100%;padding:.5rem;margin:.5rem 0 1rem;box-sizing:border-box}
		input[type=submit]{cursor:pointer}
		</style>
		{$head_extra}
		</head>
		<body><div class="card">
		{$logo_block}
		<h1>{$title}</h1>
		{$form}
		</div>
		{$footer_extra}
		</body></html>
		HTML;
		// phpcs:enable
	}
}
