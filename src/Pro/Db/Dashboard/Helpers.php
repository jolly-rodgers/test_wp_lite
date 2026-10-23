<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use WPForms\Pro\Tasks\Actions\DashboardBackfillTask;
use WPForms\Pro\Tasks\Actions\DashboardRollupTask;

/**
 * Shared helpers for the Dashboard rollup subsystem.
 *
 * @since 2.0.2
 */
class Helpers {

	/**
	 * Get the rollup watermark option value.
	 *
	 * @since 2.0.2
	 *
	 * @return string Watermark day (`Y-m-d`), or an empty string when not yet set.
	 */
	public static function get_watermark(): string {

		return (string) get_option( DashboardRollupTask::WATERMARK_OPTION, '' );
	}

	/**
	 * Clamp a range's start date to the watermark.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Requested range start date.
	 *
	 * @return DateTimeImmutable
	 */
	public static function clamp_rollup_start( DateTimeImmutable $start ): DateTimeImmutable {

		$watermark = self::get_watermark();

		if ( $watermark === '' || $start->format( 'Y-m-d' ) >= $watermark ) {
			return $start;
		}

		return new DateTimeImmutable( $watermark, $start->getTimezone() );
	}

	/**
	 * Clamp a range's start date to the deepest day the rollup will ever reach.
	 *
	 * The live fallback that serves an uncovered span walks it a chunk at a time, one query
	 * per chunk, so its cost grows with how far back the range starts. Every caller that
	 * reaches it is expected to hand over a bounded range, but the read path is public and
	 * the range originates in a request, so the bound is applied here — at the only method
	 * whose cost scales with the span — rather than trusted from four layers up.
	 *
	 * The backfill never builds below `DAY_CAP`, and the maintenance hooks stop marking days
	 * dirty below it, so a start older than that asks the fallback to walk days no rollup
	 * will ever cover.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start Requested range start date.
	 *
	 * @return DateTimeImmutable
	 */
	public static function clamp_live_start( DateTimeImmutable $start ): DateTimeImmutable {

		// Kept in the start's own timezone, matching clamp_rollup_start().
		$cap = date_create_immutable( 'now', $start->getTimezone() )
			->setTime( 0, 0, 0 )
			->modify( '-' . DashboardBackfillTask::DAY_CAP . ' days' );

		return max( $start, $cap );
	}

	/**
	 * Get the GMT offset in minutes for a specific local day.
	 *
	 * Uses wp_timezone() to compute the historically correct offset for the
	 * given date, accounting for DST transitions.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day The local day.
	 *
	 * @return int Offset in minutes (positive = east of UTC).
	 */
	public static function gmt_offset_minutes_for_day( DateTimeImmutable $day ): int {

		return (int) ( wp_timezone()->getOffset( $day ) / 60 );
	}

	/**
	 * Compute the UTC range boundaries for a local day.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day The local day.
	 *
	 * @return array [ start_utc 'Y-m-d H:i:s', end_utc 'Y-m-d H:i:s' ].
	 */
	public static function utc_day_bounds( DateTimeImmutable $day ): array {

		$shift = sprintf( '%+d minutes', -self::gmt_offset_minutes_for_day( $day ) );

		$start = ( new DateTimeImmutable( $day->format( 'Y-m-d 00:00:00' ) ) )->modify( $shift )->format( 'Y-m-d H:i:s' );
		$end   = ( new DateTimeImmutable( $day->format( 'Y-m-d 23:59:59' ) ) )->modify( $shift )->format( 'Y-m-d H:i:s' );

		return [ $start, $end ];
	}

	/**
	 * Compute the UTC range boundaries spanning multiple local days.
	 *
	 * Uses per-day DST-aware offsets for the start and end boundaries.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $start First local day (inclusive).
	 * @param DateTimeImmutable $end   Last local day (inclusive).
	 *
	 * @return array [ start_utc 'Y-m-d H:i:s', end_utc 'Y-m-d H:i:s' ].
	 */
	public static function utc_range_bounds( DateTimeImmutable $start, DateTimeImmutable $end ): array {

		$lo_shift = sprintf( '%+d minutes', -self::gmt_offset_minutes_for_day( $start ) );
		$hi_shift = sprintf( '%+d minutes', -self::gmt_offset_minutes_for_day( $end ) );

		$lo = ( new DateTimeImmutable( $start->format( 'Y-m-d 00:00:00' ) ) )->modify( $lo_shift )->format( 'Y-m-d H:i:s' );
		$hi = ( new DateTimeImmutable( $end->format( 'Y-m-d 23:59:59' ) ) )->modify( $hi_shift )->format( 'Y-m-d H:i:s' );

		return [ $lo, $hi ];
	}

	/**
	 * Rebuild every rollup table for one local day.
	 *
	 * Each table is attempted even when a sibling fails, so one failing domain does not leave
	 * the others stale. The combined result tells callers whether the day may be treated as
	 * covered — advancing a watermark past a day that failed would drop it permanently.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Local day to rebuild.
	 *
	 * @return bool True only when all three tables were rebuilt and committed.
	 */
	public static function recompute_all_for_day( DateTimeImmutable $day ): bool {

		$succeeded = ( new FormDaily() )->recompute_day( $day );
		$succeeded = ( new LocationDaily() )->recompute_day( $day ) && $succeeded;

		return ( new PaymentDaily() )->recompute_day( $day ) && $succeeded;
	}
}
