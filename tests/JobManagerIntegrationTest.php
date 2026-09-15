<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Integrations\Job_Manager;

/** WP Job Manager job submissions (#35). */
final class JobManagerIntegrationTest extends TestCase {

	private const SECRET = 'jjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjjj';

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
		$_SERVER['REMOTE_ADDR']                                   = '198.51.100.21';
		$_POST                                                    = [];

		Guard_Runner::init();
	}

	private function token(): string {
		$issued = time() - 30;
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::SECRET );
	}

	/** A realistic listing: long description, several structured URLs. */
	private function legitimateValues(): array {
		return [
			'job'     => [
				'job_title'       => 'Senior Groundskeeper',
				'job_description' => 'We are hiring a groundskeeper for our estate. '
					. 'The role covers planting, maintenance and seasonal work. '
					. 'Full details on our careers page.',
				'application'     => 'jobs@example.com',
			],
			'company' => [
				'company_name'    => 'Example Estates',
				'company_website' => 'https://example.com',
				'company_video'   => 'https://videos.example.com/intro',
				'company_twitter' => 'https://twitter.com/example',
			],
		];
	}

	// --- the contract WP Job Manager expects ------------------------------

	public function test_a_clean_submission_passes_the_incoming_validity_through(): void {
		$_POST['simple_spam_shield_form_loaded'] = $this->token();

		$this->assertTrue( Job_Manager::validate( true, [], $this->legitimateValues() ) );
	}

	public function test_a_filled_honeypot_is_returned_as_a_wp_error(): void {
		$_POST['simple_spam_shield_form_loaded'] = $this->token();
		$_POST['simple_spam_shield_website_url'] = 'http://bot.example';

		$result = Job_Manager::validate( true, [], $this->legitimateValues() );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'simple_spam_shield_honeypot_failed', $result->get_error_code() );
	}

	/** An earlier validator's error must not be replaced by ours. */
	public function test_an_existing_error_is_passed_through_untouched(): void {
		$existing = new WP_Error( 'validation-error', 'Terms and Conditions is a required field' );
		$_POST['simple_spam_shield_website_url'] = 'http://bot.example';

		$this->assertSame( $existing, Job_Manager::validate( $existing, [], $this->legitimateValues() ) );
	}

	// --- the mapping ------------------------------------------------------

	/**
	 * The structured URL fields must stay out of `content`. A legitimate
	 * listing fills website, video and Twitter, and counting those as content
	 * links would reject ordinary submissions at the default limit of 3.
	 */
	public function test_structured_urls_do_not_count_against_the_link_limit(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 3;
		$_POST['simple_spam_shield_form_loaded']                                             = $this->token();

		$this->assertTrue(
			Job_Manager::validate( true, [], $this->legitimateValues() ),
			'a listing with the usual company URLs must not be blocked'
		);
	}

	/** Links the poster wrote into the description still count. */
	public function test_links_in_the_description_still_count(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max']     = 3;
		$_POST['simple_spam_shield_form_loaded']                                             = $this->token();

		$values                            = $this->legitimateValues();
		$values['job']['job_description'] = 'Earn money fast http://a.example http://b.example '
			. 'http://c.example http://d.example';

		$this->assertInstanceOf( WP_Error::class, Job_Manager::validate( true, [], $values ) );
	}

	public function test_the_job_title_is_checked_as_content(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_keyword_block_enabled'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']      = "casino\nviagra";
		$_POST['simple_spam_shield_form_loaded']                                               = $this->token();

		$values                       = $this->legitimateValues();
		$values['job']['job_title'] = 'Casino affiliate wanted';

		$this->assertInstanceOf( WP_Error::class, Job_Manager::validate( true, [], $values ) );
	}

	/** An `application` URL is not an email address and must not be sent as one. */
	public function test_an_application_url_is_not_treated_as_an_email(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_enabled'] = true;
		$_POST['simple_spam_shield_form_loaded']                                           = $this->token();

		$urlValues                          = $this->legitimateValues();
		$urlValues['job']['application']   = 'https://example.com/apply';
		$emailValues                        = $this->legitimateValues();
		$emailValues['job']['application'] = 'jobs@example.com';

		// Both pass, and the duplicate guard sees them as different submissions
		// because the email component differs rather than both being blank.
		$this->assertTrue( Job_Manager::validate( true, [], $urlValues ) );
		$this->assertTrue( Job_Manager::validate( true, [], $emailValues ) );
	}

	// --- robustness -------------------------------------------------------

	/** Drafts arrive partially filled; that must not fatal. */
	public function test_missing_field_groups_do_not_fatal(): void {
		$_POST['simple_spam_shield_form_loaded'] = $this->token();

		// Three near-empty submissions share a fingerprint, so the duplicate
		// guard would rightly reject the repeats. It is not what is under test.
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_duplicate_enabled'] = false;

		$this->assertTrue( Job_Manager::validate( true, [], [] ) );
		$this->assertTrue( Job_Manager::validate( true, [], [ 'job' => [] ] ) );
		$this->assertTrue( Job_Manager::validate( true, [], [ 'job' => 'not an array' ] ) );
	}

	public function test_the_context_is_registered_for_per_form_thresholds(): void {
		$contexts = Job_Manager::register_context( [] );

		$this->assertArrayHasKey( 'job_submission', $contexts );
		$this->assertArrayHasKey( 'label', $contexts['job_submission'] );
	}

	public function test_the_submission_form_is_added_to_the_script_selectors(): void {
		$this->assertContains( '#submit-job-form', Job_Manager::add_selector( [ '#commentform' ] ) );
	}

	/** Blocks are logged under their own context, so the log can be filtered. */
	public function test_a_block_is_logged_under_the_job_submission_context(): void {
		$_POST['simple_spam_shield_form_loaded'] = $this->token();
		$_POST['simple_spam_shield_website_url'] = 'http://bot.example';

		$seen = [];
		add_action( 'simple_spam_shield_blocked', static function ( $guard, $context ) use ( &$seen ): void {
			$seen[] = $context;
		}, 10, 4 );

		Job_Manager::validate( true, [], $this->legitimateValues() );

		$this->assertSame( [ 'job_submission' ], $seen );
	}
}
