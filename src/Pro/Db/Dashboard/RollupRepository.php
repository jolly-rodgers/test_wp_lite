<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use WPForms\Admin\Helpers\Datepicker;
use WPForms\Helpers\DB as HelpersDB;
use WPForms\Pro\Reports\EntriesCount;
use WPForms\Pro\Tasks\Actions\DashboardRollupTask;

/**
 * Rollup reader façade for the Pro Dashboard cache.
 *
 * @since 2.0.2
 */
class RollupRepository {

	/**
	 * Entries count reports instance, reused to build the `top_forms()` shape.
	 *
	 * @since 2.0.2
	 *
	 * @var EntriesCount|null
	 */
	private $entries_count;

	/**
	 * Published form IDs, memoized per-request.
	 *
	 * @since 2.0.2
	 *
	 * @var array|null
	 */
	private $published_form_ids;

	/**
	 * Whether all three dashboard rollup tables exist.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	public static function tables_exist(): bool {

		return HelpersDB::table_exists( FormDaily::get_table_name() )
			&& HelpersDB::table_exists( LocationDaily::get_table_name() )
			&& HelpersDB::table_exists( PaymentDaily::get_table_name() );
	}

	/**
	 * Whether a range can be served from the rollup.
	 *
	 * A missing `payment_daily` / `form_daily` row is indistinguishable from a day that
	 * genuinely had no activity, so an absent row cannot be detected on read and silently sums
	 * as zero. The watermark alone does not rule that out: it only marks how far back the
	 * backfill has reached, not whether the days above it are current. So coverage is decided
	 * from the maintenance task's own bookkeeping instead — the dirty-day set for days known to
	 * need a recompute, and the last-maintained day for how far the task has actually got.
	 * Whenever either says the range is not current, the caller serves the whole range live,
	 * which is slower but never undercounts.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return bool
	 */
	public function has_coverage( DateTimeImmutable $start, DateTimeImmutable $end ): bool {

		$watermark = Helpers::get_watermark();

		if ( $watermark === '' ) {
			return false;
		}

		$end_day = $end->format( 'Y-m-d' );

		// The whole range predates the backfill, so no rollup row exists for any of it.
		if ( $end_day < $watermark ) {
			return false;
		}

		// `recompute_recent_days()` moves this to today on every successful run, so it lags by
		// exactly as long as the task has been failing or unscheduled. A range reaching past it
		// ends in days nothing has computed yet.
		$last_maintained = (string) get_option( DashboardRollupTask::LAST_MAINTAINED_OPTION, '' );

		if ( $last_maintained === '' || $end_day > $last_maintained ) {
			return false;
		}

		// Days below the watermark are served by the callers' live tail, so only the span
		// actually read from the rollup has to be free of pending recomputes.
		return ! $this->has_dirty_day_in_range( Helpers::clamp_rollup_start( $start ), $end );
	}

