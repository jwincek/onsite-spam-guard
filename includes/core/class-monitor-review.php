<?php
/**
 * Monitor review — what enforcing a monitored form would catch.
 *
 * Monitor mode records what would have been blocked; this summarizes it for
 * the person deciding whether to enforce. Beside each monitored form on the
 * Per-form tab: how long it has been monitored, how many submissions would
 * have been blocked and by which checks, a link to those log entries, and a
 * link that switches the form to Enforce.
 *
 * When monitoring began is recorded as the mode changes rather than inferred
 * from the earliest monitored log row. A form monitored for a week without
 * seeing spam has no rows, so inferring would report nothing at all where the
 * honest answer is "a week, and nothing would have been blocked".
 *
 * @package Simple_Spam_Shield
 * @since   1.7.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Monitor_Review {

	/**
	 * The site-wide monitor-mode option; per-form overrides add `__<context>`.
	 */
	private const MODE = 'simple_spam_shield_monitor_mode';

	/**
	 * When monitoring began, as a Unix timestamp. Site-wide under this name,
	 * per form under `__<context>`, matching the mode options they time.
	 */
	public const SINCE = 'simple_spam_shield_monitor_since';

	/**
	 * The admin-post.php action, and nonce action, for "Enforce this form".
	 */
	public const ACTION = 'simple_spam_shield_enforce_form';

	/**
	 * Register hooks. The option hooks run on every request, so a mode changed
	 * from WP-CLI or code is timed the same as one changed on the settings page.
	 */
	public static function init(): void {
		add_action( 'added_option', [ __CLASS__, 'on_added_option' ], 10, 2 );
		add_action( 'updated_option', [ __CLASS__, 'on_updated_option' ], 10, 3 );
		add_action( 'admin_post_' . self::ACTION, [ __CLASS__, 'handle_enforce' ] );
	}

	/**
	 * @param mixed $option Option name.
	 * @param mixed $value  New value.
	 */
	public static function on_added_option( mixed $option, mixed $value ): void {
		self::record( (string) $option, null, $value );
	}

	/**
	 * @param mixed $option    Option name.
	 * @param mixed $old_value Previous value.
	 * @param mixed $value     New value.
	 */
	public static function on_updated_option( mixed $option, mixed $old_value, mixed $value ): void {
		self::record( (string) $option, $old_value, $value );
	}

	/**
	 * Start the clock when a mode option turns monitoring on, and clear it when
	 * it turns monitoring off. Saving the settings page with the mode unchanged
	 * leaves it running.
	 */
	private static function record( string $option, mixed $old_value, mixed $value ): void {
		if ( self::MODE === $option ) {
			$since  = self::SINCE;
			$was_on = (bool) $old_value;
			$is_on  = (bool) $value;
		} elseif ( str_starts_with( $option, self::MODE . '__' ) ) {
			$since  = self::SINCE . substr( $option, strlen( self::MODE ) );
			$was_on = 'monitor' === $old_value;
			$is_on  = 'monitor' === $value;
		} else {
			return;
		}

		if ( $is_on && ! $was_on ) {
			update_option( $since, time(), false );
		} elseif ( ! $is_on ) {
			delete_option( $since );
		}
	}

	/**
	 * When the form's current spell of monitoring began.
	 *
	 * @return int|null Unix timestamp; null if the form is not being monitored,
	 *                  or monitoring began before this was recorded (1.7.0).
	 */
	public static function since( string $context ): ?int {
		$override = (string) get_option( Contexts::option( self::MODE, $context ), '' );

		if ( 'monitor' === $override ) {
			$since = get_option( Contexts::option( self::SINCE, $context ), false );
		} elseif ( '' === $override && (bool) get_option( self::MODE, false ) ) {
			$since = get_option( self::SINCE, false );
		} else {
			return null;
		}

		return is_numeric( $since ) && (int) $since > 0 ? (int) $since : null;
	}

	/**
	 * What enforcing the form would have caught during this spell of monitoring.
	 *
	 * @return array{since:?int,total:int,by_guard:array<string,int>,logging:bool}
	 */
	public static function summary( string $context ): array {
		$since    = self::since( $context );
		$by_guard = Database_Manager::monitored_by_guard( $context, $since );

		return [
			'since'    => $since,
			'total'    => array_sum( $by_guard ),
			'by_guard' => $by_guard,
			// Monitored submissions are only recorded when logging is on.
			'logging'  => (bool) get_option( 'simple_spam_shield_log_blocked', true ),
		];
	}

	/**
	 * Switch a form to Enforce.
	 *
	 * @return bool False for a context that is not registered.
	 */
	public static function enforce( string $context ): bool {
		if ( ! array_key_exists( $context, Contexts::all() ) ) {
			return false;
		}

		update_option( Contexts::option( self::MODE, $context ), 'enforce' );

		return true;
	}

	/**
	 * Handle the admin-post.php request behind "Enforce this form".
	 */
	public static function handle_enforce(): void {
		check_admin_referer( self::ACTION );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to change these settings.', 'onsite-spam-guard' ), 403 );
		}

		$context = isset( $_GET['context'] ) ? sanitize_key( wp_unslash( $_GET['context'] ) ) : '';

		if ( ! self::enforce( $context ) ) {
			wp_die( esc_html__( 'That form is not one Onsite Spam Guard protects.', 'onsite-spam-guard' ), 400 );
		}

		wp_safe_redirect( add_query_arg( 'enforced', $context, admin_url( 'admin.php?page=onsite-spam-guard' ) ) );
		exit;
	}

	/**
	 * The review for one form, printed at the top of its Per-form section.
	 * Prints nothing for a form that is not being monitored.
	 */
	public static function render( string $context ): void {
		if ( ! Guard_Runner::is_monitoring( $context ) ) {
			return;
		}

		$summary = self::summary( $context );
		$parts   = [];

		$parts[] = null === $summary['since']
			? esc_html__( 'Monitoring. When it began was not recorded, so the count covers everything in the log.', 'onsite-spam-guard' )
			: esc_html(
				sprintf(
					/* translators: 1: date monitoring began, 2: how long ago, e.g. "6 days". */
					__( 'Monitoring since %1$s (%2$s).', 'onsite-spam-guard' ),
					wp_date( (string) get_option( 'date_format', 'F j, Y' ), $summary['since'] ),
					human_time_diff( $summary['since'] )
				)
			);

		if ( ! $summary['logging'] ) {
			$parts[] = esc_html__( 'Logging is off, so nothing monitor mode lets through is recorded. Turn logging on to see what enforcing this form would catch.', 'onsite-spam-guard' );
		} elseif ( 0 === $summary['total'] ) {
			$parts[] = esc_html__( 'Nothing would have been blocked.', 'onsite-spam-guard' );
		} else {
			$parts[] = esc_html(
				sprintf(
					/* translators: 1: number of submissions, 2: list of checks with counts, e.g. "Blocked keywords 10, Minimum submission time 4". */
					_n( '%1$s submission would have been blocked: %2$s.', '%1$s submissions would have been blocked: %2$s.', $summary['total'], 'onsite-spam-guard' ),
					number_format_i18n( $summary['total'] ),
					self::describe( $summary['by_guard'] )
				)
			);
		}

		$links = [];
		if ( $summary['total'] > 0 ) {
			$links[] = sprintf(
				'<a href="%s">%s</a>',
				esc_url( admin_url( 'admin.php?page=onsite-spam-guard-spam-logs&filter_context=' . rawurlencode( $context ) . '&filter_outcome=' . Database_Manager::OUTCOME_MONITORED ) ),
				esc_html__( 'View them', 'onsite-spam-guard' )
			);
		}
		$links[] = sprintf(
			'<a href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION . '&context=' . rawurlencode( $context ) ), self::ACTION ) ),
			esc_html__( 'Enforce this form', 'onsite-spam-guard' )
		);

		// Every part is escaped above; the links are built from escaped pieces.
		printf(
			'<div class="notice notice-info inline onsite-spam-guard-monitor-review"><p>%s</p><p>%s</p></div>',
			implode( ' ', $parts ), // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
			implode( ' &middot; ', $links ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped when built.
		);
	}

	/**
	 * "Blocked keywords 10, Minimum submission time 4", using each check's label.
	 *
	 * @param array<string, int> $by_guard Guard slug => count, highest first.
	 */
	private static function describe( array $by_guard ): string {
		$definitions = Guard_Runner::definitions();
		$described   = [];

		foreach ( $by_guard as $slug => $count ) {
			$label       = (string) ( $definitions[ $slug ]['label'] ?? $slug );
			$described[] = $label . ' ' . number_format_i18n( $count );
		}

		return implode( ', ', $described );
	}
}
