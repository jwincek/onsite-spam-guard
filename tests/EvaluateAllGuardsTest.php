<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Duplicate;
use Simple_Spam_Shield\Guards\Rate_Limit;

/**
 * The runner keeps its short-circuit for the *verdict* but keeps evaluating
 * for the *record*, so the log can show every guard that matched.
 *
 * The contract that makes this safe: guards evaluated after the outcome is
 * decided run in observe-only mode and must not change state.
 */
final class EvaluateAllGuardsTest extends TestCase {

	private const SECRET = 'eeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeeee';

	public static function setUpBeforeClass(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		Guard_Runner::init();
	}

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [
			'simple_spam_shield_enabled'           => true,
			'simple_spam_shield_log_blocked'       => false,
			'simple_spam_shield_token_secret'      => self::SECRET,
			'simple_spam_shield_blocked_keywords'  => 'casino',
			'simple_spam_shield_link_limit_max'    => 2,
		];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$GLOBALS['simple_spam_shield_test_user_id']    = 0;
		$_SERVER['REMOTE_ADDR']                        = '198.51.100.5';
		$_POST                                         = [];
	}

	private function token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	// --- the guards themselves -------------------------------------------

	public function test_duplicate_does_not_record_the_submission_when_observing(): void {
		$guard = new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] );
		$data  = [ 'content' => 'hello', 'author' => 'A', 'email' => 'a@example.com' ];

		$this->assertTrue( $guard->check( $data, 'comment', true ) );
		// Observing must leave no trace, so the same content still passes.
		$this->assertTrue( $guard->check( $data, 'comment', true ) );

		// A real (non-observing) pass does record it.
		$this->assertTrue( $guard->check( $data, 'comment' ) );
		$this->assertInstanceOf( WP_Error::class, $guard->check( $data, 'comment' ) );
	}

	public function test_rate_limit_does_not_consume_budget_when_observing(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max'] = 2;
		$guard = new Rate_Limit( 'rate_limit', [ 'max_per_window' => 2, 'window_seconds' => 60 ] );

		for ( $i = 0; $i < 10; $i++ ) {
			$this->assertTrue( $guard->check( [], 'comment', true ), "observation {$i} consumed budget" );
		}

		// Budget is untouched, so two real submissions still pass.
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertInstanceOf( WP_Error::class, $guard->check( [], 'comment' ) );
	}

	public function test_verdict_is_identical_in_both_modes(): void {
		$guard = new Rate_Limit( 'rate_limit', [ 'max_per_window' => 1, 'window_seconds' => 60 ] );
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max'] = 1;

		$guard->check( [], 'comment' );                    // consume the single slot
		$observed = $guard->check( [], 'comment', true );
		$real     = $guard->check( [], 'comment' );

		$this->assertInstanceOf( WP_Error::class, $observed );
		$this->assertInstanceOf( WP_Error::class, $real );
		$this->assertSame( $real->get_error_code(), $observed->get_error_code() );
	}

	// --- the runner -------------------------------------------------------

	/** A submission tripping several guards is still decided by the first. */
	public function test_highest_weight_guard_still_decides_the_outcome(): void {
		$data = [
			'content'                        => 'visit my casino http://a.example http://b.example http://c.example',
			'simple_spam_shield_website_url' => 'http://bot.example',   // honeypot, weight 100
			'simple_spam_shield_form_loaded' => $this->token(),
		];

		$result = Guard_Runner::run( $data, 'comment' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'simple_spam_shield_honeypot_failed', $result->get_error_code() );
	}

	/** A clean submission is unaffected by the change. */
	public function test_clean_submission_still_passes(): void {
		$data = [
			'content'                        => 'a perfectly ordinary comment',
			'simple_spam_shield_website_url' => '',
			'simple_spam_shield_form_loaded' => $this->token(),
		];

		$this->assertTrue( Guard_Runner::run( $data, 'comment' ) );
	}

	/**
	 * A blocked submission must not consume rate-limit budget on its way out.
	 * Before observe-only this was impossible to get wrong, because the
	 * rate-limit guard never ran once an earlier guard had blocked.
	 */
	public function test_blocked_submission_does_not_consume_rate_limit_budget(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max']     = 2;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_enabled'] = true;

		$blocked = [
			'content'                        => 'hello',
			'simple_spam_shield_website_url' => 'http://bot.example',
			'simple_spam_shield_form_loaded' => $this->token(),
		];
		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertInstanceOf( WP_Error::class, Guard_Runner::run( $blocked, 'comment' ) );
		}

		$clean = [
			'content'                        => 'an ordinary comment',
			'simple_spam_shield_website_url' => '',
			'simple_spam_shield_form_loaded' => $this->token(),
		];
		$this->assertTrue( Guard_Runner::run( $clean, 'comment' ) );
	}
}
