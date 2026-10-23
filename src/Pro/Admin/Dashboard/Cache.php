<?php

namespace WPForms\Pro\Admin\Dashboard;

use DateTime;
use DateTimeImmutable;
use DateTimeZone;
use WPForms\Admin\Dashboard\Cache as CacheBase;
use WPForms\Admin\Dashboard\PaymentStats;
use WPForms\Admin\Helpers\Datepicker;
use WPForms\Helpers\Transient;
use WPForms\Pro\AntiSpam\SpamEntry;
use WPForms\Pro\Db\Analytics\DB as AnalyticsFieldsDB;
use WPForms\Pro\Db\Dashboard\FormDaily;
use WPForms\Pro\Db\Dashboard\Helpers;
use WPForms\Pro\Db\Dashboard\LocationDaily;
use WPForms\Pro\Db\Dashboard\LocationRollup;
use WPForms\Pro\Db\Dashboard\PaymentDaily;
use WPForms\Pro\Db\Dashboard\PaymentRollup;
use WPForms\Pro\Db\Dashboard\RollupRepository;
use WPForms\Pro\Reports\EntriesCount;

/**
 * Dashboard aggregate cache (Pro).
 *
 * @since 2.0.2
 */
class Cache extends CacheBase {

	/**
	 * Ceiling on the cached per-form superset, ranked by entry count.
	 *
	 * The widget renders at most ten rows, but the cache is one site-wide transient while
	 * the gear selection is per-user, so it cannot be narrowed to a single user's picks.
	 * The ceiling bounds the hydration loop, both analytics `IN ()` lists and the
	 * serialized payload. A selected form ranked below it is counted at render instead.
	 *
	 * @since 2.0.2
	 */
	private const TOP_FORMS_LIMIT = 30;

	/**
	 * Ceiling on the language groups the window returns, ranked by entry count.
	 *
	 * Real audiences fall far short of it, so this bounds what a client can do rather than
	 * what a site has: the tag comes from a request header, and a flood of made-up ones
	 * would otherwise return a group per entry and cache the lot. Ranking by count is what
	 * makes the ceiling harmless, since a made-up tag arrives once and sorts last.
	 *
	 * @since 2.0.2
	 */
	private const LANGUAGE_GROUPS_LIMIT = 100;

	/**
	 * Get the ceiling on the cached per-form superset.
	 *
	 * @since 2.0.2
	 *
	 * @return int
	 */
	private function get_top_forms_limit(): int {

		/**
		 * Filters the ceiling on the Dashboard's cached per-form superset.
		 *
		 * Sites with many forms can trade payload size against how often a gear-selected
		 * form falls below the ceiling and has to be counted at render.
		 *
		 * @since 2.0.2
		 *
		 * @param int $limit Maximum rows to cache, ranked by entry count. `0` removes the ceiling.
		 */
		return (int) apply_filters( 'wpforms_pro_admin_dashboard_cache_top_forms_limit', self::TOP_FORMS_LIMIT );
	}

	/**
	 * Entries count reports instance.
	 *
	 * @since 2.0.2
	 *
	 * @var EntriesCount|null
	 */
	private $entries_count;

	/**
	 * Rollup repository instance.
	 *
	 * @since 2.0.2
	 *
	 * @var RollupRepository|null
	 */
	private $rollup_repository;

	/**
	 * Bootstrap the cache.
	 *
	 * @since 2.0.2
	 */
	public function init(): void {

		parent::init();

		$this->hooks();
	}

