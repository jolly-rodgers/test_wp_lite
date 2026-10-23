<?php

namespace WPForms\Pro\Admin\Dashboard;

use DateTimeImmutable;
use DateTimeZone;
use WPForms\Pro\Db\Dashboard\FormDaily;
use WPForms\Pro\Db\Dashboard\Helpers;
use WPForms\Pro\Db\Dashboard\LocationDaily;
use WPForms\Pro\Db\Dashboard\PaymentDaily;
use WPForms\Pro\Tasks\Actions\DashboardBackfillTask;
use WPForms\Pro\Tasks\Actions\DashboardRollupTask;

/**
 * Rollup dirty-day marking and form-cleanup hook callbacks.
 *
 * @since 2.0.2
 */
class RollupHooks {

	/**
	 * How many entry IDs to resolve per date lookup.
	 *
	 * @since 2.0.2
	 */
	const ENTRY_ID_CHUNK_SIZE = 500;

	/**
	 * Mark an entry's day dirty when a base-table update changes its status.
	 *
	 * @since 2.0.2
	 *
	 * @param array $data   Updated columns.
	 * @param int   $row_id Entry ID.
	 */
	public function mark_dirty_from_update( $data, $row_id ): void {

		if ( ! is_array( $data ) || ! array_key_exists( 'status', $data ) ) {
			return;
		}

		$this->mark_entry_day_dirty( (int) $row_id );
	}

	/**
	 * Mark an entry's day dirty before it is deleted.
	 *
	 * Only the single-entry shape is relevant; the form-cleanup path removes rollup rows outright.
	 *
	 * @since 2.0.2
	 *
	 * @param int|string $row_id Column value identifying the row(s) being deleted.
	 * @param string     $column Column name used to identify the row(s).
	 */
	public function mark_dirty_from_delete( $row_id, $column ): void {

		if ( $column !== 'entry_id' ) {
			return;
		}

		$this->mark_entry_day_dirty( (int) $row_id );
	}

	/**
	 * Mark an entry's day dirty when it's marked as spam or restored from spam.
	 *
	 * @since 2.0.2
	 *
	 * @param int $entry_id Entry ID.
	 * @param int $form_id  Form ID. Unused - the day is resolved from the entry itself.
	 */
	public function mark_dirty_from_spam( $entry_id, $form_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- Required by the hook signature.

		$this->mark_entry_day_dirty( (int) $entry_id );
	}

	/**
	 * Delete a deleted form's rollup rows.
	 *
	 * @since 2.0.2
	 *
	 * @param array $ids Deleted form IDs.
	 */
	public function delete_form_rollup_rows( $ids ): void {

		foreach ( (array) $ids as $form_id ) {
			$this->delete_form_rows( (int) $form_id );
		}
	}

	/**
	 * Mark the WP-local days of purged entries dirty.
	 *
	 * The retention purge fires its own hook, not the base CRUD delete hooks.
	 *
	 * @since 2.0.2
	 *
	 * @param array $entry_ids Entry IDs being purged.
	 * @param int   $form_id   Form ID. Unused.
	 */
	public function mark_dirty_from_purge( $entry_ids, $form_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $form_id required by the hook signature.

		$entry_ids = array_filter( array_map( 'absint', (array) $entry_ids ) );

		if ( ! $entry_ids ) {
			return;
		}

		foreach ( $this->get_local_days_for_entries( $entry_ids ) as $day ) {
			$this->mark_local_day_dirty( $day );
		}
	}

	/**
	 * Mark a payment's day dirty when it is created.
	 *
	 * @since 2.0.2
	 *
	 * @param int $payment_id Payment ID.
	 */
	public function mark_dirty_from_payment( $payment_id ): void {

		$this->mark_payment_day_dirty( (int) $payment_id );
	}

	/**
	 * Mark a payment's day dirty when it is updated.
	 *
	 * @since 2.0.2
	 *
	 * @param array $data   Updated columns. Unused.
	 * @param int   $row_id Payment ID.
	 */
	public function mark_dirty_from_payment_update( $data, $row_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data required by the hook signature.

		$this->mark_payment_day_dirty( (int) $row_id );
	}

	/**
	 * Mark a payment's day dirty before it is deleted.
	 *
	 * @since 2.0.2
	 *
	 * @param int $payment_id Payment ID.
	 */
	public function on_payment_deleted( $payment_id ): void {

		$this->mark_payment_day_dirty( (int) $payment_id );
	}

