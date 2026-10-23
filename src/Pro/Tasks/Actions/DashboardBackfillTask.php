<?php

namespace WPForms\Pro\Tasks\Actions;

use ActionScheduler;
use ActionScheduler_Store;
use DateTimeImmutable;
use WPForms\Admin\Dashboard\Cache;
use WPForms\Helpers\DB;
use WPForms\Pro\Db\Dashboard\Helpers;
use WPForms\Pro\Db\Dashboard\RollupRecomputer;
use WPForms\Pro\Db\Dashboard\RollupRepository;
use WPForms\Tasks\Task;
use WPForms\Tasks\Tasks;
use WPForms_DB;

/**
 * One-off, self-rescheduling Dashboard rollup backfill task.
 *
 * @since 2.0.2
 */
class DashboardBackfillTask extends Task {

	/**
	 * Action Scheduler action name.
	 *
	 * @since 2.0.2
	 */
	public const ACTION = 'wpforms_dashboard_rollup_backfill';

	/**
	 * Soft wall-clock budget (seconds) per drain run.
	 *
	 * @since 2.0.2
	 */
	public const SOFT_BUDGET_SECONDS = 25.0;

	/**
	 * Maximum number of days the backfill will ever walk back (target depth = largest preset).
	 *
	 * @since 2.0.2
	 */
	public const DAY_CAP = 365;

	/**
	 * Class constructor.
	 *
	 * @since 2.0.2
	 */
	public function __construct() {

		parent::__construct( self::ACTION );

		$this->hooks();
	}

	/**
	 * Bind the drain action to process().
	 *
	 * @since 2.0.2
	 */
	private function hooks(): void {

		add_action( self::ACTION, [ $this, 'process' ] );
	}

	/**
	 * Enqueue a single async drain run, unless already scheduled or complete.
	 *
	 * @since 2.0.2
	 */
	public static function kick(): void {

		if ( get_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION ) ) {
			return;
		}

		$tasks = wpforms()->obj( 'tasks' );

		if ( ! $tasks || $tasks->is_scheduled( self::ACTION ) !== false ) {
			return;
		}

