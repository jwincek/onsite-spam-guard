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

		foreach ( self::definitions() as $slug => $def ) {
			$class = $def['class'] ?? null;

			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				continue;
			}

			// A guard that does not implement the contract would fail at the
			// first submission; skip it now and say why, rather than fataling
			// on someone's comment form.
			if ( ! is_subclass_of( $class, Guard_Interface::class ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: 1: guard slug, 2: class name, 3: interface name. */
						esc_html__( 'Guard "%1$s" was skipped: %2$s does not implement %3$s.', 'onsite-spam-guard' ),
						esc_html( (string) $slug ),
						esc_html( $class ),
						'Guard_Interface'
					),
					'1.3.0'
				);
				continue;
			}

			self::$guards[] = new $class( (string) $slug, $def );
		}

		// Sort by weight descending (highest priority first).
		usort( self::$guards, fn( Guard_Interface $a, Guard_Interface $b ) => $b->get_weight() <=> $a->get_weight() );
	}

	/**
	 * The guard definitions, after filtering.
	 *
	 * Built-in guards come from config/guards.json; each entry is given the
	 * class that implements it. Other plugins can register their own guard, or
	 * adjust or remove a built-in one, through the
	 * `simple_spam_shield_guards` filter.
	 *
	 * This is the single source of truth for both the pipeline and the
	 * settings screen, so a registered guard automatically gets its own on/off
	 * toggle on the Guards tab without any extra work.
	 *
	 * @return array<string, array<string, mixed>> Slug => definition.
	 */
	public static function definitions(): array {
		$builtin_classes = [
			'honeypot'      => \Simple_Spam_Shield\Guards\Honeypot::class,
			'time_gate'     => \Simple_Spam_Shield\Guards\Time_Gate::class,
			'nonce'         => \Simple_Spam_Shield\Guards\Nonce::class,
			'link_limit'    => \Simple_Spam_Shield\Guards\Link_Limit::class,
			'keyword_block' => \Simple_Spam_Shield\Guards\Keyword_Block::class,
			'duplicate'     => \Simple_Spam_Shield\Guards\Duplicate::class,
			'rate_limit'    => \Simple_Spam_Shield\Guards\Rate_Limit::class,
			'behavioral'    => \Simple_Spam_Shield\Guards\Behavioral::class,
		];

		$definitions = [];

		foreach ( Config::get( 'guards', 'guards', [] ) as $slug => $def ) {
			if ( ! isset( $builtin_classes[ $slug ] ) ) {
				continue;
			}

			$def['class']         = $builtin_classes[ $slug ];
			$definitions[ $slug ] = $def;
		}

		/**
		 * Filter the guards that make up the spam-check pipeline.
		 *
		 * Each entry is keyed by slug and understands:
		 *
		 *   class              Fully-qualified class implementing Guard_Interface.
		 *   label              Shown on the Guards settings tab.
		 *   description        Optional longer explanation.
		 *   weight             Higher runs first; the highest-weight failure
		 *                      decides the outcome and the visitor's message.
		 *   enabled_by_default Whether the toggle starts on.
		 *
		 * Any other keys are handed to the guard's constructor as its config.
		 *
		 * Hook this before `plugins_loaded` priority 10 — registering it when
		 * your plugin file loads is the usual way.
		 *
		 * A registered guard is evaluated even after another guard has blocked,
		 * so the log can record every guard that matched. `check()` must
		 * therefore return the same verdict whatever the outcome. State
		 * describing an *accepted* submission belongs in `commit()`, which the
		 * runner calls only once no guard has objected.
		 *
		 * @since 1.3.0
		 *
		 * @param array<string, array<string, mixed>> $definitions Slug => definition.
		 */
		/** @var mixed $filtered */
		$filtered = apply_filters( 'simple_spam_shield_guards', $definitions );

		// A filter belonging to another plugin can return anything. Falling back
		// to an empty list would silently disable every guard and leave the site
		// unprotected, so fall back to the built-in definitions instead.
		return is_array( $filtered ) ? $filtered : $definitions;
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

		$verdict = null;
		$matched = [];
		$enabled = [];

		foreach ( self::$guards as $guard ) {
			if ( ! $guard->is_enabled() ) {
				continue;
			}

			// Every enabled guard is evaluated even once one has blocked, so the
			// log can record each guard that matched rather than only the first.
			// The verdict is still decided by the highest-weight failure, so
			// nothing evaluated later changes what the visitor sees.
			$enabled[] = $guard;

			$result = $guard->check( $data, $context );

			if ( ! is_wp_error( $result ) ) {
				continue;
			}

			$matched[] = $guard->get_slug();

			// The highest-weight failure decides the outcome and the message.
			if ( null === $verdict ) {
				$verdict = $result;
			}
		}

		// Nothing objected: tell the guards the submission was accepted, so the
		// ones holding state record it. Doing this only now is the whole point —
		// a guard must not record a submission that some later guard rejects.
		if ( null === $verdict ) {
			foreach ( $enabled as $guard ) {
				$guard->commit( $data, $context );
			}

			return true;
		}

		self::log_block( $matched[0], $context, $verdict->get_error_message(), $data, $matched );

		/**
		 * Fires when a submission has been blocked.
		 *
		 * Runs after the block is logged and just before the error is returned
		 * to whichever integration is handling the submission. Use it to
		 * notify, count, or feed a dashboard without polling the log table.
		 *
		 * Thanks to the pipeline evaluating every guard, $matched lists all of
		 * them, not just the one that decided the outcome — $guard is always
		 * $matched[0].
		 *
		 * $data carries the submitted content, author name and email, so treat
		 * it as personal data: do not send it anywhere the site owner has not
		 * agreed to.
		 *
		 * @since 1.3.0
		 *
		 * @param string   $guard   Slug of the guard that decided the block.
		 * @param string   $context Form context, e.g. 'comment' or a custom label.
		 * @param string[] $matched Every guard that matched, in weight order.
		 * @param array    $data    Normalized submission data.
		 */
		do_action( 'simple_spam_shield_blocked', $matched[0], $context, $matched, $data );

		return $verdict;
	}

	/**
	 * Log a blocked submission to the custom database table.
	 *
	 * @param string   $guard   Slug of the guard that blocked the submission.
	 * @param string   $context Form context.
	 * @param string   $reason  Human-readable block reason.
	 * @param array    $data    Normalized submission data (provides the content).
	 * @param string[] $matched Every guard that failed, in weight order. The
	 *                          first is $guard; the rest were evaluated for the
	 *                          record after the outcome was already decided.
	 */
	private static function log_block( string $guard, string $context, string $reason, array $data, array $matched = [] ): void {
		if ( ! (bool) get_option( 'simple_spam_shield_log_blocked', true ) ) {
			return;
		}

		// The content comes from the normalized submission data so the log
		// works for any form (comments, reviews, Jetpack, or a third-party
		// form via simple_spam_shield_check()). It is escaped on insert and
		// only ever rendered escaped in the log table.
		Database_Manager::insert( [
			'guard'          => $guard,
			'guards_matched' => implode( ',', $matched ),
			'context'        => $context,
			'reason'         => $reason,
			'content'        => (string) ( $data['content'] ?? '' ),
			'ip_address'     => Request::ip(),
			'user_agent'     => sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ?? '' ) ),
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
