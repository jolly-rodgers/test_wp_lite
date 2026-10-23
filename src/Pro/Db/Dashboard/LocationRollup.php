<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use WPForms\Pro\Reports\EntriesCount;

/**
 * Location rollup reader for the Pro Dashboard cache.
 *
 * @since 2.0.2
 */
class LocationRollup {

	/**
	 * Days per chunk for the live location-decode fallback.
	 *
	 * @since 2.0.2
	 */
	private const CHUNK_DAYS = 7;

	/**
	 * Get the country breakdown for a range, with chunked live fallback for uncovered spans.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Published form IDs.
	 * @param DateTimeImmutable $start    Range start date.
	 * @param DateTimeImmutable $end      Range end date.
	 *
	 * @return array
	 */
	public function locations( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		if ( ! $form_ids ) {
			return self::build_locations_payload( [] );
		}

		global $wpdb;

		// Bound the uncovered span this method may walk below, whatever the caller passed.
		$start = Helpers::clamp_live_start( $start );

		$table        = LocationDaily::get_table_name();
		$rollup_start = Helpers::clamp_rollup_start( $start );
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$values       = array_merge( $form_ids, [ $rollup_start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ] );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT country, SUM(count) AS count FROM {$table} WHERE form_id IN ( $placeholders ) AND day BETWEEN %s AND %s GROUP BY country",
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		$counts = [];

		foreach ( $rows as $row ) {
			$counts[ (string) $row->country ] = (int) $row->count;
		}

		if ( $start < $rollup_start ) {
			$counts = $this->live_location_counts( $form_ids, $start, $rollup_start->modify( '-1 day' ), $counts );
		}

		return self::build_locations_payload( $counts );
	}

	/**
	 * Shape a country => visitor-count map into the cached `locations` structure.
	 *
	 * @since 2.0.2
	 *
	 * @param array $counts Visitor counts keyed by country.
	 *
	 * @return array
	 */
	public static function build_locations_payload( array $counts ): array {

		$donut_total = array_sum( $counts );
		$countries   = [];

		foreach ( $counts as $country => $visitors ) {
			$countries[] = [
				'country'  => $country,
				'share'    => $donut_total > 0 ? round( $visitors / $donut_total * 100, 1 ) : 0.0,
				'visitors' => $visitors,
			];
		}

		return [
			'countries'   => $countries,
			'donut_total' => $donut_total,
		];
	}

	/**
	 * Tally per-country visitor counts for the uncovered pre-watermark span.
	 *
	 * @since 2.0.2
	 *
	 * @param array             $form_ids Published form IDs.
	 * @param DateTimeImmutable $start    Uncovered span start date.
	 * @param DateTimeImmutable $end      Uncovered span end date.
	 * @param array             $counts   Existing tally to merge into.
	 *
	 * @return array Merged visitor counts keyed by country code.
	 */
	private function live_location_counts( array $form_ids, DateTimeImmutable $start, DateTimeImmutable $end, array $counts ): array {

		global $wpdb;

		$meta         = wpforms()->obj( 'entry_meta' )->table_name;
		$entries      = wpforms()->obj( 'entry' )->table_name;
		$chunk        = $start;
		$placeholders = implode( ', ', array_fill( 0, count( $form_ids ), '%d' ) );
		$statuses     = wpforms_wpdb_prepare_in( EntriesCount::EXCLUDED_STATUSES );

		while ( $chunk <= $end ) {
			$chunk_end = min( $chunk->modify( '+' . ( self::CHUNK_DAYS - 1 ) . ' days' ), $end );

			[ $lo, $hi ] = Helpers::utc_range_bounds( $chunk, $chunk_end );

			$values = array_merge( [ 'location' ], $form_ids, [ $lo, $hi ] );

			// The status join keeps this fallback on the same entries as the rollup it
			// stands in for (see `LocationDaily::count_countries()`).
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
			$rows = (array) $wpdb->get_col(
				$wpdb->prepare(
					"SELECT m.data
					FROM {$meta} AS m
					INNER JOIN {$entries} AS e ON e.entry_id = m.entry_id
					WHERE m.type = %s
						AND m.form_id IN ( $placeholders )
						AND m.date BETWEEN %s AND %s
						AND e.status NOT IN ( {$statuses} )",
					$values
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

			$counts = $this->tally_location_rows( $rows, $counts );
			$chunk  = $chunk_end->modify( '+1 day' );
		}

		return $counts;
	}

	/**
	 * Tally one query result's `location` meta rows into a country => count map.
	 *
	 * @since 2.0.2
	 *
	 * @param array $rows   JSON payloads from `entry_meta.data`.
	 * @param array $counts Existing tally to add to, keyed by country code.
	 *
	 * @return array
	 */
	private function tally_location_rows( array $rows, array $counts ): array {

		foreach ( $rows as $row ) {
			$location = json_decode( (string) $row, true );
			$country  = is_array( $location ) && ! empty( $location['country'] ) ? (string) $location['country'] : '';

			if ( $country === '' ) {
				continue;
			}

			$counts[ $country ] = ( $counts[ $country ] ?? 0 ) + 1;
		}

		return $counts;
	}
}
