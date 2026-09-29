<?php
/**
 * Abilities API integration — read-only access to the spam log for REST,
 * MCP and AI clients (WordPress 6.9+).
 *
 * Registers two abilities, both read-only and limited to administrators:
 *
 * - onsite-spam-guard/stats          the 7-day summary shown on the dashboard
 * - onsite-spam-guard/recent-blocks  recent log entries
 *
 * Neither returns personal data. A log row also holds the visitor's IP
 * address, user agent and an excerpt of what they submitted; those stay on the
 * Spam Logs screen. An ability's output goes wherever its caller sends it —
 * an AI provider, when code hands it to the AI Client — so it carries only
 * what explains the block: when, which checks objected, where, and why.
 *
 * Registration is optional rather than required, so `Requires at least` stays
 * below 6.9. The init actions below exist only on 6.9+, and each callback also
 * checks for the function it calls.
 *
 * `show_in_rest` is set explicitly. WordPress 7.1 added a `public` flag that
 * seeds it, but 6.9 and 7.0 do not have that flag.
 *
 * @package Simple_Spam_Shield
 * @since   1.7.0
 */

declare( strict_types=1 );

namespace Simple_Spam_Shield\Integrations;

use Simple_Spam_Shield\Core\Database_Manager;

final class Abilities_API {

	/**
	 * Ability category slug.
	 */
	public const CATEGORY = 'onsite-spam-guard';

	/**
	 * Ability names. Public identifiers: callers address abilities by these.
	 */
	public const STATS         = 'onsite-spam-guard/stats';
	public const RECENT_BLOCKS = 'onsite-spam-guard/recent-blocks';

	/**
	 * Default and maximum number of entries recent-blocks returns.
	 */
	private const DEFAULT_LIMIT = 20;
	private const MAX_LIMIT     = 100;

	/**
	 * Register hooks. Harmless below 6.9, where the actions never fire.
	 */
	public static function init(): void {
		add_action( 'wp_abilities_api_categories_init', [ __CLASS__, 'register_category' ] );
		add_action( 'wp_abilities_api_init', [ __CLASS__, 'register_abilities' ] );
	}

	/**
	 * Register the ability category.
	 */
	public static function register_category(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}

