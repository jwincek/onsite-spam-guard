<?php
/**
 * Smoke checks run inside a real WordPress install by tests/smoke/run.sh.
 *
 * Each mode asserts one stage of the plugin's life on a real database and a
 * real request lifecycle — the parts PHPUnit's stubs cannot reach. Run as:
 *
 *   wp eval-file tests/smoke/checks.php <mode> [args]
 *
 * Every failed assertion is reported, and the mode then exits non-zero.
 *
 * @package Onsite_Spam_Guard
 */

use Simple_Spam_Shield\Core\Admin;
use Simple_Spam_Shield\Core\Assets;
use Simple_Spam_Shield\Core\Database_Manager;
use Simple_Spam_Shield\Core\Guard_Runner;
use Simple_Spam_Shield\Core\Proxy_Diagnostics;
use Simple_Spam_Shield\Guards\Honeypot;

$mode     = $args[0] ?? '';
$failures = 0;

$check = static function ( bool $ok, string $label ) use ( &$failures ): void {
	if ( $ok ) {
		WP_CLI::log( "  ok    {$label}" );
		return;
	}
	++$failures;
	WP_CLI::log( "  FAIL  {$label}" );
};

// The schema version this build expects, without duplicating the constant.
$schema = static function (): string {
	return ( new ReflectionClassConstant( Database_Manager::class, 'DB_VERSION' ) )->getValue();
};

// Spelled out rather than read from Database_Manager: after uninstall the
// plugin's files are gone, so its classes cannot load. 'activation' checks
// the two still agree.
$log_table = $GLOBALS['wpdb']->prefix . 'simple_spam_shield_spam_logs';

$table_exists = static function () use ( $log_table ): bool {
	global $wpdb;
	return $log_table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $log_table ) );
};

$columns = static function () use ( $log_table ): array {
	global $wpdb;
	return $wpdb->get_col( "SHOW COLUMNS FROM {$log_table}" );
};

$latest_row = static function () use ( $log_table ): ?array {
	global $wpdb;
	return $wpdb->get_row( "SELECT guard, guards_matched, outcome, context FROM {$log_table} ORDER BY id DESC LIMIT 1", ARRAY_A );
};

// A comment exactly as the Comments integration normalises one. The token is
// the one the real form markup carries; the honeypot is empty unless filled.
$submission = static function ( string $token, string $honeypot = '' ): array {
	return [
		'content'                            => 'Thanks for the write-up, the second section was useful.',
		'author'                             => 'Smoke Test',
		'email'                              => 'smoke@example.com',
		'simple_spam_shield_website_url'     => $honeypot,
		'simple_spam_shield_form_loaded'     => $token,
		'simple_spam_shield_behavioral_data' => '',
	];
};

// A visitor address from the documentation range, so no allowlist matches.
$_SERVER['REMOTE_ADDR'] = '192.0.2.10';

