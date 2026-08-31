<?php
/**
 * Token — issues and verifies HMAC-signed form tokens.
 *
 * The front-end injects a token into every protected form. The token is
 * "<issued_at>.<hmac>", where the HMAC is computed server-side with a
 * private per-site secret. This serves two guards:
 *
 *  - Time gate: the issue time is signed, so a bot cannot forge an older
 *    timestamp to skip the minimum-wait check.
 *  - Signature: a valid HMAC proves the form was served by this site,
 *    without using a WordPress nonce. Unlike a nonce, the signature does
 *    not expire on the 12-hour tick, so it survives full-page caching
 *    without producing false positives for legitimate visitors.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Token {

	private const SECRET_OPTION = 'simple_spam_shield_token_secret';

	/**
	 * Issue a fresh signed token for embedding in a form.
	 */
	public static function issue(): string {
		$issued = time();
		return $issued . '.' . hash_hmac( 'sha256', (string) $issued, self::secret() );
	}

	/**
	 * Verify a token's signature and return its issue time (Unix seconds),
	 * or false if the token is missing, malformed, or has an invalid signature.
	 *
	 * @param string $token The token value from the submission.
	 * @return int|false
	 */
	public static function verify( string $token ): int|false {
		if ( ! str_contains( $token, '.' ) ) {
			return false;
		}

		[ $issued, $signature ] = explode( '.', $token, 2 );

		if ( '' === $issued || ! ctype_digit( $issued ) ) {
			return false;
		}

		$expected = hash_hmac( 'sha256', $issued, self::secret() );

		if ( ! hash_equals( $expected, $signature ) ) {
			return false;
		}

		return (int) $issued;
	}

	/**
	 * Get the per-site signing secret, generating and storing it on first use.
	 */
	/**
	 * Derive a stable, site-specific value from the signing secret.
	 *
	 * Stable for a site, so cached pages and in-flight forms keep working, and
	 * unpredictable across sites, so a value derived here cannot be guessed
	 * from the outside. Used for the honeypot field name, which would otherwise
	 * be identical on every install and therefore trivially skip-listed.
	 *
	 * @param string $purpose Distinguishes derived values from one another.
	 * @param int    $length  Hex characters to return.
	 */
	public static function derive( string $purpose, int $length = 12 ): string {
		return substr( hash_hmac( 'sha256', $purpose, self::secret() ), 0, max( 1, $length ) );
	}

	private static function secret(): string {
		$secret = get_option( self::SECRET_OPTION );

		if ( ! is_string( $secret ) || strlen( $secret ) < 64 ) {
			$secret = wp_generate_password( 64, true, true );
			update_option( self::SECRET_OPTION, $secret, false );
		}

		return $secret;
	}
}
