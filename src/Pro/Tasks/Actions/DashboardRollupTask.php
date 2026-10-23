<?php

namespace WPForms\Pro\Tasks\Actions;

use ActionScheduler;
use ActionScheduler_Store;
use DateTimeImmutable;
use WPForms\Admin\Dashboard\Cache;
use WPForms\Pro\Db\Dashboard\FormDaily;
use WPForms\Pro\Db\Dashboard\Helpers;
use WPForms\Pro\Db\Dashboard\LocationDaily;
use WPForms\Pro\Db\Dashboard\PaymentDaily;
use WPForms\Pro\Db\Dashboard\RollupRepository;
use WPForms\Tasks\Task;
use WPForms\Tasks\Tasks;

/**
 * Steady-state Dashboard rollup maintenance task.
 *
 * @since 2.0.2
 */
class DashboardRollupTask extends Task {

	/**
	 * Action Scheduler action name.
	 *
	 * @since 2.0.2
	 */
	public const ACTION = 'wpforms_dashboard_rollup_maintain';

	/**
	 * Option key storing the last-applied interval (seconds).
	 *
	 * @since 2.0.2
	 */
	public const INTERVAL_OPTION = 'wpforms_dashboard_rollup_maintain_interval';

	/**
	 * Option key storing the rollup watermark (`Y-m-d`).
	 *
	 * @since 2.0.2
	 */
	public const WATERMARK_OPTION = 'wpforms_dashboard_rollup_watermark';

	/**
	 * Option key storing the dirty-day set (array of `Y-m-d` strings).
	 *
	 * @since 2.0.2
	 */
	public const DIRTY_DAYS_OPTION = 'wpforms_dashboard_rollup_dirty_days';

	/**
	 * One-off async action that drains the remaining dirty days after a budgeted slice.
	 *
	 * @since 2.0.2
	 */
	public const DRAIN_ACTION = 'wpforms_dashboard_rollup_drain';

	/**
	 * MySQL named-lock key guarding read-modify-write of the dirty-day option.
	 *
	 * @since 2.0.2
	 */
	private const DIRTY_LOCK = 'wpforms_dashboard_rollup_dirty_days';

	/**
	 * Soft wall-clock budget (seconds) for one dirty-day drain slice.
	 *
	 * @since 2.0.2
	 */
	private const SOFT_BUDGET_SECONDS = 25.0;

	/**
	 * Relative date modifier stepping the day cursor one day back.
	 *
	 * @since 2.0.2
	 */
	private const PREVIOUS_DAY = '-1 day';

	/**
	 * Relative date modifier stepping the day cursor one day forward.
	 *
	 * @since 2.0.2
	 */
	private const NEXT_DAY = '+1 day';

	/**
	 * Option key storing the site timezone the rollup was last built for.
	 *
	 * @since 2.0.2
	 */
	public const TIMEZONE_OPTION = 'wpforms_dashboard_rollup_gmt_offset';

	/**
	 * Option key flagging that the supporting indexes have been built.
	 *
	 * @since 2.0.2
	 */
	public const INDEXES_BUILT_OPTION = 'wpforms_dashboard_rollup_indexes_built';

	/**
	 * Option key flagging that the backfill has reached the oldest entry (or the day cap).
	 *
	 * @since 2.0.2
	 */
	public const BACKFILL_COMPLETE_OPTION = 'wpforms_dashboard_rollup_backfill_complete';

	/**
	 * Option key storing a monotonic generation counter, bumped on GMT-offset reset.
	 *
	 * @since 2.0.2
	 */
	public const GENERATION_OPTION = 'wpforms_dashboard_rollup_generation';

	/**
	 * Option key storing the last fully maintained day (`Y-m-d`).
	 *
	 * @since 2.0.2
	 */
	public const LAST_MAINTAINED_OPTION = 'wpforms_dashboard_rollup_last_maintained';

	/**
	 * Interval in seconds. 0 cancels schedule; non-zero floored at 30 minutes.
	 *
	 * @since 2.0.2
	 *
	 * @var int
	 */
	private $interval;

