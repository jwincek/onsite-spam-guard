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
	 * The thresholds a form can set for itself, with the bounds the settings
	 * page enforces and the plugin's shipped default for each. Both the
	 * Per-form tab and a context's `defaults` are limited to these.
	 *
	 * The defaults must match config/guards.json, which the guards read;
	 * ContextDefaultsTest fails if they drift.
	 *
	 * @var array<string, array{min: int|float, max: int|float, default: int|float}>
	 */
	public const THRESHOLDS = [
		'simple_spam_shield_time_gate_seconds'         => [
			'min'     => 1,
			'max'     => 30,
			'default' => 3,
		],
		'simple_spam_shield_link_limit_max'            => [
			'min'     => 0,
			'max'     => 50,
			'default' => 3,
		],
		'simple_spam_shield_duplicate_window_seconds'  => [
			'min'     => 1,
			'max'     => 86400,
			'default' => 60,
		],
		'simple_spam_shield_rate_limit_max'            => [
			'min'     => 0,
			'max'     => 1000,
			'default' => 10,
		],
		'simple_spam_shield_rate_limit_window_seconds' => [
			'min'     => 1,
			'max'     => 86400,
			'default' => 60,
		],
		'simple_spam_shield_behavioral_threshold'      => [
			'min'     => 0.0,
			'max'     => 1.0,
			'default' => 0.6,
		],
	];

	/**
	 * Every context the settings screen can offer overrides for.
	 *
	 * @return array<string, array{label: string, defaults?: array<string, int|float>}> Context slug => definition.
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
		 * A context can also carry `defaults`: starting values for thresholds
		 * that suit its form better than ones tuned for comments. A job listing
		 * links to more pages than a comment does, for instance:
		 *
		 *     $contexts['commission_form'] = [
		 *         'label'    => 'Commission requests',
		 *         'defaults' => [ 'simple_spam_shield_link_limit_max' => 10 ],
		 *     ];
		 *
		 * They only change what an unconfigured form inherits. A per-form
		 * override on the Per-form tab always wins, and so does a site-wide
		 * value the site has changed from the plugin's default. Only the keys
		 * of Contexts::THRESHOLDS are accepted, clamped to the same bounds as
		 * the settings page; anything else is dropped.
		 *
		 * @since 1.4.0
		 * @since 1.7.0 Contexts may carry `defaults`.
		 *
		 * @param array<string, array{label: string, defaults?: array<string, int|float>}> $contexts Context slug => definition.
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

			$defaults = self::valid_defaults( $definition['defaults'] ?? [] );
			if ( [] !== $defaults ) {
				$valid[ $key ]['defaults'] = $defaults;
			}
		}

		return $valid;
	}

	/**
	 * Keep only the defaults a context may set: known thresholds, numeric,
	 * clamped to the settings page's bounds. Monitor mode, guard toggles and
	 * anything else are not thresholds and are dropped — a context must not
	 * be able to switch its own protection off by default.
	 *
	 * @param mixed $defaults Whatever the definition carried.
	 * @return array<string, int|float>
	 */
	private static function valid_defaults( mixed $defaults ): array {
		if ( ! is_array( $defaults ) ) {
			return [];
		}

		$valid = [];

		/** @var mixed $value */
		foreach ( $defaults as $option => $value ) {
			if ( ! isset( self::THRESHOLDS[ $option ] ) || ! is_numeric( $value ) ) {
				continue;
			}

			$bounds           = self::THRESHOLDS[ $option ];
			$clamped          = max( $bounds['min'], min( $bounds['max'], (float) $value ) );
			$valid[ $option ] = is_float( $bounds['default'] ) ? (float) $clamped : (int) round( $clamped );
		}

		return $valid;
	}

	/**
	 * The value a form inherits for a threshold when it has no override of
	 * its own, and where that value comes from.
	 *
	 * The site-wide setting, unless the site has left it at the plugin's
	 * shipped default and the form's context supplies a default of its own.
	 * "Left at the default" has to be judged by value: activation stores
	 * every default, and saving the settings page stores every field, so the
	 * option exists on practically every site whether or not anyone chose it.
	 * A site that deliberately sets the global to exactly the shipped default
	 * cannot be told apart, and gets the context's default; a per-form
	 * override is how to insist.
	 *
	 * @param string    $option  Global option name, a key of THRESHOLDS.
	 * @param string    $context Submission context.
	 * @param int|float $shipped The plugin's default for the option.
	 * @return array{value: mixed, source: string} Source is 'context' or 'global'.
	 */
	public static function inherited( string $option, string $context, int|float $shipped ): array {
		/** @var mixed $global */
		$global = get_option( $option, null );

		$site_chose = is_numeric( $global ) && abs( (float) $global - (float) $shipped ) > 1e-9;

		if ( ! $site_chose ) {
			$contexts = self::all();
			$key      = self::key( $context );

			if ( isset( $contexts[ $key ]['defaults'][ $option ] ) ) {
				return [
					'value'  => $contexts[ $key ]['defaults'][ $option ],
					'source' => 'context',
				];
			}
		}

		return [
			'value'  => is_numeric( $global ) ? $global : $shipped,
			'source' => 'global',
		];
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
