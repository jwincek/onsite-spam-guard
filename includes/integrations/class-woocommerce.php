<?php
/**
 * WooCommerce integration — protects product reviews.
 *
 * WooCommerce reviews are WordPress comments on product post types.
 * This integration adds an extra layer that specifically targets
 * product review submissions, complementing the general Comments
 * integration for cases where WooCommerce is active.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Guard_Runner;

final class WooCommerce {

	/**
	 * Register hooks — only if WooCommerce is active.
	 */
	public static function init(): void {
		if ( ! (bool) get_option( 'simple_spam_shield_protect_woo_reviews', true ) ) {
			return;
		}

		// Wait for WooCommerce to load before hooking.
		add_action( 'woocommerce_init', [ __CLASS__, 'register_hooks' ] );
	}

	/**
	 * Register the actual review protection hooks.
	 */
	public static function register_hooks(): void {
		// Hook into WooCommerce's review validation.
		add_action( 'woocommerce_new_comment', [ __CLASS__, 'check_review' ], 1 );

		// Also inject honeypot into the review form.
		// A filter, not an action: WooCommerce runs it through apply_filters(),
		// so a callback registered with add_action() would have its return
		// value discarded and any change to the args silently dropped.
		add_filter( 'woocommerce_product_review_comment_form_args', [ __CLASS__, 'add_form_fields' ] );
	}

	/**
	 * Check a new WooCommerce review via the guard pipeline.
	 *
	 * @param int $comment_id The new comment ID.
	 */
	public static function check_review( int $comment_id ): void {
		// The preprocess_comment filter in the Comments integration
		// already covers this path. But if that integration is disabled,
		// this hook provides standalone WooCommerce protection.
		if ( (bool) get_option( 'simple_spam_shield_protect_comments', true ) ) {
			// Already handled by the Comments integration.
			return;
		}

		$comment = get_comment( $comment_id );
		if ( ! $comment ) {
			return;
		}

		// Skip moderators.
		if ( current_user_can( 'moderate_comments' ) ) {
			return;
		}

		$data = [
			'content'                            => $comment->comment_content,
			'author'                             => $comment->comment_author,
			'email'                              => $comment->comment_author_email,
			// JS-injected fields from a public form submission; there is no
			// plugin nonce to verify at this stage (that is the optional Nonce
			// guard's job downstream). Values are sanitized on read.
			// phpcs:disable WordPress.Security.NonceVerification.Missing
			'simple_spam_shield_website_url'     => \Simple_Spam_Shield\Guards\Honeypot::value_from_request( $_POST ),
			'simple_spam_shield_form_loaded'     => sanitize_text_field( wp_unslash( $_POST['simple_spam_shield_form_loaded'] ?? '' ) ),
			'simple_spam_shield_behavioral_data' => sanitize_textarea_field( wp_unslash( $_POST['simple_spam_shield_behavioral_data'] ?? '' ) ),
			// phpcs:enable WordPress.Security.NonceVerification.Missing
		];

		$result = Guard_Runner::run( $data, 'woo_review' );

		if ( is_wp_error( $result ) ) {
			// Default: move the review to the spam queue so a false positive
			// can be recovered. Hard-block mode trashes it instead.
			if ( (bool) get_option( 'simple_spam_shield_hard_block', false ) ) {
				wp_trash_comment( $comment_id );
			} else {
				wp_spam_comment( $comment_id );
			}

			if ( function_exists( 'wc_add_notice' ) ) {
				wc_add_notice( $result->get_error_message(), 'error' );
			}
		}
	}

	/**
	 * Modify WooCommerce review form args to include a note about protection.
	 *
	 * @param array $args Form arguments.
	 * @return array Modified arguments.
	 */
	public static function add_form_fields( array $args ): array {
		// The JS-based injection in guard.js handles the actual field injection.
		// This filter is a hook point for themes that override the review form.
		return $args;
	}
}
