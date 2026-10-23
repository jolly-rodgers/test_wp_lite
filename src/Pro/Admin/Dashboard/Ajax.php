<?php

namespace WPForms\Pro\Admin\Dashboard;

use WPForms\Admin\Dashboard\Ajax as AjaxBase;
use WPForms\Admin\Dashboard\Cache;
use WPForms\Admin\Dashboard\StatCards;
use WPForms\Admin\Dashboard\Widgets\Entries as EntriesBase;
use WPForms\Pro\Admin\Dashboard\Widgets\Entries as EntriesPro;

/**
 * Dashboard AJAX endpoints (Pro).
 *
 * @since 2.0.2
 */
class Ajax extends AjaxBase {

	/**
	 * Register hooks.
	 *
	 * @since 2.0.2
	 */
	public function hooks(): void {

		parent::hooks();

		add_action( 'wp_ajax_wpforms_dashboard_get_stats', [ $this, 'get_stats' ] );
		add_action( 'wp_ajax_wpforms_dashboard_entries_chart', [ $this, 'get_entries_chart' ] );
		add_action( 'wp_ajax_wpforms_dashboard_entries_chart_reset', [ $this, 'reset_entries_chart' ] );
	}

	/**
	 * Get the cached aggregates for a date range as structured JSON.
	 *
	 * @since 2.0.2
	 */
	public function get_stats(): void {

		$access = $this->validate_request();

		// Nonce verified via validate_request() before this runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );

		[ $start, $end, $days, $label ] = $this->resolve_timespan( $date );

		$data      = wpforms()->obj( 'dashboard_cache' )->get_or_compute( Cache::make_key( $days, $start, $end ), $start, $end );
		$locations = $this->get_locations_widget();
		$entries   = $this->get_entries_widget();

		wp_send_json_success(
			[
				// Display-ready strings — one formatter with the initial render (StatCards::format()).
				'stats'          => $this->get_stat_cards()->format( $data['stats'] ),
				'payments'       => $data['payments'],
				// Top Locations ships pre-rendered rather than as data: its variant can flip with
				// the range (data table ↔ no-data card), which the client cannot patch from values.
				'entries_html'   => $entries ? $entries->render_for_range( $data, $access ) : '',
				'locations_html' => $locations ? $locations->render_for_range( $data, $access ) : '',
				'chosen_filter'  => $label,
			]
		);
	}

	/**
	 * Get the stat cards formatter, registering it on demand. `Page::loader()`
	 * does not run in the AJAX request, so the formatter is not in the container
	 * yet (mirrors how the base resolves the access resolver).
	 *
	 * @since 2.0.2
	 *
	 * @return StatCards
	 */
	private function get_stat_cards(): StatCards {

		if ( ! wpforms()->obj( StatCards::ID ) ) {
			wpforms()->register(
				[
					'name' => 'Admin\Dashboard\StatCards',
					'id'   => StatCards::ID,
					'hook' => false,
					'run'  => false,
				]
			);
		}

		return wpforms()->obj( StatCards::ID );
	}

	/**
	 * Re-scope the Entries widget graph to a single form.
	 *
	 * Fetches the chosen form's entries-by-date series live for the current range —
	 * a per-form-per-range series is never cached — and persists the selection so a
	 * reload (or range change) re-applies it server-side.
	 *
	 * @since 2.0.2
	 */
	public function get_entries_chart(): void {

		$this->validate_request();

		// Nonce verified via validate_request() before this runs.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$date = sanitize_text_field( wp_unslash( $_POST['date'] ?? '' ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified via validate_request() before this runs.
		$form_id = absint( $_POST['form_id'] ?? 0 );

		if ( $form_id <= 0 ) {
			$this->send_error( 'invalid', esc_html__( 'Invalid form.', 'wpforms' ) );
		}

		$widget = $this->get_entries_widget();

		if ( ! $widget ) {
			$this->send_error( 'invalid', esc_html__( 'Unable to load the Entries widget.', 'wpforms' ) );
		}

		[ $start, $end ] = $this->resolve_timespan( $date );

		$graph = $widget->build_form_graph( $form_id, $start, $end );

		$widget->save_active_form_id( $form_id );

		wp_send_json_success(
			[
				'graph'   => $graph,
				'form_id' => $form_id,
			]
		);
	}

	/**
	 * Reset the Entries widget graph back to the site-wide series.
	 *
	 * Drops the persisted per-form selection; the client restores the site-wide
	 * series it already holds in its parsed widget config.
	 *
	 * @since 2.0.2
	 */
	public function reset_entries_chart(): void {

		$this->validate_request();

		$widget = $this->get_entries_widget();

		if ( ! $widget ) {
			$this->send_error( 'invalid', esc_html__( 'Unable to load the Entries widget.', 'wpforms' ) );
		}

		$widget->save_active_form_id( 0 );

		wp_send_json_success();
	}

	/**
	 * Resolve the Entries widget as the Pro subclass. The container returns it on
	 * Pro builds, but the base signature is the Lite type — narrowed here so the
	 * Pro-only graph methods resolve.
	 *
	 * @since 2.0.2
	 *
	 * @return EntriesPro|null
	 */
	protected function get_entries_widget(): ?EntriesBase {

		$widget = parent::get_entries_widget();

		return $widget instanceof EntriesPro ? $widget : null;
	}

	/**
	 * Resolve the Top Locations widget.
	 *
	 * @since 2.0.2
	 *
	 * @return object|null
	 */
	private function get_locations_widget(): ?object {

		return $this->get_gear_widget( 'locations' );
	}
}
