<?php

namespace WPForms\Pro\Db\Dashboard;

use DateTimeImmutable;
use RuntimeException;
use WPForms_DB;

/**
 * Dashboard per-day payment rollup table handler.
 *
 * @since 2.0.2
 */
class PaymentDaily extends WPForms_DB {

	use DeadlockRetryTrait;

	/**
	 * Primary class constructor.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {

		parent::__construct();

		$this->table_name  = self::get_table_name();
		$this->primary_key = ''; // Composite PK (form_id, day, currency) — row-level get/update/delete not used.
		$this->type        = 'dashboard_payment_daily';
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

		return $wpdb->prefix . 'wpforms_dashboard_payment_daily';
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
			form_id  BIGINT(20)  UNSIGNED NOT NULL,
			day      DATE                 NOT NULL,
			currency VARCHAR(3)           NOT NULL DEFAULT '',
			payments_count  INT(10)    UNSIGNED NOT NULL DEFAULT 0,
			sales_amount    DECIMAL(26,8)       NOT NULL DEFAULT 0,
			refunded_amount DECIMAL(26,8)       NOT NULL DEFAULT 0,
			coupons_count   INT(10)    UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (form_id, day, currency),
			KEY day (day)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		dbDelta( $query );
	}

	/**
	 * Recompute per-form, per-currency rollup rows for one day.
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
			'Dashboard payment-daily recompute'
		);
	}

	/**
	 * Delete the day's existing rollup rows.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $day Day to delete.
	 *
	 * @throws RuntimeException When the DELETE fails.
	 */
	private function delete_day( DateTimeImmutable $day ): void {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$this->table_name} WHERE day = %s", $day->format( 'Y-m-d' ) ) );

		if ( $deleted === false ) {
			throw new RuntimeException( 'Dashboard payment-daily recompute: DELETE failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}
	}

	/**
	 * Rebuild the day's rollup rows from the raw payments table.
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

		$payments = wpforms()->obj( 'payment' )->table_name;
		$meta     = wpforms()->obj( 'payment_meta' )->table_name;

		// Scalar subqueries per payment avoid cross-product inflation when a payment has
		// duplicate meta rows (refunded_amount is a cumulative total via update_or_add).
		// The coupon count applies the same status funnel as the Payments Overview coupons card
		// (neither failed nor still pending), so a rollup-covered range cannot report more
		// redemptions than live.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$this->table_name} ( form_id, day, currency, payments_count, sales_amount, refunded_amount, coupons_count )
				 SELECT p.form_id, %s, p.currency,
				        COUNT(*),
				        IFNULL( SUM( CASE WHEN p.status <> 'failed' AND p.subscription_status <> 'failed' THEN p.total_amount ELSE 0 END ), 0 ),
				        IFNULL( SUM(
				            IFNULL( ( SELECT rm.meta_value FROM {$meta} rm WHERE rm.payment_id = p.id AND rm.meta_key = 'refunded_amount' ORDER BY rm.id DESC LIMIT 1 ), 0 )
				        ), 0 ),
				        SUM( CASE WHEN p.status NOT IN ( 'failed', 'pending' ) AND p.subscription_status <> 'failed' AND EXISTS ( SELECT 1 FROM {$meta} cm WHERE cm.payment_id = p.id AND cm.meta_key = 'coupon_id' ) THEN 1 ELSE 0 END )
				 FROM {$payments} p
				 WHERE p.mode = 'live' AND p.is_published = 1
				   AND p.date_created_gmt BETWEEN %s AND %s
				 GROUP BY p.form_id, p.currency",
				$day_str,
				$range_start,
				$range_end
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		if ( $inserted === false ) {
			throw new RuntimeException( 'Dashboard payment-daily recompute: INSERT failed. ' . $wpdb->last_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception message routed to the error log, not HTML output.
		}
	}
}
