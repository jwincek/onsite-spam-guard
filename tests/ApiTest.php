<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Assets;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;

/**
 * Tests for the public integration API (includes/api.php).
 */
final class ApiTest extends TestCase {

	private const SECRET = 'public-api-test-secret-at-least-64-characters-0123456789abcdefgh';

	public static function setUpBeforeClass(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		Guard_Runner::init();
	}

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [
			'simple_spam_shield_enabled'      => true,
			'simple_spam_shield_log_blocked'  => false,
			'simple_spam_shield_token_secret' => self::SECRET,
		];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$GLOBALS['simple_spam_shield_test_filters']    = [];
		$_SERVER['REMOTE_ADDR']                        = '198.51.100.42';
		$_POST                                         = [];
	}

	private function valid_token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	public function test_check_passes_a_clean_submission(): void {
		$_POST['simple_spam_shield_website_url'] = '';
		$_POST['simple_spam_shield_form_loaded'] = $this->valid_token();

		$result = simple_spam_shield_check(
			[
				'content' => 'A genuinely helpful message.',
				'email'   => 'alice@example.com',
			],
			'acme_contact_form'
		);
		$this->assertTrue( $result );
	}

	public function test_check_blocks_a_honeypot_hit(): void {
		$_POST['simple_spam_shield_website_url'] = 'http://spam.example';
		$_POST['simple_spam_shield_form_loaded'] = $this->valid_token();

		$result = simple_spam_shield_check( [ 'content' => 'hello' ], 'acme_contact_form' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_check_blocks_a_forged_token(): void {
		$_POST['simple_spam_shield_website_url'] = '';
		$_POST['simple_spam_shield_form_loaded'] = '123.deadbeef';

		$result = simple_spam_shield_check( [ 'content' => 'hello' ], 'acme_contact_form' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_field_markup_contains_all_hidden_fields(): void {
		$markup = simple_spam_shield_field_markup();
		// The honeypot name is per-site (#23), so assert the derived one.
		$this->assertStringContainsString(
			'name="' . \Simple_Spam_Shield\Guards\Honeypot::field_name() . '"',
			$markup
		);
		$this->assertStringNotContainsString(
			'name="simple_spam_shield_website_url"',
			$markup,
			'the fixed legacy name must no longer be rendered'
		);
		$this->assertStringContainsString( 'name="simple_spam_shield_form_loaded"', $markup );
		$this->assertStringContainsString( 'name="simple_spam_shield_behavioral_data"', $markup );
	}

	public function test_protect_selector_registers_into_the_selector_list(): void {
		$this->assertNotContains( '#acme-form', Assets::selectors() );

		simple_spam_shield_protect_selector( '#acme-form' );

		$this->assertContains( '#acme-form', Assets::selectors() );
	}

	public function test_protect_selector_ignores_an_empty_selector(): void {
		simple_spam_shield_protect_selector( '' );
		$this->assertNotContains( '', Assets::selectors() );
	}

	public function test_check_reads_hidden_fields_passed_explicitly(): void {
		// REST/JSON: $_POST is empty, so the token is passed explicitly.
		$_POST  = [];
		$result = simple_spam_shield_check(
			[
				'content'                        => 'A perfectly normal note',
				'simple_spam_shield_website_url' => '',
				'simple_spam_shield_form_loaded' => $this->valid_token(),
			],
			'acme_rest'
		);
		$this->assertTrue( $result );
	}

	public function test_check_blocks_an_explicitly_passed_honeypot(): void {
		$_POST  = [];
		$result = simple_spam_shield_check(
			[
				'content'                        => 'spam',
				'simple_spam_shield_website_url' => 'http://bot.example',
				'simple_spam_shield_form_loaded' => $this->valid_token(),
			],
			'acme_rest'
		);
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_check_content_only_passes_without_a_token(): void {
		// No token at all (a content-only integration); the token-based guards
		// skip for a custom context, so a clean submission still passes.
		$_POST  = [];
		$result = simple_spam_shield_check(
			[ 'content' => 'Looking forward to the event', 'email' => 'guest@example.com' ],
			'rsvp_form'
		);
		$this->assertTrue( $result );
	}
}