	/**
	 * Tasks instance.
	 *
	 * @since 2.0.2
	 *
	 * @var Tasks|null
	 */
	private $tasks;

	/**
	 * Log title.
	 *
	 * @since 2.0.2
	 *
	 * @var string
	 */
	protected $log_title = 'Dashboard Rollup Maintenance';

	/**
	 * Class constructor.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {

		parent::__construct( self::ACTION );

		$this->init();
		$this->hooks();
	}

	/**
	 * Register the recurring schedule.
	 *
	 * @since 2.0.2
	 */
	private function init(): void {

		$this->tasks = wpforms()->obj( 'tasks' );

		if ( ! $this->tasks ) {
			return;
		}

		// Schedule add/remove is an admin/cron concern.
		if ( ! is_admin() && ! wp_doing_cron() ) {
			return;
		}

		/**
		 * Filter the Dashboard rollup maintenance task interval (seconds).
		 *
		 * Return 0 to cancel the schedule entirely. Non-zero values below 30 minutes are
		 * floored to 30 minutes.
		 *
		 * @since 2.0.2
		 *
		 * @param int $interval Interval in seconds. Default HOUR_IN_SECONDS.
		 */
		$raw = (int) apply_filters( 'wpforms_pro_tasks_actions_dashboard_rollup_task_init_interval', HOUR_IN_SECONDS );

		$this->interval = $raw <= 0 ? 0 : max( 30 * MINUTE_IN_SECONDS, $raw );

		$this->reconcile_schedule();
	}

	/**
	 * Converge the scheduled action to the filtered desired interval.
	 *
	 * @since 2.0.2
	 */
	private function reconcile_schedule(): void {

		$is_scheduled = $this->tasks->is_scheduled( self::ACTION ) !== false;

		// Cancellation requested.
		if ( $this->interval <= 0 ) {

			if ( $is_scheduled ) {
				$this->cancel();
				delete_option( self::INTERVAL_OPTION );
			}

			return;
		}

		// First-time scheduling — no existing recurring action.
		if ( ! $is_scheduled ) {

			$this->add_task();
			update_option( self::INTERVAL_OPTION, $this->interval, false );

			return;
		}

		// Already is_scheduled — re-arm only if cadence changed.
		if ( (int) get_option( self::INTERVAL_OPTION, 0 ) === $this->interval ) {
			return;
		}

		$this->cancel();
		$this->add_task();
		update_option( self::INTERVAL_OPTION, $this->interval, false );
	}

	/**
	 * Bind the recurring action to process().
	 *
	 * @since 2.0.2
	 */
	private function hooks(): void {

		add_action( self::ACTION, [ $this, 'process' ] );
		add_action( self::DRAIN_ACTION, [ $this, 'process_drain' ] );
	}

	/**
	 * Schedule the first run and recurring cadence.
	 *
	 * @since 2.0.2
	 */
	private function add_task(): void {

		if ( $this->interval <= 0 ) {
			return;
		}

		$this->tasks->create( self::ACTION )
			->recurring( time() + $this->interval, $this->interval )
			->params()
			->register();
	}

	/**
	 * Recurring callback: run steady-state maintenance, then backstop the backfill drain.
	 *
	 * @since 2.0.2
	 */
	public function process(): void {

		if ( ! RollupRepository::tables_exist() || ! wpforms()->obj( 'entry' ) || ! wpforms()->obj( 'entry_meta' ) ) {
			return;
		}

		$this->maybe_init_watermark();
		$this->maybe_handle_gmt_offset_change();
		$this->recompute_recent_days();

		Cache::invalidate_all_presets();

		$this->drain_dirty_days();
		$this->maybe_kick_backfill();

		$this->log( 'Dashboard rollup maintenance run completed.' );
	}

	/**
	 * Re-kick the async backfill drain if incomplete and not already scheduled.
	 *
	 * @since 2.0.2
	 */
	private function maybe_kick_backfill(): void {

		DashboardBackfillTask::kick();
	}

