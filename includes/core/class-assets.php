<?php
/**
 * Front-end asset enqueuing — honeypot CSS and time-gate JS.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Assets {

	/**
	 * Enqueue front-end scripts and styles.
	 */
	public static function enqueue(): void {
		if ( ! (bool) get_option( 'simple_spam_shield_enabled', true ) ) {
			return;
		}

		wp_enqueue_style(
			'onsite-spam-guard-honeypot',
			SIMPLE_SPAM_SHIELD_URL . 'assets/css/honeypot.css',
			[],
			SIMPLE_SPAM_SHIELD_VERSION
		);

		wp_enqueue_script(
			'onsite-spam-guard-frontend',
			SIMPLE_SPAM_SHIELD_URL . 'assets/js/guard.js',
			[],
			SIMPLE_SPAM_SHIELD_VERSION,
			[ 'in_footer' => true ]
		);

		// A signed token (issue time + HMAC). The time gate reads the signed
		// issue time; the signature guard verifies authenticity. Because the
		// HMAC does not expire, this stays valid under full-page caching.
		wp_localize_script( 'onsite-spam-guard-frontend', 'simpleSpamShieldGuard', [
			'token'     => Token::issue(),
			'selectors' => self::selectors(),
			'honeypot'  => \Simple_Spam_Shield\Guards\Honeypot::field_name(),
		] );
	}

	/**
	 * CSS selectors whose forms receive the protection fields on the front end.
	 *
	 * Other plugins can register their own form selector via the
	 * `simple_spam_shield_form_selectors` filter (see
	 * simple_spam_shield_protect_selector()).
	 *
	 * @return string[]
	 */
	public static function selectors(): array {
		$defaults = [
			'#commentform',                        // WP Comments.
			'#review_form form',                   // WooCommerce Reviews.
			'.wp-block-jetpack-contact-form form', // Jetpack Contact Form blocks.
			'.jetpack-contact-form form',          // Jetpack legacy class.
		];

		/**
		 * Filter the list of form selectors protected on the front end.
		 *
		 * @param string[] $selectors CSS selectors.
		 */
		$selectors = apply_filters( 'simple_spam_shield_form_selectors', $defaults );

		return array_values( array_unique( array_filter( array_map( 'strval', (array) $selectors ) ) ) );
	}

	/**
	 * Hidden protection-field markup for embedding directly inside a form.
	 *
	 * Renders the honeypot, the signed token (time gate + signature), and an
	 * empty behavioral-data field. The front-end script fills the behavioral
	 * field on submit and leaves the rest untouched.
	 *
	 * @return string
	 */
	public static function field_markup(): string {
		// Per-site field name (#23), so the honeypot is not a fixed target a bot
		// author can skip-list once and defeat everywhere.
		$field = \Simple_Spam_Shield\Guards\Honeypot::field_name();

		$honeypot = sprintf(
			'<div class="onsite-spam-guard-hp-wrap" aria-hidden="true"><label for="%1$s">%2$s</label><input type="text" name="%1$s" id="%1$s" value="" tabindex="-1" autocomplete="off"></div>',
			esc_attr( $field ),
			esc_html__( 'Website', 'onsite-spam-guard' )
		);

		$token = sprintf(
			'<input type="hidden" name="simple_spam_shield_form_loaded" value="%s">',
			esc_attr( Token::issue() )
		);

		$behavioral = '<input type="hidden" name="simple_spam_shield_behavioral_data" value="">';

		return $honeypot . $token . $behavioral;
	}
}
