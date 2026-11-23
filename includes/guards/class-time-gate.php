<?php
/**
 * Time Gate guard — rejects submissions completed faster than a human.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Time_Gate extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		$min_seconds = (int) $this->threshold( 'simple_spam_shield_time_gate_seconds', $context, $this->config['min_seconds'] ?? 3 );

		$token = (string) ( $data['simple_spam_shield_form_loaded'] ?? '' );

		if ( '' === $token ) {
			// The comment and review forms always carry a token: the server
			// renders it into every form comment_form() builds, and guard.js
			// adds it to forms it finds. One arriving without a token did not
			// come from a form this site rendered, so it is refused. Elsewhere
			// a missing token is skipped rather than refused, since a custom
			// integration may not render the fields; the other guards still
			// protect the submission.
			return $this->is_js_injected_context( $context )
				? $this->fail( __( 'Submission rejected — please reload the page and try again.', 'onsite-spam-guard' ) )
				: true;
		}

		// A token was supplied, so verify it. The issue time is read from the
		// server-signed token and cannot be forged; an invalid signature means
		// the value was tampered with or did not originate from this site.
		$issued = \Simple_Spam_Shield\Core\Token::verify( $token );

		if ( false === $issued ) {
			return $this->fail(
				__( 'Submission rejected — please refresh the page and try again.', 'onsite-spam-guard' )
			);
		}

		$elapsed = time() - $issued;

		// Clamp negative elapsed time (server clock moved backwards) to zero.
		if ( $elapsed < 0 ) {
			$elapsed = 0;
		}

		if ( $elapsed < $min_seconds ) {
			return $this->fail(
				__( 'Submission rejected — please slow down.', 'onsite-spam-guard' )
			);
		}

		return true;
	}
}
