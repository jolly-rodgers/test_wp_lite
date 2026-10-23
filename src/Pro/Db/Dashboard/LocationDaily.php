<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use RuntimeException;
use WPForms\Pro\Reports\EntriesCount;
use WPForms_DB;

/**
 * Dashboard per-day location-count rollup table handler.
 *
 * @since 2.0.2
 */
class LocationDaily extends WPForms_DB {

	use DeadlockRetryTrait;

	/**
	 * Primary class constructor.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {

		parent::__construct();

		$this->table_name  = self::get_table_name();
		$this->primary_key = ''; // Composite PK (form_id, day, country) — row-level get/update/delete not used.
		$this->type        = 'dashboard_location_daily';
	}

	/**
	 * Get the table name.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public static function get_table_name(): string {

		global $wpdb;

		return $wpdb->prefix . 'wpforms_dashboard_location_daily';
	}

	/**
	 * Create the table.
	 *
	 * @since 2.0.2
	 */
	public function create_table(): void {

		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();

		$query = "CREATE TABLE $this->table_name (
			form_id BIGINT(20)  UNSIGNED NOT NULL,
			country VARCHAR(100)         NOT NULL,
			day     DATE                 NOT NULL,
			count   INT(10)     UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (form_id, day, country),
			KEY day (day)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $query );
	}

	/**
	 * Recompute per-country rollup rows for one day.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Day to recompute, in WP local time.
	 *
	 * @return bool True when the day was recomputed and committed.
	 */
	public function recompute_day( DateTimeImmutable $day ): bool {

		$day_str = $day->format( 'Y-m-d' );

		return $this->recompute_in_transaction(
			function () use ( $day, $day_str ) {

				$counts = $this->count_countries( $day );

				$this->delete_day( $day_str );
				$this->insert_counts( $day_str, $counts );
			},
			'Dashboard location-daily recompute'
		);
	}

	/**
	 * Delete the day's existing rollup rows.
	 *
	 * @since 2.0.2
	 *
	 * @param string $day_str Day to delete, formatted `Y-m-d`.
	 *
	 * @throws RuntimeException When the DELETE fails, so the surrounding transaction rolls back.
	 */
	private function delete_day( string $day_str ): void {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE day = %s", $day_str ) );

		if ( $deleted === false ) {
			throw new RuntimeException( 'Dashboard location-daily recompute: DELETE failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}
	}

	/**
	 * Tally per-country location-visitor counts for one day from entry meta.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Day to tally, in WP local time.
	 *
	 * @return array Visitor counts keyed by form ID, then country code.
	 *
	 * @throws RuntimeException When the tally query fails.
	 */
	private function count_countries( DateTimeImmutable $day ): array {

		global $wpdb;

		$meta     = wpforms()->obj( 'entry_meta' )->table_name;
		$entries  = wpforms()->obj( 'entry' )->table_name;
		$statuses = wpforms_wpdb_prepare_in( EntriesCount::EXCLUDED_STATUSES );

		[ $range_start, $range_end ] = Helpers::utc_day_bounds( $day );

		// The `date` column is compared bare against UTC-shifted bounds (not wrapped in
		// DATE_ADD) so the `(type, date)` index range scan is usable. The status join keeps
		// the visitor counts on the same entries the rest of the dashboard counts: a meta
		// row outlives its entry's status, so spam and trashed entries would be tallied.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT m.form_id, m.data
				FROM {$meta} AS m
				INNER JOIN {$entries} AS e ON e.entry_id = m.entry_id
				WHERE m.type = %s
					AND m.date BETWEEN %s AND %s
					AND e.status NOT IN ( {$statuses} )",
				'location',
				$range_start,
				$range_end
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( $wpdb->last_error !== '' ) {
			throw new RuntimeException( 'Dashboard location-daily recompute: location tally query failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}

		$counts = [];

		foreach ( $rows as $row ) {
			$location = json_decode( (string) $row->data, true );
			$country  = is_array( $location ) && ! empty( $location['country'] ) ? (string) $location['country'] : '';

			if ( $country === '' ) {
				continue;
			}

			$form_id = (int) $row->form_id;

			$counts[ $form_id ][ $country ] = ( $counts[ $form_id ][ $country ] ?? 0 ) + 1;
		}

		return $counts;
	}

	/**
	 * Batch-insert the day's rollup rows from tallied country counts.
	 *
	 * @since 2.0.2
	 *
	 * @param string $day_str Day the counts belong to, formatted `Y-m-d`.
	 * @param array  $counts  Visitor counts keyed by form ID, then country code.
	 *
	 * @throws RuntimeException When the INSERT fails.
	 */
	private function insert_counts( string $day_str, array $counts ): void {

		if ( ! $counts ) {
			return;
		}

		global $wpdb;

		$rows   = [];
		$values = [];

		foreach ( $counts as $form_id => $country_counts ) {
			foreach ( $country_counts as $country => $count ) {
				$rows[] = '( %d, %s, %s, %d )';

				$values[] = (int) $form_id;
				$values[] = $country;
				$values[] = $day_str;
				$values[] = $count;
			}
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table_name} ( form_id, country, day, count ) VALUES " . implode( ', ', $rows ),
				$values
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare

		if ( $inserted === false ) {
			throw new RuntimeException( 'Dashboard location-daily recompute: INSERT failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}
	}
}
