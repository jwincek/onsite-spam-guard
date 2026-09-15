<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Integrations\Contact_Form_7;

/** Stand-in for a WPCF7_FormTag. */
final class Fake_CF7_Tag {
	public function __construct( public string $name, public string $basetype ) {}
}

/** Stand-in for a WPCF7_ContactForm: only scan_form_tags() is used. */
final class Fake_CF7_Form {
	/** @param Fake_CF7_Tag[] $tags */
	public function __construct( private array $tags ) {}

	public function scan_form_tags( $cond = null ): array {
		$want = (array) ( $cond['basetype'] ?? [] );

		return array_values(
			array_filter( $this->tags, static fn( $t ) => in_array( $t->basetype, $want, true ) )
		);
	}
}

/** Stand-in for a WPCF7_Submission. */
final class Fake_CF7_Submission {
	public array $spam_log = [];

	public function __construct( private array $posted, private Fake_CF7_Form $form ) {}

	public function get_posted_data(): array {
		return $this->posted;
	}

	public function get_contact_form(): Fake_CF7_Form {
		return $this->form;
	}

	public function add_spam_log( $data = '' ): void {
		$this->spam_log[] = $data;
	}
}

/** Contact Form 7 (#20). */
final class ContactForm7IntegrationTest extends TestCase {

	private const SECRET = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

	protected function setUp(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		$GLOBALS['simple_spam_shield_test_options']               = [
			'simple_spam_shield_enabled'      => true,
			'simple_spam_shield_log_blocked'  => false,
			'simple_spam_shield_token_secret' => self::SECRET,
		];
		$GLOBALS['simple_spam_shield_test_transients']            = [];
		$GLOBALS['simple_spam_shield_test_transient_expirations'] = [];
		$GLOBALS['simple_spam_shield_test_filters']               = [];
		$GLOBALS['simple_spam_shield_test_actions']               = [];
		$_SERVER['REMOTE_ADDR']                                   = '198.51.100.44';
		$_POST                                                    = [];

		Guard_Runner::init();
	}

	private function token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	/** The default Contact Form 7 template's shape. */
	private function submission( array $overrides = [] ): Fake_CF7_Submission {
		$posted = array_merge(
			[
				'your-name'    => 'Jane Doe',
				'your-email'   => 'jane@example.com',
				'your-subject' => 'Question about opening hours',
				'your-message' => 'Hello, when are you open on Sundays?',
			],
			$overrides
		);

		$form = new Fake_CF7_Form(
			[
				new Fake_CF7_Tag( 'your-name', 'text' ),
				new Fake_CF7_Tag( 'your-email', 'email' ),
				new Fake_CF7_Tag( 'your-subject', 'text' ),
				new Fake_CF7_Tag( 'your-message', 'textarea' ),
			]
		);

		return new Fake_CF7_Submission( $posted, $form );
	}

	// --- the contract Contact Form 7 expects -------------------------------

	public function test_a_clean_submission_is_not_marked_spam(): void {
		$_POST['simple_spam_shield_form_loaded'] = $this->token();

		$this->assertFalse( Contact_Form_7::check( false, $this->submission() ) );
	}

	public function test_a_filled_honeypot_is_marked_spam_with_a_logged_reason(): void {
		$_POST['simple_spam_shield_form_loaded'] = $this->token();
		$_POST['simple_spam_shield_website_url'] = 'http://bot.example';

		$submission = $this->submission();

		$this->assertTrue( Contact_Form_7::check( false, $submission ) );
		$this->assertCount( 1, $submission->spam_log );
		$this->assertSame( 'onsite-spam-guard', $submission->spam_log[0]['agent'] );
		$this->assertNotSame( '', $submission->spam_log[0]['reason'] );
	}

	/** Another agent already flagged it; do not add a second reason. */
	public function test_an_existing_spam_verdict_is_left_alone(): void {
		$submission = $this->submission();

		$this->assertTrue( Contact_Form_7::check( true, $submission ) );
		$this->assertSame( [], $submission->spam_log, 'the existing agent\'s reason must stand alone' );
	}

	// --- the mapping ------------------------------------------------------

	/**
	 * Field names are author-defined, so the mapping reads tag types. The
	 * default template's subject is a plain text field, and a subject line is a
	 * real spam vector — screening only the textarea would let it through.
	 */
	public function test_a_blocked_keyword_in_the_subject_is_caught(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = "casino\nviagra";
		$_POST['simple_spam_shield_form_loaded']                                               = $this->token();

		$this->assertTrue(
			Contact_Form_7::check( false, $this->submission( [ 'your-subject' => 'Cheap casino deals' ] ) )
		);
	}

