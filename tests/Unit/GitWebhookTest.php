<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use ReflectionMethod;
use WebMultipliers\ArtifactCanvas\GitWebhook;
use WP_Error;
use WP_REST_Request;

final class GitWebhookTest extends TestCase {

	private function verify( WP_REST_Request $request ) {
		$method = new ReflectionMethod( GitWebhook::class, 'verify_signature' );
		$method->setAccessible( true );
		return $method->invoke( new GitWebhook(), $request );
	}

	protected function setUp(): void {
		parent::setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	public function test_rejects_when_unconfigured_and_unsigned_not_allowed(): void {
		Functions\expect( 'get_option' )->once()->with( GitWebhook::OPTION_SECRET, '' )->andReturn( '' );
		Functions\expect( 'apply_filters' )->once()
			->with( 'wmac_git_webhook_allow_unsigned', false )
			->andReturn( false );

		$result = $this->verify( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 403, $result->get_error_data()['status'] );
	}

	public function test_allows_unsigned_when_filter_opts_in(): void {
		Functions\expect( 'get_option' )->once()->andReturn( '' );
		Functions\expect( 'apply_filters' )->once()
			->with( 'wmac_git_webhook_allow_unsigned', false )
			->andReturn( true );

		$result = $this->verify( new WP_REST_Request() );

		$this->assertTrue( $result );
	}

	public function test_accepts_valid_github_signature(): void {
		$secret = 'shhh';
		$body   = '{"ref":"refs/heads/main"}';
		$sig    = 'sha256=' . hash_hmac( 'sha256', $body, $secret );

		Functions\expect( 'get_option' )->once()->andReturn( $secret );

		$request = new WP_REST_Request( [], [ 'X-Hub-Signature-256' => $sig ], $body );

		$this->assertTrue( $this->verify( $request ) );
	}

	public function test_rejects_invalid_github_signature(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'shhh' );

		$request = new WP_REST_Request( [], [ 'X-Hub-Signature-256' => 'sha256=deadbeef' ], '{}' );

		$result = $this->verify( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_accepts_valid_gitlab_token(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'shhh' );

		$request = new WP_REST_Request( [], [ 'X-Gitlab-Token' => 'shhh' ] );

		$this->assertTrue( $this->verify( $request ) );
	}

	public function test_rejects_invalid_gitlab_token(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'shhh' );

		$request = new WP_REST_Request( [], [ 'X-Gitlab-Token' => 'wrong' ] );

		$result = $this->verify( $request );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	public function test_rejects_missing_signature_headers_when_secret_configured(): void {
		Functions\expect( 'get_option' )->once()->andReturn( 'shhh' );

		$result = $this->verify( new WP_REST_Request() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 401, $result->get_error_data()['status'] );
	}

	// -------------------------------------------------------------------
	// Archive URL resolution (SSRF allowlist)
	// -------------------------------------------------------------------

	private function resolve( array $payload ) {
		$method = new ReflectionMethod( GitWebhook::class, 'resolve_archive_url' );
		$method->setAccessible( true );
		return $method->invoke( new GitWebhook(), $payload );
	}

	private function stub_default_allowlist(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default ) {
				return $default;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );
	}

	public function test_arbitrary_archive_url_is_rejected_by_default(): void {
		$this->stub_default_allowlist();

		$result = $this->resolve( [ 'wmac_archive_url' => 'https://internal-service.local/secrets.zip' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_archive_host_not_allowed', $result->get_error_code() );
	}

	public function test_non_https_archive_url_is_rejected(): void {
		$this->stub_default_allowlist();

		$result = $this->resolve( [ 'wmac_archive_url' => 'http://api.github.com/repos/a/b/zipball/main' ] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_archive_url_invalid', $result->get_error_code() );
	}

	public function test_archive_url_allowed_when_host_is_allowlisted_via_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( string $tag, $default ) {
				if ( $tag === 'wmac_git_archive_hosts' ) {
					$default[] = 'ci.example.com';
				}
				return $default;
			}
		);
		Functions\when( 'esc_url_raw' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_parse_url' )->alias( 'parse_url' );

		$result = $this->resolve( [ 'wmac_archive_url' => 'https://ci.example.com/build.zip' ] );

		$this->assertSame( 'https://ci.example.com/build.zip', $result );
	}

	public function test_github_payload_resolves_to_github_api(): void {
		$this->stub_default_allowlist();

		$result = $this->resolve( [
			'repository' => [ 'full_name' => 'acme/site' ],
			'ref'        => 'refs/heads/main',
		] );

		$this->assertSame( 'https://api.github.com/repos/acme/site/zipball/main', $result );
	}

	public function test_github_payload_with_malformed_repo_is_rejected(): void {
		$this->stub_default_allowlist();

		$result = $this->resolve( [
			'repository' => [ 'full_name' => 'acme/site/../../evil' ],
			'ref'        => 'refs/heads/main',
		] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_webhook_bad_repo', $result->get_error_code() );
	}

	public function test_self_hosted_gitlab_is_rejected_until_allowlisted(): void {
		$this->stub_default_allowlist();

		$result = $this->resolve( [
			'project' => [ 'http_url_to_repo' => 'https://gitlab.internal.corp/team/site.git' ],
			'ref'     => 'refs/heads/main',
		] );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'wmac_archive_host_not_allowed', $result->get_error_code() );
	}
}
