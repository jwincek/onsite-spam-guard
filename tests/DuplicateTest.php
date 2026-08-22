<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Guards\Duplicate;

final class DuplicateTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options']    = [];
		$GLOBALS['simple_spam_shield_test_transients'] = [];
		$_SERVER['REMOTE_ADDR']         = '198.51.100.7';
	}

	private function guard(): Duplicate {
		return new Duplicate( 'duplicate', [ 'window_seconds' => 60 ] );
	}

	public function test_allows_first_submission_then_blocks_an_identical_repeat(): void {
		$data = [
			'content' => 'hello there',
			'author'  => 'Bob',
			'email'   => 'bob@example.com',
		];
		$this->assertTrue( $this->guard()->check( $data, 'comment' ) );

		// The runner commits once no guard has objected; that is what records
		// the submission, not the check itself.
		$this->guard()->commit( $data, 'comment' );

		$this->assertInstanceOf( WP_Error::class, $this->guard()->check( $data, 'comment' ) );
	}

	/** Checking without committing must leave nothing behind. */
	public function test_a_check_alone_never_records_the_submission(): void {
		$data = [
			'content' => 'hello there',
			'author'  => 'Bob',
			'email'   => 'bob@example.com',
		];

		for ( $i = 0; $i < 5; $i++ ) {
			$this->assertTrue( $this->guard()->check( $data, 'comment' ), "check {$i} recorded the submission" );
		}
	}

	public function test_allows_a_different_submission(): void {
		$first = [
			'content' => 'hello there',
			'author'  => 'Bob',
			'email'   => 'bob@example.com',
		];
		$this->assertTrue( $this->guard()->check( $first, 'comment' ) );
		$this->guard()->commit( $first, 'comment' );

		$second            = $first;
		$second['content'] = 'a completely different message';
		$this->assertTrue( $this->guard()->check( $second, 'comment' ) );
	}

	public function test_distinguishes_identical_content_by_ip(): void {
		$data = [
			'content' => 'hello there',
			'author'  => 'Bob',
			'email'   => 'bob@example.com',
		];
		$this->assertTrue( $this->guard()->check( $data, 'comment' ) );
		$this->guard()->commit( $data, 'comment' );

		// The same content from a different IP is not a duplicate.
		$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
		$this->assertTrue( $this->guard()->check( $data, 'comment' ) );
	}
}
