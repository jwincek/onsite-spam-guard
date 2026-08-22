<?php
/**
 * Admin settings — provides WP admin pages for configuring the plugin
 * and viewing spam logs.
 *
 * Now includes: allowlist field, behavioral analysis threshold, and
 * a dedicated Spam Logs subpage using WP_List_Table backed by a
 * custom database table (all ported from Comment & Form Guard).
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Admin {

	/**
	 * Register hooks.
	 */
	public static function init(): void {
		add_action( 'admin_menu', [ __CLASS__, 'add_menus' ] );
		add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		add_action( 'admin_init', [ __CLASS__, 'add_privacy_policy_content' ] );
		add_action( 'admin_init', [ Database_Manager::class, 'create_table' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_settings_assets' ] );
	}

	/**
	 * Enqueue the tabbed-settings CSS/JS, only on the settings screen.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public static function enqueue_settings_assets( string $hook ): void {
		if ( 'toplevel_page_onsite-spam-guard' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'onsite-spam-guard-admin',
			SIMPLE_SPAM_SHIELD_URL . 'assets/css/admin-settings.css',
			[],
			SIMPLE_SPAM_SHIELD_VERSION
		);

		wp_enqueue_script(
			'onsite-spam-guard-admin',
			SIMPLE_SPAM_SHIELD_URL . 'assets/js/admin-settings.js',
			[],
			SIMPLE_SPAM_SHIELD_VERSION,
			[ 'in_footer' => true ]
		);
	}

	/**
	 * Register suggested privacy-policy text.
	 *
	 * The plugin records the IP address, user agent, and a content excerpt
	 * of blocked submissions in a custom table, so site owners should
	 * disclose this in their privacy policy.
	 */
	public static function add_privacy_policy_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = sprintf(
			/* translators: %s: number of days entries are retained, or "indefinitely". */
			__( 'Onsite Spam Guard blocks spam submissions on comment, review, and contact forms. When a submission is blocked, the plugin records the visitor IP address, browser user-agent string, and a short excerpt of the submitted content in a log on this site, to help the site owner review false positives and tune spam protection. These entries are stored for %s and are not shared with any third party.', 'onsite-spam-guard' ),
			(int) get_option( 'simple_spam_shield_log_retention_days', 30 ) > 0
				/* translators: %d: number of days. */
				? sprintf( _n( '%d day', '%d days', (int) get_option( 'simple_spam_shield_log_retention_days', 30 ), 'onsite-spam-guard' ), (int) get_option( 'simple_spam_shield_log_retention_days', 30 ) )
				: __( 'as long as the plugin is active', 'onsite-spam-guard' )
		);

		wp_add_privacy_policy_content( 'Onsite Spam Guard', wp_kses_post( wpautop( $content ) ) );
	}

	// ------------------------------------------------------------------
	// Menu registration
	// ------------------------------------------------------------------

	/**
	 * Add a top-level menu with Settings and Spam Logs subpages.
	 * Mirrors the Comment & Form Guard admin menu structure.
	 */
	public static function add_menus(): void {
		add_menu_page(
			__( 'Spam Guard', 'onsite-spam-guard' ),
			__( 'Spam Guard', 'onsite-spam-guard' ),
			'manage_options',
			'onsite-spam-guard',
			[ __CLASS__, 'render_settings_page' ],
			'dashicons-shield',
			80
		);

		add_submenu_page(
			'onsite-spam-guard',
			__( 'Settings', 'onsite-spam-guard' ),
			__( 'Settings', 'onsite-spam-guard' ),
			'manage_options',
			'onsite-spam-guard',
			[ __CLASS__, 'render_settings_page' ]
		);

		add_submenu_page(
			'onsite-spam-guard',
			__( 'Spam Logs', 'onsite-spam-guard' ),
			__( 'Spam Logs', 'onsite-spam-guard' ),
			'manage_options',
			'onsite-spam-guard-spam-logs',
			[ __CLASS__, 'render_logs_page' ]
		);
	}

	// ------------------------------------------------------------------
	// Settings registration
	// ------------------------------------------------------------------

	/**
	 * Register all settings, sections, and fields for the settings page.
	 */
	public static function register_settings(): void {

		$tabs = self::tabs();

		// ---- General tab: General + Protection targets ----
		add_settings_section( 'simple_spam_shield_general', __( 'General', 'onsite-spam-guard' ), '__return_null', $tabs['general']['page'] );
		self::add_toggle( 'simple_spam_shield_enabled', __( 'Enable spam protection', 'onsite-spam-guard' ), $tabs['general']['page'], 'simple_spam_shield_general', true );
		self::add_toggle( 'simple_spam_shield_hard_block', __( 'Reject blocked comments with an error instead of moving them to the spam queue', 'onsite-spam-guard' ), $tabs['general']['page'], 'simple_spam_shield_general', false );

		add_settings_section( 'simple_spam_shield_targets', __( 'Protection targets', 'onsite-spam-guard' ), function () {
			echo '<p>' . esc_html__( 'Choose which form types to protect.', 'onsite-spam-guard' ) . '</p>';
		}, $tabs['general']['page'] );

		self::add_toggle( 'simple_spam_shield_protect_comments', __( 'WordPress comments', 'onsite-spam-guard' ), $tabs['general']['page'], 'simple_spam_shield_targets', true );
		self::add_toggle( 'simple_spam_shield_protect_woo_reviews', __( 'WooCommerce product reviews', 'onsite-spam-guard' ), $tabs['general']['page'], 'simple_spam_shield_targets', true );
		self::add_toggle( 'simple_spam_shield_protect_jetpack_forms', __( 'Jetpack contact form blocks', 'onsite-spam-guard' ), $tabs['general']['page'], 'simple_spam_shield_targets', true );

		// ---- Guards tab ----
		$guards_page = $tabs['guards']['page'];
		add_settings_section( 'simple_spam_shield_guards', __( 'Spam guards', 'onsite-spam-guard' ), function () {
			echo '<p>' . esc_html__( 'Enable or disable individual spam checks.', 'onsite-spam-guard' ) . '</p>';
		}, $guards_page );

		// The filtered definitions, so a guard registered by another plugin
		// through simple_spam_shield_guards gets its own toggle here too.
		$guard_defs = Guard_Runner::definitions();
		foreach ( $guard_defs as $slug => $def ) {
			self::add_toggle(
				"simple_spam_shield_{$slug}_enabled",
				$def['label'] ?? $slug,
				$guards_page,
				'simple_spam_shield_guards',
				$def['enabled_by_default'] ?? true
			);
		}

		self::add_number( 'simple_spam_shield_time_gate_seconds', __( 'Minimum seconds before submit', 'onsite-spam-guard' ), $guards_page, 'simple_spam_shield_guards', 3, 1, 30, __( 'seconds', 'onsite-spam-guard' ) );
		self::add_number( 'simple_spam_shield_link_limit_max', __( 'Maximum links per submission', 'onsite-spam-guard' ), $guards_page, 'simple_spam_shield_guards', 3, 0, 50 );
		self::add_number( 'simple_spam_shield_rate_limit_max', __( 'Rate limit: max submissions per minute', 'onsite-spam-guard' ), $guards_page, 'simple_spam_shield_guards', 10, 0, 1000, __( 'per sender (0 = no limit)', 'onsite-spam-guard' ) );

		// Behavioral threshold.
		register_setting( 'onsite-spam-guard', 'simple_spam_shield_behavioral_threshold', [
			'type'              => 'number',
			'sanitize_callback' => function ( $v ) {
				return max( 0.0, min( 1.0, (float) $v ) );
			},
			'default'           => 0.6,
		] );

		add_settings_field(
			'simple_spam_shield_behavioral_threshold',
			__( 'Behavioral suspicion threshold', 'onsite-spam-guard' ),
			function () {
				$value = get_option( 'simple_spam_shield_behavioral_threshold', 0.6 );
				printf(
					'<input type="number" name="simple_spam_shield_behavioral_threshold" value="%.1f" min="0.0" max="1.0" step="0.1" class="small-text">' .
					'<p class="description">%s</p>',
					esc_attr( $value ),
					esc_html__( 'Score between 0.0 (lenient) and 1.0 (strict). Submissions scoring at or above this threshold are blocked. Default 0.6.', 'onsite-spam-guard' )
				);
			},
			$guards_page,
			'simple_spam_shield_guards'
		);

		// Blocked keywords.
		register_setting( 'onsite-spam-guard', 'simple_spam_shield_blocked_keywords', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		] );

		add_settings_field(
			'simple_spam_shield_blocked_keywords',
			__( 'Blocked keywords', 'onsite-spam-guard' ),
			function () {
				$value = get_option( 'simple_spam_shield_blocked_keywords', '' );
				printf(
					'<textarea name="simple_spam_shield_blocked_keywords" rows="8" cols="50" class="large-text code">%s</textarea>' .
					'<p class="description">%s</p>',
					esc_textarea( $value ),
					esc_html__( 'One keyword or phrase per line. Case-insensitive.', 'onsite-spam-guard' )
				);
			},
			$guards_page,
			'simple_spam_shield_guards'
		);

		self::add_toggle( 'simple_spam_shield_use_wp_disallowed_keys', __( 'Also apply WordPress\'s Disallowed Comment Keys (Settings → Discussion) to every protected form', 'onsite-spam-guard' ), $guards_page, 'simple_spam_shield_guards', false );

		// ---- Allowlist tab ----
		$allowlist_page = $tabs['allowlist']['page'];
		add_settings_section( 'simple_spam_shield_allowlist', __( 'Allowlist', 'onsite-spam-guard' ), function () {
			echo '<p>' . esc_html__( 'Submissions from allowlisted IPs or emails bypass all guards.', 'onsite-spam-guard' ) . '</p>';
		}, $allowlist_page );

		register_setting( 'onsite-spam-guard', 'simple_spam_shield_allowlist', [
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_textarea_field',
			'default'           => '',
		] );

		add_settings_field(
			'simple_spam_shield_allowlist',
			__( 'Allowed IPs and emails', 'onsite-spam-guard' ),
			function () {
				$value = get_option( 'simple_spam_shield_allowlist', '' );
				printf(
					'<textarea name="simple_spam_shield_allowlist" rows="6" cols="50" class="large-text code">%s</textarea>' .
					'<p class="description">%s</p>',
					esc_textarea( $value ),
					esc_html__( 'One entry per line. Supports: exact IPs (192.168.1.1), CIDR ranges (10.0.0.0/8), exact emails (user@example.com), or email domains (@trusted.org).', 'onsite-spam-guard' )
				);
			},
			$allowlist_page,
			'simple_spam_shield_allowlist'
		);

		// Trusted-proxy toggle — governs whether forwarded headers are
		// trusted for IP detection (allowlist + logging).
		register_setting( 'onsite-spam-guard', 'simple_spam_shield_trust_proxy', [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => false,
		] );

		add_settings_field(
			'simple_spam_shield_trust_proxy',
			__( 'Trust proxy headers for IP detection', 'onsite-spam-guard' ),
			function () {
				printf(
					'<label><input type="checkbox" name="simple_spam_shield_trust_proxy" value="1" %1$s> %2$s</label><p class="description">%3$s</p>',
					checked( get_option( 'simple_spam_shield_trust_proxy', false ), true, false ),
					esc_html__( 'Use the X-Forwarded-For header to determine the visitor IP.', 'onsite-spam-guard' ),
					esc_html__( 'Enable only if this site is behind a trusted reverse proxy or load balancer (e.g. Cloudflare, Nginx). When off, the direct connection IP is used. Turning this on without a trusted proxy lets visitors spoof their IP and bypass the allowlist.', 'onsite-spam-guard' )
				);
			},
			$allowlist_page,
			'simple_spam_shield_allowlist'
		);

		// ---- Logging tab ----
		$logging_page = $tabs['logging']['page'];
		add_settings_section( 'simple_spam_shield_logging', __( 'Logging', 'onsite-spam-guard' ), '__return_null', $logging_page );
		self::add_toggle( 'simple_spam_shield_log_blocked', __( 'Log blocked submissions to database', 'onsite-spam-guard' ), $logging_page, 'simple_spam_shield_logging', true );
		self::add_number( 'simple_spam_shield_log_retention_days', __( 'Delete logs older than', 'onsite-spam-guard' ), $logging_page, 'simple_spam_shield_logging', 30, 0, 3650, __( 'days (0 = keep forever)', 'onsite-spam-guard' ) );
		self::add_toggle( 'simple_spam_shield_delete_data_on_uninstall', __( 'Delete all plugin data (spam logs and settings) when this plugin is deleted', 'onsite-spam-guard' ), $logging_page, 'simple_spam_shield_logging', true );
	}

	/**
	 * The settings tabs, in display order: id => [ label, page ].
	 *
	 * Each tab renders one settings page; the General tab reuses the top-level
	 * menu slug so its sections keep the primary page.
	 *
	 * @return array<string, array{label:string, page:string}>
	 */
	private static function tabs(): array {
		return [
			'general'   => [
				'label' => __( 'General', 'onsite-spam-guard' ),
				'page'  => 'onsite-spam-guard',
			],
			'guards'    => [
				'label' => __( 'Guards', 'onsite-spam-guard' ),
				'page'  => 'onsite-spam-guard-guards',
			],
			'allowlist' => [
				'label' => __( 'Allowlist', 'onsite-spam-guard' ),
				'page'  => 'onsite-spam-guard-allowlist',
			],
			'logging'   => [
				'label' => __( 'Logging', 'onsite-spam-guard' ),
				'page'  => 'onsite-spam-guard-logging',
			],
		];
	}

	// ------------------------------------------------------------------
	// Page renderers
	// ------------------------------------------------------------------

	/**
	 * Render the Settings page.
	 */
	public static function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		echo '<div class="wrap">';
		echo '<h1>' . esc_html( get_admin_page_title() ) . '</h1>';

		// Quick stats banner.
		$count = Database_Manager::get_count();
		if ( $count > 0 ) {
			echo '<div class="notice notice-info"><p>';
			printf(
				/* translators: 1: number of blocked submissions, 2: link to logs page */
				esc_html__( '%1$d submissions blocked. %2$s', 'onsite-spam-guard' ),
				absint( $count ),
				'<a href="' . esc_url( admin_url( 'admin.php?page=onsite-spam-guard-spam-logs' ) ) . '">' .
				esc_html__( 'View spam logs →', 'onsite-spam-guard' ) . '</a>'
			);
			echo '</p></div>';
		}

		$tabs = self::tabs();

		echo '<div class="onsite-spam-guard-settings">';

		// Tab navigation (revealed and wired up by the admin script; without
		// JavaScript the bar is hidden and every panel is shown, so nothing
		// becomes unreachable).
		echo '<h2 class="nav-tab-wrapper onsite-spam-guard-tabs">';
		$first = true;
		foreach ( $tabs as $tab_id => $tab ) {
			printf(
				'<a href="#" class="nav-tab%1$s" data-sss-tab="%2$s">%3$s</a>',
				$first ? ' nav-tab-active' : '',
				esc_attr( $tab_id ),
				esc_html( $tab['label'] )
			);
			$first = false;
		}
		echo '</h2>';

		echo '<form method="post" action="options.php">';
		settings_fields( 'onsite-spam-guard' );

		$first = true;
		foreach ( $tabs as $tab_id => $tab ) {
			printf(
				'<div class="onsite-spam-guard-tab-panel%1$s" data-sss-panel="%2$s">',
				$first ? ' is-active' : '',
				esc_attr( $tab_id )
			);
			do_settings_sections( $tab['page'] );
			echo '</div>';
			$first = false;
		}

		submit_button();
		echo '</form>';
		echo '</div>'; // .onsite-spam-guard-settings
		echo '</div>'; // .wrap
	}

	/**
	 * Render the Spam Logs page with WP_List_Table.
	 */
	public static function render_logs_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Handle "Clear all" action.
		if ( isset( $_GET['action'] ) && 'clear_all' === sanitize_text_field( wp_unslash( $_GET['action'] ) ) ) {
			$nonce = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : '';
			if ( wp_verify_nonce( $nonce, 'simple_spam_shield_clear_all_logs' ) ) {
				Database_Manager::delete_all();
				wp_safe_redirect( admin_url( 'admin.php?page=onsite-spam-guard-spam-logs&cleared=1' ) );
				exit;
			}
		}

		// Load the list table class.
		require_once SIMPLE_SPAM_SHIELD_DIR . 'admin/class-spam-logs-list-table.php';
		$table = new \Simple_Spam_Shield\Admin\Spam_Logs_List_Table();
		$table->prepare_items();

		echo '<div class="wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Spam Logs', 'onsite-spam-guard' ) . '</h1>';

		// Show success notices.
		if ( isset( $_GET['cleared'] ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' .
				esc_html__( 'All spam logs cleared.', 'onsite-spam-guard' ) . '</p></div>';
		}

		$count = Database_Manager::get_count();
		if ( $count > 0 ) {
			$clear_url = wp_nonce_url(
				admin_url( 'admin.php?page=onsite-spam-guard-spam-logs&action=clear_all' ),
				'simple_spam_shield_clear_all_logs'
			);
			echo '<a href="' . esc_url( $clear_url ) . '" class="page-title-action" onclick="return confirm(\'' .
				esc_js( __( 'Delete all log entries?', 'onsite-spam-guard' ) ) . '\')">' .
				esc_html__( 'Clear all logs', 'onsite-spam-guard' ) . '</a>';
		}

		// 7-day blocked-submission summary (cached).
		$stats = Database_Manager::get_stats();
		if ( $stats['week_total'] > 0 ) {
			echo '<p class="description">';
			printf(
				/* translators: %d: number of submissions blocked in the last 7 days. */
				esc_html__( 'Blocked in the last 7 days: %d.', 'onsite-spam-guard' ),
				absint( $stats['week_total'] )
			);
			if ( '' !== $stats['top_guard'] ) {
				echo ' ';
				printf(
					/* translators: 1: guard name, 2: number of blocks by that guard. */
					esc_html__( 'Most active guard: %1$s (%2$d).', 'onsite-spam-guard' ),
					esc_html( $stats['top_guard'] ),
					absint( $stats['top_count'] )
				);
			}
			echo '</p>';
		}

		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="onsite-spam-guard-spam-logs">';
		$table->display();
		echo '</form>';
		echo '</div>';
	}

	// ------------------------------------------------------------------
	// Field helpers
	// ------------------------------------------------------------------

	/**
	 * Register a toggle (checkbox) setting + field on a given tab page.
	 */
	private static function add_toggle( string $option, string $label, string $page, string $section, bool $default ): void {
		register_setting( 'onsite-spam-guard', $option, [
			'type'              => 'boolean',
			'sanitize_callback' => 'rest_sanitize_boolean',
			'default'           => $default,
		] );

		add_settings_field( $option, $label, function () use ( $option, $default ) {
			printf(
				'<input type="checkbox" name="%s" value="1" %s>',
				esc_attr( $option ),
				checked( get_option( $option, $default ), true, false )
			);
		}, $page, $section );
	}

	/**
	 * Register a number input setting + field on a given tab page.
	 */
	private static function add_number( string $option, string $label, string $page, string $section, int $default, int $min, int $max, string $suffix = '' ): void {
		register_setting( 'onsite-spam-guard', $option, [
			'type'              => 'integer',
			'sanitize_callback' => 'absint',
			'default'           => $default,
		] );

		add_settings_field( $option, $label, function () use ( $option, $default, $min, $max, $suffix ) {
			$value = get_option( $option, $default );
			printf(
				'<input type="number" name="%s" value="%d" min="%d" max="%d" class="small-text">',
				esc_attr( $option ),
				esc_attr( $value ),
				absint( $min ),
				absint( $max )
			);
			if ( $suffix ) {
				echo ' ' . esc_html( $suffix );
			}
		}, $page, $section );
	}
}
