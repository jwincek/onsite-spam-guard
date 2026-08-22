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
	 * The runner keeps evaluating after a submission has already been blocked,
	 * so the log can record every guard that matched rather than only the first.
	 * A guard is therefore called whatever the outcome, and must return the same
	 * verdict for the same input either way.
	 *
	 * A guard may record the *attempt* here — `Rate_Limit` counts every
	 * submission a sender makes, including rejected ones, because a rejected
	 * attempt is still an attempt and is the main thing worth throttling.
	 * Anything that should only be recorded once a submission is *accepted*
	 * belongs in commit() instead.
	 *
	 * @param array  $data    Submission data.
	 * @param string $context 'comment' | 'woo_review' | 'jetpack_form', or a
	 *                        custom label from simple_spam_shield_check().
	 * @return \WP_Error|true  True on pass, WP_Error on fail.
	 */
	public function check( array $data, string $context ): \WP_Error|true;

	/**
	 * Record that a submission cleared the whole pipeline.
	 *
	 * Called once, on every enabled guard, only after no guard objected. This is
	 * where state describing an *accepted* submission belongs — `Duplicate`
	 * registers the content here, so a visitor rejected by some other guard is
	 * not then refused as a duplicate of their own blocked attempt.
	 *
	 * Guards holding no such state do nothing; `Abstract_Guard` provides an
	 * empty default so only the guards that need it override this.
	 *
	 * @param array  $data    Submission data, as passed to check().
	 * @param string $context Submission context.
	 */
	public function commit( array $data, string $context ): void;

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
