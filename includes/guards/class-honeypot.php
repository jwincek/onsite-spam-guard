<?php
/**
 * Honeypot guard — rejects submissions where the hidden honeypot field is filled.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Honeypot extends Abstract_Guard {

	/**
	 * The hidden field's name.
	 *
	 * Intentionally a constant rather than configuration: the name is also
	 * hardcoded in the rendered markup, the front-end script and every
	 * integration that forwards it, so a configurable value that only this
	 * class honoured would silently disable the guard. See #23 for wiring it
	 * through properly, which would allow a per-site randomised name.
	 */
	public const FIELD = 'simple_spam_shield_website_url';

	public function check( array $data, string $context ): \WP_Error|true {
		// If the honeypot field is present and non-empty, it's a bot.
		if ( ! empty( $data[ self::FIELD ] ) ) {
			return $this->fail(
				__( 'Submission rejected.', 'onsite-spam-guard' )
			);
		}

		return true;
	}
}