	/**
	 * Register the rollup dirty-day marking and form-cleanup hooks.
	 *
	 * @since 2.0.2
	 */
	private function hooks(): void {

		$rollup_hooks = new RollupHooks();

		// Empty-type hook: entries have $type='' in WPForms_DB, so the hook name is `wpforms_post_update_`.
		// Fires for ALL empty-type updates; handler guards by checking for `status` key in $data.
		add_action( 'wpforms_post_update_', [ $rollup_hooks, 'mark_dirty_from_update' ], 10, 2 );
		add_action( 'wpforms_pre_delete_entries', [ $rollup_hooks, 'mark_dirty_from_delete' ], 10, 2 );
		add_action( 'wpforms_pro_anti_spam_entry_marked_as_spam', [ $rollup_hooks, 'mark_dirty_from_spam' ], 10, 2 );
		add_action( 'wpforms_pro_anti_spam_entry_set_as_not_spam', [ $rollup_hooks, 'mark_dirty_from_spam' ], 10, 2 );
		add_action( 'wpforms_delete_form', [ $rollup_hooks, 'delete_form_rollup_rows' ] );
		add_action( 'wpforms_pro_tasks_actions_purge_entries_task_delete_entries', [ $rollup_hooks, 'mark_dirty_from_purge' ], 10, 2 );

		// Payment creation, updates (refunds, status changes), and hard-delete.
		add_action( 'wpforms_process_payment_saved', [ $rollup_hooks, 'mark_dirty_from_payment' ], 10, 1 );
		add_action( 'wpforms_post_update_payment', [ $rollup_hooks, 'mark_dirty_from_payment_update' ], 10, 2 );
		add_action( 'wpforms_pre_delete_payment', [ $rollup_hooks, 'on_payment_deleted' ] );

		// Backdated inserts (e.g. LiteConnect import).
		add_action( 'wpforms_post_insert_', [ $rollup_hooks, 'on_entry_inserted' ], 10, 2 );

		// The entries importer passes an explicit 'entry' type to WPForms_DB::add(),
		// so its inserts fire this variant instead of the empty-type hook above.
		add_action( 'wpforms_post_insert_entry', [ $rollup_hooks, 'on_entry_inserted' ], 10, 2 );
		add_action( 'wpforms_post_insert_payment', [ $rollup_hooks, 'on_payment_inserted' ] );

		// Bulk empty-trash (bypasses per-row delete hooks).
		add_action( 'wpforms_pro_admin_entries_page_empty_trash_before', [ $rollup_hooks, 'mark_dirty_from_empty_trash' ], 10, 2 );
	}

	/**
	 * Get the entries count reports instance, instantiating it once.
	 *
	 * @since 2.0.2
	 *
	 * @return EntriesCount
	 */
	private function get_entries_count(): EntriesCount {

		if ( $this->entries_count === null ) {
			$this->entries_count = new EntriesCount();
		}

		return $this->entries_count;
	}

	/**
	 * Get the rollup repository instance, instantiating it once.
	 *
	 * @since 2.0.2
	 *
	 * @return RollupRepository
	 */
	private function get_rollup_repository(): RollupRepository {

		if ( $this->rollup_repository === null ) {
			$this->rollup_repository = new RollupRepository();
		}

		return $this->rollup_repository;
	}

	/**
	 * Whether a range can be served through the rollup repository.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return bool
	 */
	private function is_rollup_covered( DateTimeImmutable $start, DateTimeImmutable $end ): bool {

		return RollupRepository::tables_exist() && $this->get_rollup_repository()->has_coverage( $start, $end );
	}

	/**
	 * Ensure today's rollup rows are fresh before reading from the rollup.
	 *
	 * Only runs on cache miss (the transient layer prevents this on every request).
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 */
	private function ensure_recent_days_fresh( DateTimeImmutable $start, DateTimeImmutable $end ): void {

		if ( ! $this->is_rollup_covered( $start, $end ) ) {
			return;
		}

		$today = new DateTimeImmutable( 'now', wp_timezone() );

		if ( $end->format( 'Y-m-d' ) < $today->format( 'Y-m-d' ) ) {
			return;
		}

		$form_daily     = new FormDaily();
		$location_daily = new LocationDaily();
		$payment_daily  = new PaymentDaily();

		foreach ( [ $today, $today->modify( '-1 day' ) ] as $day ) {
			$form_daily->recompute_day( $day );
			$location_daily->recompute_day( $day );
			$payment_daily->recompute_day( $day );
		}
	}

