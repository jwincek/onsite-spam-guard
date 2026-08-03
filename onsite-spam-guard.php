<?php
/**
 * Plugin Name: Onsite Spam Guard
 * Description: Config-driven spam prevention for Comments, WooCommerce Reviews, and Jetpack Contact Form blocks — no external services required.
 * Version:     1.1.2
 * Requires at least: 6.2
 * Requires PHP: 8.2
 * Author:      Jerome Wincek
 * Text Domain: onsite-spam-guard
 * License:     GPL-2.0-or-later
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Plugin constants.
define( 'SIMPLE_SPAM_SHIELD_VERSION', '1.1.2' );
define( 'SIMPLE_SPAM_SHIELD_FILE', __FILE__ );
define( 'SIMPLE_SPAM_SHIELD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIMPLE_SPAM_SHIELD_URL', plugin_dir_url( __FILE__ ) );

// Autoload namespaced classes, e.g. Simple_Spam_Shield\Core\Config maps to includes/core/class-config.php.
spl_autoload_register( function ( string $class ): void {
	if ( ! str_starts_with( $class, 'Simple_Spam_Shield\\' ) ) {
		return;
	}

	$relative = substr( $class, strlen( 'Simple_Spam_Shield\\' ) );
	$parts    = explode( '\\', $relative );
	$name     = array_pop( $parts );
	$dir      = strtolower( implode( '/', $parts ) );
	$file     = SIMPLE_SPAM_SHIELD_DIR . 'includes/' . $dir . '/class-' . strtolower( str_replace( '_', '-', $name ) ) . '.php';

	if ( file_exists( $file ) ) {
		require_once $file;
	}
} );

// Public integration API for other plugins (prefixed global functions).
require_once SIMPLE_SPAM_SHIELD_DIR . 'includes/api.php';

/**
 * Plugin activation — set default options.
 */
register_activation_hook( __FILE__, function (): void {
	\Simple_Spam_Shield\Core\Config::init( SIMPLE_SPAM_SHIELD_DIR . 'config/' );

	$defaults = \Simple_Spam_Shield\Core\Config::get( 'settings', 'defaults', [] );
	foreach ( $defaults as $key => $value ) {
		if ( false === get_option( "simple_spam_shield_{$key}" ) ) {
			add_option( "simple_spam_shield_{$key}", $value );
		}
	}

	// Create the spam logs database table.
	\Simple_Spam_Shield\Core\Database_Manager::create_table();

	// Schedule the daily log-retention purge.
	if ( ! wp_next_scheduled( 'simple_spam_shield_purge_logs' ) ) {
		wp_schedule_event( time(), 'daily', 'simple_spam_shield_purge_logs' );
	}
} );

/**
 * Plugin deactivation — clean up transients and scheduled events.
 */
register_deactivation_hook( __FILE__, function (): void {
	delete_transient( 'simple_spam_shield_stats' );
	wp_clear_scheduled_hook( 'simple_spam_shield_purge_logs' );
} );

/**
 * Daily cron callback — prune log rows older than the retention window.
 */
function simple_spam_shield_purge_logs(): void {
	$days = (int) get_option( 'simple_spam_shield_log_retention_days', 30 );
	\Simple_Spam_Shield\Core\Database_Manager::purge_older_than( $days );
}
add_action( 'simple_spam_shield_purge_logs', 'simple_spam_shield_purge_logs' );

/**
 * Initialize the plugin on plugins_loaded.
 */
function simple_spam_shield_init(): void {
	// 1. Config loader.
	\Simple_Spam_Shield\Core\Config::init( SIMPLE_SPAM_SHIELD_DIR . 'config/' );

	// 2. Guard pipeline (the "abilities" layer).
	\Simple_Spam_Shield\Core\Guard_Runner::init();

	// 3. Integration hooks — thin consumers that delegate to the guard pipeline.
	\Simple_Spam_Shield\Integrations\Comments::init();
	\Simple_Spam_Shield\Integrations\WooCommerce::init();
	\Simple_Spam_Shield\Integrations\Jetpack_Forms::init();

	// 4. Front-end assets (honeypot field + JS timer).
	add_action( 'wp_enqueue_scripts', [ \Simple_Spam_Shield\Core\Assets::class, 'enqueue' ] );

	// 5. Admin settings (admin only).
	if ( is_admin() ) {
		\Simple_Spam_Shield\Core\Admin::init();
	}

	// 6. Self-heal the retention cron for installs that predate it
	// (the activation hook only fires on (re)activation).
	if ( ! wp_next_scheduled( 'simple_spam_shield_purge_logs' ) ) {
		wp_schedule_event( time(), 'daily', 'simple_spam_shield_purge_logs' );
	}
}
add_action( 'plugins_loaded', 'simple_spam_shield_init' );
