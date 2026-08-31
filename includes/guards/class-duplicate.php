<?php
/**
 * Duplicate Submission guard — rejects rapid-fire identical submissions.
 *
 * Ported from Comment & Form Guard's is_duplicate_submission() method.
 * Uses a transient-based MD5 hash of the submission content + author +
 * email + IP to detect the same submission sent within a short window.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Duplicate extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		if ( get_transient( self::transient_key( $data ) ) ) {
			return $this->fail(
				__( 'Duplicate submission detected — please wait before resubmitting.', 'onsite-spam-guard' )
			);
		}

		return true;
	}

	/**
	 * Register an accepted submission for the duration of the window.
	 *
	 * This deliberately happens on commit rather than during check(): the guard
	 * asks "have I already taken this exact content?", and a submission some
	 * other guard rejected was never taken. Recording it during the check would
	 * refuse a visitor who fixed whatever tripped them and resubmitted, naming
	 * a duplicate of their own blocked attempt as the reason.
	 *
	 * @param array  $data    Submission data.
	 * @param string $context Submission context.
	 */
	public function commit( array $data, string $context ): void {
		// Floored at 1: set_transient() treats 0 as "no expiration", which would
		// make this block permanent. The settings field clamps too, but only on
		// save — this covers a value stored by anything else.
		$window = max( 1, (int) get_option( 'simple_spam_shield_duplicate_window_seconds', $this->config['window_seconds'] ?? 60 ) );

		set_transient( self::transient_key( $data ), time(), $window );
	}

	/**
	 * Transient key identifying a submission, so check() and commit() cannot
	 * drift apart on how a submission is fingerprinted.
	 *
	 * @param array $data Submission data.
	 */
	private static function transient_key( array $data ): string {
		$content = $data['content'] ?? $data['comment'] ?? '';
		$author  = $data['author'] ?? $data['author_name'] ?? '';
		$email   = $data['email'] ?? $data['author_email'] ?? '';
		$ip      = \Simple_Spam_Shield\Core\Request::ip();

		return 'simple_spam_shield_dup_' . md5( $content . $author . $email . $ip );
	}
}
