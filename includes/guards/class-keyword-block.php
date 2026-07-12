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
		$haystack     = trim( strtolower( "{$content} {$author} {$email}" ) );

		if ( ! empty( $keywords_raw ) && '' !== $haystack ) {
			$keywords = array_filter( array_map( 'trim', explode( "\n", $keywords_raw ) ) );

			foreach ( $keywords as $keyword ) {
				$keyword = strtolower( $keyword );

				// Multi-word phrase: substring match. Single word: word boundary.
				$matched = str_contains( $keyword, ' ' )
					? str_contains( $haystack, $keyword )
					: (bool) preg_match( '/\b' . preg_quote( $keyword, '/' ) . '\b/i', $haystack );

				if ( $matched ) {
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
}
