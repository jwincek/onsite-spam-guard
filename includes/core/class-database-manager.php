<?php
/**
 * Database Manager — custom table for spam log storage.
 *
 * Ported from Comment & Form Guard's My_Spam_Plugin_Database_Manager.
 * Uses a proper custom database table with dbDelta instead of the
 * wp_options array, supporting pagination, sorting, and bulk actions.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Core;

final class Database_Manager {

	private const DB_VERSION     = '1.1';
	private const DB_VERSION_KEY = 'simple_spam_shield_db_version';
	private const STATS_KEY      = 'simple_spam_shield_stats';

	/**
	 * Get the full table name with prefix.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'simple_spam_shield_spam_logs';
	}

	/**
	 * Create or update the spam logs table.
	 * Called on plugin activation and on admin_init to handle upgrades.
	 */
	public static function create_table(): void {
		// Skip if the table is already at the current version.
		if ( get_option( self::DB_VERSION_KEY ) === self::DB_VERSION ) {
			return;
		}

		global $wpdb;

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blocked_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
			guard varchar(50) NOT NULL,
			context varchar(50) NOT NULL,
			reason text NOT NULL,
			content longtext NOT NULL,
			ip_address varchar(100) NOT NULL,
			user_agent varchar(255) NOT NULL,
			PRIMARY KEY  (id),
			KEY blocked_at (blocked_at),
			KEY guard (guard),
			KEY context (context)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		update_option( self::DB_VERSION_KEY, self::DB_VERSION );
	}

	/**
	 * Insert a spam log entry.
	 *
	 * @param array $entry Log entry data.
	 * @return int|false Insert ID on success, false on failure.
	 */
	public static function insert( array $entry ): int|false {
		global $wpdb;

		$data = [
			'blocked_at' => current_time( 'mysql', true ),
			'guard'      => sanitize_text_field( $entry['guard'] ?? '' ),
			'context'    => sanitize_text_field( $entry['context'] ?? '' ),
			'reason'     => sanitize_textarea_field( $entry['reason'] ?? '' ),
			'content'    => wp_kses_post( mb_substr( $entry['content'] ?? '', 0, 500 ) ),
			'ip_address' => sanitize_text_field( $entry['ip_address'] ?? '' ),
			'user_agent' => sanitize_text_field( mb_substr( $entry['user_agent'] ?? '', 0, 255 ) ),
		];

		$inserted = $wpdb->insert( self::table_name(), $data, [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ] );

		return $inserted ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Build the optional WHERE clause and bound parameters for a filtered
	 * log query. Only the guard and context columns can be filtered, both
	 * via prepared %s placeholders.
	 *
	 * @param array $filters Associative filters: 'guard' and/or 'context'.
	 * @return array{0:string,1:array} [ where-clause, bound params ].
	 */
	public static function build_filter( array $filters ): array {
		$clauses = [];
		$params  = [];

		if ( ! empty( $filters['guard'] ) ) {
			$clauses[] = 'guard = %s';
			$params[]  = (string) $filters['guard'];
		}
		if ( ! empty( $filters['context'] ) ) {
			$clauses[] = 'context = %s';
			$params[]  = (string) $filters['context'];
		}

		$where = $clauses ? ' WHERE ' . implode( ' AND ', $clauses ) : '';

		return [ $where, $params ];
	}

	/**
	 * Get spam log entries with pagination and optional filtering.
	 *
	 * @param int    $per_page Items per page.
	 * @param int    $offset   Offset for pagination.
	 * @param string $orderby  Column to sort by.
	 * @param string $order    ASC or DESC.
	 * @param array  $filters  Optional guard/context filters.
	 * @return \stdClass[] Row objects.
	 */
	public static function get_logs( int $per_page = 20, int $offset = 0, string $orderby = 'blocked_at', string $order = 'DESC', array $filters = [] ): array {
		global $wpdb;

		$table = self::table_name();

		// Whitelist sortable columns to prevent SQL injection.
		$allowed_orderby = [ 'blocked_at', 'guard', 'context', 'ip_address' ];
		if ( ! in_array( $orderby, $allowed_orderby, true ) ) {
			$orderby = 'blocked_at';
		}
		$order = strtoupper( $order ) === 'ASC' ? 'ASC' : 'DESC';

		[ $where, $params ] = self::build_filter( $filters );
		$params[]           = $per_page;
		$params[]           = $offset;

		// Table/ORDER BY are interpolated from whitelisted values; the %s/%d
		// placeholders live in the prepared $where fragment and the variadic
		// $params spread, which the static sniffs cannot follow.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}{$where} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
				...$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get the total count of log entries, optionally filtered.
	 *
	 * @param array $filters Optional guard/context filters.
	 * @return int
	 */
	public static function get_count( array $filters = [] ): int {
		global $wpdb;

		$table              = self::table_name();
		[ $where, $params ] = self::build_filter( $filters );

		if ( empty( $params ) ) {
			return (int) $wpdb->get_var( "SELECT COUNT(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL
		}

		// The %s placeholders live in the prepared $where fragment from
		// build_filter(); the table name is the plugin's own.
		// phpcs:disable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$table}{$where}",
				...$params
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get the distinct values present in a filterable column.
	 *
	 * @param string $column One of 'guard' or 'context'.
	 * @return string[]
	 */
	public static function distinct_values( string $column ): array {
		if ( ! in_array( $column, [ 'guard', 'context' ], true ) ) {
			return [];
		}

		global $wpdb;
		$table = self::table_name();

		// $column is whitelisted above; identifiers cannot be placeholders.
		return $wpdb->get_col( "SELECT DISTINCT {$column} FROM {$table} ORDER BY {$column} ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Get cached blocked-submission stats for the last 7 days.
	 *
	 * @return array{week_total:int,top_guard:string,top_count:int}
	 */
	public static function get_stats(): array {
		$cached = get_transient( self::STATS_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		$table = self::table_name();
		$since = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

		$week_total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(id) FROM {$table} WHERE blocked_at >= %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$since
			)
		);

		$top = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT guard, COUNT(id) AS hits FROM {$table} WHERE blocked_at >= %s GROUP BY guard ORDER BY hits DESC LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL
				$since
			)
		);

		$stats = [
			'week_total' => $week_total,
			'top_guard'  => $top->guard ?? '',
			'top_count'  => isset( $top->hits ) ? (int) $top->hits : 0,
		];

		set_transient( self::STATS_KEY, $stats, 15 * MINUTE_IN_SECONDS );

		return $stats;
	}

	/**
	 * Invalidate the cached stats (after a manual clear or purge).
	 */
	public static function flush_stats(): void {
		delete_transient( self::STATS_KEY );
	}

	/**
	 * Delete a single log entry by ID.
	 */
	public static function delete( int $id ): bool {
		global $wpdb;
		return (bool) $wpdb->delete( self::table_name(), [ 'id' => $id ], [ '%d' ] );
	}

	/**
	 * Delete multiple log entries by ID.
	 *
	 * @param int[] $ids Array of log IDs.
	 * @return int Number of rows deleted.
	 */
	public static function delete_many( array $ids ): int {
		global $wpdb;

		$ids = array_map( 'absint', $ids );
		$ids = array_filter( $ids );

		if ( empty( $ids ) ) {
			return 0;
		}

		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$table        = self::table_name();

		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE id IN ({$placeholders})", // phpcs:ignore WordPress.DB.PreparedSQL, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Table name and %d placeholders are built from a known-safe count of integer IDs.
				...$ids
			)
		);

		self::flush_stats();

		return $deleted;
	}

	/**
	 * Delete log entries older than a given number of days.
	 *
	 * Rows are stored with GMT timestamps (see insert()), so the cutoff is
	 * computed in GMT to match. A value of 0 (or less) disables pruning.
	 *
	 * @param int $days Retention window in days.
	 * @return int Number of rows deleted.
	 */
	public static function purge_older_than( int $days ): int {
		if ( $days <= 0 ) {
			return 0;
		}

		global $wpdb;

		$table  = self::table_name();
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		$deleted = (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE blocked_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL
				$cutoff
			)
		);

		self::flush_stats();

		return $deleted;
	}

	/**
	 * Delete all log entries.
	 */
	public static function delete_all(): bool {
		global $wpdb;
		$result = false !== $wpdb->query( 'TRUNCATE TABLE ' . self::table_name() ); // phpcs:ignore WordPress.DB.PreparedSQL
		self::flush_stats();
		return $result;
	}
}