	/**
	 * Whether any day awaiting a recompute falls inside a range.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return bool
	 */
	private function has_dirty_day_in_range( DateTimeImmutable $start, DateTimeImmutable $end ): bool {

		$dirty = (array) get_option( DashboardRollupTask::DIRTY_DAYS_OPTION, [] );

		if ( ! $dirty ) {
			return false;
		}

		$from = $start->format( 'Y-m-d' );
		$to   = $end->format( 'Y-m-d' );

		foreach ( $dirty as $day ) {
			$day = (string) $day;

			// Zero-padded `Y-m-d` strings order the same lexicographically as by date.
			if ( $day >= $from && $day <= $to ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get total entries count for a range, with live fallback for uncovered spans.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return int
	 */
	public function total( DateTimeImmutable $start, DateTimeImmutable $end ): int {

		global $wpdb;

		$form_ids = $this->get_published_form_ids();

		if ( ! $form_ids ) {
			return 0;
		}

		$rollup_start = Helpers::clamp_rollup_start( $start );
		$table        = FormDaily::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$values       = array_merge( $form_ids, [ $rollup_start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(count) FROM {$table} WHERE form_id IN ( $placeholders ) AND day BETWEEN %s AND %s",
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $start < $rollup_start ) {
			$total += array_sum( $this->live_daily_counts( $form_ids, $start, $rollup_start->modify( '-1 day' ) ) );
		}

		return $total;
	}

	/**
	 * Get the entries graph (per-day totals) for a range, with live fallback for uncovered spans.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	public function graph( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		global $wpdb;

		$form_ids = $this->get_published_form_ids();

		if ( ! $form_ids ) {
			return [];
		}

		$rollup_start = Helpers::clamp_rollup_start( $start );
		$table        = FormDaily::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$values       = array_merge( $form_ids, [ $rollup_start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, SUM(count) AS count FROM {$table} WHERE form_id IN ( $placeholders ) AND day BETWEEN %s AND %s GROUP BY day ORDER BY day",
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$graph = [];

		if ( $start < $rollup_start ) {
			foreach ( $this->live_daily_counts( $form_ids, $start, $rollup_start->modify( '-1 day' ) ) as $day => $count ) {
				$graph[] = [
					'date'  => $day,
					'count' => $count,
				];
			}
		}

		foreach ( $rows as $row ) {
			$graph[] = [
				'date'  => (string) $row->day,
				'count' => (int) $row->count,
			];
		}

		return $graph;
	}

	/**
	 * Get top N forms by entry count for a range, with live fallback for uncovered spans.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 * @param int               $limit Maximum number of forms to return; `0` returns all.
	 *
	 * @return array
	 */
	public function top_forms( DateTimeImmutable $start, DateTimeImmutable $end, int $limit ): array {

		$form_ids = $this->get_published_form_ids();

		if ( ! $form_ids ) {
			return [];
		}

		$rollup_start = Helpers::clamp_rollup_start( $start );
		$is_partial   = $start < $rollup_start;

		// Partial range: fetch all rollup counts, then apply the limit after merging with live counts.
		$counts = $this->query_form_counts( $form_ids, $rollup_start, $end, $is_partial ? 0 : $limit );

		if ( $is_partial ) {
			foreach ( $this->live_form_counts( $form_ids, $start, $rollup_start->modify( '-1 day' ) ) as $form_id => $count ) {
				$counts[ $form_id ] = ( $counts[ $form_id ] ?? 0 ) + $count;
			}
		}

		return $this->hydrate_top_forms( $counts, $limit );
	}

	/**
	 * Query the rollup for per-form entry counts over a range.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Published form IDs.
	 * @param DateTimeImmutable $start    Range start date.
	 * @param DateTimeImmutable $end      Range end date.
	 * @param int               $limit    Maximum rows to return, or `0` for no SQL limit.
	 *
	 * @return array Entry counts keyed by form ID.
	 */
	private function query_form_counts( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end, int $limit ): array {

		global $wpdb;

		$table        = FormDaily::get_table_name();
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$values       = array_merge( $form_ids, [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ] );
		$sql          = "SELECT form_id, SUM(count) AS count FROM {$table} WHERE form_id IN ( $placeholders ) AND day BETWEEN %s AND %s GROUP BY form_id ORDER BY count DESC";

		if ( $limit > 0 ) {
			$sql     .= ' LIMIT %d';
			$values[] = $limit;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $values ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ absint( $row->form_id ) ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Rank, limit, and hydrate a form-count map into the cached `entries.forms` shape.
	 *
	 * @since 2.0.2
	 *
	 * @param array $counts Entry counts keyed by form ID.
	 * @param int   $limit  Maximum number of forms to return; `0` returns all.
	 *
	 * @return array
	 */
	private function hydrate_top_forms( array $counts, int $limit ): array {

		if ( ! $counts ) {
			return [];
		}

		arsort( $counts );

		if ( $limit > 0 ) {
			$counts = array_slice( $counts, 0, $limit, true );
		}

		// Prime the post cache for every candidate in a single query.
		if ( function_exists( '_prime_post_caches' ) ) {
			_prime_post_caches( array_map( 'absint', array_keys( $counts ) ), false, false );
		}

		$forms = [];

		foreach ( $counts as $form_id => $count ) {
			$forms[ $form_id ] = (object) [ 'count' => $count ];
		}

		return $this->get_entries_count()->fill_forms_list_form_data( $forms );
	}

	/**
	 * Tally entries by local calendar day for the uncovered pre-watermark span.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Published form IDs.
	 * @param DateTimeImmutable $start    Uncovered span start date.
	 * @param DateTimeImmutable $end      Uncovered span end date.
	 *
	 * @return array Entry counts keyed by day (`Y-m-d`).
	 */
	private function live_daily_counts( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		global $wpdb;

		$entries      = wpforms()->obj( 'entry' )->table_name;
		$offset       = Helpers::gmt_offset_minutes_for_day( $start );
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$status_list  = implode( ', ', array_fill( 0, count( EntriesCount::EXCLUDED_STATUSES ), '%s' ) );

		[ $lo, $hi ] = Helpers::utc_range_bounds( $start, $end );

		$values = array_merge(
			[ $offset ],
			$form_ids,
			[ $lo, $hi ],
			EntriesCount::EXCLUDED_STATUSES
		);

		// Bare `date` comparison keeps the `(date, form_id)` index range scan usable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST(DATE_ADD(date, INTERVAL %d MINUTE) AS DATE) AS day, COUNT(*) AS count FROM {$entries}
				 WHERE form_id IN ( $placeholders )
				   AND date BETWEEN %s AND %s
				   AND status NOT IN ( $status_list )
				 GROUP BY day ORDER BY day",
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ (string) $row->day ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Tally entries by form for the uncovered pre-watermark span.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Published form IDs.
	 * @param DateTimeImmutable $start    Uncovered span start date.
	 * @param DateTimeImmutable $end      Uncovered span end date.
	 *
	 * @return array Entry counts keyed by form ID.
	 */
	private function live_form_counts( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		global $wpdb;

		$entries      = wpforms()->obj( 'entry' )->table_name;
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$status_list  = implode( ', ', array_fill( 0, count( EntriesCount::EXCLUDED_STATUSES ), '%s' ) );

		[ $lo, $hi ] = Helpers::utc_range_bounds( $start, $end );

		$values = array_merge(
			$form_ids,
			[ $lo, $hi ],
			EntriesCount::EXCLUDED_STATUSES
		);

		// Bare `date` comparison (see `live_daily_counts()`).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT form_id, COUNT(*) AS count FROM {$entries}
				 WHERE form_id IN ( $placeholders )
				   AND date BETWEEN %s AND %s
				   AND status NOT IN ( $status_list )
				 GROUP BY form_id",
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ absint( $row->form_id ) ] = (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Get the IDs of every currently-published form, memoized on the instance.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	private function get_published_form_ids(): array {

		if ( $this->published_form_ids !== null ) {
			return $this->published_form_ids;
		}

		$forms = wpforms()->obj( 'form' )->get( '', [ 'fields' => 'ids' ] );

		$this->published_form_ids = is_array( $forms ) ? array_map( 'absint', $forms ) : [];

		return $this->published_form_ids;
	}

	/**
	 * Get the country breakdown for a range, delegating to `LocationRollup`.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	public function locations( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		return ( new LocationRollup() )->locations( $this->get_published_form_ids(), $start, $end );
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
	 * Get payment stats for a range, delegating to `PaymentRollup`.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	public function payment_stats( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		return ( new PaymentRollup() )->get_stats( $start, $end );
	}

	/**
	 * Get payment stats for a range plus each metric's trend delta, in a single combined
	 * call: the current-range sums, the prior equal-length range sums, and the percentage
	 * delta between them.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array Payment stats keyed as `PaymentRollup::get_stats()`, plus a `{key}_delta`
	 *               entry for each metric.
	 */
	public function payment_stats_with_deltas( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$rollup  = new PaymentRollup();
		$current = $rollup->get_stats( $start, $end );

		$prev_dates = Datepicker::get_prev_timespan_dates( $start, $end );

		if ( ! $prev_dates ) {
			return $current;
		}

		[ $prev_start, $prev_end ] = $prev_dates;
		$previous                  = $rollup->get_stats( $prev_start, $prev_end );

		foreach ( $current as $metric => $value ) {
			$current[ "{$metric}_delta" ] = $this->calculate_delta( (float) $value, (float) ( $previous[ $metric ] ?? 0 ) );
		}

		return $current;
	}

	/**
	 * Calculate a percentage delta between a current and prior value, matching the Overview
	 * endpoint's formula.
	 *
	 * @since 2.0.2
	 *
	 * @param float $current  Current-range value.
	 * @param float $previous Prior-range value.
	 *
	 * @return int
	 */
	private function calculate_delta( float $current, float $previous ): int {

		if ( $current === 0.0 ) {
			return 0;
		}

		return (int) round( ( $current - $previous ) / $current * 100 );
	}
}
