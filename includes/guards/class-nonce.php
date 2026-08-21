<?php
/**
 * Signature guard — verifies the submission carries a valid, server-signed token.
 *
 * Historically this guard validated a WordPress nonce, but nonces expire on
 * the 12-hour tick and therefore produce false positives on full-page-cached
 * pages. It now verifies the HMAC signature of the form token instead, which
 * proves the form was served by this site without expiring. The guard slug
 * remains "nonce" for settings compatibility.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Nonce extends Abstract_Guard {

	public const FIELD = 'simple_spam_shield_form_loaded';

	public function check( array $data, string $context, bool $observe_only = false ): \WP_Error|true {
		$token = (string) ( $data[ self::FIELD ] ?? '' );

		if ( '' === $token ) {
			// The token is guaranteed only on the built-in comment/review
			// forms (guard.js injects it). Skip rather than hard-fail
			// elsewhere — Jetpack strips it, and custom integrations may omit
			// it; the other guards still protect the submission.
			return $this->is_js_injected_context( $context )
				? $this->fail( __( 'Security check failed — please refresh the page and try again.', 'onsite-spam-guard' ) )
				: true;
		}

		if ( false === \Simple_Spam_Shield\Core\Token::verify( $token ) ) {
			return $this->fail(
				__( 'Security check failed — please refresh the page and try again.', 'onsite-spam-guard' )
			);
		}

		return true;
	}
}
