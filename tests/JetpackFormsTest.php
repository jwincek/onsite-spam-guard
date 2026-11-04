<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Integrations\Jetpack_Forms;

/**
 * Regression tests built from a real production false positive.
 *
 * A volunteer application containing no links of its own was flagged by the
 * link limit, because normalize() concatenated every string in Jetpack's
 * Akismet values — including the metadata prepare_for_akismet() adds: the
 * blog home URL, the referrer, the entry permalink, and HTTP_* headers. Four
 * URLs the visitor never typed exceeded a limit of three.
 */
final class JetpackFormsTest extends TestCase {

	public static function setUpBeforeClass(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		Guard_Runner::init();
	}

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [
			'simple_spam_shield_enabled'        => true,
			'simple_spam_shield_log_blocked'    => false,
			'simple_spam_shield_link_limit_max' => 3,
		];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$_SERVER['REMOTE_ADDR']                        = '198.51.100.70';
		$_POST                                         = [];
	}

	/**
	 * Jetpack's Akismet values: the visitor's fields plus the metadata that
	 * prepare_for_akismet() bolts on. Four of these values are URLs the
	 * visitor never typed (permalink, referrer, blog, HTTP_ORIGIN).
	 *
	 * @param array $overrides Values to merge over the baseline.
	 * @return array
	 */
	private function akismet_values( array $overrides = [] ): array {
		return array_merge(
			[
				// --- the visitor's own input ---
				'comment_author'                   => 'Zoe Grace',
				'comment_author_email'             => 'zoe@example.com',
				'comment_author_url'               => '',
				'comment_content'                  => null, // Jetpack nulls this when empty.
				'contact_form_field_name'          => 'Zoe Grace',
				'contact_form_field_address'       => '2371 County Line Rd',
				'contact_form_field_what-talents'  => 'Love for pets. Dedication to hard work.',
				// --- Jetpack / server metadata: must never be inspected ---
				'contact_form_subject'             => 'Volunteer Application',
				'comment_author_ip'                => '198.51.100.70',
				'permalink'                        => 'https://example.org/volunteer/volunteer-resources',
				'comment_type'                     => 'contact_form',
				'user_ip'                          => '198.51.100.70',
				'user_agent'                       => 'Mozilla/5.0 (iPhone; CPU iPhone OS 26_5_0 like Mac OS X) Safari/604.1',
				'referrer'                         => 'https://example.org/volunteer/volunteer-resources',
				'blog'                             => 'https://example.org',
				'blog_lang'                        => 'en_US',
				'REQUEST_URI'                      => '/volunteer/volunteer-resources',
				'HTTP_ORIGIN'                      => 'https://example.org',
				'HTTP_HOST'                        => 'example.org',
			],
			$overrides
		);
	}

	public function test_jetpack_metadata_urls_do_not_trip_the_link_limit(): void {
		// The exact production case: 4 metadata URLs, 0 links from the visitor.
		$this->assertFalse( Jetpack_Forms::check_form( false, $this->akismet_values() ) );
	}

	public function test_metadata_is_not_searched_for_keywords(): void {
		// "iPhone" appears only in the user-agent, which is not the visitor's input.
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'iPhone';
		$this->assertFalse( Jetpack_Forms::check_form( false, $this->akismet_values() ) );
	}

	public function test_links_in_the_visitors_own_input_still_trip_the_link_limit(): void {
		$spammy = $this->akismet_values(
			[ 'contact_form_field_message' => 'http://a.example http://b.example http://c.example http://d.example' ]
		);
		$this->assertTrue( Jetpack_Forms::check_form( false, $spammy ) );
	}

	public function test_visitor_field_values_are_searched_for_keywords(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'casino';
		$spammy = $this->akismet_values( [ 'contact_form_field_message' => 'try our casino tonight' ] );
		$this->assertTrue( Jetpack_Forms::check_form( false, $spammy ) );
	}

	public function test_comment_content_is_searched_when_present(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'casino';
		$spammy = $this->akismet_values( [ 'comment_content' => 'visit my casino' ] );
		$this->assertTrue( Jetpack_Forms::check_form( false, $spammy ) );
	}

	public function test_author_is_read_from_the_comment_author_key(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'blockedname';
		$data = $this->akismet_values( [ 'comment_author' => 'blockedname' ] );
		$this->assertTrue( Jetpack_Forms::check_form( false, $data ) );
	}

	public function test_email_is_read_from_the_comment_author_email_key(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'blockedmail';
		$data = $this->akismet_values( [ 'comment_author_email' => 'blockedmail@example.com' ] );
		$this->assertTrue( Jetpack_Forms::check_form( false, $data ) );
	}

	public function test_already_flagged_submission_is_passed_through(): void {
		$this->assertTrue( Jetpack_Forms::check_form( true, $this->akismet_values() ) );
	}

	// --- the plugin's fields in the rendered form -----------------------------

	/** Jetpack's form markup as of Jetpack 16: no class guard.js used to match. */
	private function jetpack_form_html(): string {
		return '<div class="jetpack-contact-form-container"><form action="/contact/" id="contact-form-1" method="post" class="contact-form commentsblock jetpack-contact-form__form" data-wp-on--submit="actions.onFormSubmit" novalidate >'
			. "\n<input type='hidden' name='jetpack_contact_form_jwt' value='x' />"
			. '<input name="g1-name" /></form></div>';
	}

	public function test_the_fields_are_rendered_right_after_the_opening_form_tag(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_token_secret'] = str_repeat( 'j', 64 );

		$html = Jetpack_Forms::add_fields( $this->jetpack_form_html() );

		$opening = strpos( $html, 'novalidate >' ) + strlen( 'novalidate >' );
		$this->assertStringStartsWith( '<div class="onsite-spam-guard-hp-wrap"', substr( $html, $opening ) );
		$this->assertSame( 1, substr_count( $html, 'name="simple_spam_shield_form_loaded"' ) );
		$this->assertSame( 1, substr_count( $html, 'name="simple_spam_shield_behavioral_data"' ) );
		$this->assertStringContainsString( "name='jetpack_contact_form_jwt'", $html, "Jetpack's own fields are kept" );
	}

	public function test_fields_are_added_once(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_token_secret'] = str_repeat( 'j', 64 );

		$twice = Jetpack_Forms::add_fields( Jetpack_Forms::add_fields( $this->jetpack_form_html() ) );

		$this->assertSame( 1, substr_count( $twice, 'name="simple_spam_shield_form_loaded"' ) );
		$this->assertSame( 1, substr_count( $twice, 'onsite-spam-guard-hp-wrap' ) );
	}

	public function test_html_without_a_form_and_non_strings_are_passed_on(): void {
		$this->assertSame( '<p>No form here.</p>', Jetpack_Forms::add_fields( '<p>No form here.</p>' ) );
		$this->assertNull( Jetpack_Forms::add_fields( null ) );
	}

	/**
	 * The production case: a bot fills every field it finds. With the
	 * honeypot rendered into the form it fills that too, and is refused on
	 * its first submission rather than only on a repeat.
	 */
	public function test_a_bot_that_fills_the_rendered_honeypot_is_marked_spam(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_token_secret'] = str_repeat( 'j', 64 );
		preg_match( '/<input type="text" name="([^"]+)"/', Jetpack_Forms::add_fields( $this->jetpack_form_html() ), $honeypot );
		$_POST = [ $honeypot[1] => 'https://spam.example/' ];

		$this->assertTrue( Jetpack_Forms::check_form( false, [ 'comment_content' => 'BuByPPtvufenomKGpHSq' ] ) );
	}
}
