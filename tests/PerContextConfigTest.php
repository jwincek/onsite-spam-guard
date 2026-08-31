<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Contexts;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Link_Limit;
use Simple_Spam_Shield\Guards\Time_Gate;
use Simple_Spam_Shield\Guards\Duplicate;

/** Per-context threshold overrides (#21). */
final class PerContextConfigTest extends TestCase {

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options']               = [];
		$GLOBALS['simple_spam_shield_test_transients']            = [];
		$GLOBALS['simple_spam_shield_test_transient_expirations'] = [];
		$GLOBALS['simple_spam_shield_test_filters']               = [];
		$_SERVER['REMOTE_ADDR']                                   = '198.51.100.7';
	}

	private function linkLimit(): Link_Limit {
		return new Link_Limit( 'link_limit', [ 'max_links' => 3 ] );
	}

	private function twoLinks(): array {
		return [ 'content' => 'see http://a.example and http://b.example' ];
	}

	// --- the registry ------------------------------------------------------

	public function test_the_three_built_in_contexts_are_registered(): void {
		$this->assertSame(
			[ 'comment', 'woo_review', 'jetpack_form' ],
			array_keys( Contexts::all() )
		);
	}

	public function test_a_plugin_can_register_its_own_context(): void {
		add_filter( 'simple_spam_shield_contexts', static function ( array $c ): array {
			$c['commission_form'] = [ 'label' => 'Commission requests' ];
			return $c;
		} );

		$this->assertArrayHasKey( 'commission_form', Contexts::all() );
	}

	public function test_a_malformed_registration_is_dropped_rather_than_rendered_nameless(): void {
		add_filter( 'simple_spam_shield_contexts', static function ( array $c ): array {
			$c['no_label'] = [ 'weight' => 5 ];   // missing label
			$c['not_even_an_array'] = 'nope';
			return $c;
		} );

		$all = Contexts::all();
		$this->assertArrayNotHasKey( 'no_label', $all );
		$this->assertArrayNotHasKey( 'not_even_an_array', $all );
		$this->assertArrayHasKey( 'comment', $all, 'valid contexts must survive' );
	}

	public function test_a_filter_returning_a_non_array_falls_back_to_the_builtins(): void {
		add_filter( 'simple_spam_shield_contexts', static fn() => 'not an array' );

		$this->assertCount( 3, Contexts::all() );
	}

	public function test_context_keys_are_normalised_for_use_in_an_option_name(): void {
		// sanitize_key() lowercases and strips anything outside [a-z0-9_-] —
		// it removes the space rather than replacing it.
		$this->assertSame( 'myform', Contexts::key( 'My Form' ) );
		$this->assertSame( 'rsvp_form', Contexts::key( 'RSVP_Form' ) );
		$this->assertSame(
			'simple_spam_shield_link_limit_max__rsvp_form',
			Contexts::option( 'simple_spam_shield_link_limit_max', 'rsvp_form' )
		);
	}

	/** The runner's context and the settings key must agree, or an override silently never applies. */
	public function test_a_context_registered_unnormalised_still_matches_at_runtime(): void {
		add_filter( 'simple_spam_shield_contexts', static function ( array $c ): array {
			$c['Commission Form'] = [ 'label' => 'Commission requests' ];
			return $c;
		} );

		$registered = array_keys( Contexts::all() );
		$this->assertContains( 'commissionform', $registered );

		// A guard handed the raw label resolves to the same option name.
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']                 = 3;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max__commissionform'] = '1';

		$this->assertInstanceOf(
			WP_Error::class,
			$this->linkLimit()->check( $this->twoLinks(), 'Commission Form' )
		);
	}

	// --- resolution --------------------------------------------------------

	public function test_a_context_override_beats_the_global(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']                   = 3;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max__commission_form']  = '1';

		$this->assertTrue( $this->linkLimit()->check( $this->twoLinks(), 'comment' ) );
		$this->assertInstanceOf( WP_Error::class, $this->linkLimit()->check( $this->twoLinks(), 'commission_form' ) );
	}

	public function test_a_blank_override_inherits_the_global_rather_than_meaning_zero(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']                  = 3;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max__commission_form'] = '';

		$this->assertTrue(
			$this->linkLimit()->check( $this->twoLinks(), 'commission_form' ),
			'blank must inherit 3, not collapse to 0 and block everything'
		);
	}

	public function test_zero_is_a_real_override_and_not_treated_as_blank(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']                  = 3;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max__commission_form'] = '0';

		$this->assertInstanceOf(
			WP_Error::class,
			$this->linkLimit()->check( [ 'content' => 'one http://a.example link' ], 'commission_form' ),
			'0 links must be enforceable as a deliberate setting'
		);
	}

	public function test_an_unregistered_context_falls_back_to_the_global(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max'] = 1;

		$this->assertInstanceOf(
			WP_Error::class,
			$this->linkLimit()->check( $this->twoLinks(), 'never_registered_anywhere' )
		);
	}

	public function test_the_config_default_still_applies_when_nothing_is_set(): void {
		// guards.json says 3, so two links pass and four do not.
		$this->assertTrue( $this->linkLimit()->check( $this->twoLinks(), 'comment' ) );
		$this->assertInstanceOf(
			WP_Error::class,
			$this->linkLimit()->check(
				[ 'content' => 'a http://a.example b http://b.example c http://c.example d http://d.example' ],
				'comment'
			)
		);
	}

	// --- the same, on other guards ----------------------------------------

	public function test_time_gate_honours_a_per_context_override(): void {
		$secret = str_repeat( 'k', 64 );
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_token_secret']              = $secret;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_time_gate_seconds']         = 3;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_time_gate_seconds__survey'] = '30';

		$issued = time() - 10;   // ten seconds: fine globally, too fast for the survey
		$data   = [ 'simple_spam_shield_form_loaded' => $issued . '.' . hash_hmac( 'sha256', (string) $issued, $secret ) ];
		$guard  = new Time_Gate( 'time_gate', [ 'min_seconds' => 3 ] );

		$this->assertTrue( $guard->check( $data, 'comment' ) );
		$this->assertInstanceOf( WP_Error::class, $guard->check( $data, 'survey' ) );
	}

	public function test_duplicate_window_override_reaches_the_transient(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_window_seconds']          = 60;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_window_seconds__comment'] = '5';

		$guard = new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] );
		$guard->commit( [ 'content' => 'Thanks!' ], 'comment' );

		$this->assertSame( [ 5 ], array_values( $GLOBALS['simple_spam_shield_test_transient_expirations'] ) );
	}
}
