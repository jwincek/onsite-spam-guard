<?php
/**
 * Spam Logs List Table — extends WP_List_Table for the admin spam log viewer.
 *
 * Ported from Comment & Form Guard's My_Spam_Plugin_Spam_Logs_List_Table,
 * adapted to use our Database_Manager and namespace conventions.
 *
 * @package Simple_Spam_Shield
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Admin;

use Simple_Spam_Shield\Core\Database_Manager;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// WP_List_Table is loaded by WordPress admin but guard against edge cases.
if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

final class Spam_Logs_List_Table extends \WP_List_Table {

	public function __construct() {
		parent::__construct( [
			'singular' => 'spam_log',
			'plural'   => 'spam_logs',
			'ajax'     => false,
		] );
	}

	/**
	 * Define table columns.
	 */
	public function get_columns(): array {
		return [
			'cb'         => '<input type="checkbox" />',
			'blocked_at' => __( 'Date / Time', 'onsite-spam-guard' ),
			'guard'      => __( 'Guard', 'onsite-spam-guard' ),
			'context'    => __( 'Context', 'onsite-spam-guard' ),
			'reason'     => __( 'Reason', 'onsite-spam-guard' ),
			'content'    => __( 'Content', 'onsite-spam-guard' ),
			'ip_address' => __( 'IP Address', 'onsite-spam-guard' ),
			'user_agent' => __( 'User Agent', 'onsite-spam-guard' ),
		];
	}

	/**
	 * Define sortable columns.
	 */
	public function get_sortable_columns(): array {
		return [
			'blocked_at' => [ 'blocked_at', true ],
			'guard'      => [ 'guard', false ],
			'context'    => [ 'context', false ],
			'ip_address' => [ 'ip_address', false ],
		];
	}

	/**
	 * Define bulk actions.
	 */
	public function get_bulk_actions(): array {
		return [
			'delete' => __( 'Delete', 'onsite-spam-guard' ),
		];
	}

	/**
	 * Checkbox column.
	 */
	public function column_cb( $item ): string {
		return sprintf(
			'<input type="checkbox" name="log_id[]" value="%d" />',
			(int) $item->id
		);
	}

	/**
	 * Content column with row actions.
	 */
	public function column_content( $item ): string {
		$content = esc_html( wp_trim_words( $item->content, 15, '…' ) );

		$delete_url = wp_nonce_url(
			add_query_arg(
				[
					'page'   => 'onsite-spam-guard-spam-logs',
					'action' => 'delete',
					'log_id' => [ (int) $item->id ],
				],
				admin_url( 'admin.php' )
			),
			'bulk-spam_logs'
		);

		$actions = [
			'delete' => sprintf(
				'<a href="%s" class="submitdelete">%s</a>',
				esc_url( $delete_url ),
				esc_html__( 'Delete', 'onsite-spam-guard' )
			),
		];

		return $content . $this->row_actions( $actions );
	}

	/**
	 * Default column renderer.
	 */
	public function column_default( $item, $column_name ): string {
		return match ( $column_name ) {
			'blocked_at' => esc_html(
				get_date_from_gmt(
					$item->blocked_at,
					get_option( 'date_format' ) . ' ' . get_option( 'time_format' )
				)
			),
			'guard', 'context', 'reason', 'ip_address' => esc_html( $item->$column_name ),
			default => '',
		};
	}

	/**
	 * User-agent column — truncated, with the full value in a tooltip.
	 */
	public function column_user_agent( $item ): string {
		$agent = (string) $item->user_agent;

		if ( '' === $agent ) {
			return '—';
		}

		return sprintf(
			'<span title="%s">%s</span>',
			esc_attr( $agent ),
			esc_html( mb_strimwidth( $agent, 0, 40, '…' ) )
		);
	}

	/**
	 * Render the guard/context filter dropdowns above the table.
	 *
	 * @param string $which Tablenav position ('top' or 'bottom').
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}

		// Read-only display filters; whitelisted in the query layer, so no
		// nonce is required to read them.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$current_guard   = isset( $_GET['filter_guard'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_guard'] ) ) : '';
		$current_context = isset( $_GET['filter_context'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_context'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$guards   = Database_Manager::distinct_values( 'guard' );
		$contexts = Database_Manager::distinct_values( 'context' );

		if ( empty( $guards ) && empty( $contexts ) ) {
			return;
		}

		echo '<div class="alignleft actions">';

		echo '<label class="screen-reader-text" for="filter_guard">' . esc_html__( 'Filter by guard', 'onsite-spam-guard' ) . '</label>';
		echo '<select name="filter_guard" id="filter_guard">';
		echo '<option value="">' . esc_html__( 'All guards', 'onsite-spam-guard' ) . '</option>';
		foreach ( $guards as $guard ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $guard ),
				selected( $current_guard, $guard, false ),
				esc_html( $guard )
			);
		}
		echo '</select>';

		echo '<label class="screen-reader-text" for="filter_context">' . esc_html__( 'Filter by context', 'onsite-spam-guard' ) . '</label>';
		echo '<select name="filter_context" id="filter_context">';
		echo '<option value="">' . esc_html__( 'All contexts', 'onsite-spam-guard' ) . '</option>';
		foreach ( $contexts as $context ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $context ),
				selected( $current_context, $context, false ),
				esc_html( $context )
			);
		}
		echo '</select>';

		submit_button( __( 'Filter', 'onsite-spam-guard' ), '', 'filter_action', false );

		echo '</div>';
	}

	/**
	 * Message when no items exist.
	 */
	public function no_items(): void {
		esc_html_e( 'No blocked submissions recorded yet.', 'onsite-spam-guard' );
	}

	/**
	 * Prepare items for display — query, sort, paginate.
	 */
	public function prepare_items(): void {
		$columns  = $this->get_columns();
		$hidden   = [];
		$sortable = $this->get_sortable_columns();

		$this->_column_headers = [ $columns, $hidden, $sortable ];

		// Process bulk actions.
		$this->process_bulk_action();

		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$offset       = ( $current_page - 1 ) * $per_page;

		// Read-only sort/filter parameters for display; all are whitelisted
		// in the query layer, so no nonce is required to read them.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$orderby = sanitize_text_field( wp_unslash( $_GET['orderby'] ?? 'blocked_at' ) );
		$order   = sanitize_text_field( wp_unslash( $_GET['order'] ?? 'DESC' ) );
		$filters = [
			'guard'   => isset( $_GET['filter_guard'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_guard'] ) ) : '',
			'context' => isset( $_GET['filter_context'] ) ? sanitize_text_field( wp_unslash( $_GET['filter_context'] ) ) : '',
		];
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$this->items = Database_Manager::get_logs( $per_page, $offset, $orderby, $order, $filters );

		$total_items = Database_Manager::get_count( $filters );

		$this->set_pagination_args( [
			'total_items' => $total_items,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil( $total_items / $per_page ),
		] );
	}

	/**
	 * Process bulk delete action.
	 *
	 * Verifies both the nonce and the current user's capability. The logs page
	 * that renders this table already checks `manage_options`, but this method
	 * re-checks rather than relying on the caller, so the deletion cannot be
	 * reached without permission through any future entry point.
	 */
	public function process_bulk_action(): void {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to delete these entries.', 'onsite-spam-guard' ) );
		}

		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'bulk-spam_logs' ) ) {
			wp_die( esc_html__( 'Nonce verification failed.', 'onsite-spam-guard' ) );
		}

		$ids = array_map( 'absint', (array) ( $_REQUEST['log_id'] ?? [] ) );

		if ( ! empty( $ids ) ) {
			Database_Manager::delete_many( $ids );
		}
	}
}
