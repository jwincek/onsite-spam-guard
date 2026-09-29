<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Monitor_Review;

/**
 * The review of a monitored form (#57): timing each spell of monitoring,
 * summarizing what enforcing would catch, and switching to Enforce. The
 * settings-page save, the links and the admin-post handler are exercised
 * end to end in tests/smoke.
 */
final class MonitorReviewTest extends TestCase {

	private const SITE        = 'simple_spam_shield_monitor_mode';
	private const FORM        = 'simple_spam_shield_monitor_mode__comment';
	private const SITE_SINCE  = 'simple_spam_shield_monitor_since';
	private const FORM_SINCE  = 'simple_spam_shield_monitor_since__comment';
	private const A_WEEK_AGO  = 1790000000;

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options'] = [];
		$GLOBALS['simple_spam_shield_test_filters'] = [];
		$GLOBALS['simple_spam_shield_test_queries'] = [];
		$GLOBALS['simple_spam_shield_test_results'] = [];
	}

	private function options(): array {
		return $GLOBALS['simple_spam_shield_test_options'];
	}

	private function render( string $context = 'comment' ): string {
		ob_start();
		Monitor_Review::render( $context );
		return (string) ob_get_clean();
	}

	// --- timing -------------------------------------------------------------

	public function test_turning_site_wide_monitoring_on_starts_the_clock(): void {
		Monitor_Review::on_updated_option( self::SITE, false, true );

		$this->assertEqualsWithDelta( time(), $this->options()[ self::SITE_SINCE ], 5 );
	}

	public function test_saving_again_with_monitoring_still_on_keeps_the_start(): void {
		$GLOBALS['simple_spam_shield_test_options'][ self::SITE_SINCE ] = self::A_WEEK_AGO;

		Monitor_Review::on_updated_option( self::SITE, '1', '1' );

		$this->assertSame( self::A_WEEK_AGO, $this->options()[ self::SITE_SINCE ] );
	}

	public function test_turning_monitoring_off_clears_the_start(): void {
		$GLOBALS['simple_spam_shield_test_options'][ self::SITE_SINCE ] = self::A_WEEK_AGO;

		Monitor_Review::on_updated_option( self::SITE, '1', '' );

		$this->assertArrayNotHasKey( self::SITE_SINCE, $this->options() );
	}

	public function test_a_form_is_timed_under_its_own_option(): void {
		Monitor_Review::on_added_option( self::FORM, 'monitor' );
		$this->assertArrayHasKey( self::FORM_SINCE, $this->options() );

		Monitor_Review::on_updated_option( self::FORM, 'monitor', 'enforce' );
		$this->assertArrayNotHasKey( self::FORM_SINCE, $this->options() );
	}

	public function test_other_options_are_ignored(): void {
		Monitor_Review::on_updated_option( 'simple_spam_shield_link_limit_max__comment', '3', 'monitor' );
		Monitor_Review::on_updated_option( self::SITE_SINCE, false, self::A_WEEK_AGO );

		$this->assertSame( [], $this->options() );
	}

	// --- which clock applies --------------------------------------------------

	public function test_a_monitored_form_uses_its_own_start(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor', self::FORM_SINCE => (string) self::A_WEEK_AGO, self::SITE => true, self::SITE_SINCE => 1 ];

		$this->assertSame( self::A_WEEK_AGO, Monitor_Review::since( 'comment' ) );
	}

	public function test_an_inheriting_form_uses_the_site_wide_start(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::SITE => true, self::SITE_SINCE => self::A_WEEK_AGO ];

		$this->assertSame( self::A_WEEK_AGO, Monitor_Review::since( 'comment' ) );
	}

	public function test_an_enforced_or_unmonitored_form_has_no_start(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'enforce', self::SITE => true, self::SITE_SINCE => self::A_WEEK_AGO ];
		$this->assertNull( Monitor_Review::since( 'comment' ) );

		$GLOBALS['simple_spam_shield_test_options'] = [];
		$this->assertNull( Monitor_Review::since( 'comment' ) );
	}

	public function test_monitoring_begun_before_it_was_recorded_has_no_start(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor' ];

		$this->assertNull( Monitor_Review::since( 'comment' ) );
	}

	// --- the count ------------------------------------------------------------

	public function test_the_count_is_limited_to_this_spell_of_monitoring(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor', self::FORM_SINCE => self::A_WEEK_AGO ];
		$GLOBALS['simple_spam_shield_test_results'] = [ (object) [ 'guard' => 'keyword_block', 'hits' => '10' ], (object) [ 'guard' => 'time_gate', 'hits' => '4' ] ];

		$summary = Monitor_Review::summary( 'comment' );

		$this->assertSame( 14, $summary['total'] );
		$this->assertSame( [ 'keyword_block' => 10, 'time_gate' => 4 ], $summary['by_guard'] );
		$query = $GLOBALS['simple_spam_shield_test_queries'][0];
		$this->assertStringContainsString( 'blocked_at >= %s', $query['query'] );
		$this->assertSame( [ 'comment', 'monitored', gmdate( 'Y-m-d H:i:s', self::A_WEEK_AGO ) ], $query['args'] );
	}

	public function test_without_a_start_the_count_covers_the_whole_log(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor' ];

		Monitor_Review::summary( 'comment' );

		$query = $GLOBALS['simple_spam_shield_test_queries'][0];
		$this->assertStringNotContainsString( 'blocked_at', $query['query'] );
		$this->assertSame( [ 'comment', 'monitored' ], $query['args'] );
	}

	// --- enforce ----------------------------------------------------------------

	public function test_enforce_switches_a_form_to_enforce(): void {
		$this->assertTrue( Monitor_Review::enforce( 'comment' ) );
		$this->assertSame( 'enforce', $this->options()[ self::FORM ] );
	}

	public function test_enforce_refuses_an_unknown_form(): void {
		$this->assertFalse( Monitor_Review::enforce( 'no_such_form' ) );
		$this->assertSame( [], $this->options() );
	}

	// --- what the Per-form tab shows ------------------------------------------

	public function test_nothing_is_shown_for_an_enforced_form(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_the_review_names_the_checks_and_links_onward(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor', self::FORM_SINCE => time() - 6 * 86400 ];
		$GLOBALS['simple_spam_shield_test_results'] = [ (object) [ 'guard' => 'keyword_block', 'hits' => '10' ], (object) [ 'guard' => 'time_gate', 'hits' => '4' ] ];

		$html = $this->render();

		$this->assertStringContainsString( '(6 days)', $html );
		$this->assertStringContainsString( '14 submissions would have been blocked: Blocked keywords 10, Minimum submission time 4.', $html );
		$this->assertStringContainsString( 'page=onsite-spam-guard-spam-logs&amp;filter_context=comment&amp;filter_outcome=monitored', $html );
		$this->assertStringContainsString( 'admin-post.php?action=simple_spam_shield_enforce_form&amp;context=comment&amp;_wpnonce=nonce-simple_spam_shield_enforce_form', $html );
	}

	public function test_a_quiet_form_says_so_and_offers_only_enforce(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor', self::FORM_SINCE => time() - 86400 ];

		$html = $this->render();

		$this->assertStringContainsString( 'Nothing would have been blocked.', $html );
		$this->assertStringNotContainsString( 'View them', $html );
		$this->assertStringContainsString( 'Enforce this form', $html );
	}

	public function test_with_logging_off_it_does_not_claim_nothing_was_caught(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor', self::FORM_SINCE => time() - 86400, 'simple_spam_shield_log_blocked' => false ];

		$html = $this->render();

		$this->assertStringContainsString( 'Logging is off', $html );
		$this->assertStringNotContainsString( 'Nothing would have been blocked', $html );
	}

	public function test_an_unrecorded_start_is_admitted_not_invented(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ self::FORM => 'monitor' ];

		$this->assertStringContainsString( 'When it began was not recorded', $this->render() );
	}
}