		wp_register_ability_category(
			self::CATEGORY,
			[
				'label'       => __( 'Onsite Spam Guard', 'onsite-spam-guard' ),
				'description' => __( 'What the spam protection has refused, and why.', 'onsite-spam-guard' ),
			]
		);
	}

	/**
	 * Register both abilities.
	 */
	public static function register_abilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			return;
		}

		$meta = [
			'annotations'  => [
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			],
			'show_in_rest' => true,
		];

		wp_register_ability(
			self::STATS,
			[
				'label'               => __( 'Spam protection summary', 'onsite-spam-guard' ),
				'description'         => __( 'Summarizes the last 7 days of spam protection: how many submissions were refused, how many monitor mode let through that would otherwise have been refused, and which check refused the most. Refreshed at most every 15 minutes.', 'onsite-spam-guard' ),
				'category'            => self::CATEGORY,
				'output_schema'       => self::stats_schema(),
				'execute_callback'    => [ __CLASS__, 'stats' ],
				'permission_callback' => [ __CLASS__, 'can_read' ],
				'meta'                => $meta,
			]
		);

		wp_register_ability(
			self::RECENT_BLOCKS,
			[
				'label'               => __( 'Recent spam blocks', 'onsite-spam-guard' ),
				'description'         => __( 'Lists the most recent submissions the spam protection refused, or would have refused under monitor mode, newest first: when, on which form, which checks objected, and the reason given. Contains no IP addresses, user agents or submitted text.', 'onsite-spam-guard' ),
				'category'            => self::CATEGORY,
				'input_schema'        => self::recent_blocks_input_schema(),
				'output_schema'       => self::recent_blocks_output_schema(),
				'execute_callback'    => [ __CLASS__, 'recent_blocks' ],
				'permission_callback' => [ __CLASS__, 'can_read' ],
				'meta'                => $meta,
			]
		);
	}

	/**
	 * Both abilities read what the Spam Logs screen shows, so they need the
	 * capability that screen needs.
	 */
	public static function can_read(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Execute callback for onsite-spam-guard/stats.
	 *
	 * @return array{days:int,blocked:int,monitored:int,top_guard:string,top_guard_blocked:int}
	 */
	public static function stats(): array {
		return self::summary( Database_Manager::get_stats() );
	}

	/**
	 * Execute callback for onsite-spam-guard/recent-blocks.
	 *
	 * @param mixed $input Validated against the input schema; an array, or
	 *                     empty when called with no input.
	 * @return array{entries:list<array<string,mixed>>,total:int}
	 */
	public static function recent_blocks( mixed $input = null ): array {
		$input = is_array( $input ) ? $input : [];
		$limit = isset( $input['limit'] ) ? (int) $input['limit'] : self::DEFAULT_LIMIT;
		$limit = max( 1, min( self::MAX_LIMIT, $limit ) );

		$filters = [];
		if ( isset( $input['outcome'] ) && in_array( $input['outcome'], [ Database_Manager::OUTCOME_BLOCKED, Database_Manager::OUTCOME_MONITORED ], true ) ) {
			$filters['outcome'] = $input['outcome'];
		}

		return [
			'entries' => array_map( [ __CLASS__, 'entry' ], Database_Manager::get_logs( $limit, 0, 'blocked_at', 'DESC', $filters ) ),
			'total'   => Database_Manager::get_count( $filters ),
		];
	}

	/**
	 * Map Database_Manager::get_stats() to the ability's output. The stored
	 * keys are internal; these are the public ones.
	 *
	 * @param array<string, mixed> $stats As returned by get_stats().
	 * @return array{days:int,blocked:int,monitored:int,top_guard:string,top_guard_blocked:int}
	 */
	public static function summary( array $stats ): array {
		return [
			'days'              => 7,
			'blocked'           => (int) ( $stats['week_total'] ?? 0 ),
			'monitored'         => (int) ( $stats['week_monitored'] ?? 0 ),
			'top_guard'         => (string) ( $stats['top_guard'] ?? '' ),
			'top_guard_blocked' => (int) ( $stats['top_count'] ?? 0 ),
		];
	}

	/**
	 * Map one log row to an entry. Selects fields rather than removing them,
	 * so a column added to the log later is not exposed by default.
	 *
	 * @param object $row A row from Database_Manager::get_logs().
	 * @return array{logged_at:string,outcome:string,context:string,guard:string,guards_matched:list<string>,reason:string}
	 */
	public static function entry( object $row ): array {
		$matched = array_values( array_filter( array_map( 'trim', explode( ',', (string) ( $row->guards_matched ?? '' ) ) ) ) );
		$guard   = (string) ( $row->guard ?? '' );

		// Rows written before 1.3.0 have no guards_matched; the reporting
		// guard is the one match known for them.
		if ( [] === $matched && '' !== $guard ) {
			$matched = [ $guard ];
		}

		// blocked_at is stored in UTC (current_time( 'mysql', true )).
		$time = strtotime( (string) ( $row->blocked_at ?? '' ) . ' UTC' );

		return [
			'logged_at'      => false === $time ? '' : gmdate( 'c', $time ),
			'outcome'        => Database_Manager::OUTCOME_MONITORED === ( $row->outcome ?? '' ) ? Database_Manager::OUTCOME_MONITORED : Database_Manager::OUTCOME_BLOCKED,
			'context'        => (string) ( $row->context ?? '' ),
			'guard'          => $guard,
			'guards_matched' => $matched,
			'reason'         => (string) ( $row->reason ?? '' ),
		];
	}

	/**
	 * Output schema for onsite-spam-guard/stats.
	 *
	 * @return array<string, mixed>
	 */
	private static function stats_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'days'              => [
					'type'        => 'integer',
					'description' => __( 'Length of the period summarized, in days.', 'onsite-spam-guard' ),
				],
				'blocked'           => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Submissions refused in the period.', 'onsite-spam-guard' ),
				],
				'monitored'         => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'Submissions monitor mode let through that would otherwise have been refused.', 'onsite-spam-guard' ),
				],
				'top_guard'         => [
					'type'        => 'string',
					'description' => __( 'Slug of the check that refused the most submissions, such as "honeypot" or "time_gate". Empty when nothing was refused.', 'onsite-spam-guard' ),
				],
				'top_guard_blocked' => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many submissions that check refused.', 'onsite-spam-guard' ),
				],
			],
			'required'             => [ 'days', 'blocked', 'monitored', 'top_guard', 'top_guard_blocked' ],
			'additionalProperties' => false,
		];
	}

	/**
	 * Input schema for onsite-spam-guard/recent-blocks.
	 *
	 * @return array<string, mixed>
	 */
	private static function recent_blocks_input_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'limit'   => [
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => self::MAX_LIMIT,
					'default'     => self::DEFAULT_LIMIT,
					'description' => __( 'How many entries to return, newest first.', 'onsite-spam-guard' ),
				],
				'outcome' => [
					'type'        => 'string',
					'enum'        => [ Database_Manager::OUTCOME_BLOCKED, Database_Manager::OUTCOME_MONITORED ],
					'description' => __( 'Only entries with this outcome. Omit for both.', 'onsite-spam-guard' ),
				],
			],
			'additionalProperties' => false,
			// Lets the ability run with no input at all.
			'default'              => [],
		];
	}

	/**
	 * Output schema for onsite-spam-guard/recent-blocks.
	 *
	 * @return array<string, mixed>
	 */
	private static function recent_blocks_output_schema(): array {
		return [
			'type'                 => 'object',
			'properties'           => [
				'entries' => [
					'type'        => 'array',
					'description' => __( 'Log entries, newest first.', 'onsite-spam-guard' ),
					'items'       => [
						'type'                 => 'object',
						'properties'           => [
							'logged_at'      => [
								'type'        => 'string',
								'format'      => 'date-time',
								'description' => __( 'When the submission was refused, or let through under monitor mode (ISO 8601, UTC).', 'onsite-spam-guard' ),
							],
							'outcome'        => [
								'type'        => 'string',
								'enum'        => [ Database_Manager::OUTCOME_BLOCKED, Database_Manager::OUTCOME_MONITORED ],
								'description' => __( '"blocked" if the submission was refused; "monitored" if monitor mode let it through.', 'onsite-spam-guard' ),
							],
							'context'        => [
								'type'        => 'string',
								'description' => __( 'The form it was submitted to, such as "comment", "woo_review" or "cf7".', 'onsite-spam-guard' ),
							],
							'guard'          => [
								'type'        => 'string',
								'description' => __( 'Slug of the check whose objection was reported.', 'onsite-spam-guard' ),
							],
							'guards_matched' => [
								'type'        => 'array',
								'items'       => [ 'type' => 'string' ],
								'description' => __( 'Slugs of every check that objected.', 'onsite-spam-guard' ),
							],
							'reason'         => [
								'type'        => 'string',
								'description' => __( 'The message the check gave. Built-in checks give a fixed message; a check added by another plugin writes its own.', 'onsite-spam-guard' ),
							],
						],
						'required'             => [ 'logged_at', 'outcome', 'context', 'guard', 'guards_matched', 'reason' ],
						'additionalProperties' => false,
					],
				],
				'total'   => [
					'type'        => 'integer',
					'minimum'     => 0,
					'description' => __( 'How many entries match in all, of which up to "limit" are returned.', 'onsite-spam-guard' ),
				],
			],
			'required'             => [ 'entries', 'total' ],
			'additionalProperties' => false,
		];
	}
}
