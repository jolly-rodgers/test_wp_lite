<?php

namespace WPForms\Pro\Admin\Dashboard;

use WPForms\Admin\Dashboard\Page as PageBase;
use WPForms\Admin\Helpers\Datepicker;
use WPForms\Pro\Db\Dashboard\RollupReadiness;

/**
 * Dashboard page (Pro) — adds the date-range selector.
 *
 * @since 2.0.2
 */
class Page extends PageBase {

	/**
	 * Rollup readiness instance, lazy-initialized.
	 *
	 * @since 2.0.2
	 *
	 * @var RollupReadiness|null
	 */
	private $readiness;

	/**
	 * Get the rollup readiness instance, instantiating it once per request.
	 *
	 * @since 2.0.2
	 *
	 * @return RollupReadiness
	 */
	private function get_readiness(): RollupReadiness {

		if ( $this->readiness === null ) {
			$this->readiness = new RollupReadiness();
		}

		return $this->readiness;
	}

	/**
	 * Enqueue the Dashboard assets.
	 *
	 * @since 2.0.2
	 */
	public function enqueue_assets(): void {

		wp_enqueue_style(
			'wpforms-flatpickr',
			WPFORMS_PLUGIN_URL . 'assets/lib/flatpickr/flatpickr.min.css',
			[],
			'4.6.9'
		);

		wp_enqueue_script(
			'wpforms-flatpickr',
			WPFORMS_PLUGIN_URL . 'assets/lib/flatpickr/flatpickr.min.js',
			[ 'jquery' ],
			'4.6.9',
			false
		);

		// Enables live heartbeat updates of rollup backfill coverage.
		wp_enqueue_script( 'heartbeat' );

		// Chart.js + the donut module are enqueued by the base page for every widget
		// state (sample preview or live data).
		parent::enqueue_assets();
	}

	/**
	 * Get the Dashboard script dependencies, adding flatpickr.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	protected function get_script_dependencies(): array {

		$deps = array_merge( parent::get_script_dependencies(), [ 'wpforms-flatpickr' ] );

		if ( wp_script_is( 'wpforms-education-feature-tooltip', 'registered' ) ) {
			$deps[] = 'wpforms-education-feature-tooltip';
		}

		return $deps;
	}

	/**
	 * Get the localized data, adding datepicker/updater modules and flatpickr config.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	protected function get_localized_data(): array {

		$data = parent::get_localized_data();
		$min  = wpforms_get_min_suffix();

		// Append the Basic+ modules to the base manifest (which already carries the
		// shared gear-menu module) — do not replace it.
		$data['modules'][] = [
			'name' => 'updater',
			'path' => WPFORMS_PLUGIN_URL . "assets/pro/js/admin/dashboard/updater$min.js",
		];

		$data['modules'][] = [
			'name' => 'datepicker',
			'path' => WPFORMS_PLUGIN_URL . "assets/pro/js/admin/dashboard/datepicker$min.js",
		];

		$data['modules'][] = [
			'name' => 'statCards',
			'path' => WPFORMS_PLUGIN_URL . "assets/js/admin/dashboard/modules/stat-cards$min.js",
		];

		$data['flatpickr'] = [
			'delimiter' => Datepicker::TIMESPAN_DELIMITER,
			'locale'    => sanitize_key( wpforms_get_language_code() ),
		];

		$data['readiness'] = $this->get_readiness()->get_state();

		// The widget title this graph sits under is `wpforms`-domain, so its label must be too.
		$data['i18n']['entries'] = __( 'Entries', 'wpforms' );

		// Date-range fetch failure notices (updater.js).
		$data['i18n']['session_expired'] = __( 'Couldn\'t load data for this date range because your session expired. Reload the page to try again. The stats below are still from your previous selection.', 'wpforms' );
		$data['i18n']['network_failure'] = __( 'Couldn\'t load data for this date range. The stats below are still from your previous selection.', 'wpforms' );
		$data['i18n']['reload']          = __( 'Reload Page', 'wpforms' );
		$data['i18n']['retry']           = __( 'Retry', 'wpforms' );

		return $data;
	}

	/**
	 * Get the date-range datepicker rendered HTML.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_datepicker_html(): string {

		$timespan                            = $this->get_timespan();
		[ $choices, $chosen_filter, $value ] = Datepicker::process_datepicker_choices( $timespan );

		return (string) wpforms_render(
			'admin/components/datepicker',
			[
				'id'            => 'dashboard',
				'action'        => admin_url( 'admin.php?page=wpforms-dashboard' ),
				'chosen_filter' => $chosen_filter,
				'choices'       => $choices,
				'value'         => $value,
				'hidden_fields' => [],
			],
			true
		);
	}

	/**
	 * Get the date-range readiness notice HTML.
	 *
	 * Rendered only while the rollup backfill is still processing.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function get_date_range_notice_html(): string {

		if ( $this->get_readiness()->get_state()['complete'] ) {
			return '';
		}

		return (string) wpforms_render( 'admin/dashboard/date-range-notice' );
	}

	/**
	 * Resolve the selected timespan, adapting the default to rollup coverage.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	protected function get_timespan(): array {

		$timespan = Datepicker::process_timespan();

		// An explicit ?date= range is a user choice — never override it.
		if ( filter_input( INPUT_GET, 'date', FILTER_SANITIZE_FULL_SPECIAL_CHARS ) ) {
			return $timespan;
		}

		$days  = (int) ( $timespan[2] ?? 0 );
		$ready = array_map( 'intval', $this->get_readiness()->ready_presets() );

		// Nothing warmed yet, or the default preset is already covered — keep the default.
		if ( ! $ready || in_array( $days, $ready, true ) ) {
			return $timespan;
		}

		// Coverage is contiguous back from today, so the largest ready preset is the deepest range.
		return Datepicker::get_timespan_dates( (string) max( $ready ) );
	}
}
