<?php
/**
 * Comments integration — protects WordPress comments.
 *
 * This is a thin integration class (the "consumer" pattern from the
 * reference architecture). It hooks into WP's comment lifecycle and
 * delegates all spam-checking to the Guard_Runner pipeline.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Guard_Runner;

final class Comments {

	/**
	 * Whether the current submission was flagged by a guard.
	 *
	 * @var bool
	 */
	private static bool $flagged = false;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		if ( ! (bool) get_option( 'simple_spam_shield_protect_comments', true ) ) {
			return;
		}

		add_filter( 'preprocess_comment', [ __CLASS__, 'check_comment' ], 1 );
		add_filter( 'pre_comment_approved', [ __CLASS__, 'maybe_mark_spam' ], 99, 2 );
	}

	/**
	 * Run the guard pipeline on a comment submission.
	 *
	 * @param array $commentdata Comment data array.
	 * @return array Unmodified data if checks pass.
	 */
	public static function check_comment( array $commentdata ): array {
		// Skip logged-in users with moderate_comments capability.
		if ( current_user_can( 'moderate_comments' ) ) {
			return $commentdata;
		}

		// Build normalized data for the guard pipeline.
		$data = self::normalize( $commentdata );

		$result = Guard_Runner::run( $data, 'comment' );

		if ( is_wp_error( $result ) ) {
			// Hard-block mode: reject outright with an error page.
			if ( (bool) get_option( 'simple_spam_shield_hard_block', false ) ) {
				wp_die(
					esc_html( $result->get_error_message() ),
					esc_html__( 'Comment Blocked', 'onsite-spam-guard' ),
					[
						'response'  => 403,
						'back_link' => true,
					]
				);
			}

			// Default: let the comment save but route it to the spam queue,
			// so a false positive can be recovered by a moderator instead of
			// being lost. The status is applied via pre_comment_approved.
			self::$flagged = true;
		}

		return $commentdata;
	}

	/**
	 * Force a flagged comment into the spam queue.
	 *
	 * @param int|string $approved    Current approval status.
	 * @param array      $commentdata Comment data (unused).
	 * @return int|string 'spam' when flagged, otherwise the original status.
	 */
	public static function maybe_mark_spam( $approved, $commentdata ) {
		unset( $commentdata );
		return self::$flagged ? 'spam' : $approved;
	}

	/**
	 * Normalize comment data into the format the guards expect.
	 */
	private static function normalize( array $commentdata ): array {
		return [
			'content'                            => $commentdata['comment_content'] ?? '',
			'author'                             => $commentdata['comment_author'] ?? '',
			'email'                              => $commentdata['comment_author_email'] ?? '',
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
}
