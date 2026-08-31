<?php
/**
 * Jetpack Forms integration — protects Jetpack Contact Form block submissions.
 *
 * Jetpack's contact form (the wp-block-jetpack-contact-form block) sends
 * submissions through its own processing pipeline. This integration hooks
 * into the jetpack_contact_form_is_spam filter to run guard checks.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Guard_Runner;

final class Jetpack_Forms {

	/**
	 * Register hooks — only if Jetpack is active.
	 */
	public static function init(): void {
		if ( ! (bool) get_option( 'simple_spam_shield_protect_jetpack_forms', true ) ) {
			return;
		}

		// Jetpack's contact form spam filter — returns true to mark as spam.
		add_filter( 'jetpack_contact_form_is_spam', [ __CLASS__, 'check_form' ], 10, 2 );

		// Fallback: hook into the grunion (Jetpack forms module) processing.
		add_filter( 'grunion_contact_form_is_spam', [ __CLASS__, 'check_form' ], 10, 2 );
	}

	/**
	 * Run the guard pipeline on a Jetpack form submission.
	 *
	 * @param bool  $is_spam  Current spam status.
	 * @param array $form_data Form field values (varies by form config).
	 * @return bool True if spam, original value if not.
	 */
	public static function check_form( bool $is_spam, array $form_data = [] ): bool {
		// If already flagged as spam by another filter, don't override.
		if ( $is_spam ) {
			return $is_spam;
		}

		$data = self::normalize( $form_data );

		$result = Guard_Runner::run( $data, 'jetpack_form' );

		if ( is_wp_error( $result ) ) {
			return true; // Mark as spam.
		}

		return $is_spam;
	}

	/**
	 * Normalize Jetpack's Akismet-shaped values for the guard pipeline.
	 *
	 * Jetpack hands the filter prepare_for_akismet()'s array, which carries the
	 * visitor's submission *alongside* site and server metadata: the blog home
	 * URL, the referrer, the entry permalink, REQUEST_URI, and every HTTP_*
	 * header. Only the visitor's own input may be inspected — concatenating
	 * every string value made the link and keyword guards match Jetpack's own
	 * URLs and the user-agent string, which flagged legitimate submissions.
	 *
	 * The visitor's input lives in `comment_content` (which Jetpack sets to
	 * null when empty), `comment_author_url`, and one `contact_form_field_*`
	 * entry per submitted field, so allow-list exactly those.
	 *
	 * @param array $form_data Jetpack's Akismet values array.
	 * @return array Normalized data for the guard pipeline.
	 */
	private static function normalize( array $form_data ): array {
		$content_parts = [];

		foreach ( $form_data as $key => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			if ( 'comment_content' === $key
				|| 'comment_author_url' === $key
				|| str_starts_with( $key, 'contact_form_field_' ) ) {
				$content_parts[] = $value;
			}
		}

		return [
			'content'                            => implode( ' ', $content_parts ),
			'author'                             => self::string_value( $form_data, 'comment_author' ),
			'email'                              => self::string_value( $form_data, 'comment_author_email' ),
			// JS-injected fields from a public form submission; there is no
			// plugin nonce to verify at this stage (that is the optional Nonce
			// guard's job downstream). Values are sanitized on read.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			'simple_spam_shield_website_url'     => \Simple_Spam_Shield\Guards\Honeypot::value_from_request( $_POST ),
			'simple_spam_shield_form_loaded'     => sanitize_text_field( wp_unslash( $_POST['simple_spam_shield_form_loaded'] ?? '' ) ),
			'simple_spam_shield_behavioral_data' => sanitize_textarea_field( wp_unslash( $_POST['simple_spam_shield_behavioral_data'] ?? '' ) ),
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		];
	}

	/**
	 * Read a string value from the form data, defaulting to an empty string.
	 *
	 * @param array  $form_data Jetpack's Akismet values array.
	 * @param string $key       Key to read.
	 * @return string
	 */
	private static function string_value( array $form_data, string $key ): string {
		return isset( $form_data[ $key ] ) && is_string( $form_data[ $key ] ) ? $form_data[ $key ] : '';
	}
}
