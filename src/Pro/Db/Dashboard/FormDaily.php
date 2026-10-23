<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use RuntimeException;
use WPForms\Pro\Reports\EntriesCount;
use WPForms_DB;

/**
 * Dashboard per-day form-count rollup table handler.
 *
 * @since 2.0.2
 */
class FormDaily extends WPForms_DB {

	use DeadlockRetryTrait;

	/**
	 * Primary class constructor.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {

		parent::__construct();

		$this->table_name  = self::get_table_name();
		$this->primary_key = ''; // Composite PK (form_id, day) — row-level get/update/delete not used.
		$this->type        = 'dashboard_form_daily';
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

		return $wpdb->prefix . 'wpforms_dashboard_form_daily';
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
			form_id BIGINT(20) UNSIGNED NOT NULL,
			day     DATE               NOT NULL,
			count   INT(10)   UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (form_id, day),
			KEY day (day)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $query );
	}

	/**
	 * Recompute per-form rollup rows for one day.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Day to recompute, in WP local time.
	 *
	 * @return bool True when the day was recomputed and committed.
	 */
	public function recompute_day( DateTimeImmutable $day ): bool {

		return $this->recompute_in_transaction(
			function () use ( $day ) {

				$this->delete_day( $day );
				$this->insert_day( $day );
			},
			'Dashboard form-daily recompute'
		);
	}

	/**
	 * Delete the day's existing rollup rows.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Day to delete.
	 *
	 * @throws RuntimeException When the DELETE fails, so the surrounding transaction rolls back.
	 */
	private function delete_day( DateTimeImmutable $day ): void {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE day = %s", $day->format( 'Y-m-d' ) ) );

		if ( $deleted === false ) {
			throw new RuntimeException( 'Dashboard form-daily recompute: DELETE failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}
	}

	/**
	 * Rebuild the day's rollup rows from the raw entries table.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Day to rebuild.
	 *
	 * @throws RuntimeException When the INSERT fails.
	 */
	private function insert_day( DateTimeImmutable $day ): void {

		global $wpdb;

		$day_str = $day->format( 'Y-m-d' );

		[ $range_start, $range_end ] = Helpers::utc_day_bounds( $day );

		$entries      = wpforms()->obj( 'entry' )->table_name;
		$status_list  = implode( ', ', array_fill( 0, count( EntriesCount::EXCLUDED_STATUSES ), '%s' ) );
		$placeholders = array_merge( [ $day_str, $range_start, $range_end ], EntriesCount::EXCLUDED_STATUSES );

		// The `date` column is compared bare against UTC-shifted bounds (not wrapped in
		// DATE_ADD) so the `(date, form_id)` index range scan is usable.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table_name} ( form_id, day, count )
				 SELECT form_id, %s, COUNT(*) FROM {$entries}
				 WHERE date BETWEEN %s AND %s
				   AND status NOT IN ( {$status_list} )
				 GROUP BY form_id",
				$placeholders
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber

		if ( $inserted === false ) {
			throw new RuntimeException( 'Dashboard form-daily recompute: INSERT failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}
	}
}
