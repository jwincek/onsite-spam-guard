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

	public function check( array $data, string $context, bool $observe_only = false ): \WP_Error|true {
		$content = $data['content'] ?? $data['comment'] ?? '';
		$author  = $data['author'] ?? $data['author_name'] ?? '';
		$email   = $data['email'] ?? $data['author_email'] ?? '';
		$ip      = \Simple_Spam_Shield\Core\Request::ip();

		$hash = md5( $content . $author . $email . $ip );

		$transient_key = 'simple_spam_shield_dup_' . $hash;
		$window        = (int) ( $this->config['window_seconds'] ?? 60 );

		if ( get_transient( $transient_key ) ) {
			return $this->fail(
				__( 'Duplicate submission detected — please wait before resubmitting.', 'onsite-spam-guard' )
			);
		}

		// Mark this submission as seen for the duration of the window. Skipped
		// when only observing, so a submission already blocked by an earlier
		// guard does not register itself — otherwise a visitor who fixed
		// whatever tripped them and resubmitted the same content would be
		// rejected as a duplicate of their own blocked attempt.
		if ( ! $observe_only ) {
			set_transient( $transient_key, time(), $window );
		}

		return true;
	}
}
