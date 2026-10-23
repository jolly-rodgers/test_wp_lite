<?php

namespace WPForms\Pro\Migrations;

use WPForms\Helpers\DB;
use WPForms\Migrations\UpgradeBase;
use WPForms\Pro\Db\Analytics\DB as AnalyticsFieldsDB;
use WPForms\Pro\Db\Dashboard\RollupRecomputer;
use WPForms\Pro\Tasks\Actions\DashboardBackfillTask;
use WPForms\Pro\Tasks\Actions\DashboardRollupTask;

/**
 * Class upgrade for the 2.0.2 release.
 *
 * Creates rollup tables, builds supporting indexes, runs a budget-limited warm-up,
 * and enqueues the async backfill drain.
 *
 * @since 2.0.2
 *
 * @noinspection PhpUnused
 */
class Upgrade2_0_2 extends UpgradeBase {

	/**
	 * Day-count cap for the inline cold-start warm. 0 = no day cap.
	 *
	 * @since 2.0.2
	 */
	private const INLINE_WARMUP_DAYS = 0;

	/**
	 * Soft time budget (seconds) for the inline cold-start warm.
	 *
	 * @since 2.0.2
	 */
	private const INLINE_WARMUP_MAX_SECONDS = 15.0;

	/**
	 * MySQL named-lock key serialising the one-time cold-start work.
	 *
	 * @since 2.0.2
	 */
	private const COLD_START_LOCK = 'wpforms_dashboard_rollup_migration';

	/**
	 * Run upgrade.
	 *
	 * @since 2.0.2
	 *
	 * @return bool|null Upgrade result:
	 *                   true  - the upgrade completed successfully,
	 *                   false - in the case of failure,
	 *                   null  - upgrade started but not yet finished (background task).
	 */
	public function run(): ?bool { // phpcs:ignore WPForms.PHP.HooksMethod.InvalidPlaceForAddingHooks

		// Tables are created by the self-healing custom-tables registry.
		DB::create_custom_tables( true );

		// Schema change owned by this release, not by the Dashboard cold start.
		$this->add_analytics_index();

		// Fast path: the heavy cold-start work already ran on an earlier request.
		if ( $this->is_cold_start_complete() ) {
			return true;
		}

		global $wpdb;

		// Serialise the cold-start work across concurrent requests.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::COLD_START_LOCK, 0 ) ) !== '1' ) {
			// Another request holds the lock. Report "not finished" so the version gate stays open.
			return null;
		}

		try {
			// The winner may have finished between the fast-path check and acquiring the lock.
			if ( $this->is_cold_start_complete() ) {
				return true;
			}

			$this->build_indexes_inline();

			$this->warm_up_inline();

		} finally {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::COLD_START_LOCK ) );
		}

		// The Tasks manager is not registered yet at migration time; defer to `init`.
		add_action(
			'init',
			static function () {

				DashboardBackfillTask::kick();
			},
			PHP_INT_MAX
		);

		return true;
	}

	/**
	 * Whether the one-time cold-start work already completed.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	private function is_cold_start_complete(): bool {

		return get_option( DashboardRollupTask::WATERMARK_OPTION, '' ) !== ''
			&& (bool) get_option( DashboardRollupTask::INDEXES_BUILT_OPTION, false );
	}

	/**
	 * Add the Form Analytics range-pruning index to an existing table.
	 *
	 * Serves the Dashboard's per-form interactions aggregation and Form Analytics'
	 * own date-ranged field reads. Fresh installs get the key from
	 * `Fields::create_table()`.
	 *
	 * Attempts the ALTER at most once, mirroring the index-build and warm-up guards.
	 * `run()` is re-entered on every request until it returns a non-null value, so a
	 * request killed mid-DDL must not re-cost the ALTER on every page load.
	 *
	 * @since 2.0.2
	 */
	private function add_analytics_index(): void {

		if ( get_option( 'wpforms_analytics_form_period_index_attempted' ) ) {
			return;
		}

		$table = AnalyticsFieldsDB::fields_table();

		// The table is absent on sites where its creation failed; nothing to alter.
		if ( ! DB::table_exists( $table ) ) {
			return;
		}

		update_option( 'wpforms_analytics_form_period_index_attempted', true, false );

		wpforms_set_time_limit( 60 );

		DB::add_index_if_missing( $table, 'form_period', '( form_id, period_date )' );
	}

	/**
	 * Build the supporting indexes synchronously, before the warm-up runs.
	 *
	 * Attempts the inline build at most once. If the first request dies
	 * mid-ALTER, subsequent requests skip inline and let the async drain
	 * handle it.
	 *
	 * @since 2.0.2
	 */
	private function build_indexes_inline(): void {

		if ( get_option( 'wpforms_dashboard_indexes_inline_attempted' ) ) {
			return;
		}

		update_option( 'wpforms_dashboard_indexes_inline_attempted', true, false );

		wpforms_set_time_limit( 60 );

		try {
			DashboardBackfillTask::build_indexes();
		} catch ( \Throwable $e ) {
			wpforms_log(
				'Dashboard rollup inline index build failed, falling back to the async drain',
				[ 'message' => $e->getMessage() ],
				[
					'type'  => [ 'error' ],
					'force' => true,
				]
			);
		}
	}

	/**
	 * Warm as many recent days as fit in the time budget.
	 *
	 * @since 2.0.2
	 */
	private function warm_up_inline(): void {

		// Already warmed by a previous inline run.
		if ( get_option( DashboardRollupTask::WATERMARK_OPTION, '' ) !== '' ) {
			return;
		}

		// Inline warm-up attempts at most once, mirroring the index-build guard.
		// A prior attempt that died mid-run must not re-cost the recompute on every
		// page load; the async backfill drain completes the remaining days.
		if ( get_option( 'wpforms_dashboard_warmup_attempted' ) ) {
			return;
		}

		update_option( 'wpforms_dashboard_warmup_attempted', true, false );

		// "Today" is the site-timezone date, matching the offset-adjusted day bucketing.
		$today = date_create_immutable( 'now', wp_timezone() )->setTime( 0, 0, 0 );

		// Floor at the 365-day cap; the time budget does the real bounding.
		$floor = $today->modify( '-' . DashboardBackfillTask::DAY_CAP . ' days' )->format( 'Y-m-d' );

		$watermark = ( new RollupRecomputer() )->recompute_back(
			$today,
			self::INLINE_WARMUP_DAYS,
			self::INLINE_WARMUP_MAX_SECONDS,
			$floor
		);

		if ( $watermark === null ) {
			return;
		}

		update_option( DashboardRollupTask::WATERMARK_OPTION, $watermark, false );

		// Establish the gmt-offset baseline now so the maintenance task's offset-change guard is armed.
		update_option( DashboardRollupTask::TIMEZONE_OPTION, wp_timezone()->getName(), false );
	}
}
