<?php

namespace WPForms\Pro\Admin\Dashboard;

use WPForms\Pro\Db\Dashboard\RollupReadiness;
use WPForms\Pro\Tasks\Actions\DashboardBackfillTask;

/**
 * Dashboard rollup readiness heartbeat handler.
 *
 * Registered separately from the Dashboard Page so the heartbeat filter runs
 * during admin-ajax requests without being gated by Page::allow_load().
 *
 * @since 2.0.2
 */
class Heartbeat {

	/**
	 * Register hooks.
	 *
	 * @since 2.0.2
	 */
	public function hooks(): void {

		add_filter( 'heartbeat_received', [ $this, 'handle_tick' ], 10, 3 );
	}

	/**
	 * Nudge the rollup backfill forward on each heartbeat tick, and return current rollup
	 * readiness on the dashboard screen.
	 *
	 * @since 2.0.2
	 *
	 * @param array  $response  Heartbeat response.
	 * @param array  $data      Heartbeat request data (unused).
	 * @param string $screen_id Current screen ID.
	 *
	 * @return array
	 */
	public function handle_tick( $response, $data, $screen_id ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found

		$response = (array) $response;

		if ( ! wpforms_current_user_can() ) {
			return $response;
		}

		// Keep the backfill draining while an admin is present.
		DashboardBackfillTask::kick();

		if ( $screen_id !== 'wpforms_page_wpforms-dashboard' ) {
			return $response;
		}

		$state = ( new RollupReadiness() )->get_state();

		$response['wpforms_dashboard_rollup_ready'] = $state;

		return $response;
	}
}
