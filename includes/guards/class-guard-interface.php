<?php
/**
 * Guard interface — every spam-check guard implements this.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

interface Guard_Interface {

	/**
	 * Run the spam check.
	 *
	 * When $observe_only is true the guard MUST return its verdict without
	 * changing any state: no transients, no options, no database writes. The
	 * runner uses this to keep evaluating after a submission has already been
	 * blocked, so the log can record every guard that matched rather than only
	 * the first — without a blocked submission consuming rate-limit budget or
	 * registering itself in the duplicate cache.
	 *
	 * The verdict must be identical in both modes. Guards with no side effects
	 * can ignore the flag entirely.
	 *
	 * @param array  $data         Submission data.
	 * @param string $context      'comment' | 'woo_review' | 'jetpack_form', or a
	 *                             custom label from simple_spam_shield_check().
	 * @param bool   $observe_only Evaluate without mutating state.
	 * @return \WP_Error|true  True on pass, WP_Error on fail.
	 */
	public function check( array $data, string $context, bool $observe_only = false ): \WP_Error|true;

	/**
	 * Whether this guard is enabled in settings.
	 */
	public function is_enabled(): bool;

	/**
	 * Priority weight (higher = runs first).
	 */
	public function get_weight(): int;

	/**
	 * Guard slug identifier.
	 */
	public function get_slug(): string;
}
