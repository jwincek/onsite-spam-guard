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
	 * Key this guard reads from the normalised submission data.
	 *
	 * This is internal plumbing, not the name rendered in the form — that is
	 * per-site and comes from field_name(). Integrations read whichever field
	 * name arrived and normalise it to this key, so the guard, the public
	 * simple_spam_shield_check() contract and every test stay stable while the
	 * name on the wire varies by site.
	 *
	 * It doubles as the legacy wire name, which is still accepted; see
	 * value_from_request().
	 */
	public const FIELD = 'simple_spam_shield_website_url';

	/**
	 * The field name rendered into forms on this site.
	 *
	 * Every install used to render `simple_spam_shield_website_url`, making the
	 * highest-weight guard a fixed target: a bot author who met the plugin once
	 * could add that name to a skip-list and defeat it on every site running
	 * it, permanently. Deriving the name from the site's signing secret keeps it
	 * stable for a site — so cached pages and forms already in a visitor's
	 * browser keep working — while differing between sites and being
	 * unpredictable from the outside.
	 */
	public static function field_name(): string {
		return 'ossg_' . \Simple_Spam_Shield\Core\Token::derive( 'honeypot-field' );
	}

	/**
	 * Read the honeypot value out of a request array.
	 *
	 * Accepts the per-site name and the legacy one. The legacy fallback is not
	 * merely for old integrations: a page cached before this release serves a
	 * form carrying the old name, and without it that submission would arrive
	 * with no honeypot value at all. The guard would then pass rather than
	 * block — failing open, and silently, for as long as the cache lives.
	 *
	 * Returns the first *non-empty* value rather than the first key present.
	 * Both names can legitimately arrive at once — a page cached before this
	 * release carries the old field while the current script injects the new
	 * one — and returning the empty one would let a bot that filled only the
	 * other sail through.
	 *
	 * @param array $source Request data, typically $_POST.
	 */
	public static function value_from_request( array $source ): string {
		foreach ( [ self::field_name(), self::FIELD ] as $key ) {
			if ( ! empty( $source[ $key ] ) ) {
				return sanitize_text_field( wp_unslash( $source[ $key ] ) );
			}
		}

		return '';
	}

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