switch ( $mode ) {
	/*
	 * An update replaces the files and fires no activation hook, and an
	 * automatic one runs from WP-Cron, where admin_init does not fire. This
	 * request is the same: no activation, no admin_init.
	 */
	case 'upgrade':
		$from = $args[1] ?? '';
		$check( '' !== $from && $from !== $schema(), "the previous release had an older schema ({$from}, now {$schema()})" );
		$check( 0 === did_action( 'admin_init' ), 'admin_init has not fired in this request' );
		$check( $schema() === get_option( 'simple_spam_shield_db_version' ), "the table was upgraded to {$schema()} without admin_init" );
		$check( in_array( 'outcome', $columns(), true ), 'the upgraded table has the outcome column' );

		$verdict = Guard_Runner::run( $submission( '', 'https://spam.example/' ), 'comment' );
		$row     = $latest_row();
		$check( is_wp_error( $verdict ), 'spam submitted straight after the update is blocked' );
		$check( null !== $row && 'blocked' === $row['outcome'], 'and the block is logged' );
		break;

	case 'activation':
		$check( Database_Manager::table_name() === $log_table, 'the log table name matches Database_Manager' );
		$check( $table_exists(), 'activation creates the log table' );
		$check( $schema() === get_option( 'simple_spam_shield_db_version' ), "at schema {$schema()}" );
		$check( (bool) get_option( 'simple_spam_shield_enabled' ), 'protection is on by default' );
		$check( false !== wp_next_scheduled( 'simple_spam_shield_purge_logs' ), 'the log-retention purge is scheduled' );
		break;

	/*
	 * run.sh defines WP_ADMIN before WordPress loads, so is_admin() was true
	 * on plugins_loaded and Admin::init() ran. The rest of an admin request is
	 * set up here. Not WP-CLI's --context=admin: on WordPress 7.x its
	 * bootstrap raises warnings in wp-admin/includes/menu.php with no plugin
	 * active at all, which the log scan cannot tell from the plugin's own.
	 */
	case 'admin':
		global $wp_registered_settings;

		$check( is_admin() && false !== has_action( 'admin_init', [ Admin::class, 'register_settings' ] ), 'Admin::init() ran on plugins_loaded' );

		require_once ABSPATH . 'wp-admin/includes/admin.php';
		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );
		do_action( 'admin_init' );
		$check( isset( $wp_registered_settings['simple_spam_shield_monitor_mode'] ), 'settings register on admin_init' );

		do_action( 'admin_menu' );
		$check( '' !== (string) menu_page_url( 'onsite-spam-guard', false ), 'the admin menu is registered' );

		// Set up the request as wp-admin/admin.php does for a plugin page, so
		// core functions the page calls see what a real page load gives them.
		// A CLI request has no host, no page and no screen.
		$open_page = static function ( string $page ): void {
			$GLOBALS['pagenow']     = 'admin.php';
			$GLOBALS['plugin_page'] = $page;
			$_GET['page']           = $page;
			$_SERVER['HTTP_HOST']   = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php?page=' . $page;
			$GLOBALS['hook_suffix'] = (string) get_plugin_page_hook( $page, 'admin.php' );
			set_current_screen();
		};

		$open_page( 'onsite-spam-guard' );
		ob_start();
		Admin::render_settings_page();
		$settings = (string) ob_get_clean();
		$check( str_contains( $settings, 'simple_spam_shield_monitor_mode' ), 'the settings page renders its fields' );

		$open_page( 'onsite-spam-guard-spam-logs' );
		ob_start();
		Admin::render_logs_page();
		$logs = (string) ob_get_clean();
		$check( str_contains( $logs, 'wp-list-table' ), 'the spam log page renders its table' );

		$tests  = apply_filters( 'site_status_tests', [ 'direct' => [], 'async' => [] ] );
		$health = isset( $tests['direct']['onsite_spam_guard_trusted_proxy'] )
			? call_user_func( $tests['direct']['onsite_spam_guard_trusted_proxy']['test'] )
			: [];
		$check( in_array( $health['status'] ?? '', [ Proxy_Diagnostics::GOOD, Proxy_Diagnostics::RECOMMENDED, Proxy_Diagnostics::CRITICAL ], true ), 'the Site Health test runs' );
		break;

	case 'guards':
		// The field markup every protected form carries. Its token is signed
		// at issue, and the time gate refuses it for its first 3 seconds.
		$markup = Assets::field_markup();
		preg_match( '/name="simple_spam_shield_form_loaded" value="([^"]+)"/', $markup, $token );
		$token = $token[1] ?? '';
		$check( '' !== $token && str_contains( $markup, 'name="' . Honeypot::field_name() . '"' ), 'the form markup carries a token and the honeypot field' );
		sleep( 4 );

		$check( true === Guard_Runner::run( $submission( $token ), 'comment' ), 'a genuine comment is accepted' );

		$verdict = Guard_Runner::run( $submission( $token, 'https://spam.example/' ), 'comment' );
		$row     = $latest_row();
		$check( is_wp_error( $verdict ), 'a comment with the honeypot filled is blocked' );
		$check( null !== $row && 'honeypot' === $row['guard'] && 'blocked' === $row['outcome'], 'and logged as a honeypot block' );

		update_option( 'simple_spam_shield_monitor_mode', true );
		$verdict = Guard_Runner::run( $submission( $token, 'https://spam.example/other' ), 'comment' );
		$row     = $latest_row();
		update_option( 'simple_spam_shield_monitor_mode', false );
		$check( true === $verdict, 'in monitor mode the same spam is let through' );
		$check( null !== $row && 'monitored' === $row['outcome'], 'and logged as monitored' );
		break;

	/*
	 * None of the host plugins is installed. Every integration's init() has
	 * already run on plugins_loaded; running each again here makes that
	 * explicit rather than implied by the request not having died.
	 */
	case 'integrations':
		$hosts = [
			'WooCommerce'    => class_exists( 'WooCommerce' ),
			'Jetpack'        => class_exists( 'Jetpack' ),
			'Contact Form 7' => defined( 'WPCF7_VERSION' ),
			'WP Job Manager' => class_exists( 'WP_Job_Manager' ),
			'BuddyPress'     => function_exists( 'buddypress' ),
		];
		$check( ! in_array( true, $hosts, true ), 'no host plugin is active (' . implode( ', ', array_keys( $hosts ) ) . ')' );

		foreach ( [ 'Comments', 'WooCommerce', 'Jetpack_Forms', 'Job_Manager', 'Contact_Form_7', 'BuddyPress_Messages', 'Registration', 'Abilities_API' ] as $integration ) {
			call_user_func( [ "Simple_Spam_Shield\\Integrations\\{$integration}", 'init' ] );
			$check( true, "{$integration}::init() runs without its host" );
		}
		$check( false !== has_filter( 'preprocess_comment' ), 'comment protection is hooked' );
		break;

	/*
	 * The abilities exist from WordPress 6.9. Below that, registration must
	 * do nothing and break nothing; from 6.9, both abilities must register,
	 * refuse visitors, pass core's own output validation, and carry none of
	 * the personal data the log holds. Runs after 'guards', so the log has
	 * a blocked and a monitored entry from the smoke visitor.
	 */
	case 'abilities':
		if ( ! function_exists( 'wp_get_ability' ) ) {
			$check( true, 'no Abilities API in WordPress ' . get_bloginfo( 'version' ) . ': nothing registered, nothing failed' );
			break;
		}

		$names = [ 'onsite-spam-guard/stats', 'onsite-spam-guard/recent-blocks' ];
		foreach ( $names as $name ) {
			$check( null !== wp_get_ability( $name ), "{$name} is registered" );
		}

		wp_set_current_user( 0 );
		$check( is_wp_error( wp_get_ability( $names[0] )->execute() ), 'a visitor is refused' );

		$admins = get_users( [ 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ] );
		wp_set_current_user( (int) ( $admins[0] ?? 0 ) );

		// The summary is cached for 15 minutes and logging does not clear it.
		// The spam log page cached it in 'admin', before anything was logged.
		Database_Manager::flush_stats();
		$stats = wp_get_ability( $names[0] )->execute();
		$check( is_array( $stats ) && 1 === $stats['blocked'] && 1 === $stats['monitored'], 'stats passes core output validation and counts the log' );

		$recent = wp_get_ability( $names[1] )->execute( [ 'limit' => 5 ] );
		$check( is_array( $recent ) && 2 === count( $recent['entries'] ), 'recent-blocks passes core output validation and lists the log' );

		$flat = (string) wp_json_encode( $recent );
		$check( ! str_contains( $flat, '192.0.2.10' ) && ! str_contains( $flat, 'smoke@example.com' ) && ! str_contains( $flat, 'second section' ), 'and carries no IP address, email or submitted text' );

		$run = rest_do_request( new WP_REST_Request( 'GET', '/wp-abilities/v1/abilities/onsite-spam-guard/stats/run' ) );
		$check( 200 === $run->get_status(), 'stats runs over REST' );
		break;

	case 'uninstalled':
		global $wpdb;
		$left = $wpdb->get_col( "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE 'simple\\_spam\\_shield\\_%'" );
		$check( ! $table_exists(), 'uninstall drops the log table' );
		$check( [] === $left, 'and deletes every option' . ( $left ? ' — left: ' . implode( ', ', $left ) : '' ) );
		$check( false === wp_next_scheduled( 'simple_spam_shield_purge_logs' ), 'and unschedules the purge' );
		break;

	default:
		WP_CLI::error( "Unknown mode '{$mode}'." );
}

if ( $failures > 0 ) {
	WP_CLI::error( "{$failures} check(s) failed in '{$mode}'." );
}