	/**
	 * Mark an inserted entry's day dirty (e.g. LiteConnect backdated import).
	 *
	 * The wpforms_post_insert_ hook fires for all empty-type tables (entries,
	 * entry_meta, entry_fields). Only entry inserts carry a `fields` column.
	 *
	 * @since 2.0.2
	 *
	 * @param int   $entry_id Entry ID.
	 * @param array $data     Inserted column data.
	 */
	public function on_entry_inserted( $entry_id, $data = [] ): void {

		if ( ! is_array( $data ) || ! isset( $data['fields'] ) ) {
			return;
		}

		// Only needed for backdated inserts into a day the rollup already covered.
		if ( Helpers::get_watermark() === '' ) {
			return;
		}

		$date = $this->get_entry_date( (int) $entry_id );

		if ( $date === null ) {
			return;
		}

		$day = $this->resolve_local_day( $date );

		$this->mark_local_day_dirty( $day );
		$this->maybe_reopen_backfill( $day );
	}

	/**
	 * Resume the backfill when a row lands below the watermark.
	 *
	 * The initial walk stops at the oldest data present at the time, so an import
	 * arriving later can carry days no coverage exists for.
	 *
	 * @since 2.0.2
	 *
	 * @param string $day WP-local day of the inserted row.
	 */
	private function maybe_reopen_backfill( string $day ): void {

		if ( $day >= Helpers::get_watermark() || $day < $this->floor_day() ) {
			return;
		}

		// Reopen once: the first below-watermark insert clears the flag and kicks
		// the walk; the rest of a bulk import short-circuits here.
		if ( ! get_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION ) ) {
			return;
		}

