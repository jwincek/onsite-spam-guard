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

	public function test_duplicate_records_only_on_commit(): void {
		$guard = new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] );
		$data  = [ 'content' => 'hello', 'author' => 'A', 'email' => 'a@example.com' ];

		// Checking alone leaves no trace, however many times it happens.
		$this->assertTrue( $guard->check( $data, 'comment' ) );
		$this->assertTrue( $guard->check( $data, 'comment' ) );

		// Only an accepted submission is recorded.
		$guard->commit( $data, 'comment' );
		$this->assertInstanceOf( WP_Error::class, $guard->check( $data, 'comment' ) );
	}

	public function test_rate_limit_counts_every_attempt_including_rejected_ones(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max'] = 2;
		$guard = new Rate_Limit( 'rate_limit', [ 'max_per_window' => 2, 'window_seconds' => 60 ] );

		// The limiter throttles a sender by attempts, so no commit is involved.
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertTrue( $guard->check( [], 'comment' ) );
		$this->assertInstanceOf( WP_Error::class, $guard->check( [], 'comment' ) );
	}

	public function test_rate_limit_keeps_failing_once_the_window_is_spent(): void {
		$guard = new Rate_Limit( 'rate_limit', [ 'max_per_window' => 1, 'window_seconds' => 60 ] );
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max'] = 1;

		$guard->check( [], 'comment' );   // consume the single slot

		$first  = $guard->check( [], 'comment' );
		$second = $guard->check( [], 'comment' );

		$this->assertInstanceOf( WP_Error::class, $first );
		$this->assertInstanceOf( WP_Error::class, $second );
		$this->assertSame( $first->get_error_code(), $second->get_error_code() );
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
	 * A submission rejected by another guard must not land in the duplicate
	 * cache — otherwise the visitor fixes the problem, resubmits, and is
	 * refused as a duplicate of their own blocked attempt, naming the wrong
	 * reason. This is the regression #26 was filed for.
	 */
	public function test_a_blocked_submission_is_not_recorded_as_a_duplicate(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_enabled']  = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 3;

		// Tripped by link_limit (weight 70), well below duplicate (95).
		$spammy = [
			'content'                        => 'hi http://a.example http://b.example http://c.example http://d.example',
			'simple_spam_shield_website_url' => '',
			'simple_spam_shield_form_loaded' => $this->token(),
		];

		$first = Guard_Runner::run( $spammy, 'comment' );
		$this->assertSame( 'simple_spam_shield_link_limit_failed', $first->get_error_code() );

		// Resubmitting the identical text must still name the real problem.
		$second = Guard_Runner::run( $spammy, 'comment' );
		$this->assertSame(
			'simple_spam_shield_link_limit_failed',
			$second->get_error_code(),
			'a blocked submission was recorded in the duplicate cache'
		);

		// And the corrected version goes through.
		$fixed = [
			'content'                        => 'hi http://a.example',
			'simple_spam_shield_website_url' => '',
			'simple_spam_shield_form_loaded' => $this->token(),
		];
		$this->assertTrue( Guard_Runner::run( $fixed, 'comment' ) );
	}

	/**
	 * The inverse, and the reason Rate_Limit is not moved to commit(): a sender
	 * flooding the form is overwhelmingly being rejected by some other guard.
	 * Counting only accepted submissions would let a bot flood forever while
	 * still charging a legitimate visitor for a single mistake.
	 */
	public function test_a_flooder_blocked_by_another_guard_still_consumes_rate_limit_budget(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_max']     = 3;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_rate_limit_enabled'] = true;

		// Every attempt trips the honeypot (weight 100), above rate_limit (85).
		$flood = [
			'content'                        => 'flood',
			'simple_spam_shield_website_url' => 'http://bot.example',
			'simple_spam_shield_form_loaded' => $this->token(),
		];
		for ( $i = 0; $i < 3; $i++ ) {
			$this->assertSame(
				'simple_spam_shield_honeypot_failed',
				Guard_Runner::run( $flood, 'comment' )->get_error_code()
			);
		}

		// The honeypot keeps deciding the verdict for the bot's own traffic, as
		// it should — it outranks the limiter. What proves the attempts were
		// counted is that the sender's budget is now spent, so even a submission
		// with nothing wrong with it is throttled.
		$clean = [
			'content'                        => 'an ordinary comment',
			'simple_spam_shield_website_url' => '',
			'simple_spam_shield_form_loaded' => $this->token(),
		];
		$result = Guard_Runner::run( $clean, 'comment' );
		$this->assertSame(
			'simple_spam_shield_rate_limit_failed',
			$result->get_error_code(),
			'a flooding sender was never charged for rejected attempts'
		);
	}

}
