<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Database_Manager;
use Simple_Spam_Shield\Core\Guard_Runner;

/** Monitor mode: log what would be blocked, block nothing (#48). */
final class MonitorModeTest extends TestCase {

	private const SECRET = 'mmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmmm';

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options']               = [
			'simple_spam_shield_enabled'               => true,
			'simple_spam_shield_log_blocked'           => true,
			'simple_spam_shield_keyword_block_enabled' => true,
			'simple_spam_shield_blocked_keywords'      => 'casino',
			'simple_spam_shield_token_secret'          => self::SECRET,
		];
		$GLOBALS['simple_spam_shield_test_transients']            = [];
		$GLOBALS['simple_spam_shield_test_transient_expirations'] = [];
		$GLOBALS['simple_spam_shield_test_filters']               = [];
		$GLOBALS['simple_spam_shield_test_actions']               = [];
		$GLOBALS['simple_spam_shield_test_log_rows']              = [];
		$_SERVER['REMOTE_ADDR']                                   = '198.51.100.70';

		Guard_Runner::init();
	}

	/** A genuine signed token, so the form-origin guards pass and content decides. */
	private function token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	private function spam(): array {
		return [
			'content'                        => 'visit my casino today',
			'author'                         => 'Bot',
			'email'                          => 'bot@example.com',
			'simple_spam_shield_form_loaded' => $this->token(),
		];
	}

	private function lastRow(): array {
		$rows = $GLOBALS['simple_spam_shield_test_log_rows'];
		return (array) end( $rows );
	}

	// --- default: nothing changes ------------------------------------------

	public function test_without_monitor_mode_spam_is_blocked_and_logged_as_blocked(): void {
		$this->assertInstanceOf( WP_Error::class, Guard_Runner::run( $this->spam(), 'comment' ) );
		$this->assertSame( Database_Manager::OUTCOME_BLOCKED, $this->lastRow()['outcome'] );
	}

	// --- site-wide monitor mode --------------------------------------------

	public function test_site_wide_monitor_mode_lets_spam_through_and_logs_it(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode'] = true;

		$this->assertTrue( Guard_Runner::run( $this->spam(), 'comment' ) );

		$row = $this->lastRow();
		$this->assertSame( Database_Manager::OUTCOME_MONITORED, $row['outcome'] );
		$this->assertSame( 'keyword_block', $row['guard'], 'the log still records which guard would have decided' );
	}

	/**
	 * `simple_spam_shield_blocked` promises a block. Firing it for traffic that
	 * was let through would make a listener — one banning addresses, say — act
	 * on submissions the site accepted.
	 */
	public function test_the_blocked_action_does_not_fire_for_monitored_traffic(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode'] = true;

		$fired = 0;
		add_action( 'simple_spam_shield_blocked', static function () use ( &$fired ): void {
			++$fired;
		}, 10, 4 );

		Guard_Runner::run( $this->spam(), 'comment' );

		$this->assertSame( 0, $fired );
	}

	public function test_the_blocked_action_still_fires_for_a_real_block(): void {
		$fired = 0;
		add_action( 'simple_spam_shield_blocked', static function () use ( &$fired ): void {
			++$fired;
		}, 10, 4 );

		Guard_Runner::run( $this->spam(), 'comment' );

		$this->assertSame( 1, $fired );
	}

	/** A monitored submission was accepted, so state-holding guards record it. */
	public function test_a_monitored_submission_is_committed_like_an_accepted_one(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode']      = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_enabled'] = true;

		Guard_Runner::run( $this->spam(), 'comment' );

		$recorded = array_filter(
			array_keys( $GLOBALS['simple_spam_shield_test_transients'] ),
			static fn( $key ) => str_starts_with( $key, 'simple_spam_shield_dup_' )
		);
		$this->assertNotEmpty( $recorded, 'the duplicate guard should have recorded the accepted submission' );
	}

	// --- per-form override --------------------------------------------------

	public function test_one_form_can_be_monitored_while_the_rest_are_enforced(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode__contact_form_7'] = 'monitor';

		$this->assertTrue( Guard_Runner::run( $this->spam(), 'contact_form_7' ) );
		$this->assertInstanceOf( WP_Error::class, Guard_Runner::run( $this->spam(), 'comment' ) );
	}

	public function test_one_form_can_be_enforced_while_the_rest_are_monitored(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode']           = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode__comment'] = 'enforce';

		$this->assertInstanceOf( WP_Error::class, Guard_Runner::run( $this->spam(), 'comment' ) );
		$this->assertTrue( Guard_Runner::run( $this->spam(), 'contact_form_7' ) );
	}

	public function test_a_blank_override_inherits_the_site_wide_setting(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode']           = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode__comment'] = '';

		$this->assertTrue( Guard_Runner::is_monitoring( 'comment' ) );
	}

	// --- storage -------------------------------------------------------------

	/** Anything other than the monitored marker is stored as a real block. */
	public function test_an_unrecognised_outcome_is_stored_as_blocked(): void {
		Database_Manager::insert( [ 'guard' => 'honeypot', 'context' => 'comment', 'outcome' => 'let-me-through' ] );

		$this->assertSame( Database_Manager::OUTCOME_BLOCKED, $this->lastRow()['outcome'] );
	}

	/** A clean submission is unaffected and logs nothing. */
	public function test_a_clean_submission_logs_nothing_in_monitor_mode(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode'] = true;

		$this->assertTrue(
			Guard_Runner::run(
				[ 'content' => 'a lovely post', 'simple_spam_shield_form_loaded' => $this->token() ],
				'comment'
			)
		);
		$this->assertSame( [], $GLOBALS['simple_spam_shield_test_log_rows'] );
	}
}
