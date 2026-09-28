<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Proxy_Diagnostics;
use Simple_Spam_Shield\Core\Request;

/** Trusted-proxy diagnostics (#50). */
final class ProxyDiagnosticsTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [];
		$GLOBALS['simple_spam_shield_test_filters'] = [];
		$_SERVER['REMOTE_ADDR']                     = '10.0.0.1';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
	}

	/** A snapshot with only the fields assess() reads, overridable per case. */
	private function snap( array $overrides = [] ): array {
		return array_merge(
			[
				'trust_proxy' => false,
				'remote'      => '10.0.0.1',
				'forwarded'   => '',
				'entries'     => 0,
				'hops'        => 1,
				'resolved'    => '10.0.0.1',
				'allowlisted' => false,
			],
			$overrides
		);
	}

	// --- trust proxy off -----------------------------------------------------

	public function test_off_with_no_forwarded_header_is_good(): void {
		$this->assertSame( Proxy_Diagnostics::GOOD, Proxy_Diagnostics::assess( $this->snap() )['status'] );
	}

	/** Behind a proxy with the setting off, every visitor looks like one sender. */
	public function test_off_with_a_forwarded_header_is_flagged(): void {
		$result = Proxy_Diagnostics::assess( $this->snap( [ 'forwarded' => '198.51.100.9', 'entries' => 1 ] ) );

		$this->assertSame( Proxy_Diagnostics::RECOMMENDED, $result['status'] );
	}

	// --- trust proxy on, no proxy in the path -------------------------------

	/**
	 * The misconfiguration this exists for: the setting trusts a header that
	 * this request shows no proxy is adding. With an allowlist, a visitor who
	 * supplies an allowlisted address skips every guard.
	 */
	public function test_on_with_no_forwarded_header_and_an_allowlist_is_critical(): void {
		$result = Proxy_Diagnostics::assess( $this->snap( [ 'trust_proxy' => true, 'allowlisted' => true ] ) );

		$this->assertSame( Proxy_Diagnostics::CRITICAL, $result['status'] );
	}

	/** Without an allowlist only the rate limit and duplicate detection are at stake. */
	public function test_on_with_no_forwarded_header_and_no_allowlist_is_recommended(): void {
		$result = Proxy_Diagnostics::assess( $this->snap( [ 'trust_proxy' => true ] ) );

		$this->assertSame( Proxy_Diagnostics::RECOMMENDED, $result['status'] );
	}

	// --- trust proxy on, through a proxy ------------------------------------

	public function test_on_through_one_proxy_is_good(): void {
		$result = Proxy_Diagnostics::assess( $this->snap( [ 'trust_proxy' => true, 'forwarded' => '198.51.100.9', 'entries' => 1 ] ) );

		$this->assertSame( Proxy_Diagnostics::GOOD, $result['status'] );
		$this->assertStringNotContainsString( 'trusted_proxy_hops', $result['description'] );
	}

	/** Two entries with one configured hop is how an unconfigured second proxy looks. */
	public function test_more_entries_than_hops_points_at_the_hop_filter(): void {
		$result = Proxy_Diagnostics::assess(
			$this->snap( [ 'trust_proxy' => true, 'forwarded' => '198.51.100.9, 104.16.0.1', 'entries' => 2 ] )
		);

		$this->assertSame( Proxy_Diagnostics::GOOD, $result['status'] );
		$this->assertStringContainsString( 'simple_spam_shield_trusted_proxy_hops', $result['description'] );
	}

	public function test_fewer_entries_than_hops_is_flagged(): void {
		$result = Proxy_Diagnostics::assess(
			$this->snap( [ 'trust_proxy' => true, 'forwarded' => '198.51.100.9', 'entries' => 1, 'hops' => 2 ] )
		);

		$this->assertSame( Proxy_Diagnostics::RECOMMENDED, $result['status'] );
	}

	// --- the snapshot --------------------------------------------------------

	/** The diagnostics must report the address the plugin actually uses. */
	public function test_the_snapshot_resolves_the_same_address_as_the_plugin(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 198.51.100.99';

		$snap = Proxy_Diagnostics::snapshot();

		$this->assertSame( Request::ip(), $snap['resolved'] );
		$this->assertSame( '198.51.100.99', $snap['resolved'] );
		$this->assertSame( 2, $snap['entries'] );
	}

	public function test_the_snapshot_follows_the_hop_filter(): void {
		add_filter( 'simple_spam_shield_trusted_proxy_hops', static fn() => 2 );

		$this->assertSame( 2, Proxy_Diagnostics::snapshot()['hops'] );
	}

	// --- Site Health -----------------------------------------------------------

	public function test_the_site_health_test_is_registered_as_a_direct_test(): void {
		$tests = Proxy_Diagnostics::register_site_health_test( [ 'direct' => [], 'async' => [] ] );

		$this->assertArrayHasKey( 'onsite_spam_guard_trusted_proxy', $tests['direct'] );
	}

	public function test_a_malformed_tests_value_is_passed_through(): void {
		$this->assertSame( 'nope', Proxy_Diagnostics::register_site_health_test( 'nope' ) );
	}

	public function test_the_site_health_result_has_the_shape_core_expects(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_allowlist']   = '203.0.113.50';

		$result = Proxy_Diagnostics::site_health_test();

		foreach ( [ 'label', 'status', 'badge', 'description', 'actions', 'test' ] as $key ) {
			$this->assertArrayHasKey( $key, $result );
		}
		$this->assertSame( Proxy_Diagnostics::CRITICAL, $result['status'] );
	}
}
