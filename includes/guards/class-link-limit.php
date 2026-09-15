<?php
/**
 * Link Limit guard — rejects submissions containing too many URLs.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Link_Limit extends Abstract_Guard {

	public function check( array $data, string $context ): \WP_Error|true {
		$max_links = (int) $this->threshold( 'simple_spam_shield_link_limit_max', $context, $this->config['max_links'] ?? 3 );
		$content   = $data['content'] ?? $data['comment'] ?? '';

		if ( empty( $content ) ) {
			return true;
		}

		// Count how many *distinct* addresses the submission links to.
		//
		// Counting raw matches double-counts an autolinked URL, which rich-text
		// editors produce constantly: <a href="https://x">https://x</a> is one
		// link a reader sees and two matches. A long description with four
		// links, one of them autolinked, counted five and was rejected at the
		// default limit of three.
		//
		// The trade is that a submission repeating one address many times now
		// counts as one link. That is the honest reading of "how many links is
		// this", and such content is squarely what the keyword and duplicate
		// guards are for.
		preg_match_all( '#https?://[^\s<>"\']+#i', $content, $matches );

		$urls = array_map(
			// Trailing punctuation belongs to the sentence, not the address, and
			// would otherwise make the same link look like two.
			static fn( $url ) => rtrim( strtolower( $url ), '.,;:!?)"\'' ),
			$matches[0]
		);

		$count = count( array_unique( $urls ) );

		if ( $count > $max_links ) {
			return $this->fail(
				sprintf(
					/* translators: %d: maximum allowed links */
					__( 'Submission rejected — too many links (max %d).', 'onsite-spam-guard' ),
					$max_links
				)
			);
		}

		return true;
	}
}
