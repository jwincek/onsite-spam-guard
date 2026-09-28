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
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Request {

	/**
	 * Resolve the visitor's IP address.
	 *
	 * @return string A validated IP, or '0.0.0.0' when none can be determined.
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
			$hops  = max( 1, (int) apply_filters( 'simple_spam_shield_trusted_proxy_hops', 1 ) );
			$index = count( $entries ) - $hops;

			if ( $index >= 0 && filter_var( $entries[ $index ], FILTER_VALIDATE_IP ) ) {
				return $entries[ $index ];
			}
		}

		return filter_var( $remote, FILTER_VALIDATE_IP ) ? $remote : '0.0.0.0';
	}
}
