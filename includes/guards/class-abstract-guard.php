<?php
/**
 * Abstract base guard — shared logic for all guards.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Guards;

abstract class Abstract_Guard implements Guard_Interface {

	protected string $slug;
	protected array $config;

	public function __construct( string $slug, array $config ) {
		$this->slug   = $slug;
		$this->config = $config;
	}

	public function get_slug(): string {
		return $this->slug;
	}

	public function get_weight(): int {
		return (int) ( $this->config['weight'] ?? 50 );
	}

	public function is_enabled(): bool {
		return (bool) get_option( "simple_spam_shield_{$this->slug}_enabled", $this->config['enabled_by_default'] ?? true );
	}

	/**
	 * Record an accepted submission.
	 *
	 * Empty by default: most guards read state rather than write it. Guards that
	 * must remember an accepted submission — `Duplicate` — override this.
	 *
	 * @param array  $data    Submission data, as passed to check().
	 * @param string $context Submission context.
	 */
	public function commit( array $data, string $context ): void {} // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Deliberate no-op default for guards that hold no state.

	/**
	 * Helper: build a WP_Error for a failed guard.
	 */
	protected function fail( string $message ): \WP_Error {
		return new \WP_Error(
			"simple_spam_shield_{$this->slug}_failed",
			$message,
			[ 'status' => 403 ]
		);
	}

	/**
	 * Resolve a threshold for the form the submission came from.
	 *
	 * Thresholds are global by default, but a site can override any of them for
	 * one context — the right link limit genuinely differs between a comment
	 * thread and a contact form, and without overrides the only way to settle a
	 * conflict between two forms is to loosen the setting for both.
	 *
	 * Resolution order, first hit wins:
	 *
	 *   1. the context override, if the site set one
	 *   2. the global setting
	 *   3. the default from config/guards.json, then the literal passed in
	 *
	 * An override stored as an empty string means "inherit", which is how the
	 * settings screen represents a field left blank.
	 *
	 * @param string $option  Global option name.
	 * @param string $context Submission context.
	 * @param mixed  $default Fallback when neither is set.
	 * @return mixed
	 */
	protected function threshold( string $option, string $context, mixed $default ): mixed {
		$override = get_option( \Simple_Spam_Shield\Core\Contexts::option( $option, $context ), '' );

		if ( '' !== $override && null !== $override && false !== $override ) {
			return $override;
		}

		return get_option( $option, $default );
	}

	/**
	 * Whether the context is a built-in form whose JS-injected fields (token,
	 * behavioral data) are guaranteed to be present.
	 *
	 * For these, a missing field is suspicious and the JS-dependent guards
	 * hard-fail. For any other context (Jetpack, or a third-party form
	 * integrated via simple_spam_shield_check()) the field may be legitimately
	 * absent, so those guards skip rather than block.
	 *
	 * @param string $context Submission context.
	 * @return bool
	 */
	protected function is_js_injected_context( string $context ): bool {
		return in_array( $context, [ 'comment', 'woo_review' ], true );
	}
}
