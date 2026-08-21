<?php
/**
 * Onsite Spam Guard — Uninstall Script.
 *
 * Runs when the plugin is deleted (not just deactivated). Removes all plugin
 * data — the custom table, options, transients, and scheduled task — for every
 * site on a multisite network, or the single site otherwise.
 *
 * @package Simple_Spam_Shield
 */

// Exit if WP_UNINSTALL_PLUGIN is not defined.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all Onsite Spam Guard data for the current site.
 */
function simple_spam_shield_uninstall_site(): void {
	global $wpdb;

	// Respect the opt-out: leave all data in place unless deletion is enabled
	// (default on, matching the plugin's documented clean-uninstall behavior).
	if ( ! (bool) get_option( 'simple_spam_shield_delete_data_on_uninstall', true ) ) {
		return;
	}

	// 1. Drop the custom spam logs database table.
	$table = $wpdb->prefix . 'simple_spam_shield_spam_logs';
	$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table identifier cannot be a prepared placeholder; value is the plugin's own prefixed table name.

	// 2. Delete all plugin options.
	$options = [
		'simple_spam_shield_enabled',
		'simple_spam_shield_hard_block',
		'simple_spam_shield_protect_comments',
		'simple_spam_shield_protect_woo_reviews',
		'simple_spam_shield_protect_jetpack_forms',
		'simple_spam_shield_honeypot_enabled',
		'simple_spam_shield_time_gate_enabled',
		'simple_spam_shield_time_gate_seconds',
		'simple_spam_shield_nonce_enabled',
		'simple_spam_shield_link_limit_enabled',
		'simple_spam_shield_link_limit_max',
		'simple_spam_shield_keyword_block_enabled',
		'simple_spam_shield_blocked_keywords',
		'simple_spam_shield_use_wp_disallowed_keys',
		'simple_spam_shield_duplicate_enabled',
		'simple_spam_shield_rate_limit_enabled',
		'simple_spam_shield_rate_limit_max',
		'simple_spam_shield_behavioral_enabled',
		'simple_spam_shield_behavioral_threshold',
		'simple_spam_shield_log_blocked',
		'simple_spam_shield_log_retention_days',
		'simple_spam_shield_delete_data_on_uninstall',
		'simple_spam_shield_allowlist',
		'simple_spam_shield_trust_proxy',
		'simple_spam_shield_token_secret',
		'simple_spam_shield_db_version',
	];

	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// 3. Clean up every transient the plugin creates. Matching on the shared
	// prefix covers duplicate detection, rate limiting, and anything added
	// later, rather than naming each family and forgetting one — the
	// rate-limit transients added in 1.2.0 were missed by the old pattern.
	//
	// Underscores are escaped: `_` is a single-character wildcard in SQL LIKE,
	// so an unescaped pattern matches more loosely than it appears to.
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		  WHERE option_name LIKE '\\_transient\\_simple\\_spam\\_shield\\_%'
		     OR option_name LIKE '\\_transient\\_timeout\\_simple\\_spam\\_shield\\_%'"
	); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

	// 4. Delete the stats transient.
	delete_transient( 'simple_spam_shield_stats' );

	// 5. Clear the scheduled retention-purge cron event.
	wp_clear_scheduled_hook( 'simple_spam_shield_purge_logs' );
}

// Run for every site on a network, or just the current site otherwise.
if ( is_multisite() ) {
	$simple_spam_shield_site_ids = get_sites( [
		'fields' => 'ids',
		'number' => 0,
	] );

	foreach ( $simple_spam_shield_site_ids as $simple_spam_shield_site_id ) {
		switch_to_blog( (int) $simple_spam_shield_site_id );
		simple_spam_shield_uninstall_site();
		restore_current_blog();
	}
} else {
	simple_spam_shield_uninstall_site();
}
