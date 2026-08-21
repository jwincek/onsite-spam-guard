<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Guards\Honeypot;

final class HoneypotTest extends TestCase {

	private function guard(): Honeypot {
		return new Honeypot( 'honeypot', [] );
	}

	public function test_blocks_when_the_honeypot_field_is_filled(): void {
		$data   = [ 'simple_spam_shield_website_url' => 'http://spam.example' ];
		$result = $this->guard()->check( $data, 'comment' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_allows_when_the_honeypot_field_is_empty(): void {
		$data = [ 'simple_spam_shield_website_url' => '' ];
		$this->assertTrue( $this->guard()->check( $data, 'comment' ) );
	}

	public function test_allows_when_the_honeypot_field_is_absent(): void {
		$this->assertTrue( $this->guard()->check( [], 'comment' ) );
	}

	/**
	 * The field name is a constant, not configuration. It used to be readable
	 * from guards.json while the markup, front-end script and integrations all
	 * hardcoded it, so a changed value disabled the guard instead of renaming
	 * the field. Constructor config must not be able to override it.
	 */
	public function test_field_name_is_not_overridable_by_config(): void {
		$guard = new Honeypot( 'honeypot', [ 'field_name' => 'my_trap' ] );

		// The real field still blocks...
		$this->assertInstanceOf(
			WP_Error::class,
			$guard->check( [ Honeypot::FIELD => 'http://spam.example' ], 'comment' )
		);

		// ...and a name supplied via config is ignored rather than honoured.
		$this->assertTrue( $guard->check( [ 'my_trap' => 'http://spam.example' ], 'comment' ) );
	}
}
