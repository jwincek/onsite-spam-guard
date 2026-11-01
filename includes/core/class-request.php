<?php
/**
 * Request helpers — single source of truth for reading the client request.
 *
 * Centralizes visitor-IP detection so every guard and the allowlist agree
 * on the same value. By default only the direct connection IP
 * (REMOTE_ADDR) is trusted; forwarded headers are honored *only* when the
 * admin explicitly enables the trusted-proxy option.
 *
 * When they are, the address is read from the **right** of X-Forwarded-For.
 * Proxies append to that header rather than replacing it, so a client can
 * pre-seed it with any address they like and the proxy adds the real one
 * after. The entries the site's own proxies appended are the only ones a
 * client cannot write.
 *
 * Addresses come back in one canonical spelling, so the same address is never
 * counted, logged or allowlisted as two. For counting a sender, sender() goes
 * further and groups an IPv6 address with the rest of its /64.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Request {

	/**
	 * Resolve the visitor's IP address.
	 *
	 * @return string A validated IP in canonical form, or '0.0.0.0' when none
	 *                can be determined.
	 */
	public static function ip(): string {
		$remote = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '';

		// Only consult forwarded headers when the site is explicitly
		// configured to sit behind a trusted reverse proxy / load balancer.
		if ( get_option( 'simple_spam_shield_trust_proxy', false ) && ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$raw     = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			$entries = array_values( array_filter( array_map( 'trim', explode( ',', $raw ) ), static fn( $entry ) => '' !== $entry ) );

			$hops  = self::trusted_proxy_hops();
			$index = count( $entries ) - $hops;

			if ( $index >= 0 && filter_var( $entries[ $index ], FILTER_VALIDATE_IP ) ) {
				return self::canonical( $entries[ $index ] );
			}
		}

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? self::canonical( $remote ) : '0.0.0.0';
	}

	/**
	 * One spelling for one address.
	 *
	 * IPv6 has many ways to write the same address — upper or lower case,
	 * leading zeros, where the "::" falls — and a connection can arrive with
	 * any of them. An IPv4 client can also be reported in IPv6 form
	 * (::ffff:192.0.2.1). Compared as text, each would be a different sender,
	 * and an allowlisted address written one way would miss a request
	 * written another.
	 *
	 * @param string $ip An IP address; anything else is returned unchanged.
	 * @return string The address as inet_ntop() writes it, IPv4-mapped
	 *                addresses as plain IPv4.
	 */
	public static function canonical( string $ip ): string {
		if ( ! filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}

		$packed = inet_pton( $ip );
		if ( false === $packed ) {
			return $ip;
		}

		// ::ffff:a.b.c.d is an IPv4 address carried in IPv6.
		if ( 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			$packed = substr( $packed, 12 );
		}

		$text = inet_ntop( $packed );

		return false === $text ? $ip : $text;
	}

	/**
	 * Who a submission comes from, for counting: the address for IPv4, and
	 * its /64 network for IPv6.
	 *
	 * An IPv6 subscriber is normally given a whole /64 — eighteen million
	 * trillion addresses — and can use any of them. Keyed on the exact
	 * address, a guard that counts submissions per sender would see each one
	 * as a stranger. A /64 is what one household or office gets, so treating
	 * it as one sender is the usual unit for rate limiting.
	 *
	 * @param string|null $ip Address to classify; the visitor's by default.
	 * @return string An IPv4 address, or an IPv6 network such as "2001:db8:1:2::/64".
	 */
	public static function sender( ?string $ip = null ): string {
		$ip = self::canonical( $ip ?? self::ip() );

		if ( ! filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return $ip;
		}

		$packed  = (string) inet_pton( $ip );
		$network = inet_ntop( substr( $packed, 0, 8 ) . str_repeat( "\0", 8 ) );

		return ( false === $network ? $ip : $network ) . '/64';
	}
	/**
	 * How many trusted proxies sit in front of the site.
	 *
	 * Shared by ip() and the proxy diagnostics, so the diagnostics always
	 * report on the address the plugin actually uses.
	 *
	 * @return int At least 1.
	 */
	public static function trusted_proxy_hops(): int {
		/**
		 * Filters how many trusted proxies sit in front of the site.
		 *
		 * Each proxy appends the address it received the request from, so
		 * the visitor's address is this many entries from the right of
		 * X-Forwarded-For. The default of 1 fits a site behind a single
		 * proxy — Cloudflare straight to the server, or one load balancer.
		 * Behind two (Cloudflare in front of a load balancer), return 2;
		 * otherwise every visitor resolves to a Cloudflare edge address.
		 *
		 * Anything further left than this was supplied by the client and
		 * is never used.
		 *
		 * @since 1.5.1
		 *
		 * @param int $hops Number of trusted proxies. Minimum 1.
		 */
		return max( 1, (int) apply_filters( 'simple_spam_shield_trusted_proxy_hops', 1 ) );
	}
}