	/**
	 * Get the aggregates for a range: Lite-safe values plus real entries, spam, and
	 * locations aggregation.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	protected function get_aggregates( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$this->ensure_recent_days_fresh( $start, $end );

		$data = parent::get_aggregates( $start, $end );

		$data['stats']['spam_entries'] = $this->get_spam_count( $start, $end );
		$data['entries']               = $this->get_entries_data( $start, $end );
		$data['locations']             = $this->get_locations( $start, $end );

		return $data;
	}

	/**
	 * Get the payments aggregates: from the rollup when covered, otherwise live.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	protected function get_payments_aggregates( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		// An override of the stat-card aggregation SQL cannot be reproduced from the
		// pre-aggregated rollup, so fall back to the live path rather than serve totals that
		// would silently disagree with the Payments Overview on the same data.
		if ( ! $this->is_rollup_covered( $start, $end ) || PaymentStats::has_column_clause_overrides() ) {
			return parent::get_payments_aggregates( $start, $end );
		}

		$stats = $this->get_rollup_repository()->payment_stats_with_deltas( $start, $end );

		// `payment_daily` stores no refund row count, so the count is omitted rather than
		// reported as zero — the tile then shows the amount alone instead of contradicting
		// the same range served live. Absent keys are skipped by `Payments::get_tiles()`.
		$tiles = [
			'total_payments'       => $stats['total_payments'],
			'total_payments_delta' => $stats['total_payments_delta'] ?? 0,
			'total_sales'          => (string) ( $stats['total_sales'] ?? '0' ),
			'total_sales_delta'    => $stats['total_sales_delta'] ?? 0,
			'total_refunded'       => [
				'amount' => (string) ( $stats['total_refunded'] ?? '0' ),
			],
			'total_refunded_delta' => $stats['total_refunded_delta'] ?? 0,
			'coupons'              => $stats['coupons_redeemed'],
			'coupons_delta'        => $stats['coupons_redeemed_delta'] ?? 0,
		];

		// Subscriptions and renewals are not tracked in the rollup. Where the site has them,
		// read the real figures from the live aggregator rather than showing zeros; where it
		// has none, `fill_missing_tiles()` supplies the zero cards below so the widget keeps
		// the same stat cards on either side of rollup coverage.
		$subscription_tiles = [];

		if ( wpforms()->obj( 'payment_queries' )->has_subscription() ) {
			$subscription_tiles = array_intersect_key(
				( new PaymentStats() )->get_tiles( $start, $end ),
				array_flip( [ 'new_subscriptions', 'new_subscriptions_delta', 'renewals', 'renewals_delta' ] )
			);
		}

		return [
			'tiles'        => PaymentStats::fill_missing_tiles( $tiles + $subscription_tiles ),
			'graph'        => ( new PaymentRollup() )->payment_sales_graph( $start, $end ),
			'graph_report' => 'total_sales',
		];
	}

	/**
	 * Get the total entries count for a range: from the rollup when covered, otherwise live.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return int
	 */
	protected function get_entries_total( DateTimeImmutable $start, DateTimeImmutable $end ): int {

		if ( $this->is_rollup_covered( $start, $end ) ) {
			return $this->get_rollup_repository()->total( $start, $end );
		}

		return $this->get_entries_total_live( $start, $end );
	}

	/**
	 * Get the total entries count across all forms for a range (live SQL sum).
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return int
	 */
	private function get_entries_total_live( DateTimeImmutable $start, DateTimeImmutable $end ): int {

		return $this->get_entries_count()->get_total( 0, $this->to_mutable_date( $start ), $this->to_mutable_date( $end ) );
	}

