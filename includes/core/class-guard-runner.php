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

			$class = $guard_map[ $slug ];
			/** @var Guard_Interface $instance */
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
	 * Check if an IP is within a CIDR range.
	 *
	 * Ported from Comment & Form Guard's Comment_Form_Guard_Helpers::ip_in_cidr().
	 */
	private static function ip_in_cidr( string $ip, string $cidr ): bool {
		if ( ! str_contains( $cidr, '/' ) ) {
			return false;
		}

		[ $subnet, $mask ] = explode( '/', $cidr );

		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 )
			&& filter_var( $subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			$ip_long     = ip2long( $ip );
			$subnet_long = ip2long( $subnet );
			$mask_long   = -1 << ( 32 - (int) $mask );

			return ( $ip_long & $mask_long ) === ( $subnet_long & $mask_long );
		}

		return false;
	}
}
