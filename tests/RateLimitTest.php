<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Guards\Rate_Limit;

final class RateLimitTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [ 'simple_spam_shield_rate_limit_max' => 3 ];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$GLOBALS['simple_spam_shield_test_user_id']    = 0;
		$_SERVER['REMOTE_ADDR']                        = '198.51.100.10';
	}

	private function guard(): Rate_Limit {
		return new Rate_Limit( 'rate_limit', [ 'max_per_window' => 3, 'window_seconds' => 60 ] );
	}

	public function test_allows_up_to_the_limit_then_blocks(): void {
		$guard = $this->guard();
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertInstanceOf( WP_Error::class, $guard->check( [], 'comment' ) );
	}

	public function test_separate_senders_are_tracked_independently(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < 3; $i++ ) {
			$guard->check( [], 'comment' );
		}
		$this->assertInstanceOf( WP_Error::class, $guard->check( [], 'comment' ) );

		// A logged-in user is a different sender than the anonymous IP.
		$GLOBALS['simple_spam_shield_test_user_id'] = 7;
		$this->assertTrue( $guard->check( [], 'comment' ) );
	}

	public function test_different_context_is_a_separate_bucket(): void {
		$guard = $this->guard();
		for ( $i = 0; $i < 3; $i++ ) {
			$guard->check( [], 'comment' );
		}
		$this->assertInstanceOf( WP_Error::class, $guard->check( [], 'comment' ) );
		$this->assertTrue( $guard->check( [], 'bp_message' ) );
	}

	public function test_disabled_when_max_is_zero(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max'] = 0;
		$guard = new Rate_Limit( 'rate_limit', [ 'max_per_window' => 0 ] );
		for ( $i = 0; $i < 20; $i++ ) {
			$this->assertTrue( $guard->check( [], 'comment' ) );
		}
	}
}
