<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Core\Config;
use Simple_Spam_Shield\Core\Guard_Runner;

/**
 * The allowlist is the documented escape hatch for false positives, so a
 * silent failure there is especially unhelpful — an admin believes a visitor
 * is exempt and they are not.
 *
 * is_allowlisted() and ip_in_cidr() are private, so these exercise them the
 * way the plugin does: through Guard_Runner::run() with a submission that
 * would otherwise be blocked. A filled honeypot is the highest-weight guard,
 * so "passes anyway" can only mean the allowlist bypassed the pipeline.
 */
final class AllowlistTest extends TestCase {

	public static function setUpBeforeClass(): void {
		Config::init( SIMPLE_SPAM_SHIELD_PLUGIN_ROOT . '/config/' );
		Guard_Runner::init();
	}

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [
			'simple_spam_shield_enabled'     => true,
			'simple_spam_shield_log_blocked' => false,
		];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$_POST                                         = [];
	}

	/** A submission that every install would block: the honeypot is filled. */
	private function spammy(): array {
		return [
			'content'                        => 'buy cheap things',
			'author'                         => 'Bot',
			'email'                          => 'bot@example.com',
			'simple_spam_shield_website_url' => 'http://spam.example',
		];
	}

	private function allow( string $list, string $ip ): bool {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_allowlist'] = $list;
		$_SERVER['REMOTE_ADDR'] = $ip;
		return true === Guard_Runner::run( $this->spammy(), 'comment' );
	}

	public function test_blocked_when_the_allowlist_is_empty(): void {
		$this->assertFalse( $this->allow( '', '192.0.2.10' ) );
	}

	public function test_exact_ipv4_match_bypasses_every_guard(): void {
		$this->assertTrue( $this->allow( '192.0.2.10', '192.0.2.10' ) );
	}

	public function test_non_matching_exact_ip_does_not_bypass(): void {
		$this->assertFalse( $this->allow( '192.0.2.11', '192.0.2.10' ) );
	}

	public function test_ipv4_cidr_range(): void {
		$this->assertTrue( $this->allow( '192.0.2.0/24', '192.0.2.10' ) );
		$this->assertFalse( $this->allow( '198.51.100.0/24', '192.0.2.10' ) );
	}

	/** Regression: IPv6 ranges silently never matched before this was fixed. */
	public function test_ipv6_cidr_range(): void {
		$this->assertTrue( $this->allow( '2001:db8::/32', '2001:db8::1' ) );
		$this->assertFalse( $this->allow( '2001:db8::/32', '2001:db9::1' ) );
	}

	public function test_exact_ipv6_address(): void {
		$this->assertTrue( $this->allow( '2001:db8::1', '2001:db8::1' ) );
	}

	public function test_address_families_do_not_cross_match(): void {
		$this->assertFalse( $this->allow( '2001:db8::/32', '192.0.2.10' ) );
		$this->assertFalse( $this->allow( '192.0.2.0/24', '2001:db8::1' ) );
	}

	public function test_malformed_entries_are_ignored_rather_than_matching_everything(): void {
		foreach ( [ 'not-an-ip/24', '192.0.2.0/abc', '192.0.2.0/33', '///' ] as $bad ) {
			$this->assertFalse( $this->allow( $bad, '192.0.2.10' ), "entry: {$bad}" );
		}
	}

	public function test_exact_email_match(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_allowlist'] = 'bot@example.com';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$this->assertTrue( true === Guard_Runner::run( $this->spammy(), 'comment' ) );
	}

	public function test_email_domain_pattern(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_allowlist'] = '@example.com';
		$_SERVER['REMOTE_ADDR'] = '192.0.2.10';
		$this->assertTrue( true === Guard_Runner::run( $this->spammy(), 'comment' ) );
	}

	public function test_multiple_entries_one_line_each(): void {
		$list = "198.51.100.5\n2001:db8::/32\n@trusted.invalid";
		$this->assertTrue( $this->allow( $list, '2001:db8::99' ) );
		$this->assertFalse( $this->allow( $list, '203.0.113.1' ) );
	}
}
