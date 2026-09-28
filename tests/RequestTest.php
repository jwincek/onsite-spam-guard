<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Request;

final class RequestTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [];
		$_SERVER['REMOTE_ADDR']      = '203.0.113.5';
		unset( $_SERVER['HTTP_X_FORWARDED_FOR'] );
		$GLOBALS['simple_spam_shield_test_filters'] = [];
	}

	public function test_uses_remote_addr_and_ignores_forwarded_header_by_default(): void {
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '8.8.8.8';
		$this->assertSame( '203.0.113.5', Request::ip() );
	}

	/**
	 * Proxies append to X-Forwarded-For. The entry the site's own proxy added
	 * is the rightmost one; everything to its left came from the client.
	 */
	public function test_honors_the_proxy_appended_entry_when_proxy_is_trusted(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		$this->assertSame( '198.51.100.99', Request::ip() );
	}

	/**
	 * A client can pre-seed the header with any address, and the proxy appends
	 * the real one after it. The seeded value must never be used — it would let
	 * a visitor pose as an allowlisted address, or change address on every
	 * request to slip past the rate limit and duplicate detection.
	 */
	public function test_an_address_supplied_by_the_client_is_ignored(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 198.51.100.99';

		$this->assertSame( '198.51.100.99', Request::ip() );
	}

	/** Behind two proxies the visitor is the second entry from the right. */
	public function test_the_hop_count_can_be_raised_for_a_second_proxy(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		add_filter( 'simple_spam_shield_trusted_proxy_hops', static fn() => 2 );
		$_SERVER['REMOTE_ADDR']          = '10.0.0.1';
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.50, 198.51.100.99, 104.16.0.1';

		$this->assertSame( '198.51.100.99', Request::ip() );
	}

	/** More hops than entries means the header is not what we expect. */
	public function test_too_few_entries_falls_back_to_remote_addr(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		add_filter( 'simple_spam_shield_trusted_proxy_hops', static fn() => 3 );
		$_SERVER['HTTP_X_FORWARDED_FOR'] = '198.51.100.99';

		$this->assertSame( '203.0.113.5', Request::ip() );
	}

	public function test_empty_entries_and_whitespace_are_ignored(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		$_SERVER['HTTP_X_FORWARDED_FOR'] = ' 203.0.113.50 , , 198.51.100.99 , ';

		$this->assertSame( '198.51.100.99', Request::ip() );
	}

	public function test_falls_back_to_remote_addr_when_forwarded_header_is_invalid(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_trust_proxy'] = true;
		$_SERVER['HTTP_X_FORWARDED_FOR']                              = 'not-an-ip';
		$this->assertSame( '203.0.113.5', Request::ip() );
	}

	public function test_returns_zero_ip_for_invalid_remote_addr(): void {
		$_SERVER['REMOTE_ADDR'] = 'bogus';
		$this->assertSame( '0.0.0.0', Request::ip() );
	}
}