	/**
	 * Get the entries graph and per-form breakdown for a range: from the rollup when covered, otherwise live.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	private function get_entries_data( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		if ( $this->is_rollup_covered( $start, $end ) ) {
			return [
				'graph' => $this->get_rollup_repository()->graph( $start, $end ),
				'forms' => $this->enrich_forms_with_analytics( $this->get_rollup_repository()->top_forms( $start, $end, $this->get_top_forms_limit() ), $start, $end ),
			];
		}

		return $this->get_entries_data_live( $start, $end );
	}

	/**
	 * Get the entries graph and per-form breakdown for a range (live queries).
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	private function get_entries_data_live( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$entries_count = $this->get_entries_count();
		$date_start    = $this->to_mutable_date( $start );
		$date_end      = $this->to_mutable_date( $end );

		return [
			'graph' => $this->format_entries_graph( $entries_count->get_by_date_sql( 0, $date_start, $date_end ) ),
			'forms' => $this->enrich_forms_with_analytics(
				$entries_count->get_by_form_sql( 0, $date_start, $date_end, [ 'limit' => $this->get_top_forms_limit() ] ),
				$start,
				$end
			),
		];
	}

	/**
	 * Merge per-form analytics counters into the entries.forms rows, adding Pro interactions.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $forms Entries.forms rows keyed by form_id.
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	public function enrich_forms_with_analytics( array $forms, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$forms        = parent::enrich_forms_with_analytics( $forms, $start, $end );
		$interactions = $this->get_interactions_by_form( array_keys( $forms ), $start, $end );

		foreach ( array_keys( $forms ) as $form_id ) {
			$forms[ $form_id ]['interactions'] = $interactions[ $form_id ] ?? 0;
		}

		return $forms;
	}

	/**
	 * Get per-form field interactions for a range from the Pro field rollup table.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Form IDs to fetch.
	 * @param DateTimeImmutable $start    Range start date.
	 * @param DateTimeImmutable $end      Range end date.
	 *
	 * @return array Map of form_id => interactions int.
	 */
	private function get_interactions_by_form( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$rows = $this->query_form_analytics(
			AnalyticsFieldsDB::fields_table(),
			'SUM(focus_count + click_count + input_count) AS interactions',
			$form_ids,
			$start,
			$end
		);

		$map = [];

		foreach ( $rows as $row ) {
			$map[ (int) $row['form_id'] ] = (int) $row['interactions'];
		}

		// The nightly aggregation never processes the current day, so a range that
		// includes today counts its interactions live from the unprocessed snapshots —
		// mirroring the views/submissions layer in the base get_views_by_form().
		foreach ( $this->get_today_interactions_rows( $form_ids, $start, $end ) as $row ) {
			$form_id = (int) $row['form_id'];

			$map[ $form_id ] = ( $map[ $form_id ] ?? 0 ) + (int) $row['interactions'];
		}

		return $map;
	}

	/**
	 * Get today's live per-form interactions when the range covers today.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Form IDs to count.
	 * @param DateTimeImmutable $start    Range start date.
	 * @param DateTimeImmutable $end      Range end date.
	 *
	 * @return array Rows carrying form_id, interactions. Empty when the range excludes today.
	 */
	private function get_today_interactions_rows( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		if ( ! $this->range_includes_today( $start, $end ) || ! AnalyticsFieldsDB::tables_exist() ) {
			return [];
		}

		$db = wpforms()->obj( 'analytics_db' );

		return $db instanceof AnalyticsFieldsDB ? $db->get_today_interactions( $form_ids ) : [];
	}

	/**
	 * Reshape `EntriesCount::get_by_date_sql()`'s rows into the cached `entries.graph` shape.
	 *
	 * @since 2.0.2
	 *
	 * @param array $rows Rows keyed by day, each with `day` and `count` properties.
	 *
	 * @return array
	 */
	private function format_entries_graph( array $rows ): array {

		$graph = [];

		foreach ( $rows as $row ) {
			$graph[] = [
				'date'  => (string) $row->day,
				'count' => (int) $row->count,
			];
		}

		return $graph;
	}

