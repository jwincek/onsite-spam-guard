<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Assets;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Honeypot;

/** Per-site honeypot field name (#23). */
final class HoneypotFieldNameTest extends TestCase {

	private const SECRET_A = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
	private const SECRET_B = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

	public static function setUpBeforeClass(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		Guard_Runner::init();
	}

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [
			'simple_spam_shield_enabled'      => true,
			'simple_spam_shield_log_blocked'  => false,
			'simple_spam_shield_token_secret' => self::SECRET_A,
		];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$GLOBALS['simple_spam_shield_test_filters']    = [];
		$_SERVER['REMOTE_ADDR']                        = '198.51.100.23';
		$_POST                                         = [];
	}

	private function useSecret( string $secret ): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_token_secret'] = $secret;
	}

	// --- derivation --------------------------------------------------------

	public function test_the_name_is_stable_for_a_site(): void {
		// Must not change between requests, or a form already in a visitor's
		// browser (or on a cached page) would submit a name the server ignores.
		$this->assertSame( Honeypot::field_name(), Honeypot::field_name() );
	}

	public function test_the_name_differs_between_sites(): void {
		$a = Honeypot::field_name();
		$this->useSecret( self::SECRET_B );

		$this->assertNotSame( $a, Honeypot::field_name(), 'a shared name is a skip-listable fixed target' );
	}

	public function test_the_name_is_no_longer_the_fixed_legacy_one(): void {
		$this->assertNotSame( Honeypot::FIELD, Honeypot::field_name() );
		$this->assertMatchesRegularExpression( '/^ossg_[0-9a-f]{12}$/', Honeypot::field_name() );
	}

	public function test_the_rendered_markup_uses_the_derived_name(): void {
		$this->assertStringContainsString( 'name="' . Honeypot::field_name() . '"', Assets::field_markup() );
	}

	// --- reading a submission ---------------------------------------------

	public function test_the_derived_name_is_read(): void {
		$this->assertSame( 'http://spam.example', Honeypot::value_from_request( [ Honeypot::field_name() => 'http://spam.example' ] ) );
	}

	/** A page cached before this release still submits the old name. */
	public function test_the_legacy_name_is_still_accepted(): void {
		$this->assertSame( 'http://spam.example', Honeypot::value_from_request( [ Honeypot::FIELD => 'http://spam.example' ] ) );
	}

	public function test_an_absent_field_reads_as_empty(): void {
		$this->assertSame( '', Honeypot::value_from_request( [] ) );
		$this->assertSame( '', Honeypot::value_from_request( [ Honeypot::field_name() => '' ] ) );
	}

	/**
	 * Both names can arrive together: a cached page carries the legacy field
	 * while the current script injects the derived one. Taking the first key
	 * *present* rather than the first *filled* would let the empty derived
	 * field mask a bot that filled the legacy one.
	 */
	public function test_a_filled_legacy_field_is_not_masked_by_an_empty_derived_one(): void {
		$value = Honeypot::value_from_request( [
			Honeypot::field_name() => '',
			Honeypot::FIELD        => 'http://spam.example',
		] );

		$this->assertSame( 'http://spam.example', $value, 'an empty new field must not mask a filled old one' );
	}

	public function test_a_filled_derived_field_still_wins_when_both_are_filled(): void {
		$value = Honeypot::value_from_request( [
			Honeypot::field_name() => 'http://a.example',
			Honeypot::FIELD        => 'http://b.example',
		] );

		$this->assertSame( 'http://a.example', $value );
	}

	// --- end to end --------------------------------------------------------

	public function test_a_bot_filling_either_name_is_blocked_through_the_api(): void {
		foreach ( [ Honeypot::field_name(), Honeypot::FIELD ] as $name ) {
			$_POST = [ $name => 'http://spam.example' ];

			$this->assertInstanceOf(
				WP_Error::class,
				simple_spam_shield_check( [ 'content' => 'hello' ], 'acme_form' ),
				"a bot filling {$name} must be blocked"
			);
		}
	}
}
