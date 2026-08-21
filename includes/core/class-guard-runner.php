<?php
/**
 * Guard Runner — executes the spam-check pipeline.
 *
 * This is the "abilities layer" equivalent from the Petstablished Sync
 * architecture: thin, testable operations with clear inputs and outputs.
 * Each guard is a class implementing Guard_Interface. The runner loads
 * them from config, sorts by weight, and runs them in order.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

use Simple_Spam_Shield\Guards\Guard_Interface;

final class Guard_Runner {

	/** @var Guard_Interface[] */
	private static array $guards = [];

	/**
	 * Initialize: register all guards defined in config/guards.json.
	 */
	public static function init(): void {
		self::$guards = []; // Idempotent: re-initializing replaces, never appends.

		$definitions = Config::get( 'guards', 'guards', [] );

		$guard_map = [
			'honeypot'      => \Simple_Spam_Shield\Guards\Honeypot::class,
			'time_gate'     => \Simple_Spam_Shield\Guards\Time_Gate::class,
			'nonce'         => \Simple_Spam_Shield\Guards\Nonce::class,
			'link_limit'    => \Simple_Spam_Shield\Guards\Link_Limit::class,
			'keyword_block' => \Simple_Spam_Shield\Guards\Keyword_Block::class,
			'duplicate'     => \Simple_Spam_Shield\Guards\Duplicate::class,
			'rate_limit'    => \Simple_Spam_Shield\Guards\Rate_Limit::class,
			'behavioral'    => \Simple_Spam_Shield\Guards\Behavioral::class,
		];

		foreach ( $definitions as $slug => $def ) {
			if ( ! isset( $guard_map[ $slug ] ) ) {
				continue;
			}

			$class    = $guard_map[ $slug ];
			$instance = new $class( $slug, $def );

			self::$guards[] = $instance;
		}

		// Sort by weight descending (highest priority first).
		usort( self::$guards, fn( Guard_Interface $a, Guard_Interface $b ) => $b->get_weight() <=> $a->get_weight() );
	}

	/**
	 * Run all enabled guards against a submission.
	 *
	 * @param array  $data    Submission data (comment fields, form fields, etc.).
	 * @param string $context One of 'comment', 'woo_review', 'jetpack_form'.
	 * @return \WP_Error|true  True if all guards pass, WP_Error on first failure.
	 */
	public static function run( array $data, string $context ): \WP_Error|true {
		if ( ! (bool) get_option( 'simple_spam_shield_enabled', true ) ) {
			return true;
		}

		// Allowlist bypass — ported from Comment & Form Guard.
		// Check IPs and emails against the allowlist before running any guards.
		if ( self::is_allowlisted( $data ) ) {
			return true;
		}

		foreach ( self::$guards as $guard ) {
			if ( ! $guard->is_enabled() ) {
				continue;
			}

			$result = $guard->check( $data, $context );

			if ( is_wp_error( $result ) ) {
				self::log_block( $guard->get_slug(), $context, $result->get_error_message(), $data );
				return $result;
			}
		}

		return true;
	}

	/**
	 * Log a blocked submission to the custom database table.
	 *
	 * @param string $guard   Slug of the guard that blocked the submission.
	 * @param string $context Form context.
	 * @param string $reason  Human-readable block reason.
	 * @param array  $data    Normalized submission data (provides the content).
	 */
	private static function log_block( string $guard, string $context, string $reason, array $data ): void {
		if ( ! (bool) get_option( 'simple_spam_shield_log_blocked', true ) ) {
			return;
		}

		// The content comes from the normalized submission data so the log
		// works for any form (comments, reviews, Jetpack, or a third-party
		// form via simple_spam_shield_check()). It is escaped on insert and
		// only ever rendered escaped in the log table.
		Database_Manager::insert( [
			'guard'      => $guard,
			'context'    => $context,
			'reason'     => $reason,
			'content'    => (string) ( $data['content'] ?? '' ),
			'ip_address' => Request::ip(),
			'user_agent' => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
		] );
	}

	/**
	 * Check whether the submission is from an allowlisted IP or email.
	 *
	 * Ported from Comment & Form Guard's is_whitelisted() method,
	 * adapted to our config-driven architecture.
	 */
	private static function is_allowlisted( array $data ): bool {
		$allowlisted_raw = get_option( 'simple_spam_shield_allowlist', '' );

		if ( empty( $allowlisted_raw ) ) {
			return false;
		}

		$entries = array_filter( array_map( 'trim', explode( "\n", $allowlisted_raw ) ) );

		if ( empty( $entries ) ) {
			return false;
		}

		$ip    = Request::ip();
		$email = strtolower( $data['email'] ?? $data['author_email'] ?? '' );

		foreach ( $entries as $entry ) {
			$entry_lower = strtolower( $entry );

			// IP match (exact).
			if ( filter_var( $entry, FILTER_VALIDATE_IP ) && $entry === $ip ) {
				return true;
			}

			// CIDR match.
			if ( str_contains( $entry, '/' ) && self::ip_in_cidr( $ip, $entry ) ) {
				return true;
			}

			// Email domain match (e.g. @example.com).
			if ( str_starts_with( $entry_lower, '@' ) && ! empty( $email ) && str_ends_with( $email, $entry_lower ) ) {
				return true;
			}

			// Exact email match.
			if ( filter_var( $entry, FILTER_VALIDATE_EMAIL ) && $entry_lower === $email ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check whether an IP falls inside a CIDR range.
	 *
	 * Handles IPv4 and IPv6 through the same path by comparing the packed
	 * binary forms from inet_pton(): 4 bytes for IPv4, 16 for IPv6. The
	 * previous implementation used ip2long() and 32-bit arithmetic, so an
	 * IPv6 range in the allowlist silently never matched.
	 *
	 * @param string $ip   Visitor IP.
	 * @param string $cidr Range in CIDR notation.
	 * @return bool
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return false;
		}

		[ $subnet, $prefix ] = explode( '/', $cidr, 2 );

		if ( '' === $prefix || ! ctype_digit( $prefix ) ) {
			return false;
		}

		$ip_packed     = @inet_pton( $ip );     // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- returns false for malformed input, which is handled below.
		$subnet_packed = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- as above.

		if ( false === $ip_packed || false === $subnet_packed ) {
			return false;
		}

		// Different address families never match (4 bytes vs 16).
		if ( strlen( $ip_packed ) !== strlen( $subnet_packed ) ) {
			return false;
		}

		$bits = (int) $prefix;
		$max  = strlen( $ip_packed ) * 8;

		if ( $bits > $max ) {
			return false;
		}

		$whole_bytes   = intdiv( $bits, 8 );
		$leftover_bits = $bits % 8;

		if ( $whole_bytes > 0 && strncmp( $ip_packed, $subnet_packed, $whole_bytes ) !== 0 ) {
			return false;
		}

		if ( 0 === $leftover_bits ) {
			return true;
		}

		// Compare only the significant high bits of the next byte.
		$mask = chr( ( 0xFF << ( 8 - $leftover_bits ) ) & 0xFF );

		return ( $ip_packed[ $whole_bytes ] & $mask ) === ( $subnet_packed[ $whole_bytes ] & $mask );
	}
}
