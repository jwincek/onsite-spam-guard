<?php
/**
 * Keyword Block guard — rejects submissions containing blocked keywords.
 *
 * Keywords are stored one per line in the simple_spam_shield_blocked_keywords option.
 * Each keyword is matched case-insensitively as a whole word boundary.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Keyword_Block extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		$keywords_raw = get_option( 'simple_spam_shield_blocked_keywords', '' );

		if ( empty( $keywords_raw ) ) {
			return true;
		}

		$content  = strtolower( $data['content'] ?? $data['comment'] ?? '' );
		$author   = strtolower( $data['author'] ?? $data['author_name'] ?? '' );
		$email    = strtolower( $data['email'] ?? $data['author_email'] ?? '' );
		$haystack = "{$content} {$author} {$email}";

		if ( empty( trim( $haystack ) ) ) {
			return true;
		}

		$keywords = array_filter(
			array_map( 'trim', explode( "\n", $keywords_raw ) )
		);

		foreach ( $keywords as $keyword ) {
			$keyword = strtolower( $keyword );

			// Use word boundary matching for single words, substring for phrases.
			if ( str_contains( $keyword, ' ' ) ) {
				// Multi-word phrase: substring match.
				if ( str_contains( $haystack, $keyword ) ) {
					return $this->fail(
						__( 'Submission rejected — contains blocked content.', 'onsite-spam-guard' )
					);
				}
			} elseif ( preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $haystack ) ) {
				// Single word: word boundary match.
				return $this->fail(
					__( 'Submission rejected — contains blocked content.', 'onsite-spam-guard' )
				);
			}
		}

		return true;
	}
}
