<?php

namespace WPForms\Pro\Db\Dashboard;

use WPForms\Admin\Dashboard\Cache;
use WPForms\Pro\Tasks\Actions\DashboardRollupTask;

/**
 * Derives datepicker readiness from the rollup watermark during cold-start backfill.
 *
 * @since 2.0.2
 */
class RollupReadiness {

	/**
	 * Preset keys whose full window is covered by the watermark.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	public function ready_presets(): array {

		if ( get_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION ) ) {
			return Cache::PRESET_RANGES;
		}

		$watermark = get_option( DashboardRollupTask::WATERMARK_OPTION, '' );

		if ( $watermark === '' ) {
			return [ '0' ];
		}

		$today = date_create_immutable( 'now', wp_timezone() )->setTime( 0, 0, 0 );
		$w     = date_create_immutable( (string) $watermark, wp_timezone() );

		if ( $w === false ) {
			return [ '0' ];
		}

		$ready = [];

		foreach ( Cache::PRESET_RANGES as $days ) {
			if ( $w <= $today->modify( '-' . (int) $days . ' day' ) ) {
				$ready[] = $days;
			}
		}

		return $ready;
	}

	/**
	 * Earliest selectable calendar date (Y-m-d), or '' when nothing is warmed.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	private function min_date(): string {

		return (string) get_option( DashboardRollupTask::WATERMARK_OPTION, '' );
	}

	/**
	 * Payload emitted by both wp_localize_script and the Heartbeat filter.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	public function get_state(): array {

		return [
			'presets'  => $this->ready_presets(),
			'min_date' => $this->min_date(),
			'complete' => (bool) get_option( DashboardRollupTask::BACKFILL_COMPLETE_OPTION, false ),
		];
	}
}
