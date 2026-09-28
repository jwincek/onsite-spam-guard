<?php
/**
 * Registration integration — protects account signup forms.
 *
 * Fake registrations are a persistent form of spam on community and shop
 * sites, and unlike a spam comment each one leaves an account behind. Three
 * signup forms are covered, each through a hook verified against its source:
 *
 * - WordPress core: `registration_errors`, fired by register_new_user().
 * - WooCommerce: `woocommerce_process_registration_errors`, which fires only
 *   for the My Account registration form. Not `woocommerce_registration_errors`:
 *   that fires inside wc_create_new_customer(), which is also used for account
 *   creation at checkout, after purchase, for back-in-stock signups, and by the
 *   customer data store behind the REST API, admin and imports. Hooking it
 *   would put spam guards in front of paying customers and administrators.
 * - BuddyPress: `bp_core_validate_user_signup`, from the signup screen and the
 *   REST signup endpoint.
 *
 * Off by default. A false positive here stops someone creating an account,
 * which is worse than a rejected comment; monitor mode is how a site trials it
 * on real signups first.
 *
 * These contexts fail open when the hidden fields are absent, as every context
 * other than comments and reviews does. The BuddyPress REST endpoint and custom
 * forms calling register_new_user() never render them, and hard-failing would
 * refuse every signup made that way.
 *
 * @package Simple_Spam_Shield
 * @since   1.6.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Assets;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Guards\Honeypot;

final class Registration {

	public const CONTEXT_CORE = 'registration';
	public const CONTEXT_WOO  = 'woo_registration';
	public const CONTEXT_BP   = 'bp_signup';

	/**
	 * Late, so validators that reject for their own reasons have already run.
	 * A submission already being refused is left alone — screening it would
	 * record a signup the host form never accepted.
	 */
	private const PRIORITY = 99;

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		// Registered whatever the toggle says, so the Per-form tab can be
		// prepared — including setting a form to monitor — before protection
		// is switched on.
		add_filter( 'simple_spam_shield_contexts', [ __CLASS__, 'register_contexts' ] );

		if ( ! (bool) get_option( 'simple_spam_shield_protect_registration', false ) ) {
			return;
		}

		add_action( 'register_form', [ __CLASS__, 'render_fields' ] );
		add_filter( 'registration_errors', [ __CLASS__, 'check_core' ], self::PRIORITY, 3 );

		add_action( 'woocommerce_register_form', [ __CLASS__, 'render_fields' ] );
		add_filter( 'woocommerce_process_registration_errors', [ __CLASS__, 'check_woocommerce' ], self::PRIORITY, 4 );

		// Fires inside the signup form in both the Legacy and Nouveau packs.
		add_action( 'bp_before_registration_submit_buttons', [ __CLASS__, 'render_fields' ] );
		add_filter( 'bp_core_validate_user_signup', [ __CLASS__, 'check_buddypress' ], self::PRIORITY );

		add_filter( 'simple_spam_shield_form_selectors', [ __CLASS__, 'add_selectors' ] );
	}

	/**
	 * Offer each signup form that exists on this site its own thresholds.
	 *
	 * @param array<string, array{label: string}> $contexts Registered contexts.
	 * @return array<string, array{label: string}>
	 */
	public static function register_contexts( array $contexts ): array {
		$contexts[ self::CONTEXT_CORE ] = [ 'label' => __( 'Account registration (WordPress)', 'onsite-spam-guard' ) ];

		if ( defined( 'WC_VERSION' ) ) {
			$contexts[ self::CONTEXT_WOO ] = [ 'label' => __( 'Account registration (WooCommerce)', 'onsite-spam-guard' ) ];
		}

		if ( function_exists( 'bp_is_active' ) ) {
			$contexts[ self::CONTEXT_BP ] = [ 'label' => __( 'Account registration (BuddyPress)', 'onsite-spam-guard' ) ];
		}

		return $contexts;
	}

	/**
	 * Add the signup forms to the selectors the front-end script enhances.
	 *
	 * @param string[] $selectors Existing selectors.
	 * @return string[]
	 */
	public static function add_selectors( array $selectors ): array {
		// BuddyPress uses #signup-form in Nouveau and #signup_form in Legacy.
		return array_merge( $selectors, [ '#registerform', '.woocommerce-form-register', '#signup-form', '#signup_form' ] );
	}

	/**
	 * Render the hidden protection fields inside a signup form.
	 */
	public static function render_fields(): void {
		echo Assets::field_markup(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Pre-escaped markup.
	}

	/**
	 * WordPress core registration.
	 *
	 * @param mixed  $errors   WP_Error accumulating registration errors.
	 * @param string $username Sanitized user login.
	 * @param string $email    User email.
	 * @return mixed
	 */
	public static function check_core( $errors, $username = '', $email = '' ) {
		if ( ! $errors instanceof \WP_Error || $errors->has_errors() ) {
			return $errors;
		}

		$verdict = self::screen( (string) $username, (string) $email, self::CONTEXT_CORE );

		if ( is_wp_error( $verdict ) ) {
			$errors->add( 'simple_spam_shield_blocked', $verdict->get_error_message() );
		}

		return $errors;
	}

	/**
	 * WooCommerce My Account registration.
	 *
	 * The username is empty when WooCommerce is set to generate usernames, which
	 * leaves the email address as the only text to screen.
	 *
	 * @param mixed  $errors   WP_Error accumulating validation errors.
	 * @param string $username Submitted username, possibly empty.
	 * @param string $password Submitted password (unused).
	 * @param string $email    Submitted email.
	 * @return mixed
	 */
	public static function check_woocommerce( $errors, $username = '', $password = '', $email = '' ) {
		if ( ! $errors instanceof \WP_Error || $errors->has_errors() ) {
			return $errors;
		}

		$verdict = self::screen( (string) $username, (string) $email, self::CONTEXT_WOO );

		if ( is_wp_error( $verdict ) ) {
			$errors->add( 'simple_spam_shield_blocked', $verdict->get_error_message() );
		}

		return $errors;
	}

	/**
	 * BuddyPress signup.
	 *
	 * The error must be added under `user_name`. BuddyPress's signup screen
	 * copies only the `user_name` and `user_email` keys into its own error list,
	 * and completes the signup when that list is empty — an error under any
	 * other key is neither shown nor enforced, and the account is created
	 * anyway. The REST endpoint checks every message, so `user_name` works for
	 * both paths.
	 *
	 * @param mixed $result Validation result: user_name, user_email and errors.
	 * @return mixed
	 */
	public static function check_buddypress( $result ) {
		if ( ! is_array( $result ) || ! ( ( $result['errors'] ?? null ) instanceof \WP_Error ) || $result['errors']->has_errors() ) {
			return $result;
		}

		$verdict = self::screen( (string) ( $result['user_name'] ?? '' ), (string) ( $result['user_email'] ?? '' ), self::CONTEXT_BP );

		if ( is_wp_error( $verdict ) ) {
			$result['errors']->add( 'user_name', $verdict->get_error_message() );
		}

		return $result;
	}

	/**
	 * Run a signup through the guard pipeline.
	 *
	 * A registration carries little free text, so the username is screened as
	 * the content: keyword rules then apply to names like "casino-bonus-2026",
	 * and the email address is screened alongside it.
	 *
	 * @param string $username Submitted username.
	 * @param string $email    Submitted email.
	 * @param string $context  Registration context.
	 * @return \WP_Error|true
	 */
	private static function screen( string $username, string $email, string $context ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Anti-spam check on a public form; the host form verifies its own nonce where it has one. Values are sanitized on read.
		$data = [
			'content'                            => $username,
			'author'                             => $username,
			'email'                              => $email,
			'simple_spam_shield_website_url'     => Honeypot::value_from_request( $_POST ),
			'simple_spam_shield_form_loaded'     => sanitize_text_field( wp_unslash( $_POST['simple_spam_shield_form_loaded'] ?? '' ) ),
			'simple_spam_shield_behavioral_data' => sanitize_textarea_field( wp_unslash( $_POST['simple_spam_shield_behavioral_data'] ?? '' ) ),
		];
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return Guard_Runner::run( $data, $context );
	}
}
