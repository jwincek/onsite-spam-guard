<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Integrations\Registration;

/** Account registration: WordPress core, WooCommerce, BuddyPress (#49). */
final class RegistrationTest extends TestCase {

	private const SECRET = 'rrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrrr';

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options']               = [
			'simple_spam_shield_enabled'               => true,
			'simple_spam_shield_log_blocked'           => true,
			'simple_spam_shield_token_secret'          => self::SECRET,
			'simple_spam_shield_keyword_block_enabled' => true,
			'simple_spam_shield_blocked_keywords'      => 'casino',
		];
		$GLOBALS['simple_spam_shield_test_transients']            = [];
		$GLOBALS['simple_spam_shield_test_transient_expirations'] = [];
		$GLOBALS['simple_spam_shield_test_filters']               = [];
		$GLOBALS['simple_spam_shield_test_actions']               = [];
		$GLOBALS['simple_spam_shield_test_log_rows']              = [];
		$_SERVER['REMOTE_ADDR']                                   = '198.51.100.80';
		$_POST                                                    = [ 'simple_spam_shield_form_loaded' => $this->token() ];

		Guard_Runner::init();
	}

	private function token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	// --- WordPress core ------------------------------------------------------

	public function test_core_refuses_a_spam_username(): void {
		$errors = Registration::check_core( new WP_Error(), 'casino_bonus_77', 'a@example.com' );

		$this->assertTrue( $errors->has_errors() );
	}

	public function test_core_lets_a_genuine_signup_through(): void {
		$errors = Registration::check_core( new WP_Error(), 'jane', 'jane@example.com' );

		$this->assertFalse( $errors->has_errors() );
	}

	/**
	 * A signup the form is already refusing is left alone. Screening it would
	 * record, in the duplicate cache and the log, a signup the host never took.
	 */
	public function test_core_does_not_screen_a_signup_already_being_refused(): void {
		$errors = new WP_Error( 'username_exists', 'That username is taken.' );

		Registration::check_core( $errors, 'casino_bonus_77', 'a@example.com' );

		$this->assertSame( [ 'username_exists' ], $errors->get_error_codes() );
		$this->assertSame( [], $GLOBALS['simple_spam_shield_test_log_rows'] );
	}

	/** A custom form that never renders the hidden fields must not be refused for that alone. */
	public function test_a_signup_without_the_hidden_fields_fails_open(): void {
		$_POST = [];

		$this->assertFalse( Registration::check_core( new WP_Error(), 'jane', 'jane@example.com' )->has_errors() );
	}

	public function test_a_filled_honeypot_is_refused(): void {
		$_POST['simple_spam_shield_website_url'] = 'http://bot.example';

		$this->assertTrue( Registration::check_core( new WP_Error(), 'jane', 'jane@example.com' )->has_errors() );
	}

	// --- WooCommerce ----------------------------------------------------------

	public function test_woocommerce_refuses_spam(): void {
		$errors = Registration::check_woocommerce( new WP_Error(), 'casino_king', 'pw', 'a@example.com' );

		$this->assertTrue( $errors->has_errors() );
	}

	/** With a generated username, the email address is the only text there is. */
	public function test_woocommerce_screens_the_email_when_the_username_is_generated(): void {
		$errors = Registration::check_woocommerce( new WP_Error(), '', 'pw', 'promo_casino@example.com' );

		$this->assertTrue( $errors->has_errors() );
	}

	// --- BuddyPress -----------------------------------------------------------

	/**
	 * BuddyPress's signup screen copies only the `user_name` and `user_email`
	 * keys into its own error list, and completes the signup when that list is
	 * empty. An error under any other key would be ignored, and the spam account
	 * created anyway.
	 */
	public function test_buddypress_refusal_is_added_under_a_key_the_signup_screen_enforces(): void {
		$result = Registration::check_buddypress(
			[ 'user_name' => 'casino_king', 'user_email' => 'a@example.com', 'errors' => new WP_Error() ]
		);

		$this->assertNotEmpty(
			$result['errors']->errors['user_name'] ?? null,
			'the refusal must be under user_name, or BuddyPress completes the signup regardless'
		);
	}

	public function test_buddypress_lets_a_genuine_signup_through(): void {
		$result = Registration::check_buddypress(
			[ 'user_name' => 'janedoe', 'user_email' => 'jane@example.com', 'errors' => new WP_Error() ]
		);

		$this->assertFalse( $result['errors']->has_errors() );
	}

	public function test_buddypress_malformed_input_is_returned_unchanged(): void {
		$this->assertSame( 'not an array', Registration::check_buddypress( 'not an array' ) );
		$this->assertSame( [ 'errors' => 'nope' ], Registration::check_buddypress( [ 'errors' => 'nope' ] ) );
	}

	// --- rollout ---------------------------------------------------------------

	/** The path the issue describes: trial one signup form while the rest are enforced. */
	public function test_one_registration_form_can_be_monitored(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_monitor_mode__woo_registration'] = 'monitor';

		$woo = Registration::check_woocommerce( new WP_Error(), 'casino_king', 'pw', 'a@example.com' );
		$bp  = Registration::check_buddypress( [ 'user_name' => 'casino_king', 'user_email' => 'b@example.com', 'errors' => new WP_Error() ] );

		$this->assertFalse( $woo->has_errors(), 'the monitored form lets it through' );
		$this->assertTrue( $bp['errors']->has_errors(), 'the enforced form still refuses it' );
	}

	public function test_the_signup_forms_are_added_to_the_script_selectors(): void {
		$selectors = Registration::add_selectors( [] );

		foreach ( [ '#registerform', '.woocommerce-form-register', '#signup-form', '#signup_form' ] as $selector ) {
			$this->assertContains( $selector, $selectors );
		}
	}
}
