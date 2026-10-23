<?php

namespace WPForms\Pro\Db\Dashboard;

use DateInterval;
use DatePeriod;
use DateTimeImmutable;

/**
 * Payment rollup reader for the Pro Dashboard cache.
 *
 * @since 2.0.2
 */
class PaymentRollup {

	/**
	 * Get payment stats for a range: `total_payments`, `total_sales`,
	 * `total_refunded`, and `coupons_redeemed`.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array
	 */
	public function get_stats( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$currency     = wpforms_get_currency();
		$rollup_start = Helpers::clamp_rollup_start( $start );
		$sums         = $this->query_rollup_sums( $rollup_start, $end, $currency );

		if ( $start < $rollup_start ) {
			$tail = $this->query_live_tail_sums( $start, $rollup_start->modify( '-1 day' ), $currency );
			$sums = $this->merge_sums( $sums, $tail );
		}

		return $this->format_stats( $sums );
	}

	/**
	 * Get the payment graph (per-day payment counts) for a range, with live fallback.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array List of `[ 'day' => string, 'count' => float ]` rows, one per day in
	 *               the range (days without payments are zero), ordered by day.
	 */
	public function payment_graph( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		return $this->build_daily_graph( $start, $end, 'payments_count' );
	}

	/**
	 * Get the sales-amount graph (per-day sales sums) for a range, with live fallback.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Range start date.
	 * @param DateTimeImmutable $end   Range end date.
	 *
	 * @return array List of `[ 'day' => string, 'count' => float ]` rows, one per day in
	 *               the range (days without sales are zero), ordered by day. `count` holds
	 *               the summed sales amount for the day.
	 */
	public function payment_sales_graph( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		return $this->build_daily_graph( $start, $end, 'sales_amount' );
	}

	/**
	 * Build a per-day graph for a rollup metric, with live fallback for the uncovered
	 * pre-watermark span.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start  Range start date.
	 * @param DateTimeImmutable $end    Range end date.
	 * @param string            $metric Rollup column to sum: `payments_count` or `sales_amount`.
	 *
	 * @return array List of `[ 'day' => string, 'count' => float ]` rows, one per calendar
	 *               day in the range (days without data are zero), ordered by day.
	 */
	private function build_daily_graph( DateTimeImmutable $start, DateTimeImmutable $end, string $metric ): array {

		$currency     = wpforms_get_currency();
		$rollup_start = Helpers::clamp_rollup_start( $start );
		$by_day       = [];

		if ( $start < $rollup_start ) {
			foreach ( $this->query_live_tail_graph( $start, $rollup_start->modify( '-1 day' ), $currency, $metric ) as $day => $count ) {
				$by_day[ $day ] = (float) $count;
			}
		}

		foreach ( $this->query_rollup_graph( $rollup_start, $end, $currency, $metric ) as $row ) {
			$by_day[ (string) $row->day ] = (float) $row->count;
		}

		return $this->fill_daily_range( $by_day, $start, $end );
	}

	/**
	 * Expand a day => sum map into one row per calendar day in the range, so days
	 * without payments render as a zero point (a continuous line rather than gaps).
	 * Mirrors the live chart path's `Admin\Helpers\Chart::process_chart_dataset_data()`.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $by_day Metric sums keyed by day (`Y-m-d`).
	 * @param DateTimeImmutable $start  Range start date.
	 * @param DateTimeImmutable $end    Range end date.
	 *
	 * @return array List of `[ 'day' => string, 'count' => float ]` rows, ordered by day.
	 */
	private function fill_daily_range( array $by_day, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$graph  = [];
		$period = new DatePeriod( $start, new DateInterval( 'P1D' ), $end );

		foreach ( $period as $date ) {
			$day = $date->format( 'Y-m-d' );

			$graph[] = [
				'day'   => $day,
				'count' => $by_day[ $day ] ?? 0.0,
			];
		}

		return $graph;
	}

	/**
	 * Query `payment_daily` for per-day rollup metric sums over a range for one currency.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start    Range start date.
	 * @param DateTimeImmutable $end      Range end date.
	 * @param string            $currency Site payments currency.
	 * @param string            $metric   Rollup column to sum: `payments_count` or `sales_amount`.
	 *
	 * @return array Rows with `day` and `count` properties, ordered by day.
	 */
	private function query_rollup_graph( DateTimeImmutable $start, DateTimeImmutable $end, string $currency, string $metric ): array {

		global $wpdb;

		$table  = PaymentDaily::get_table_name();
		$column = $metric === 'sales_amount' ? 'sales_amount' : 'payments_count';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT day, SUM($column) AS count FROM {$table} WHERE day BETWEEN %s AND %s AND currency = %s GROUP BY day ORDER BY day",
				$start->format( 'Y-m-d' ),
				$end->format( 'Y-m-d' ),
				$currency
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Tally per-day rollup metric sums for the uncovered pre-watermark span.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start    Uncovered span start date.
	 * @param DateTimeImmutable $end      Uncovered span end date.
	 * @param string            $currency Site payments currency.
	 * @param string            $metric   Rollup column to sum: `payments_count` or `sales_amount`.
	 *
	 * @return array Metric sums keyed by day (`Y-m-d`).
	 */
	private function query_live_tail_graph( DateTimeImmutable $start, DateTimeImmutable $end, string $currency, string $metric ): array {

		global $wpdb;

		$payments = wpforms()->obj( 'payment' )->table_name;
		$offset   = Helpers::gmt_offset_minutes_for_day( $start );
		$column   = $metric === 'sales_amount'
			? "IFNULL( SUM( CASE WHEN status <> 'failed' AND subscription_status <> 'failed' THEN total_amount ELSE 0 END ), 0 )"
			: 'COUNT(*)';

		[ $lo, $hi ] = Helpers::utc_range_bounds( $start, $end );

		// The `date_created_gmt` column is compared bare against UTC-shifted bounds (not
		// wrapped in DATE_ADD) so the index range scan stays usable, mirroring `PaymentDaily::insert_day()`.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT CAST(DATE_ADD(date_created_gmt, INTERVAL %d MINUTE) AS DATE) AS day, {$column} AS count
				 FROM {$payments}
				 WHERE mode = 'live' AND is_published = 1 AND currency = %s
				   AND date_created_gmt BETWEEN %s AND %s
				 GROUP BY day ORDER BY day",
				$offset,
				$currency,
				$lo,
				$hi
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ (string) $row->day ] = $metric === 'sales_amount' ? (string) $row->count : (int) $row->count;
		}

