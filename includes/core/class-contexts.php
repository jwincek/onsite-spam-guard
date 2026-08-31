<?php
/**
 * Submission contexts — the forms this plugin protects.
 *
 * A context is the label passed to Guard_Runner::run() identifying which form a
 * submission came from. Three are built in; any other plugin can protect its own
 * form through simple_spam_shield_check() with a label of its choosing, so the
 * set is open rather than fixed.
 *
 * Registering a context here is what makes it configurable: the settings screen
 * offers per-context threshold overrides for every context it knows about. An
 * unregistered context still works and is still protected — it simply uses the
 * global thresholds, with nowhere to override them.
 *
 * @package Simple_Spam_Shield
 * @since   1.4.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Contexts {

	/**
	 * Every context the settings screen can offer overrides for.
	 *
	 * @return array<string, array{label: string}> Context slug => definition.
	 */
	public static function all(): array {
		$contexts = [
			'comment'      => [ 'label' => __( 'WordPress comments', 'onsite-spam-guard' ) ],
			'woo_review'   => [ 'label' => __( 'WooCommerce product reviews', 'onsite-spam-guard' ) ],
			'jetpack_form' => [ 'label' => __( 'Jetpack contact forms', 'onsite-spam-guard' ) ],
		];

		/**
		 * Filters the submission contexts offered for per-context configuration.
		 *
		 * Register the context your plugin passes to simple_spam_shield_check()
		 * to give site owners thresholds tuned to your form, rather than the
		 * one set of values shared by every form on the site:
		 *
		 *     add_filter( 'simple_spam_shield_contexts', function ( array $contexts ) {
		 *         $contexts['commission_form'] = [ 'label' => 'Commission requests' ];
		 *         return $contexts;
		 *     } );
		 *
		 * Hook this before `admin_init`, since that is when the settings are
		 * registered. Registering when your plugin file loads is the usual way.
		 *
		 * A context that is never registered is still protected — it just falls
		 * back to the global thresholds.
		 *
		 * @since 1.4.0
		 *
		 * @param array<string, array{label: string}> $contexts Context slug => definition.
		 */
		/** @var mixed $filtered Whatever the filter returned; it is another plugin's code. */
		$filtered = apply_filters( 'simple_spam_shield_contexts', $contexts );

		if ( ! is_array( $filtered ) ) {
			return $contexts;
		}

		// Drop anything malformed rather than rendering a nameless settings
		// section, and normalise the key so it is safe as an option-name suffix.
		$valid = [];

		/** @var mixed $definition */
		foreach ( $filtered as $slug => $definition ) {
			$key = self::key( (string) $slug );

			if ( '' === $key || ! is_array( $definition ) || ! isset( $definition['label'] ) ) {
				continue;
			}

			$valid[ $key ] = [ 'label' => (string) $definition['label'] ];
		}

		return $valid;
	}

	/**
	 * Normalise a context into a form safe to use inside an option name.
	 *
	 * @param string $context Raw context label.
	 */
	public static function key( string $context ): string {
		return sanitize_key( $context );
	}

	/**
	 * Option name holding a context's override for a global setting.
	 *
	 * @param string $option  Global option name.
	 * @param string $context Submission context.
	 */
	public static function option( string $option, string $context ): string {
		return $option . '__' . self::key( $context );
	}
}
