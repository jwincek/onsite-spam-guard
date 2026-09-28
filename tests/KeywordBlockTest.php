<?php
declare( strict_types=1 );

use PHPUnit\Framework\TestCase;
use Simple_Spam_Shield\Guards\Keyword_Block;

final class KeywordBlockTest extends TestCase {

	protected function setUp(): void {
		$GLOBALS['simple_spam_shield_test_options'] = [
			'simple_spam_shield_blocked_keywords' => "spam\nfree money",
		];
	}

	private function guard(): Keyword_Block {
		return new Keyword_Block( 'keyword_block', [] );
	}

	public function test_blocks_a_single_keyword_case_insensitively(): void {
		$result = $this->guard()->check( [ 'content' => 'This is SPAM!' ], 'comment' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_does_not_block_a_keyword_inside_a_larger_word(): void {
		// Word-boundary matching: "spammy" must not trip the "spam" keyword.
		$result = $this->guard()->check( [ 'content' => 'these spammy eggs' ], 'comment' );
		$this->assertTrue( $result );
	}

	public function test_blocks_a_multi_word_phrase_as_a_substring(): void {
		$result = $this->guard()->check( [ 'content' => 'win free money today' ], 'comment' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_allows_clean_content(): void {
		$this->assertTrue( $this->guard()->check( [ 'content' => 'lovely article, thanks' ], 'comment' ) );
	}

	public function test_allows_when_no_keywords_configured(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = '';
		$this->assertTrue( $this->guard()->check( [ 'content' => 'spam spam spam' ], 'comment' ) );
	}

	public function test_applies_wp_disallowed_keys_when_enabled(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']     = '';
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_use_wp_disallowed_keys'] = true;
		$GLOBALS['simple_spam_shield_test_options']['disallowed_keys']                         = 'forbidden';
		$_SERVER['REMOTE_ADDR']                                                                = '203.0.113.1';

		// A context core never checks itself (Jetpack form) still gets the list.
		$result = $this->guard()->check( [ 'content' => 'this is forbidden content' ], 'jetpack_form' );
		$this->assertInstanceOf( WP_Error::class, $result );
	}

	public function test_ignores_wp_disallowed_keys_when_toggle_off(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords']     = '';
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_use_wp_disallowed_keys'] = false;
		$GLOBALS['simple_spam_shield_test_options']['disallowed_keys']                         = 'forbidden';
		$_SERVER['REMOTE_ADDR']                                                                = '203.0.113.1';

		$this->assertTrue( $this->guard()->check( [ 'content' => 'this is forbidden content' ], 'jetpack_form' ) );
	}

	/**
	 * Keyword matching across scripts. Before 1.5.1 every single-word keyword
	 * outside Latin script silently never matched, and case-folding only
	 * worked for ASCII.
	 *
	 * @dataProvider multilingualCases
	 */
	public function test_keywords_match_in_any_script( string $keyword, string $content, bool $should_match ): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = $keyword;
		$result = ( new Keyword_Block( 'keyword_block', [] ) )->check( [ 'content' => $content ], 'comment' );

		$this->assertSame( $should_match, is_wp_error( $result ), "keyword '{$keyword}' in '{$content}'" );
	}

	/** @return array<string, array{0:string,1:string,2:bool}> */
	public static function multilingualCases(): array {
		return [
			'Cyrillic word'                 => [ 'казино', 'лучшее казино онлайн', true ],
			'Cyrillic word, other case'     => [ 'казино', 'лучшее КАЗИНО онлайн', true ],
			'Cyrillic phrase, other case'   => [ 'казино онлайн', 'лучшее КАЗИНО ОНЛАЙН', true ],
			'Greek word, other case'        => [ 'καζίνο', 'το ΚΑΖΊΝΟ εδώ', true ],
			'Chinese word inside a run'     => [ '赌场', '最好的赌场在这里', true ],
			'Japanese word inside a run'    => [ 'カジノ', '最高のカジノはここ', true ],
			'Latin word, other case'        => [ 'casino', 'visit my CASINO', true ],
			// Boundaries must still hold, in every script.
			'Latin word inside a word'      => [ 'cialis', 'ask a specialist', false ],
			'Cyrillic word inside a word'   => [ 'казино', 'казиноигры', false ],
			'accented word inside a word'   => [ 'café', 'les cafés', false ],
			// Underscore separates words in usernames and email addresses.
			'underscore-joined username'    => [ 'casino', 'casino_bonus_77', true ],
			'underscore in an email'        => [ 'casino', 'promo_casino@example.com', true ],
			'hyphen-joined username'        => [ 'casino', 'casino-bonus-77', true ],
			'genuinely one word'            => [ 'casino', 'casinobonus77', false ],
		];
	}

	/** One invalid byte must not switch keyword matching off for the whole submission. */
	public function test_invalid_utf8_does_not_disable_matching(): void {
		$GLOBALS['simple_spam_shield_test_options']['simple_spam_shield_blocked_keywords'] = 'casino';
		$result = ( new Keyword_Block( 'keyword_block', [] ) )->check(
			[ 'content' => "visit my casino \xC3\x28 now" ],
			'comment'
		);

		$this->assertInstanceOf( WP_Error::class, $result );
	}
}