	/**
	 * Set the initial watermark and stored GMT offset on the first run.
	 *
	 * @since 2.0.2
	 */
	private function maybe_init_watermark(): void {

		if ( get_option( self::WATERMARK_OPTION, '' ) !== '' ) {
			return;
		}

		update_option( self::WATERMARK_OPTION, $this->today()->format( 'Y-m-d' ), false );
		update_option( self::TIMEZONE_OPTION, wp_timezone()->getName(), false );
	}

	/**
	 * Rebuild rollups from scratch when the site's timezone setting has changed.
	 *
	 * Compares wp_timezone()->getName() instead of the raw gmt_offset, so
	 * DST transitions no longer trigger a full rebuild.
	 *
	 * @since 2.0.2
	 */
	private function maybe_handle_gmt_offset_change(): void {

		$stored  = get_option( self::TIMEZONE_OPTION, null );
		$current = wp_timezone()->getName();

		if ( $stored === null || $stored === false ) {
			update_option( self::TIMEZONE_OPTION, $current, false );

			return;
		}

		if ( $stored === $current ) {
			return;
		}

		$this->truncate_rollups();

		update_option( self::WATERMARK_OPTION, $this->today()->format( 'Y-m-d' ), false );
		update_option( self::TIMEZONE_OPTION, $current, false );

		delete_option( self::BACKFILL_COMPLETE_OPTION );

		// Bump the generation so an in-flight backfill can detect the reset.
		update_option( self::GENERATION_OPTION, (int) get_option( self::GENERATION_OPTION, 0 ) + 1, false );
	}

	/**
	 * Truncate all three rollup tables.
	 *
	 * @since 2.0.2
	 */
	private function truncate_rollups(): void {

		global $wpdb;

		$tables = [
			FormDaily::get_table_name(),
			LocationDaily::get_table_name(),
			PaymentDaily::get_table_name(),
		];

		foreach ( $tables as $table ) {
			// The table name comes from the rollup classes' own constants, never from input.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "TRUNCATE TABLE $table" );
		}
	}

	/**
	 * Recompute today and yesterday unconditionally, then catch up any gap
	 * left by a multi-day AS/cron outage.
	 *
	 * @since 2.0.2
	 */
	private function recompute_recent_days(): void {

		$today     = $this->today();
		$yesterday = $today->modify( self::PREVIOUS_DAY );

		// Today and yesterday are rebuilt on every run, so a transient failure here is retried
		// next time no matter where the watermark lands.
		$this->recompute_day_set( [ $today, $yesterday ] );

		$last_maintained = get_option( self::LAST_MAINTAINED_OPTION, '' );
		$gap_closed      = true;
		$last_processed  = $today;

		if ( $last_maintained !== '' && $last_maintained < $yesterday->format( 'Y-m-d' ) ) {
			$gap_start = date_create_immutable( $last_maintained, wp_timezone() )->modify( self::NEXT_DAY );
			$gap_end   = $yesterday->modify( self::PREVIOUS_DAY );

			$count = 0;
			$day   = $gap_start;

			while ( $day <= $gap_end && $count < 10 ) {
				// Stop at the first day that fails, leaving $day pointing at it so the
				// watermark below settles one day earlier and the next run retries it.
				// Advancing regardless would drop that day from the Dashboard permanently.
				if ( ! $this->recompute_day_set( [ $day ] ) ) {
					break;
				}

				$day = $day->modify( self::NEXT_DAY );

				++$count;
			}

			if ( $day <= $gap_end ) {
				$gap_closed     = false;
				$last_processed = $day->modify( self::PREVIOUS_DAY );
			}
		}

		update_option(
			self::LAST_MAINTAINED_OPTION,
			( $gap_closed ? $today : $last_processed )->format( 'Y-m-d' ),
			false
		);
	}

	/**
	 * Recompute all three rollup tables for each day in the set.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable[] $days Days to recompute.
	 *
	 * @return bool True when every day in the set was rebuilt successfully.
	 */
	private function recompute_day_set( array $days ): bool {

		$succeeded = true;

		foreach ( $days as $day ) {
			$succeeded = Helpers::recompute_all_for_day( $day ) && $succeeded;
		}

		return $succeeded;
	}

