<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Guards\Link_Limit;

final class LinkLimitTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [ 'simple_spam_shield_link_limit_max' => 3 ];
	}

	private function guard(): Link_Limit {
		return new Link_Limit( 'link_limit', [ 'max_links' => 3 ] );
	}

	public function test_blocks_content_with_too_many_links(): void {
		$content = 'a http://a.com b http://b.com c http://c.com d https://d.com';
		$result  = $this->guard()->check( [ 'content' => $content ], 'comment' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_allows_content_at_the_limit(): void {
		$content = 'see http://a.com and http://b.com and http://c.com';
		$this->assertTrue( $this->guard()->check( [ 'content' => $content ], 'comment' ) );
	}

	public function test_allows_content_with_no_links(): void {
		$this->assertTrue( $this->guard()->check( [ 'content' => 'a perfectly normal comment' ], 'comment' ) );
	}

	public function test_allows_empty_content(): void {
		$this->assertTrue( $this->guard()->check( [ 'content' => '' ], 'comment' ) );
	}

	public function test_respects_a_configured_max_from_options(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max'] = 1;
		$content                                                         = 'http://a.com and http://b.com';
		$this->assertInstanceOf( WP_Error::class, $this->guard()->check( [ 'content' => $content ], 'comment' ) );
	}

	/**
	 * Rich-text editors autolink constantly, producing
	 * <a href="https://x">https://x</a> — one link a reader sees, two raw
	 * regex matches. A WP Job Manager description with four links, one
	 * autolinked, counted five and was rejected at the default limit of three.
	 */
	public function test_an_autolinked_url_counts_once(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max'] = 1;
		$guard = new Link_Limit( 'link_limit', [ 'max_links' => 1 ] );

		$this->assertTrue(
			$guard->check(
				[ 'content' => '<p>See <a href="https://acme.example">https://acme.example</a></p>' ],
				'comment'
			)
		);
	}

	public function test_distinct_links_are_still_counted_separately(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max'] = 3;
		$guard = new Link_Limit( 'link_limit', [ 'max_links' => 3 ] );

		$this->assertInstanceOf(
			WP_Error::class,
			$guard->check(
				[ 'content' => 'https://a.example https://b.example https://c.example https://d.example' ],
				'comment'
			)
		);
	}

	/** Trailing punctuation belongs to the sentence, not the address. */
	public function test_trailing_punctuation_does_not_split_one_link_into_two(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_link_limit_max'] = 1;
		$guard = new Link_Limit( 'link_limit', [ 'max_links' => 1 ] );

		$this->assertTrue(
			$guard->check( [ 'content' => 'See https://a.example, or https://a.example.' ], 'comment' )
		);
	}
}
