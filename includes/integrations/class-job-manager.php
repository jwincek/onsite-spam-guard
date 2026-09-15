<?php
/**
 * WP Job Manager integration — protects frontend job submissions.
 *
 * The job submission form is an anonymous-writable surface whose only built-in
 * defence is Google reCAPTCHA, so it is a natural fit for a plugin that does
 * its filtering on the site itself.
 *
 * This hooks the same extension points WP Job Manager uses for its own
 * reCAPTCHA (see WP_Job_Manager_Recaptcha::maybe_enable_recaptcha): an action
 * inside the form for the hidden fields, and the validation filter for the
 * verdict. The validation filter returns true|WP_Error and the form displays
 * the error, which is the contract Guard_Runner::run() already satisfies.
 *
 * @package Simple_Spam_Shield
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Assets;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Honeypot;

final class Job_Manager {

	/**
	 * Submission context, also the slug of its per-form settings section.
	 */
	public const CONTEXT = 'job_submission';

	/**
	 * The submission form's id, from templates/job-submit.php.
	 */
	private const FORM_SELECTOR = '#submit-job-form';

	/**
	 * Register hooks — only when WP Job Manager is active.
	 */
	public static function init(): void {
		if ( ! defined( 'JOB_MANAGER_VERSION' ) ) {
			return;
		}

		// Registered whatever the toggle says, so the Per-form tab can be set up
		// before the form is ever switched on, and so turning protection off
		// does not orphan thresholds a site has already tuned.
		add_filter( 'simple_spam_shield_contexts', [ __CLASS__, 'register_context' ] );

		if ( ! (bool) get_option( 'simple_spam_shield_protect_job_manager', true ) ) {
			return;
		}

		add_action( 'submit_job_form_end', [ __CLASS__, 'render_fields' ] );

		// Both validation hooks, as WP Job Manager's own reCAPTCHA does. The
		// draft-save path deliberately skips validate_fields(), so hooking only
		// the main filter would leave "save as draft" unprotected.
		add_filter( 'submit_job_form_validate_fields', [ __CLASS__, 'validate' ], 10, 3 );
		add_filter( 'submit_draft_job_form_validate_fields', [ __CLASS__, 'validate' ], 10, 3 );

		// So the front-end script attaches behavioral tracking to this form.
		add_filter( 'simple_spam_shield_form_selectors', [ __CLASS__, 'add_selector' ] );
	}

	/**
	 * Offer this form its own thresholds on the Per-form settings tab.
	 *
	 * A job listing legitimately runs longer and carries more links than a
	 * comment, so the global defaults would misfire here. The time gate wants
	 * moving in the opposite direction — nobody writes a job description in
	 * three seconds, so a longer minimum is both safer and stricter.
	 *
	 * @param array<string, array{label: string}> $contexts Registered contexts.
	 * @return array<string, array{label: string}>
	 */
	public static function register_context( array $contexts ): array {
		$contexts[ self::CONTEXT ] = [
			'label' => __( 'Job submissions (WP Job Manager)', 'onsite-spam-guard' ),
		];

		return $contexts;
	}

	/**
	 * Add the submission form to the selectors the front-end script enhances.
	 *
	 * @param string[] $selectors Existing selectors.
	 * @return string[]
	 */
	public static function add_selector( array $selectors ): array {
		$selectors[] = self::FORM_SELECTOR;

		return $selectors;
	}

	/**
	 * Render the hidden protection fields inside the submission form.
	 */
	public static function render_fields(): void {
		echo Assets::field_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped markup.
	}

	/**
	 * Run a job submission through the guard pipeline.
	 *
	 * @param bool|\WP_Error $valid  Validity so far, from earlier filters.
	 * @param mixed          $fields Field definitions (unused).
	 * @param mixed          $values Submitted values, grouped by field group.
	 *                               Typed loosely on purpose: this arrives from
	 *                               another plugin's apply_filters(), and a
	 *                               partially-filled draft is a normal case.
	 * @return bool|\WP_Error True when clean, WP_Error when a guard blocked it.
	 */
	public static function validate( $valid, $fields = [], $values = [] ) {
		// Another validator already objected; do not override its message.
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$values  = is_array( $values ) ? $values : [];
		$job     = isset( $values['job'] ) && is_array( $values['job'] ) ? $values['job'] : [];
		$company = isset( $values['company'] ) && is_array( $values['company'] ) ? $values['company'] : [];

		$result = Guard_Runner::run( self::submission_data( $job, $company ), self::CONTEXT );

		return is_wp_error( $result ) ? $result : $valid;
	}

	/**
	 * Map a submission onto the guard pipeline's inputs.
	 *
	 * Only the free text the poster wrote becomes `content`. The structured URL
	 * fields — company website, video, Twitter, and an `application` value that
	 * is a URL — are deliberately excluded: a legitimate listing fills them, and
	 * folding them in would inflate the link count with valid data and reject
	 * ordinary submissions.
	 *
	 * @param array $job     The 'job' field group.
	 * @param array $company The 'company' field group.
	 * @return array<string, string>
	 */
	private static function submission_data( array $job, array $company ): array {
		$application = (string) ( $job['application'] ?? '' );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Anti-spam check on a public submission; WP Job Manager verifies its own nonce. Values are sanitized on read.
		return [
			'content'                            => trim(
				(string) ( $job['job_title'] ?? '' ) . "\n\n" . (string) ( $job['job_description'] ?? '' )
			),
			'author'                             => (string) ( $company['company_name'] ?? '' ),
			'email'                              => is_email( $application ) ? $application : '',
			'simple_spam_shield_website_url'     => Honeypot::value_from_request( $_POST ),
			'simple_spam_shield_form_loaded'     => sanitize_text_field( wp_unslash( $_POST['simple_spam_shield_form_loaded'] ?? '' ) ),
			'simple_spam_shield_behavioral_data' => sanitize_textarea_field( wp_unslash( $_POST['simple_spam_shield_behavioral_data'] ?? '' ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}
}