	public function test_a_blocked_keyword_in_the_message_is_caught(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = "casino\nviagra";
		$_POST['simple_spam_shield_form_loaded']                                               = $this->token();

		$this->assertTrue(
			Contact_Form_7::check( false, $this->submission( [ 'your-message' => 'Buy viagra online' ] ) )
		);
	}

	/**
	 * A form asking for the sender's website gets a URL from every legitimate
	 * submission, so url fields must not count toward the link limit.
	 */
	public function test_a_url_field_does_not_count_against_the_link_limit(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 1;
		$_POST['simple_spam_shield_form_loaded']                                             = $this->token();

		$form = new Fake_CF7_Form(
			[
				new Fake_CF7_Tag( 'your-name', 'text' ),
				new Fake_CF7_Tag( 'your-site', 'url' ),
				new Fake_CF7_Tag( 'your-message', 'textarea' ),
			]
		);
		$submission = new Fake_CF7_Submission(
			[
				'your-name'    => 'Jane',
				'your-site'    => 'https://jane.example',
				'your-message' => 'Please see my portfolio.',
			],
			$form
		);

		$this->assertFalse( Contact_Form_7::check( false, $submission ) );
	}

	/**
	 * Plenty of forms ask for a website with `[text your-website]` rather than
	 * `[url ...]`. That value is structurally a URL however it was declared, so
	 * counting it would reject a genuine enquiry for filling the form in
	 * correctly — found by submitting through real Contact Form 7, not in
	 * review.
	 */
	public function test_a_text_field_holding_only_a_url_does_not_count_as_a_link(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 3;
		$_POST['simple_spam_shield_form_loaded']                                             = $this->token();

		$form = new Fake_CF7_Form(
			[
				new Fake_CF7_Tag( 'your-name', 'text' ),
				new Fake_CF7_Tag( 'your-website', 'text' ),   // text, not url
				new Fake_CF7_Tag( 'your-message', 'textarea' ),
			]
		);
		$submission = new Fake_CF7_Submission(
			[
				'your-name'    => 'Jane',
				'your-website' => 'https://jane.example',
				'your-message' => 'Refs: https://a.example https://b.example https://c.example',
			],
			$form
		);

		$this->assertFalse( Contact_Form_7::check( false, $submission ) );
	}

	/** The exclusion is narrow: URLs among prose in a text field still count. */
	public function test_a_text_field_stuffed_with_links_still_counts(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 3;
		$_POST['simple_spam_shield_form_loaded']                                             = $this->token();

		$form = new Fake_CF7_Form(
			[
				new Fake_CF7_Tag( 'your-name', 'text' ),
				new Fake_CF7_Tag( 'your-message', 'textarea' ),
			]
		);
		$submission = new Fake_CF7_Submission(
			[
				'your-name'    => 'Buy https://a.example now https://b.example also https://c.example and https://d.example',
				'your-message' => 'hi',
			],
			$form
		);

		$this->assertTrue( Contact_Form_7::check( false, $submission ) );
	}

	public function test_links_in_the_message_still_count(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 2;
		$_POST['simple_spam_shield_form_loaded']                                             = $this->token();

		$this->assertTrue(
			Contact_Form_7::check(
				false,
				$this->submission(
					[ 'your-message' => 'http://a.example http://b.example http://c.example' ]
				)
			)
		);
	}

	/** A form with no textarea at all is still screened. */
	public function test_a_form_without_a_textarea_is_still_screened(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = 'casino';
		$_POST['simple_spam_shield_form_loaded']                                               = $this->token();

		$form       = new Fake_CF7_Form( [ new Fake_CF7_Tag( 'subject', 'text' ) ] );
		$submission = new Fake_CF7_Submission( [ 'subject' => 'casino offer' ], $form );

		$this->assertTrue( Contact_Form_7::check( false, $submission ) );
	}

	// --- robustness -------------------------------------------------------

	public function test_a_missing_or_malformed_submission_does_not_fatal(): void {
		$this->assertFalse( Contact_Form_7::check( false, null ) );
		$this->assertFalse( Contact_Form_7::check( false, new stdClass() ) );
	}

	public function test_the_context_and_selector_are_registered(): void {
		$this->assertArrayHasKey( 'contact_form_7', Contact_Form_7::register_context( [] ) );
		$this->assertContains( '.wpcf7-form', Contact_Form_7::add_selector( [] ) );
	}

	public function test_the_hidden_fields_are_appended_to_the_form_markup(): void {
		$out = Contact_Form_7::append_fields( '<p>form</p>' );

		$this->assertStringStartsWith( '<p>form</p>', $out );
		$this->assertStringContainsString( 'simple_spam_shield_form_loaded', $out );
	}
}
