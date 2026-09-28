<?php
/**
 * Keyword Block guard — rejects submissions containing blocked keywords.
 *
 * Two lists are consulted: the plugin's own list (one keyword/phrase per line
 * in simple_spam_shield_blocked_keywords) and, optionally, WordPress's own
 * Disallowed Comment Keys (Settings -> Discussion). Applying the latter through
 * wp_check_comment_disallowed_list() extends that one core blocklist to every
 * form the plugin protects — reviews, Jetpack forms, and API integrations —
 * not just comments.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Keyword_Block extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		$content = $data['content'] ?? $data['comment'] ?? '';
		$author  = $data['author'] ?? $data['author_name'] ?? '';
		$email   = $data['email'] ?? $data['author_email'] ?? '';

		// 1. The plugin's own keyword list.
		$keywords_raw = get_option( 'simple_spam_shield_blocked_keywords', '' );
		$haystack     = trim( self::lower( "{$content} {$author} {$email}" ) );

		if ( ! empty( $keywords_raw ) && '' !== $haystack ) {
			$keywords = array_filter( array_map( 'trim', explode( "\n", $keywords_raw ) ) );

			foreach ( $keywords as $keyword ) {
				if ( self::matches( $haystack, self::lower( $keyword ) ) ) {
					return $this->fail(
						__( 'Submission rejected — contains blocked content.', 'onsite-spam-guard' )
					);
				}
			}
		}

		// 2. Optionally, WordPress's own Disallowed Comment Keys, applied to
		// every context (core only applies it to comments itself).
		if ( get_option( 'simple_spam_shield_use_wp_disallowed_keys', false ) && function_exists( 'wp_check_comment_disallowed_list' ) ) {
			$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

			if ( wp_check_comment_disallowed_list( (string) $author, (string) $email, '', (string) $content, \Simple_Spam_Shield\Core\Request::ip(), $user_agent ) ) {
				return $this->fail(
					__( 'Submission rejected — contains blocked content.', 'onsite-spam-guard' )
				);
			}
		}

		return true;
	}

	/**
	 * Lowercase for comparison, correctly for any script.
	 *
	 * PHP 8's strtolower() only folds ASCII, so it left Cyrillic, Greek and
	 * every other non-Latin script in its original case — "КАЗИНО" never
	 * matched a blocked "казино". Invalid UTF-8 is scrubbed first, because a
	 * single bad byte makes the Unicode-aware matching below fail outright.
	 *
	 * @param string $text Text to lowercase.
	 */
	private static function lower( string $text ): string {
		if ( ! function_exists( 'mb_strtolower' ) ) {
			return strtolower( $text );
		}

		if ( function_exists( 'mb_scrub' ) ) {
			$text = mb_scrub( $text, 'UTF-8' );
		}

		return mb_strtolower( $text, 'UTF-8' );
	}

	/**
	 * Whether a blocked keyword appears in the submission.
	 *
	 * A single word must stand alone, so "cialis" does not fire on
	 * "specialist". The boundary is any character that is not a letter or
	 * digit in any script. The previous `\b` without the `u` flag treated every
	 * byte of a non-Latin letter as a non-word character, so a Cyrillic or CJK
	 * word had no boundary to anchor on and never matched at all.
	 *
	 * Phrases match as substrings, as before. So do keywords in scripts written
	 * without spaces between words — Chinese, Japanese, Thai and their
	 * neighbours — where a word is naturally surrounded by other letters and a
	 * boundary requirement would make it unmatchable.
	 *
	 * @param string $haystack Lowercased submission text.
	 * @param string $keyword  Lowercased keyword or phrase.
	 */
	private static function matches( string $haystack, string $keyword ): bool {
		if ( '' === $keyword ) {
			return false;
		}

		$unspaced = '/[\p{Han}\p{Hiragana}\p{Katakana}\p{Thai}\p{Lao}\p{Khmer}\p{Myanmar}]/u';

		if ( str_contains( $keyword, ' ' ) || 1 === preg_match( $unspaced, $keyword ) ) {
			return str_contains( $haystack, $keyword );
		}

		// Underscore is a separator, not part of a word. `\b` counted it as a
		// word character, which let any underscore-joined name escape:
		// "casino_bonus_77" and "promo_casino@example.com" never matched a
		// blocked "casino". Usernames and email addresses use it exactly as
		// they use hyphens and dots, and the email is screened in every form.
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/iu';

		return 1 === preg_match( $pattern, $haystack );
	}
}