	/**
	 * Convert a WP-timezone `DateTimeImmutable` into a mutable `DateTime` with the same
	 * wall-clock digits, without converting the underlying instant.
	 *
	 * `EntriesCount` shifts the date by the site's GMT offset, expecting WP-local digits.
	 * Converting to UTC first would apply that offset twice.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $date Date in WP timezone.
	 *
	 * @return DateTime
	 */
	private function to_mutable_date( DateTimeImmutable $date ): DateTime {

		return new DateTime( $date->format( Datepicker::DATETIME_FORMAT ) );
	}

	/**
	 * Convert a WP-timezone `DateTimeImmutable` into a mutable UTC `DateTime`.
	 *
	 * Used by `get_locations()` only, whose query runs against `entry_meta.date` (stored in UTC).
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $date Date in WP timezone.
	 *
	 * @return DateTime
	 */
	private function to_mutable_utc_date( DateTimeImmutable $date ): DateTime {

		$utc_date = new DateTime( $date->format( Datepicker::DATETIME_FORMAT ), $date->getTimezone() );

		$utc_date->setTimezone( new DateTimeZone( 'UTC' ) );

		return $utc_date;
	}

	/**
	 * Get the cross-form spam entries count for a range.
	 *
	 * Uses a targeted `status = 'spam'` count against the `(status, date)` index.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return int
	 */
	private function get_spam_count( DateTimeImmutable $start, DateTimeImmutable $end ): int {

		$entry = wpforms()->obj( 'entry' );

		if ( ! $entry ) {
			return 0;
		}

		global $wpdb;

		$table = $entry->table_name;

		// Per-day DST-aware boundaries, matching the rollup system. Deriving them from the
		// current `gmt_offset` instead would shift the first and last day of any range that
		// falls on the other side of a DST switch from today.
		[ $date_start, $date_end ] = Helpers::utc_range_bounds( $start, $end );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM $table WHERE status = %s AND date BETWEEN %s AND %s",
				SpamEntry::ENTRY_STATUS,
				$date_start,
				$date_end
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Count the entries of the last N days ending today by the visitor language stored
	 * with them, whatever range the user is looking at. Serves the behavior triggers,
	 * which evaluate a fixed window so a promo does not blink as the datepicker moves.
	 *
	 * Cached on its own key rather than rolled up per day: the language is a scalar, so
	 * one `GROUP BY` is already the precomputation a rollup table would store, and the
	 * window never follows the datepicker. The TTL is the invalidation here — a promo
	 * that reads an hour behind is the point, not a defect.
	 *
	 * @since 2.0.2
	 *
	 * @param int $days Window length in days.
	 *
	 * @return array Entry counts keyed by the stored language tag.
	 */
	public function get_languages_window( int $days ): array {

		$key    = self::KEY_PREFIX . 'languages_' . $days;
		$cached = Transient::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$counts = $this->get_languages_live( $days );

		Transient::set( $key, $counts, self::TTL );

		return $counts;
	}

	/**
	 * Tally the window's stored visitor languages (see get_languages_window()).
	 *
	 * @since 2.0.2
	 *
	 * @param int $days Window length in days.
	 *
	 * @return array Entry counts keyed by the stored language tag.
	 */
	private function get_languages_live( int $days ): array {

		$form_ids = $this->get_published_form_ids();

		if ( ! $form_ids ) {
			return [];
		}

		[ $start, $end ] = Datepicker::get_timespan_dates( (string) $days );

		global $wpdb;

		$meta     = wpforms()->obj( 'entry_meta' )->table_name;
		$entries  = wpforms()->obj( 'entry' )->table_name;
		$forms    = wpforms_wpdb_prepare_in( $form_ids, '%d' );
		$statuses = wpforms_wpdb_prepare_in( EntriesCount::EXCLUDED_STATUSES );

		// The meta row outlives its entry's status: the language is stored for a submission
		// later marked as spam, and trashing an entry leaves its meta in place. So the
		// window counts the statuses the rest of the dashboard counts, or a promo could be
		// triggered by 25 spam entries.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.data AS language, COUNT(*) AS entries
				FROM {$meta} AS m
				INNER JOIN {$entries} AS e ON e.entry_id = m.entry_id
				WHERE m.type = %s
					AND m.form_id IN ( {$forms} )
					AND m.date BETWEEN %s AND %s
					AND e.status NOT IN ( {$statuses} )
				GROUP BY m.data
				ORDER BY entries DESC
				LIMIT %d",
				'language',
				$this->to_mutable_utc_date( $start )->format( Datepicker::DATETIME_FORMAT ),
				$this->to_mutable_utc_date( $end )->format( Datepicker::DATETIME_FORMAT ),
				self::LANGUAGE_GROUPS_LIMIT
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return array_map( 'intval', wp_list_pluck( (array) $rows, 'entries', 'language' ) );
	}

	/**
	 * Get the country breakdown for a range: from the rollup when covered, otherwise live.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date, in WP timezone.
	 * @param DateTimeImmutable $end   Range end date, in WP timezone.
	 *
	 * @return array
	 */
	private function get_locations( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		// The geolocation addon is the only writer of location data.
		if ( ! $this->is_geolocation_initialized() ) {
			return LocationRollup::build_locations_payload( [] );
		}

		if ( $this->is_rollup_covered( $start, $end ) ) {
			return $this->get_rollup_repository()->locations( $start, $end );
		}

		return $this->get_locations_live( $start, $end );
	}

	/**
	 * Get the country breakdown for a range from the geolocation entry meta (live).
	 *
	 * `entry_meta.date` is stored in UTC, so range bounds are converted from WP timezone.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date, in WP timezone.
	 * @param DateTimeImmutable $end   Range end date, in WP timezone.
	 *
	 * @return array
	 */
	private function get_locations_live( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$form_ids = $this->get_published_form_ids();

		if ( ! $form_ids ) {
			return LocationRollup::build_locations_payload( [] );
		}

		global $wpdb;

		$table        = $wpdb->prefix . 'wpforms_entry_meta';
		$entries      = wpforms()->obj( 'entry' )->table_name;
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$statuses     = wpforms_wpdb_prepare_in( EntriesCount::EXCLUDED_STATUSES );
		$values       = array_merge(
			[ 'location' ],
			$form_ids,
			[
				$this->to_mutable_utc_date( $start )->format( Datepicker::DATETIME_FORMAT ),
				$this->to_mutable_utc_date( $end )->format( Datepicker::DATETIME_FORMAT ),
			]
		);

		// Bounded to cache-miss frequency: `KEY type_date` narrows the scan to this type and
		// window, but the per-row JSON decode below still runs in PHP. The status join keeps
		// this path on the same entries as the rollup it replaces (see
		// `LocationDaily::count_countries()`).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_col(
			$wpdb->prepare(
				"SELECT m.data
				FROM {$table} AS m
				INNER JOIN {$entries} AS e ON e.entry_id = m.entry_id
				WHERE m.type = %s
					AND m.form_id IN ( $placeholders )
					AND m.date BETWEEN %s AND %s
					AND e.status NOT IN ( {$statuses} )",
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = [];

		foreach ( $rows as $row ) {
			$location = json_decode( (string) $row, true );
			$country  = is_array( $location ) && ! empty( $location['country'] ) ? (string) $location['country'] : '';

			if ( $country === '' ) {
				continue;
			}

			$counts[ $country ] = ( $counts[ $country ] ?? 0 ) + 1;
		}

		return LocationRollup::build_locations_payload( $counts );
	}

	/**
	 * Determine whether the geolocation addon is initialized.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	protected function is_geolocation_initialized(): bool {

		return wpforms_is_addon_initialized( 'geolocation' );
	}
}
