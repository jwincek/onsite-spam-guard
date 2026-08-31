<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Guards\Duplicate;
use Simple_Spam_Shield\Guards\Rate_Limit;

/**
 * The duplicate and rate-limit windows are settable (#19).
 *
 * These assert the expiration that actually reaches set_transient(), not just
 * the stored option, because the window is only observable through it.
 */
final class ConfigurableWindowsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']              = [];
		$GLOBALS['simple_spam_shield_test_transients']           = [];
		$GLOBALS['simple_spam_shield_test_transient_expirations'] = [];
		$_SERVER['REMOTE_ADDR']                                  = '198.51.100.4';
	}

	/** @return array<string,int> */
	private function expirations(): array {
		return $GLOBALS['simple_spam_shield_test_transient_expirations'];
	}

	private function data(): array {
		return [ 'content' => 'hello', 'author' => 'A', 'email' => 'a@example.com' ];
	}

	// --- duplicate ---------------------------------------------------------

	public function test_duplicate_window_defaults_to_the_config_value(): void {
		( new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] ) )->commit( $this->data(), 'comment' );

		$this->assertSame( [ 60 ], array_values( $this->expirations() ) );
	}

	public function test_duplicate_window_is_taken_from_the_setting(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_window_seconds'] = 5;

		( new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] ) )->commit( $this->data(), 'comment' );

		$this->assertSame( [ 5 ], array_values( $this->expirations() ) );
	}

	// --- rate limit --------------------------------------------------------

	public function test_rate_limit_window_is_taken_from_the_setting(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max']            = 5;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_window_seconds'] = 3600;

		( new Rate_Limit( 'rate_limit', [ 'max_per_window' => 5, 'window_seconds' => 60 ] ) )->check( [], 'comment' );

		$this->assertSame( [ 3600 ], array_values( $this->expirations() ), '"20 per hour" must be expressible' );
	}

	public function test_rate_limit_window_defaults_to_the_config_value(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max'] = 5;

		( new Rate_Limit( 'rate_limit', [ 'max_per_window' => 5, 'window_seconds' => 60 ] ) )->check( [], 'comment' );

		$this->assertSame( [ 60 ], array_values( $this->expirations() ) );
	}

	// --- the hazard --------------------------------------------------------

	/**
	 * set_transient() treats 0 as "no expiration". A zero window would make a
	 * duplicate block permanent and a throttled sender throttled forever, so
	 * neither guard may ever pass 0 through, whatever is stored.
	 *
	 * @dataProvider unusableWindows
	 */
	public function test_an_unusable_stored_window_never_becomes_a_permanent_block( $stored ): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_window_seconds']  = $stored;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_window_seconds'] = $stored;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max']            = 5;

		( new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] ) )->commit( $this->data(), 'comment' );
		( new Rate_Limit( 'rate_limit', [ 'max_per_window' => 5, 'window_seconds' => 60 ] ) )->check( [], 'comment' );

		foreach ( $this->expirations() as $key => $expiration ) {
			$this->assertGreaterThan(
				0,
				$expiration,
				"stored window " . var_export( $stored, true ) . " produced a non-expiring transient: {$key}"
			);
		}
	}

	/** @return array<string,array{0:mixed}> */
	public static function unusableWindows(): array {
		return [
			'zero'            => [ 0 ],
			'negative'        => [ -30 ],
			'empty string'    => [ '' ],
			'non-numeric'     => [ 'never' ],
		];
	}
}
