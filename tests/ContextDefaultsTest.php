<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Contexts;
use Simple_Spam_Shield\Guards\Link_Limit;

/**
 * Threshold defaults a context can carry (#45): what is accepted, and where
 * they sit in the chain between a site's own settings and the plugin's.
 */
final class ContextDefaultsTest extends TestCase {

	private const LINKS = 'simple_spam_shield_link_limit_max';

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options'] = [ 'simple_spam_shield_link_limit_enabled' => true ];
		$GLOBALS['simple_spam_shield_test_filters'] = [];
	}

	private function register( mixed $defaults ): void {
		add_filter( 'simple_spam_shield_contexts', static function ( array $contexts ) use ( $defaults ): array {
			$contexts['listing'] = [ 'label' => 'Listings', 'defaults' => $defaults ];
			return $contexts;
		} );
	}

	private function links( int $count ): array {
		$urls = [];
		for ( $i = 1; $i <= $count; $i++ ) {
			$urls[] = "https://example.com/{$i}";
		}
		return [ 'content' => implode( ' ', $urls ) ];
	}

	// --- the table ------------------------------------------------------------

	public function test_the_shipped_defaults_match_what_the_guards_read(): void {
		$guards = Config::get( 'guards', 'guards', [] );
		$source = [
			'simple_spam_shield_time_gate_seconds'         => [ 'time_gate', 'min_seconds' ],
			'simple_spam_shield_link_limit_max'            => [ 'link_limit', 'max_links' ],
			'simple_spam_shield_duplicate_window_seconds'  => [ 'duplicate', 'window_seconds' ],
			'simple_spam_shield_rate_limit_max'            => [ 'rate_limit', 'max_per_window' ],
			'simple_spam_shield_rate_limit_window_seconds' => [ 'rate_limit', 'window_seconds' ],
			'simple_spam_shield_behavioral_threshold'      => [ 'behavioral', 'threshold' ],
		];

		$this->assertSame( array_keys( $source ), array_keys( Contexts::THRESHOLDS ) );
		foreach ( $source as $option => [ $guard, $key ] ) {
			$this->assertEquals( $guards[ $guard ][ $key ], Contexts::THRESHOLDS[ $option ]['default'], $option );
		}
	}

	// --- what a registration may carry ------------------------------------------

	public function test_a_context_can_carry_threshold_defaults(): void {
		$this->register( [ self::LINKS => 10 ] );

		$this->assertSame( [ self::LINKS => 10 ], Contexts::all()['listing']['defaults'] );
	}

	public function test_defaults_are_clamped_to_the_settings_page_bounds(): void {
		$this->register( [ self::LINKS => 500, 'simple_spam_shield_behavioral_threshold' => 1.5, 'simple_spam_shield_time_gate_seconds' => '4.6' ] );

		$this->assertSame(
			[ self::LINKS => 50, 'simple_spam_shield_behavioral_threshold' => 1.0, 'simple_spam_shield_time_gate_seconds' => 5 ],
			Contexts::all()['listing']['defaults']
		);
	}

	public function test_a_context_cannot_default_its_protection_off(): void {
		// Numeric on purpose, so only the allowlist can reject them.
		$this->register(
			[
				'simple_spam_shield_monitor_mode'       => 1,
				'simple_spam_shield_link_limit_enabled' => 0,
				'simple_spam_shield_enabled'            => 0,
			]
		);

		$this->assertArrayNotHasKey( 'defaults', Contexts::all()['listing'] );
	}

	public function test_non_numeric_values_and_a_non_array_are_dropped(): void {
		$this->register( [ self::LINKS => 'lots' ] );
		$this->assertArrayNotHasKey( 'defaults', Contexts::all()['listing'] );

		$GLOBALS['simple_spam_shield_test_filters'] = [];
		$this->register( 'not an array' );
		$this->assertSame( [ 'label' => 'Listings' ], Contexts::all()['listing'] );
	}

	// --- where a default sits in the chain ----------------------------------------

	public function test_a_form_with_a_default_inherits_it_when_the_site_set_nothing(): void {
		$this->register( [ self::LINKS => 10 ] );

		$this->assertSame( [ 'value' => 10, 'source' => 'context' ], Contexts::inherited( self::LINKS, 'listing', 3 ) );
	}

	public function test_a_global_still_at_the_shipped_default_counts_as_unset(): void {
		// Activation stores every default, so the option exists on practically
		// every site. Stored values come back as strings.
		$GLOBALS['simple_spam_shield_test_options'][ self::LINKS ] = '3';
		$this->register( [ self::LINKS => 10 ] );

		$this->assertSame( [ 'value' => 10, 'source' => 'context' ], Contexts::inherited( self::LINKS, 'listing', 3 ) );
	}

	public function test_a_global_the_site_changed_wins_over_the_default(): void {
		$GLOBALS['simple_spam_shield_test_options'][ self::LINKS ] = '5';
		$this->register( [ self::LINKS => 10 ] );

		$this->assertSame( [ 'value' => '5', 'source' => 'global' ], Contexts::inherited( self::LINKS, 'listing', 3 ) );
	}

	public function test_a_form_without_a_default_inherits_the_global_or_the_shipped_value(): void {
		$this->assertSame( [ 'value' => 3, 'source' => 'global' ], Contexts::inherited( self::LINKS, 'comment', 3 ) );

		$GLOBALS['simple_spam_shield_test_options'][ self::LINKS ] = '7';
		$this->assertSame( [ 'value' => '7', 'source' => 'global' ], Contexts::inherited( self::LINKS, 'comment', 3 ) );
	}

	// --- through a guard ----------------------------------------------------------

	public function test_the_guard_enforces_the_context_default(): void {
		$GLOBALS['simple_spam_shield_test_options'][ self::LINKS ] = '3';
		$this->register( [ self::LINKS => 10 ] );
		$guard = new Link_Limit( 'link_limit', [ 'max_links' => 3 ] );

		$this->assertTrue( $guard->check( $this->links( 4 ), 'listing' ) );
		$this->assertInstanceOf( WP_Error::class, $guard->check( $this->links( 4 ), 'comment' ), 'other forms keep the global' );
	}

	public function test_a_per_form_override_beats_the_context_default(): void {
		$GLOBALS['simple_spam_shield_test_options'][ Contexts::option( self::LINKS, 'listing' ) ] = '1';
		$this->register( [ self::LINKS => 10 ] );
		$guard = new Link_Limit( 'link_limit', [ 'max_links' => 3 ] );

		$this->assertInstanceOf( WP_Error::class, $guard->check( $this->links( 2 ), 'listing' ) );
	}
}