	/**
	 * Atomically read-modify-write the dirty-day option, guarded by a MySQL named lock.
	 *
	 * @since 2.0.2
	 *
	 * @param callable $mutator Callback invoked with the current dirty-day array; returns the
	 *                          array to persist, or a non-array to make no change.
	 */
	public static function update_dirty_days( callable $mutator ): void {

		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$locked = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK( %s, %d )', self::DIRTY_LOCK, 2 ) ) === '1';

		if ( ! $locked ) {
			wpforms_log(
				'Dashboard dirty-day lock timeout',
				'',
				[ 'type' => [ 'error' ] ]
			);

			return;
		}

		$dirty   = (array) get_option( self::DIRTY_DAYS_OPTION, [] );
		$updated = $mutator( $dirty );

		if ( is_array( $updated ) ) {
			update_option( self::DIRTY_DAYS_OPTION, $updated, false );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK( %s )', self::DIRTY_LOCK ) );
	}

	/**
	 * Recompute dirty days oldest-first within a soft time budget.
	 *
	 * @since 2.0.2
	 */
	private function drain_dirty_days(): void {

		$dirty = (array) get_option( self::DIRTY_DAYS_OPTION, [] );

		if ( ! $dirty ) {
			return;
		}

		wpforms_set_time_limit( 60 );

		sort( $dirty );

		$deadline  = microtime( true ) + self::SOFT_BUDGET_SECONDS;
		$processed = [];

		foreach ( $dirty as $day_str ) {
			if ( microtime( true ) >= $deadline ) {
				break;
			}

			$day = date_create_immutable( (string) $day_str, wp_timezone() );

			if ( ! $day ) {
				$processed[] = (string) $day_str;

				continue;
			}

			// A day stays dirty unless every table rebuilt, so a partial failure is retried.
			if ( Helpers::recompute_all_for_day( $day ) ) {
				$processed[] = (string) $day_str;
			}
		}

		if ( ! $processed ) {
			return;
		}

		self::update_dirty_days(
			static function ( array $current ) use ( $processed ): array {

				return array_values( array_diff( $current, $processed ) );
			}
		);

		if ( get_option( self::DIRTY_DAYS_OPTION, [] ) ) {
			$this->reschedule_drain();
		}

		Cache::invalidate_all_presets();
	}

	/**
	 * Re-enqueue the drain from within the currently running drain action.
	 *
	 * Bypasses the stale per-request Tasks::is_scheduled() snapshot.
	 *
	 * @since 2.0.2
	 */
	private function reschedule_drain(): void {

		// Query pending-only. as_next_scheduled_action() also matches RUNNING,
		// which always finds the currently-executing action and blocks rescheduling.
		$pending = ActionScheduler::store()->query_action(
			[
				'hook'   => self::DRAIN_ACTION,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'group'  => Tasks::GROUP,
			]
		);

		if ( $pending ) {
			return;
		}

		if ( ! $this->tasks ) {
			return;
		}

		// Schedule 5s in the future to prevent AS from chaining slices in one request.
		$this->tasks->create( self::DRAIN_ACTION )->once( time() + 5 )->register();
	}

	/**
	 * Drain callback: recompute one budgeted slice of the dirty-day set.
	 *
	 * @since 2.0.2
	 */
	public function process_drain(): void {

		// Only run during AJAX / cron — never block a regular admin page load.
		if ( ! wp_doing_ajax() && ! wp_doing_cron() ) {
			return;
		}

		if ( ! RollupRepository::tables_exist() ) {
			return;
		}

		$this->drain_dirty_days();
	}

	/**
	 * Get "today" in site-local time.
	 *
	 * @since 2.0.2
	 *
	 * @return DateTimeImmutable
	 */
	private function today(): DateTimeImmutable {

		return date_create_immutable( 'now', wp_timezone() );
	}
}
