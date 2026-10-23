<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Time-budgeted backward day-recompute loop for the Dashboard rollup.
 *
 * @since 2.0.2
 */
class RollupRecomputer {

	/**
	 * Hard PHP execution ceiling (seconds) for a recompute loop.
	 *
	 * @since 2.0.2
	 */
	private const HARD_TIME_LIMIT = 60;

	/**
	 * Recompute whole days backward from `$first_day`, bounded by day count, wall-clock
	 * budget, and a floor day.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $first_day           Newest day to recompute (inclusive).
	 * @param int               $max_days            Maximum days to recompute; 0 means unbounded.
	 * @param float             $soft_budget_seconds Soft wall-clock budget; checked before each day.
	 * @param string            $floor_day           Oldest day allowed (`Y-m-d`, inclusive).
	 *
	 * @return string|null Oldest day recomputed (`Y-m-d`), or null when no day was recomputed.
	 */
	public function recompute_back( DateTimeImmutable $first_day, int $max_days, float $soft_budget_seconds, string $floor_day ): ?string {

		wpforms_set_time_limit( self::HARD_TIME_LIMIT );

		$form_daily     = new FormDaily();
		$location_daily = new LocationDaily();
		$payment_daily  = new PaymentDaily();
		$loop_start     = microtime( true );
		$deadline       = $loop_start + $soft_budget_seconds;
		$recomputed_to  = null;
		$day            = $first_day;
		$count          = 0;

		while ( $max_days === 0 || $count < $max_days ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$day_str = $day->format( 'Y-m-d' );

			if ( $day_str < $floor_day ) {
				break;
			}

			$form_ok     = $form_daily->recompute_day( $day );
			$location_ok = $location_daily->recompute_day( $day );
			$payment_ok  = $payment_daily->recompute_day( $day );

			if ( ! $form_ok || ! $location_ok || ! $payment_ok ) {
				break;
			}

			$recomputed_to = $day_str;
			$day           = $day->modify( '-1 day' );

			++$count;
		}

		return $recomputed_to;
	}

	/**
	 * Get the oldest entry's WP-local day (the UTC MIN(date) shifted by the site offset).
	 *
	 * @since 2.0.2
	 *
	 * @return string|null Oldest day (`Y-m-d`), or null when there are no entries.
	 */
	public static function oldest_entry_day(): ?string {

		global $wpdb;

		$entries = wpforms()->obj( 'entry' )->table_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$min_date = $wpdb->get_var( "SELECT MIN(date) FROM {$entries}" );

		if ( ! $min_date ) {
			return null;
		}

		// entries.date is UTC; the rollup buckets by WP-local day. Shift the MIN value
		// in PHP instead of DATE_ADD in SQL, which would defeat the index.
		$day_obj = new DateTimeImmutable( (string) $min_date, new DateTimeZone( 'UTC' ) );

		return $day_obj->modify( Helpers::gmt_offset_minutes_for_day( $day_obj ) . ' minutes' )->format( 'Y-m-d' );
	}

	/**
	 * Get the oldest payment's WP-local day.
	 *
	 * @since 2.0.2
	 *
	 * @return string|null Oldest day (`Y-m-d`), or null when there are no payments.
	 */
	public static function oldest_payment_day(): ?string {

		global $wpdb;

		$payment_obj = wpforms()->obj( 'payment' );

		if ( ! $payment_obj ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$min_date = $wpdb->get_var( "SELECT MIN(date_created_gmt) FROM {$payment_obj->table_name}" );

		if ( ! $min_date ) {
			return null;
		}

		$day_obj = new DateTimeImmutable( (string) $min_date, new DateTimeZone( 'UTC' ) );

		return $day_obj->modify( Helpers::gmt_offset_minutes_for_day( $day_obj ) . ' minutes' )->format( 'Y-m-d' );
	}

	/**
	 * Get the oldest WP-local day holding any Dashboard-relevant data.
	 *
	 * Checks both tables so a payments-only site, and a site whose entry retention is
	 * shorter than its payment history, both report the day their data actually starts.
	 *
	 * @since 2.0.2
	 *
	 * @return string|null Oldest day (`Y-m-d`), or null when there are no entries or payments.
	 */
	public static function oldest_data_day(): ?string {

		$days = array_filter( [ self::oldest_entry_day(), self::oldest_payment_day() ] );

		// Both are `Y-m-d`, so the lexicographic minimum is the chronological one.
		return $days ? min( $days ) : null;
	}
}
