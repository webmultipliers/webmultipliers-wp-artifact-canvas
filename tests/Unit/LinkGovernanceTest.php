<?php

declare( strict_types=1 );

namespace WebMultipliers\ArtifactCanvas\Tests\Unit;

use Brain\Monkey\Functions;
use WebMultipliers\ArtifactCanvas\LinkGovernance;
use WP_Post;

final class LinkGovernanceTest extends TestCase {

	private function post(): WP_Post {
		return new WP_Post( [ 'ID' => 99 ] );
	}

	public function test_logged_in_users_are_never_expired(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( true );
		Functions\expect( 'get_post_meta' )->never();

		$this->assertFalse( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}

	public function test_not_expired_with_no_governance_meta(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single ) {
					return $key === LinkGovernance::MAX_VIEWS ? 0 : '';
				}
			);

		$this->assertFalse( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}

	public function test_expired_by_past_date(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single ) {
					if ( $key === LinkGovernance::EXPIRES_AT ) {
						return '2020-01-01T00:00:00Z';
					}
					return $key === LinkGovernance::MAX_VIEWS ? 0 : '';
				}
			);

		$this->assertTrue( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}

	public function test_not_expired_by_future_date(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single ) {
					if ( $key === LinkGovernance::EXPIRES_AT ) {
						return gmdate( 'c', time() + YEAR_IN_SECONDS );
					}
					return $key === LinkGovernance::MAX_VIEWS ? 0 : '';
				}
			);

		$this->assertFalse( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}

	public function test_invalid_date_string_does_not_expire(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single ) {
					if ( $key === LinkGovernance::EXPIRES_AT ) {
						return 'not-a-real-date';
					}
					return $key === LinkGovernance::MAX_VIEWS ? 0 : '';
				}
			);

		$this->assertFalse( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}

	public function test_expired_when_view_count_reaches_max(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single ) {
					return match ( $key ) {
						LinkGovernance::EXPIRES_AT => '',
						LinkGovernance::MAX_VIEWS => 5,
						LinkGovernance::VIEW_COUNT => 5,
						default => '',
					};
				}
			);

		$this->assertTrue( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}

	public function test_not_expired_when_view_count_below_max(): void {
		Functions\expect( 'is_user_logged_in' )->once()->andReturn( false );
		Functions\expect( 'get_post_meta' )
			->andReturnUsing(
				static function ( int $id, string $key, bool $single ) {
					return match ( $key ) {
						LinkGovernance::EXPIRES_AT => '',
						LinkGovernance::MAX_VIEWS => 5,
						LinkGovernance::VIEW_COUNT => 4,
						default => '',
					};
				}
			);

		$this->assertFalse( ( new LinkGovernance() )->is_expired( $this->post() ) );
	}
}
