<?php
/**
 * Contact Form 7 integration.
 *
 * Contact Form 7 has a purpose-built spam hook — `wpcf7_spam` — that is
 * separate from field validation, and a spam log for recording why. That is a
 * better fit than a validation error: a validation error is keyed to a field
 * and tells the sender what to change, which is exactly what a spammer wants to
 * know. Marking the submission as spam surfaces the form's own spam message and
 * records the reason for the site owner.
 *
 * Field names in Contact Form 7 are author-defined, so nothing here matches on
 * them. The mapping goes through the form's tag *types* instead, which Contact
 * Form 7 knows regardless of what the fields are called.
 *
 * @package Simple_Spam_Shield
 * @since   1.5.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Assets;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Honeypot;

final class Contact_Form_7 {

	/**
	 * Submission context, also the slug of its per-form settings section.
	 */
	public const CONTEXT = 'contact_form_7';

	/**
	 * The class Contact Form 7 puts on every rendered form.
	 */
	private const FORM_SELECTOR = '.wpcf7-form';

	/**
	 * Register hooks — only when Contact Form 7 is active.
	 */
	public static function init(): void {
		if ( ! defined( 'WPCF7_VERSION' ) ) {
			return;
		}

		// Registered whatever the toggle says, so thresholds tuned on the
		// Per-form tab are not orphaned by switching protection off.
		add_filter( 'simple_spam_shield_contexts', [ __CLASS__, 'register_context' ] );

		if ( ! (bool) get_option( 'simple_spam_shield_protect_cf7', true ) ) {
			return;
		}

		// Contact Form 7's own Turnstile module uses this same filter to put
		// its widget into the form.
		add_filter( 'wpcf7_form_elements', [ __CLASS__, 'append_fields' ] );

		add_filter( 'wpcf7_spam', [ __CLASS__, 'check' ], 10, 2 );

		add_filter( 'simple_spam_shield_form_selectors', [ __CLASS__, 'add_selector' ] );
	}

	/**
	 * Offer contact forms their own thresholds on the Per-form settings tab.
	 *
	 * @param array<string, array{label: string}> $contexts Registered contexts.
	 * @return array<string, array{label: string}>
	 */
	public static function register_context( array $contexts ): array {
		$contexts[ self::CONTEXT ] = [
			'label' => __( 'Contact Form 7 forms', 'onsite-spam-guard' ),
		];

		return $contexts;
	}

	/**
	 * Add Contact Form 7's forms to the selectors the front-end script enhances.
	 *
	 * @param string[] $selectors Existing selectors.
	 * @return string[]
	 */
	public static function add_selector( array $selectors ): array {
		$selectors[] = self::FORM_SELECTOR;

		return $selectors;
	}

	/**
	 * Append the hidden protection fields to the rendered form.
	 *
	 * Not `wpcf7_form_hidden_fields`: that filter renders every value as
	 * `type="hidden"`, and the honeypot has to be a text input a bot will fill.
	 *
	 * @param string $elements The form's inner HTML.
	 * @return string
	 */
	public static function append_fields( $elements ): string {
		return (string) $elements . Assets::field_markup();
	}

	/**
	 * Screen a submission through the guard pipeline.
	 *
	 * @param bool  $spam       Whether Contact Form 7 already considers this spam.
	 * @param mixed $submission The WPCF7_Submission instance.
	 * @return bool
	 */
	public static function check( $spam, $submission = null ): bool {
		// Already flagged — by Contact Form 7's own checks or another plugin.
		// Leave their reason in the spam log rather than adding a second one.
		if ( $spam ) {
			return true;
		}

		if ( ! is_object( $submission ) || ! method_exists( $submission, 'get_posted_data' ) ) {
			return (bool) $spam;
		}

		$result = Guard_Runner::run( self::submission_data( $submission ), self::CONTEXT );

		if ( ! is_wp_error( $result ) ) {
			return (bool) $spam;
		}

		if ( method_exists( $submission, 'add_spam_log' ) ) {
			$submission->add_spam_log(
				[
					'agent'  => 'onsite-spam-guard',
					'reason' => $result->get_error_message(),
				]
			);
		}

		return true;
	}

	/**
	 * Map a submission onto the guard pipeline's inputs.
	 *
	 * Field names are author-defined, so this reads the form's tag types rather
	 * than guessing at names. `url` and `tel` fields are deliberately left out
	 * of the content: a form asking for the sender's website gets a URL from
	 * every legitimate submission, and counting it would push ordinary messages
	 * past the link limit.
	 *
	 * @param object $submission The WPCF7_Submission instance.
	 * @return array<string, string>
	 */
	private static function submission_data( object $submission ): array {
		$posted = (array) $submission->get_posted_data();
		$form   = method_exists( $submission, 'get_contact_form' ) ? $submission->get_contact_form() : null;

		$textareas = self::values_of_type( $form, $posted, [ 'textarea' ] );
		$texts     = self::values_of_type( $form, $posted, [ 'text' ] );
		$emails    = self::values_of_type( $form, $posted, [ 'email' ] );

		// Text fields are screened as content as well as the message body. The
		// default template's `your-subject` is a plain text field, and a subject
		// line is a real spam vector — screening only textareas would let it
		// through. A name landing in the content too is harmless: names do not
		// carry links or blocked keywords.
		//
		// The exception is a text field holding nothing but a URL. Plenty of
		// forms ask for a website with `[text your-website]` rather than
		// `[url ...]`, and that value is structurally a URL however it was
		// declared — counting it would push a genuine enquiry over the link
		// limit for filling the form in correctly. Link-stuffing puts URLs
		// among prose, which still counts.
		$content = array_merge( $textareas, array_filter( $texts, [ __CLASS__, 'is_not_bare_url' ] ) );

		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Anti-spam check on a public submission; Contact Form 7 verifies its own nonce. Values are sanitized on read.
		return [
			'content'                            => trim( implode( "\n\n", $content ) ),
			'author'                             => (string) ( $texts[0] ?? '' ),
			'email'                              => (string) ( $emails[0] ?? '' ),
			'simple_spam_shield_website_url'     => Honeypot::value_from_request( $_POST ),
			'simple_spam_shield_form_loaded'     => sanitize_text_field( wp_unslash( $_POST['simple_spam_shield_form_loaded'] ?? '' ) ),
			'simple_spam_shield_behavioral_data' => sanitize_textarea_field( wp_unslash( $_POST['simple_spam_shield_behavioral_data'] ?? '' ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	/**
	 * Whether a value is something other than a single bare URL.
	 *
	 * @param string $value A posted field value.
	 */
	private static function is_not_bare_url( string $value ): bool {
		return ! preg_match( '#^https?://[^\s<>"\']+$#i', trim( $value ) );
	}

	/**
	 * Posted values for every field of the given tag types, in form order.
	 *
	 * @param mixed                $form   The WPCF7_ContactForm instance, if available.
	 * @param array<string, mixed> $posted Posted data, keyed by field name.
	 * @param string[]             $types  Tag basetypes to collect.
	 * @return string[]
	 */
	private static function values_of_type( $form, array $posted, array $types ): array {
		if ( ! is_object( $form ) || ! method_exists( $form, 'scan_form_tags' ) ) {
			return [];
		}

		$values = [];

		foreach ( (array) $form->scan_form_tags( [ 'basetype' => $types ] ) as $tag ) {
			$name = is_object( $tag ) ? (string) ( $tag->name ?? '' ) : '';

			if ( '' === $name || ! isset( $posted[ $name ] ) ) {
				continue;
			}

			$value = $posted[ $name ];
			$value = is_array( $value ) ? implode( ' ', array_map( 'strval', $value ) ) : (string) $value;
			$value = trim( $value );

			if ( '' !== $value ) {
				$values[] = $value;
			}
		}

		return $values;
	}
}
