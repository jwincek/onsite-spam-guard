<?php
/**
 * Honeypot guard — rejects submissions where the hidden honeypot field is filled.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Honeypot extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		$field_name = $this->config['field_name'] ?? 'simple_spam_shield_website_url';

		// If the honeypot field is present and non-empty, it's a bot.
		if ( ! empty( $data[ $field_name ] ) ) {
			return $this->fail(
				__( 'Submission rejected.', 'onsite-spam-guard' )
			);
		}

		return true;
	}

	/**
	 * Get the honeypot field name for use in form rendering.
	 */
	public static function get_field_name(): string {
		$config = \Simple_Spam_Shield\Core\Config::get( 'guards', 'guards', [] );
		return $config['honeypot']['field_name'] ?? 'simple_spam_shield_website_url';
	}
}