		$tasks->create( self::ACTION )->async()->register();
	}

	/**
	 * Re-enqueue the next backfill slice from within the currently running action.
	 *
	 * Unlike kick(), this bypasses the stale per-request Tasks::is_scheduled()
	 * snapshot by querying Action Scheduler directly.
	 *
	 * @since 2.0.2
	 */
	private static function reschedule(): void {

		if ( get_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION ) ) {
			return;
		}

		// Query the AS store directly for pending-only actions.
		// as_next_scheduled_action() also matches RUNNING actions (returns true),
		// which always finds the currently-executing action and blocks rescheduling.
		$pending = ActionScheduler::store()->query_action(
			[
				'hook'   => self::ACTION,
				'status' => ActionScheduler_Store::STATUS_PENDING,
				'group'  => Tasks::GROUP,
			]
		);

		if ( $pending ) {
			return;
		}

		$tasks = wpforms()->obj( 'tasks' );

		if ( ! $tasks ) {
			return;
		}

		// Schedule 5s in the future so the AS queue runner doesn't chain multiple
		// 25s slices in a single request (which would exceed max_execution_time).
		$tasks->create( self::ACTION )->once( time() + 5 )->register();
	}

	/**
	 * Drain callback: build indexes once, then recompute time-budgeted slices until done.
	 *
	 * @since 2.0.2
	 */
	public function process(): void {

		// Only run during AJAX (heartbeat / AS async runner) or cron — never block
		// a regular admin page load with a 25s recompute slice.
		if ( ! wp_doing_ajax() && ! wp_doing_cron() ) {
			return;
		}

		if ( ! RollupRepository::tables_exist() || ! wpforms()->obj( 'entry' ) || ! wpforms()->obj( 'entry_meta' ) ) {
			return;
		}

		// Captured before the watermark read to detect mid-flight GMT-offset resets.
		$generation = (int) get_option( DashboardRollupTask::GENERATION_OPTION, 0 );

		// Index build yields — re-enqueue so the data slice runs next.
		if ( $this->maybe_build_indexes() ) {
			self::reschedule();

			return;
		}

		$watermark = (string) get_option( DashboardRollupTask::WATERMARK_OPTION, '' );

		// Bootstrap: if warm_up_inline() died before writing a watermark,
		// seed it to today so the backfill can walk backward.
		if ( $watermark === '' ) {
			$today = date_create_immutable( 'now', wp_timezone() )->setTime( 0, 0, 0 );

			// The watermark declares coverage from that day forward, but nothing has built
			// today yet — the walk below starts at the day *before* it. Build it first and
			// leave the watermark unset on failure, so coverage is never claimed for a day
			// with no rows behind it.
			if ( ! Helpers::recompute_all_for_day( $today ) ) {
				return;
			}

			$watermark = $today->format( 'Y-m-d' );

			update_option( DashboardRollupTask::WATERMARK_OPTION, $watermark, false );
			update_option( DashboardRollupTask::TIMEZONE_OPTION, wp_timezone()->getName(), false );
		}

		$floor = $this->get_floor_day();

		if ( $floor === null || $watermark <= $floor ) {
			update_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION, true, false );

			return;
		}

		$first_day = date_create_immutable( $watermark, wp_timezone() )->modify( '-1 day' );

		$recomputed_to = ( new RollupRecomputer() )->recompute_back( $first_day, 0, self::SOFT_BUDGET_SECONDS, $floor );

		if ( $recomputed_to === null ) {
			return;
		}

		// Concurrent GMT-offset reset invalidated this slice. Discard and re-kick.
		if ( (int) get_option( DashboardRollupTask::GENERATION_OPTION, 0 ) !== $generation ) {
			self::reschedule();

			return;
		}

		update_option( DashboardRollupTask::WATERMARK_OPTION, $recomputed_to, false );

		Cache::invalidate_all_presets();

		if ( $recomputed_to <= $floor ) {
			update_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION, true, false );

			return;
		}

		// Not done — drain the next slice immediately.
		self::reschedule();
	}

	/**
	 * Oldest day the backfill should reach: the newer of the oldest data day and the `DAY_CAP`.
	 *
	 * Checks both entries and payments tables so payments-only sites and sites
	 * with short entry retention still backfill the full payment history.
	 *
	 * @since 2.0.2
	 *
	 * @return string|null Floor day (`Y-m-d`), or null when there are no entries or payments.
	 */
	private function get_floor_day(): ?string {

		$oldest = RollupRecomputer::oldest_data_day();

		if ( $oldest === null ) {
			return null;
		}

		$cap = ( new DateTimeImmutable( 'now', wp_timezone() ) )->setTime( 0, 0, 0 )->modify( '-' . self::DAY_CAP . ' days' )->format( 'Y-m-d' );

		return max( $oldest, $cap );
	}

	/**
	 * Build supporting indexes once, skipping if already built or permanently failed.
	 *
	 * @since 2.0.2
	 *
	 * @return bool Whether an index was actually built.
	 */
	private function maybe_build_indexes(): bool {

		if ( get_option( DashboardRollupTask::INDEXES_BUILT_OPTION ) ) {
			return false;
		}

		$failures = (int) get_option( 'wpforms_dashboard_index_build_failures', 0 );

		if ( $failures >= 3 ) {
			return false;
		}

		$built = self::build_indexes();

		if ( ! $built && ! get_option( DashboardRollupTask::INDEXES_BUILT_OPTION ) ) {
			update_option( 'wpforms_dashboard_index_build_failures', $failures + 1, false );
		}

		return $built;
	}

	/**
	 * Build supporting indexes on the raw entries/entry-meta/payments tables.
	 *
	 * @since 2.0.2
	 *
	 * @return bool Whether an index was actually built.
	 */
	public static function build_indexes(): bool {

		$entry_obj   = wpforms()->obj( 'entry' );
		$meta_obj    = wpforms()->obj( 'entry_meta' );
		$payment_obj = wpforms()->obj( 'payment' );

		if ( ! $entry_obj || ! $meta_obj || ! $payment_obj ) {
			return false;
		}

		$entries  = $entry_obj->table_name;
		$meta     = $meta_obj->table_name;
		$payments = $payment_obj->table_name;

		$ran = DB::add_index_if_missing( $entries, 'date_form', '( date, form_id )' );
		$ran = DB::add_index_if_missing( $meta, 'type_date', '( type(' . WPForms_DB::MAX_INDEX_LENGTH . '), date )' ) || $ran;
		$ran = DB::add_index_if_missing( $entries, 'status_date', '( status, date )' ) || $ran;
		$ran = DB::add_index_if_missing( $payments, 'created_form', '( date_created_gmt, form_id )' ) || $ran;

		// Persist the built flag only when all four indexes are present.
		if (
			DB::index_exists( $entries, 'date_form' ) &&
			DB::index_exists( $meta, 'type_date' ) &&
			DB::index_exists( $entries, 'status_date' ) &&
			DB::index_exists( $payments, 'created_form' )
		) {
			update_option( DashboardRollupTask::INDEXES_BUILT_OPTION, true, false );
		}

		// Yield only when a slow ALTER ran.
		return $ran;
	}
}
