<?php
/**
 * Rate Limit guard — throttles repeated submissions from the same sender.
 *
 * Keys the counter on the logged-in user ID when available, falling back to
 * the visitor IP. That makes it useful for authenticated flows (e.g. private
 * messages) as well as anonymous forms. Off by default, because IP-based
 * limiting can produce false positives behind shared/NAT addresses.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Rate_Limit extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		$max = (int) get_option( 'simple_spam_shield_rate_limit_max', $this->config['max_per_window'] ?? 10 );

		// A max of 0 (or less) disables the limit.
		if ( $max <= 0 ) {
			return true;
		}

		$window = (int) ( $this->config['window_seconds'] ?? 60 );

		// Identify the sender: the logged-in user, else the connection IP.
		$user_id = get_current_user_id();
		$sender  = $user_id > 0 ? 'user:' . $user_id : 'ip:' . \Simple_Spam_Shield\Core\Request::ip();
		$key     = 'simple_spam_shield_rate_' . md5( $sender . '|' . $context );

		$count = (int) get_transient( $key );

		if ( $count >= $max ) {
			return $this->fail(
				__( 'Too many submissions — please wait a moment and try again.', 'simple-spam-shield' )
			);
		}

		// Rolling window: each accepted submission extends the window, so a
		// persistent sender stays throttled until they pause for $window.
		set_transient( $key, $count + 1, $window );

		return true;
	}
}
