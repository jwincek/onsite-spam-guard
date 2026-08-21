<?php
/**
 * Behavioral Analysis guard — detects bot-like interaction patterns.
 *
 * Ported from Comment & Form Guard's behavioral analysis system.
 * The front-end JS collects mouse movements, clicks, and time-on-page,
 * encodes them as JSON, and submits them in a hidden field. This guard
 * scores that data and fails if the score exceeds the threshold.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

final class Behavioral extends Abstract_Guard {

	public function check( array $data, string $context, bool $observe_only = false ): \WP_Error|true {
		$json = $data['simple_spam_shield_behavioral_data'] ?? '';

		// If the behavioral data field is missing (e.g. Jetpack strips it),
		// skip rather than hard-fail — same pattern as the other JS-dependent guards.
		if ( empty( $json ) ) {
			return true;
		}

		$behavioral = json_decode( $json, true );

		if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $behavioral ) ) {
			return true; // Malformed data — skip gracefully.
		}

		$score = $this->calculate_score( $behavioral );

		$threshold = (float) get_option(
			'simple_spam_shield_behavioral_threshold',
			$this->config['threshold'] ?? 0.6
		);

		if ( $score >= $threshold ) {
			return $this->fail(
				__( 'Submission rejected — suspicious activity detected.', 'onsite-spam-guard' )
			);
		}

		return true;
	}

	/**
	 * Calculate a suspicion score between 0.0 (human) and 1.0 (bot).
	 *
	 * Scoring rules ported from Comment & Form Guard's analyze_behavioral_data().
	 */
	private function calculate_score( array $data ): float {
		$score = 0.0;

		$time_spent      = (float) ( $data['time_spent'] ?? 0 );
		$mouse_movements = (int) ( $data['mouse_movements'] ?? 0 );
		$clicks          = (int) ( $data['clicks'] ?? 0 );

		// Very fast submission.
		if ( $time_spent > 0 && $time_spent < 3 ) {
			$score += 0.4;
		} elseif ( $time_spent > 0 && $time_spent < 10 ) {
			$score += 0.2;
		}

		// No or very few mouse movements.
		if ( $mouse_movements < 5 ) {
			$score += 0.3;
		}

		// No clicks combined with fast submission.
		if ( 0 === $clicks && $time_spent < 5 ) {
			$score += 0.2;
		}

		return min( 1.0, $score );
	}
}
