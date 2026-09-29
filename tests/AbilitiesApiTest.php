<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Integrations\Abilities_API;

/**
 * Read-only abilities (#9). What the unit tests can reach: the mapping from
 * log data to the public output, the input handling, the permission check,
 * and doing nothing where the Abilities API does not exist. Registration and
 * core's schema validation run against real WordPress in tests/smoke.
 */
final class AbilitiesApiTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_actions']    = [];
		$GLOBALS['simple_spam_shield_test_caps']       = [];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$GLOBALS['simple_spam_shield_test_queries']    = [];
		$GLOBALS['simple_spam_shield_test_results']    = [];
		$GLOBALS['simple_spam_shield_test_var']        = null;
	}

	private function row( array $fields = [] ): object {
		return (object) array_merge(
			[
				'id'             => '7',
				'blocked_at'     => '2026-09-29 18:02:11',
				'guard'          => 'honeypot',
				'guards_matched' => 'honeypot,time_gate',
				'outcome'        => 'blocked',
				'context'        => 'comment',
				'reason'         => 'Submission rejected.',
				'content'        => 'Buy cheap things at my shop',
				'ip_address'     => '203.0.113.9',
				'user_agent'     => 'Mozilla/5.0 (probe)',
			],
			$fields
		);
	}

	// --- entries ------------------------------------------------------------

	public function test_an_entry_carries_only_the_six_public_fields(): void {
		$entry = Abilities_API::entry( $this->row() );

		$this->assertSame( [ 'logged_at', 'outcome', 'context', 'guard', 'guards_matched', 'reason' ], array_keys( $entry ) );
	}

	public function test_an_entry_never_carries_personal_data(): void {
		$flat = serialize( Abilities_API::entry( $this->row() ) );

		$this->assertStringNotContainsString( '203.0.113.9', $flat );
		$this->assertStringNotContainsString( 'probe', $flat );
		$this->assertStringNotContainsString( 'cheap things', $flat );
	}

	public function test_a_column_added_to_the_log_later_is_not_exposed(): void {
		$entry = Abilities_API::entry( $this->row( [ 'referrer' => 'https://private.example/' ] ) );

		$this->assertArrayNotHasKey( 'referrer', $entry );
	}

	public function test_the_time_is_iso_8601_in_utc(): void {
		$this->assertSame( '2026-09-29T18:02:11+00:00', Abilities_API::entry( $this->row() )['logged_at'] );
	}

	public function test_guards_matched_becomes_a_list(): void {
		$this->assertSame( [ 'honeypot', 'time_gate' ], Abilities_API::entry( $this->row() )['guards_matched'] );
	}

	public function test_a_row_from_before_guards_matched_reports_its_guard(): void {
		$entry = Abilities_API::entry( $this->row( [ 'guards_matched' => '' ] ) );

		$this->assertSame( [ 'honeypot' ], $entry['guards_matched'] );
	}

	public function test_an_unrecognised_outcome_reads_as_blocked(): void {
		// Rows written before the 1.3 schema default to 'blocked' in the
		// database; anything else unexpected is reported the same way.
		$this->assertSame( 'blocked', Abilities_API::entry( $this->row( [ 'outcome' => '' ] ) )['outcome'] );
		$this->assertSame( 'monitored', Abilities_API::entry( $this->row( [ 'outcome' => 'monitored' ] ) )['outcome'] );
	}

	// --- recent-blocks ------------------------------------------------------

	private function logs_query(): array {
		foreach ( $GLOBALS['simple_spam_shield_test_queries'] as $query ) {
			if ( str_contains( $query['query'], 'LIMIT %d OFFSET %d' ) ) {
				return $query;
			}
		}
		$this->fail( 'get_logs() was not queried.' );
	}

	public function test_recent_blocks_with_no_input_returns_the_default_twenty(): void {
		$GLOBALS['simple_spam_shield_test_results'] = [ $this->row(), $this->row( [ 'outcome' => 'monitored' ] ) ];
		$GLOBALS['simple_spam_shield_test_var']     = '42';

		$result = Abilities_API::recent_blocks();

		$this->assertCount( 2, $result['entries'] );
		$this->assertSame( 42, $result['total'] );
		$this->assertSame( [ 20, 0 ], $this->logs_query()['args'] );
	}

	public function test_recent_blocks_clamps_the_limit_for_direct_callers(): void {
		// Core validates input against the schema before execute, so these
		// only reach the callback when it is called directly.
		Abilities_API::recent_blocks( [ 'limit' => 5000 ] );
		$this->assertSame( [ 100, 0 ], $this->logs_query()['args'] );

		$GLOBALS['simple_spam_shield_test_queries'] = [];
		Abilities_API::recent_blocks( [ 'limit' => -3 ] );
		$this->assertSame( [ 1, 0 ], $this->logs_query()['args'] );
	}

	public function test_recent_blocks_filters_by_outcome(): void {
		Abilities_API::recent_blocks( [ 'outcome' => 'monitored' ] );

		$query = $this->logs_query();
		$this->assertStringContainsString( 'outcome = %s', $query['query'] );
		$this->assertSame( [ 'monitored', 20, 0 ], $query['args'] );
	}

	public function test_recent_blocks_ignores_an_unknown_outcome(): void {
		Abilities_API::recent_blocks( [ 'outcome' => 'everything' ] );

		$this->assertSame( [ 20, 0 ], $this->logs_query()['args'] );
	}

	// --- stats --------------------------------------------------------------

	public function test_stats_renames_the_internal_keys(): void {
		$GLOBALS['simple_spam_shield_test_transients']['simple_spam_shield_stats'] = [
			'week_total'     => 12,
			'week_monitored' => 3,
			'top_guard'      => 'time_gate',
			'top_count'      => 9,
		];

		$this->assertSame(
			[
				'days'              => 7,
				'blocked'           => 12,
				'monitored'         => 3,
				'top_guard'         => 'time_gate',
				'top_guard_blocked' => 9,
			],
			Abilities_API::stats()
		);
	}

	public function test_summary_survives_missing_keys(): void {
		$this->assertSame(
			[
				'days'              => 7,
				'blocked'           => 0,
				'monitored'         => 0,
				'top_guard'         => '',
				'top_guard_blocked' => 0,
			],
			Abilities_API::summary( [] )
		);
	}

	// --- permission and registration ----------------------------------------

	public function test_only_administrators_can_read(): void {
		$this->assertFalse( Abilities_API::can_read() );

		$GLOBALS['simple_spam_shield_test_caps']['manage_options'] = true;
		$this->assertTrue( Abilities_API::can_read() );
	}

	public function test_init_hooks_the_abilities_api_actions(): void {
		Abilities_API::init();

		$this->assertArrayHasKey( 'wp_abilities_api_categories_init', $GLOBALS['simple_spam_shield_test_actions'] );
		$this->assertArrayHasKey( 'wp_abilities_api_init', $GLOBALS['simple_spam_shield_test_actions'] );
	}

	public function test_registration_does_nothing_without_the_abilities_api(): void {
		// The test environment, like WordPress before 6.9, has no
		// wp_register_ability(). Both callbacks must return quietly.
		$this->assertFalse( function_exists( 'wp_register_ability' ) );

		Abilities_API::register_category();
		Abilities_API::register_abilities();

		$this->addToAssertionCount( 1 );
	}
}