		delete_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION );

		DashboardBackfillTask::kick();
	}

	/**
	 * Mark an inserted payment's day dirty.
	 *
	 * @since 2.0.2
	 *
	 * @param int $payment_id Payment ID.
	 */
	public function on_payment_inserted( $payment_id ): void {

		if ( Helpers::get_watermark() === '' ) {
			return;
		}

		$payment = wpforms()->obj( 'payment' )->get( (int) $payment_id );

		if ( ! $payment || empty( $payment->date_created_gmt ) ) {
			return;
		}

		$day = $this->resolve_local_day( $payment->date_created_gmt );

		$this->mark_local_day_dirty( $day );
		$this->maybe_reopen_backfill( $day );
	}

	/**
	 * Mark dirty days for entries about to be bulk-deleted (empty trash).
	 *
	 * @since 2.0.2
	 *
	 * @param array $entry_ids Entry IDs being deleted.
	 * @param int   $form_id   Form ID. Unused.
	 */
	public function mark_dirty_from_empty_trash( $entry_ids, $form_id ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $form_id required by the hook signature.

		$entry_ids = array_filter( array_map( 'absint', (array) $entry_ids ) );

		if ( ! $entry_ids ) {
			return;
		}

		foreach ( $this->get_local_days_for_entries( $entry_ids ) as $day ) {
			$this->mark_local_day_dirty( $day );
		}
	}

	/**
	 * Resolve an entry's WP-local day and add it to the dirty set, unless it's today or yesterday.
	 *
	 * @since 2.0.2
	 *
	 * @param int $entry_id Entry ID.
	 */
	private function mark_entry_day_dirty( int $entry_id ): void {

		$date = $this->get_entry_date( $entry_id );

		if ( $date === null ) {
			return;
		}

		$this->mark_local_day_dirty( $this->resolve_local_day( $date ) );
	}

	/**
	 * Resolve a payment's WP-local day and add it to the dirty set.
	 *
	 * @since 2.0.2
	 *
	 * @param int $payment_id Payment ID.
	 */
	private function mark_payment_day_dirty( int $payment_id ): void {

		$payment = wpforms()->obj( 'payment' )->get( $payment_id );

		if ( ! $payment || empty( $payment->date_created_gmt ) ) {
			return;
		}

		$this->mark_local_day_dirty( $this->resolve_local_day( $payment->date_created_gmt ) );
	}

	/**
	 * Add a WP-local day to the dirty set, unless it's today/yesterday or older than the backfill floor.
	 *
	 * @since 2.0.2
	 *
	 * @param string $day Day to mark dirty, formatted `Y-m-d`.
	 */
	private function mark_local_day_dirty( string $day ): void {

		if ( $this->is_recent_day( $day ) ) {
			return;
		}

		if ( $day < $this->floor_day() ) {
			return;
		}

		$this->add_dirty_day( $day );
	}

	/**
	 * Resolve the distinct WP-local days a set of entries fall on.
	 *
	 * @since 2.0.2
	 *
	 * @param array $entry_ids Entry IDs.
	 *
	 * @return array Distinct WP-local days (`Y-m-d`).
	 */
	private function get_local_days_for_entries( array $entry_ids ): array {

		global $wpdb;

		$table = wpforms()->obj( 'entry' )->table_name;
		$days  = [];

		// The ID list is unbounded: Empty Trash and the retention purge both hand over every
		// matching entry. One placeholder per ID in a single statement can exceed the
		// placeholder ceiling or `max_allowed_packet` on a large site, so query in batches and
		// merge the day set. Chunking also keeps an empty list from producing `IN ()`.
		foreach ( array_chunk( $entry_ids, self::ENTRY_ID_CHUNK_SIZE ) as $chunk ) {
			$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			$dates = (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT date FROM {$table} WHERE entry_id IN ( $placeholders )", $chunk ) );

			foreach ( array_filter( $dates ) as $date ) {
				$days[ $this->resolve_local_day( (string) $date ) ] = true;
			}
		}

		return array_keys( $days );
	}

	/**
	 * Get an entry's raw `date` column value directly.
	 *
	 * @since 2.0.2
	 *
	 * @param int $entry_id Entry ID.
	 *
	 * @return string|null
	 */
	private function get_entry_date( int $entry_id ): ?string {

		global $wpdb;

		$table = wpforms()->obj( 'entry' )->table_name;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$date = $wpdb->get_var( $wpdb->prepare( "SELECT date FROM {$table} WHERE entry_id = %d", $entry_id ) );

		return $date ? (string) $date : null;
	}

	/**
	 * Shift a UTC entry date into its WP-local calendar day, the same way
	 * `FormDaily::recompute_day()` buckets days.
	 *
	 * @since 2.0.2
	 *
	 * @param string $utc_date Entry date, stored in UTC (`Y-m-d H:i:s`).
	 *
	 * @return string Local day (`Y-m-d`).
	 */
	private function resolve_local_day( string $utc_date ): string {

		$utc_obj = new DateTimeImmutable( $utc_date, new DateTimeZone( 'UTC' ) );
		$local   = $utc_obj->modify( Helpers::gmt_offset_minutes_for_day( $utc_obj ) . ' minutes' );

		return $local->format( 'Y-m-d' );
	}

	/**
	 * Whether a day is today or yesterday (site-local) - already recomputed every run.
	 *
	 * @since 2.0.2
	 *
	 * @param string $day Day to check (`Y-m-d`).
	 *
	 * @return bool
	 */
	private function is_recent_day( string $day ): bool {

		$yesterday = date_create_immutable( 'now', wp_timezone() )->modify( '-1 day' )->format( 'Y-m-d' );

		return $day >= $yesterday;
	}

	/**
	 * Oldest day the backfill will ever reach.
	 *
	 * @since 2.0.2
	 *
	 * @return string Floor day (`Y-m-d`).
	 */
	private function floor_day(): string {

		return date_create_immutable( 'now', wp_timezone() )->modify( '-' . DashboardBackfillTask::DAY_CAP . ' days' )->format( 'Y-m-d' );
	}

	/**
	 * Add a day to the dirty-day set, atomically.
	 *
	 * @since 2.0.2
	 *
	 * @param string $day Day to mark dirty, formatted `Y-m-d`.
	 */
	private function add_dirty_day( string $day ): void {

		DashboardRollupTask::update_dirty_days(
			static function ( array $dirty ) use ( $day ): ?array {

				if ( in_array( $day, $dirty, true ) ) {
					return null;
				}

				$dirty[] = $day;

				sort( $dirty );

				return $dirty;
			}
		);
	}

	/**
	 * Delete all rollup rows for one form across all three daily tables.
	 *
	 * @since 2.0.2
	 *
	 * @param int $form_id Form ID.
	 */
	private function delete_form_rows( int $form_id ): void {

		global $wpdb;

		$form_daily     = FormDaily::get_table_name();
		$location_daily = LocationDaily::get_table_name();
		$payment_daily  = PaymentDaily::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$form_daily} WHERE form_id = %d", $form_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$location_daily} WHERE form_id = %d", $form_id ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$payment_daily} WHERE form_id = %d", $form_id ) );
	}
}