		return $counts;
	}

	/**
	 * Query `payment_daily` for summed payment measures over a range for one currency.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start    Range start date.
	 * @param DateTimeImmutable $end      Range end date.
	 * @param string            $currency Site payments currency.
	 *
	 * @return array Raw sums keyed `payments_count`, `sales_amount`, `refunded_amount`, `coupons_count`.
	 */
	private function query_rollup_sums( DateTimeImmutable $start, DateTimeImmutable $end, string $currency ): array {

		global $wpdb;

		$table = PaymentDaily::get_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT IFNULL(SUM(payments_count),0) AS payments_count,
				        IFNULL(SUM(sales_amount),0) AS sales_amount,
				        IFNULL(SUM(refunded_amount),0) AS refunded_amount,
				        IFNULL(SUM(coupons_count),0) AS coupons_count
				 FROM {$table} WHERE day BETWEEN %s AND %s AND currency = %s",
				$start->format( 'Y-m-d' ),
				$end->format( 'Y-m-d' ),
				$currency
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $row;
	}

	/**
	 * Tally payment measures for the uncovered pre-watermark span from raw tables.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start    Uncovered span start date.
	 * @param DateTimeImmutable $end      Uncovered span end date.
	 * @param string            $currency Site payments currency.
	 *
	 * @return array Raw sums keyed `payments_count`, `sales_amount`, `refunded_amount`, `coupons_count`.
	 */
	private function query_live_tail_sums( DateTimeImmutable $start, DateTimeImmutable $end, string $currency ): array {

		global $wpdb;

		$payments = wpforms()->obj( 'payment' )->table_name;
		$meta     = wpforms()->obj( 'payment_meta' )->table_name;

		[ $lo, ]  = Helpers::utc_day_bounds( $start );
		[ , $hi ] = Helpers::utc_day_bounds( $end );

		// Scalar subqueries per payment avoid cross-product inflation on duplicate meta rows.
		// The coupon count applies the same status funnel as the daily rows and the Payments
		// Overview coupons card: neither failed nor still pending.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT COUNT(*) AS payments_count,
				        IFNULL( SUM( CASE WHEN p.status <> 'failed' AND p.subscription_status <> 'failed' THEN p.total_amount ELSE 0 END ), 0 ) AS sales_amount,
				        IFNULL( SUM(
				            IFNULL( ( SELECT rm.meta_value FROM {$meta} rm WHERE rm.payment_id = p.id AND rm.meta_key = 'refunded_amount' ORDER BY rm.id DESC LIMIT 1 ), 0 )
				        ), 0 ) AS refunded_amount,
				        SUM( CASE WHEN p.status NOT IN ( 'failed', 'pending' ) AND p.subscription_status <> 'failed' AND EXISTS ( SELECT 1 FROM {$meta} cm WHERE cm.payment_id = p.id AND cm.meta_key = 'coupon_id' ) THEN 1 ELSE 0 END ) AS coupons_count
				 FROM {$payments} p
				 WHERE p.mode = 'live' AND p.is_published = 1 AND p.currency = %s
				   AND p.date_created_gmt BETWEEN %s AND %s",
				$currency,
				$lo,
				$hi
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return (array) $row;
	}

	/**
	 * Add two raw sum sets together: the rollup-covered tail plus the uncovered live span.
	 *
	 * @since 2.0.2
	 *
	 * @param array $rollup Raw sums from `query_rollup_sums()`.
	 * @param array $tail   Raw sums from `query_live_tail_sums()`.
	 *
	 * @return array Raw sums, same shape as either input.
	 */
	private function merge_sums( array $rollup, array $tail ): array {

		return [
			'payments_count'  => (int) $rollup['payments_count'] + (int) $tail['payments_count'],
			'sales_amount'    => sprintf( '%.8f', (float) $rollup['sales_amount'] + (float) $tail['sales_amount'] ),
			'refunded_amount' => sprintf( '%.8f', (float) $rollup['refunded_amount'] + (float) $tail['refunded_amount'] ),
			'coupons_count'   => (int) $rollup['coupons_count'] + (int) $tail['coupons_count'],
		];
	}

	/**
	 * Shape raw sums into the cached `stats` payment keys.
	 *
	 * @since 2.0.2
	 *
	 * @param array $sums Raw sums keyed `payments_count`, `sales_amount`, `refunded_amount`, `coupons_count`.
	 *
	 * @return array
	 */
	private function format_stats( array $sums ): array {

		return [
			'total_payments'   => (int) $sums['payments_count'],
			'total_sales'      => (string) $sums['sales_amount'],
			'total_refunded'   => (string) $sums['refunded_amount'],
			'coupons_redeemed' => (int) $sums['coupons_count'],
		];
	}
}
