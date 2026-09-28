<?php
/**
 * Trusted-proxy diagnostics.
 *
 * "Trust proxy headers" is only safe when the server accepts traffic solely
 * through the proxy. 1.5.1 documented that precondition, but documentation is
 * read after something goes wrong, not before a checkbox is ticked. This
 * inspects the current request and says whether the setting matches how the
 * server is actually being reached — on the Allowlist tab, where the setting
 * is changed, and in Site Health, where it is noticed later.
 *
 * Diagnostics only: nothing here changes how the address is resolved. The
 * request inspected is the administrator's own, so a site reached through a
 * proxy is seen through it — and a site reachable around one shows as such,
 * which is itself the thing worth knowing.
 *
 * @package Simple_Spam_Shield
 * @since   1.6.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Proxy_Diagnostics {

	public const GOOD        = 'good';
	public const RECOMMENDED = 'recommended';
	public const CRITICAL    = 'critical';

	/**
	 * What the server sees for the current request.
	 *
	 * @return array{trust_proxy: bool, remote: string, forwarded: string, entries: int, hops: int, resolved: string, allowlisted: bool}
	 */
	public static function snapshot(): array {
		$remote    = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$forwarded = isset( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) : '';
		$entries   = array_filter( array_map( 'trim', explode( ',', $forwarded ) ), static fn( $entry ) => '' !== $entry );

		return [
			'trust_proxy' => (bool) get_option( 'simple_spam_shield_trust_proxy', false ),
			'remote'      => $remote,
			'forwarded'   => $forwarded,
			'entries'     => count( $entries ),
			'hops'        => Request::trusted_proxy_hops(),
			'resolved'    => Request::ip(),
			'allowlisted' => '' !== trim( (string) get_option( 'simple_spam_shield_allowlist', '' ) ),
		];
	}

	/**
	 * Judge a snapshot. Shared by the settings screen and Site Health, so the
	 * two can never disagree.
	 *
	 * @param array $snap A snapshot().
	 * @return array{status: string, label: string, description: string}
	 */
	public static function assess( array $snap ): array {
		$has_header = $snap['entries'] > 0;

		if ( ! $snap['trust_proxy'] ) {
			if ( ! $has_header ) {
				return [
					'status'      => self::GOOD,
					'label'       => __( 'Visitor addresses come from the direct connection', 'onsite-spam-guard' ),
					'description' => __( 'Trust proxy headers is off, and this request arrived with no forwarded header, so the site does not appear to be behind a proxy. That is the safe configuration for a site reached directly.', 'onsite-spam-guard' ),
				];
			}

			return [
				'status'      => self::RECOMMENDED,
				'label'       => __( 'This site may be behind a proxy that Onsite Spam Guard is not using', 'onsite-spam-guard' ),
				'description' => __( 'This request arrived with a forwarded header, which usually means a proxy or load balancer sits in front of the site. With Trust proxy headers off, every visitor appears to come from the proxy, so rate limits and duplicate detection treat them all as one sender. If the proxy is yours and the server accepts traffic only through it, turn Trust proxy headers on.', 'onsite-spam-guard' ),
			];
		}

		if ( ! $has_header ) {
			return [
				// With an allowlist, a visitor supplying their own address can
				// skip every guard; without one, only the rate limit and
				// duplicate detection are at stake.
				'status'      => $snap['allowlisted'] ? self::CRITICAL : self::RECOMMENDED,
				'label'       => __( 'Trust proxy headers is on, but this request did not come through a proxy', 'onsite-spam-guard' ),
				'description' => __( 'This request reached the server with no forwarded header. Either the site is not behind a proxy, or the server can be reached around it. In both cases a visitor can supply their own address, which the plugin would believe. Turn Trust proxy headers off unless every request passes through your proxy, and if it should, restrict the server to accept traffic only from the proxy.', 'onsite-spam-guard' ),
			];
		}

		if ( $snap['entries'] < $snap['hops'] ) {
			return [
				'status'      => self::RECOMMENDED,
				'label'       => __( 'The forwarded header is shorter than the configured number of proxies', 'onsite-spam-guard' ),
				'description' => sprintf(
					/* translators: 1: number of entries in the forwarded header, 2: configured number of trusted proxies. */
					__( 'The forwarded header has %1$d entries, but the site is configured for %2$d trusted proxies, so the plugin falls back to the direct connection address. Check the simple_spam_shield_trusted_proxy_hops filter.', 'onsite-spam-guard' ),
					$snap['entries'],
					$snap['hops']
				),
			];
		}

		$description = __( 'Trust proxy headers is on and this request came through a proxy. The visitor address is read from the entry your proxy added, which a visitor cannot forge — provided the server accepts traffic only through that proxy.', 'onsite-spam-guard' );

		// Two or more entries with one trusted hop is how a second, unconfigured
		// proxy looks: every visitor would resolve to the first proxy's address.
		if ( $snap['entries'] > 1 && 1 === $snap['hops'] ) {
			$description .= ' ' . __( 'This request passed through more than one address. If the site sits behind two proxies — Cloudflare in front of a load balancer, for example — the address in use is the outer proxy, not the visitor; a developer can correct that with the simple_spam_shield_trusted_proxy_hops filter.', 'onsite-spam-guard' );
		}

		return [
			'status'      => self::GOOD,
			'label'       => __( 'Visitor addresses are read from your proxy', 'onsite-spam-guard' ),
			'description' => $description,
		];
	}

	/**
	 * Site Health test.
	 *
	 * @return array<string, mixed>
	 */
	public static function site_health_test(): array {
		$result = self::assess( self::snapshot() );

		return [
			'label'       => $result['label'],
			'status'      => $result['status'],
			'badge'       => [
				'label' => __( 'Security', 'onsite-spam-guard' ),
				'color' => self::GOOD === $result['status'] ? 'blue' : 'red',
			],
			'description' => '<p>' . esc_html( $result['description'] ) . '</p>',
			'actions'     => sprintf(
				'<p><a href="%s">%s</a></p>',
				esc_url( admin_url( 'admin.php?page=onsite-spam-guard' ) ),
				esc_html__( 'Review the Allowlist settings', 'onsite-spam-guard' )
			),
			'test'        => 'onsite_spam_guard_trusted_proxy',
		];
	}

	/**
	 * Register the Site Health test.
	 *
	 * @param mixed $tests Site Health tests. Typed loosely: it arrives through
	 *                     apply_filters(), and another plugin's callback can
	 *                     return anything.
	 * @return mixed
	 */
	public static function register_site_health_test( $tests ) {
		if ( ! is_array( $tests ) ) {
			return $tests;
		}

		$tests['direct']['onsite_spam_guard_trusted_proxy'] = [
			'label' => __( 'Onsite Spam Guard proxy configuration', 'onsite-spam-guard' ),
			'test'  => [ __CLASS__, 'site_health_test' ],
		];

		return $tests;
	}

	/**
	 * Render what the server sees, beneath the setting that depends on it.
	 */
	public static function render_panel(): void {
		$snap   = self::snapshot();
		$result = self::assess( $snap );
		$class  = [
			self::GOOD        => 'notice-success',
			self::RECOMMENDED => 'notice-warning',
			self::CRITICAL    => 'notice-error',
		][ $result['status'] ] ?? 'notice-info';

		$rows = [
			__( 'Connecting address', 'onsite-spam-guard' ) => '' !== $snap['remote'] ? $snap['remote'] : __( '(none)', 'onsite-spam-guard' ),
			__( 'Forwarded header', 'onsite-spam-guard' ) => '' !== $snap['forwarded'] ? $snap['forwarded'] : __( '(not present)', 'onsite-spam-guard' ),
			__( 'Address used for this request', 'onsite-spam-guard' ) => $snap['resolved'],
		];

		echo '<table class="widefat striped" style="max-width:48em"><tbody>';
		foreach ( $rows as $label => $value ) {
			printf( '<tr><th scope="row" style="width:16em">%s</th><td><code>%s</code></td></tr>', esc_html( $label ), esc_html( $value ) );
		}
		echo '</tbody></table>';

		// .inline keeps WordPress from moving the notice to the top of the page.
		printf(
			'<div class="notice inline %1$s" style="max-width:48em"><p><strong>%2$s</strong><br>%3$s</p></div>',
			esc_attr( $class ),
			esc_html( $result['label'] ),
			esc_html( $result['description'] )
		);
	}
}
