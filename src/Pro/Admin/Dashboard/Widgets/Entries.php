<?php

namespace WPForms\Pro\Admin\Dashboard\Widgets;

use DateTime;
use DateTimeImmutable;
use WPForms\Admin\Dashboard\AccessContext;
use WPForms\Admin\Dashboard\Widgets\Entries as EntriesBase;
use WPForms\Admin\Helpers\Datepicker;
use WPForms\Integrations\LiteConnect\Integration as LiteConnectIntegration;
use WPForms\Pro\Reports\EntriesCount;

/**
 * Dashboard "Entries" widget (Pro).
 *
 * Extends the Lite base with the Pro title and the tier-aware table columns:
 * real entries linked to the entries page, views linked to Form Analytics on
 * Pro/Elite, the Interactions/Conversion metrics unlocked on Pro/Elite (locked on
 * Basic/Plus), and the per-row Graph column. Registers automatically when the Pro
 * plugin is active (FQCN resolution of `Admin\Dashboard\Widgets\Entries`).
 *
 * @since 2.0.2
 */
class Entries extends EntriesBase {

	/**
	 * License tiers that unlock the Form Analytics Interactions/Conversion metrics.
	 *
	 * @since 2.0.2
	 */
	private const ANALYTICS_TIERS = [ 'pro', 'elite', 'agency', 'ultimate' ];

	/**
	 * Memoized resolved active-form selection. Null until first resolved; the
	 * render path resolves it several times per request (the table markup, then
	 * the scoped-series lookup), and every resolve re-validates against the
	 * displayed rows.
	 *
	 * @since 2.0.2
	 *
	 * @var int|null
	 */
	private $active_form_id;

	/**
	 * Get the widget title.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	public function get_title(): string {

		return __( 'Entries', 'wpforms' );
	}

	/**
	 * Render the widget body per variant.
	 *
	 * Overrides the empty ("No Forms") state with the Basic+ copy and the "Import
	 * Forms & Entries" secondary button; the filled data state falls through to the base.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param array         $data    Aggregated dashboard data.
	 * @param AccessContext $access  Access context.
	 *
	 * @return string
	 */
	protected function render_body( string $variant, array $data, AccessContext $access ): string {

		if ( $variant !== 'data' ) {
			return $this->render_empty_state(
				__( 'No Entry Data, Yet', 'wpforms' ),
				__( 'Once you start getting entries, a real chart with entry statistics will be shown here.', 'wpforms' ),
				$this->get_empty_state_ctas( __( 'Create a Form', 'wpforms' ), __( 'Import Forms & Entries', 'wpforms' ) )
			);
		}

		return parent::render_body( $variant, $data, $access );
	}

	/**
	 * Render the entry-backup bar (Pro override): offer to restore the entries
	 * Lite Connect backed up before the upgrade.
	 *
	 * Nothing renders unless a restore is actually outstanding, so a site that
	 * never ran Lite Connect — or has already restored — shows no bar at all.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	protected function render_entries_backup_bar(): string {

		if ( ! $this->is_lite_connect_restore_available() ) {
			return '';
		}

		return (string) wpforms_render(
			'admin/dashboard/widgets/entries-lite-connect-restore',
			[
				'entries_since_info' => $this->get_lite_connect_restore_since_info(),
				// The bar re-renders inside the stats AJAX request on a range change, where a
				// base-less add_query_arg() would build the link off admin-ajax.php.
				'restore_url'        => add_query_arg(
					[
						'page'                        => 'wpforms-dashboard',
						'wpforms_lite_connect_action' => 'import',
						'_wpnonce'                    => wp_create_nonce( 'wpforms_lite_connect_action' ),
					],
					admin_url( 'admin.php' )
				),
			],
			true
		);
	}

	/**
	 * Whether backed-up entries are waiting to be restored.
	 *
	 * Mirrors the gates the page-level restore notice uses
	 * (`Pro\Integrations\LiteConnect\Admin::display_import_notices()`): a restore
	 * needs an active license and a usable Action Scheduler, must not already be
	 * scheduled, running or done, and must have something left to import.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	private function is_lite_connect_restore_available(): bool {

		// Any recorded status means a restore is already scheduled, running or finished.
		if ( ! empty( $this->get_lite_connect_import_option()['status'] ) ) {
			return false;
		}

		// A stored wizard consent means the restore is queued and will start itself.
		if ( ! empty( $this->get_lite_connect_import_option()['pending_consent'] ) ) {
			return false;
		}

		// Resolved before use: the license object is only registered on an admin or
		// REST-install request (see `WPForms_Pro::objects()`), so it is absent anywhere else,
		// and an unavailable object cannot confirm a restore is possible.
		$license = wpforms()->obj( 'license' );

		if ( ! $license || ! $license->is_active() ) {
			return false;
		}

		$tasks = wpforms()->obj( 'tasks' );

		if ( ! $tasks || ! $tasks->is_usable() ) {
			return false;
		}

		return LiteConnectIntegration::get_new_entries_count() > 0;
	}

	/**
	 * Read the Lite Connect `import` settings sub-array.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	private function get_lite_connect_import_option(): array {

		return (array) wpforms_setting( 'import', [], LiteConnectIntegration::get_option_name() );
	}

	/**
	 * Build the "N entries backed up since <date>" line for the restore bar.
	 *
	 * Separate from the Lite base's builder because Pro uses its own text domain.
	 * The result is escaped here, so the template must echo it raw — re-escaping
	 * renders entities literally in translated strings.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	private function get_lite_connect_restore_since_info(): string {

		$entries_count = LiteConnectIntegration::get_new_entries_count();
		$enabled_since = LiteConnectIntegration::get_enabled_since();

		$string = sprintf(
			esc_html( /* translators: %d - backed up entries count. */
				_n(
					'%d entry backed up',
					'%d entries backed up',
					$entries_count,
					'wpforms'
				)
			),
			absint( $entries_count )
		);

		if ( empty( $enabled_since ) ) {
			return $string;
		}

		$string .= ' ';

		return $string . esc_html(
			sprintf( /* translators: %1$s - time when Lite Connect was enabled. */
				__( 'since %1$s', 'wpforms' ),
				wpforms_date_format( $enabled_since, '', true )
			)
		);
	}

	/**
	 * Get the graph no-data notice copy (Pro override): Basic+ has the dashboard
	 * date-range picker, so the copy points at the selected period.
	 *
	 * @since 2.0.2
	 *
	 * @return array Notice copy: heading, description.
	 */
	protected function get_graph_notice(): array {

		return [
			'heading'     => __( 'No Entries for Selected Period', 'wpforms' ),
			'description' => __( 'Please select a different period or check back later.', 'wpforms' ),
		];
	}

	/**
	 * Get the table column configuration for the current tier (Pro override).
	 *
	 * @since 2.0.2
	 *
	 * @param AccessContext $access Access context.
	 *
	 * @return array
	 */
	protected function get_table_config( AccessContext $access ): array {

		$is_pro_analytics = in_array( $access->get_tier(), self::ANALYTICS_TIERS, true );

		return [
			'columns'           => [
				[
					'key'    => 'name',
					'label'  => __( 'Form Names', 'wpforms' ),
					'linked' => true,
				],
				[
					'key'    => 'entries',
					'label'  => __( 'Entries', 'wpforms' ),
					'linked' => true,
				],
				[
					'key'    => 'views',
					'label'  => __( 'Views', 'wpforms' ),
					// Views link to the form's Form Analytics page on Pro/Elite only.
					'linked' => $is_pro_analytics,
				],
				[
					'key'   => 'interactions',
					'label' => __( 'Interactions', 'wpforms' ),
				],
				[
					'key'   => 'conversion',
					'label' => __( 'Conversion', 'wpforms' ),
				],
			],
			'is_pro_analytics'  => $is_pro_analytics,
			'show_graph_column' => true,
		];
	}

	/**
	 * Build a zero-filled row for a selected form absent from the cached superset.
	 *
	 * Adds the form's entries-page URL the base omits, so the zero-count entries
	 * cell links to the same target every populated row uses.
	 *
	 * @since 2.0.2
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array
	 */
	protected function get_zero_filled_row( int $form_id ): array {

		$row = parent::get_zero_filled_row( $form_id );

		$row['edit_url'] = add_query_arg(
			[
				'page'    => 'wpforms-entries',
				'view'    => 'list',
				'form_id' => $form_id,
			],
			admin_url( 'admin.php' )
		);

		return $row;
	}

	/**
	 * Resolve the form ID the graph is currently scoped to.
	 *
	 * Reads the persisted selection and validates it against the displayed rows: a
	 * selection whose form is not shown (deleted or filtered out of the table), or
	 * shows no entries in the range (the table renders no Graph buttons for it), is
	 * ignored and the graph falls back to site-wide — so the scope the server honors
	 * always has a visible active button and a reachable reset.
	 *
	 * @since 2.0.2
	 *
	 * @param array $data Aggregated dashboard data.
	 *
	 * @return int
	 */
	protected function get_active_form_id( array $data ): int {

		// Resolved once per request; every caller passes the same cached data.
		if ( $this->active_form_id !== null ) {
			return $this->active_form_id;
		}

		$this->active_form_id = 0;

		$stored = (int) ( $this->get_settings()[ self::ACTIVE_FORM_SETTING ] ?? 0 );

		if ( $stored <= 0 ) {
			return 0;
		}

		// Only honor the selection while its form is displayed with entries in range.
		foreach ( $this->get_table_rows( $data ) as $row ) {
			if ( $stored === (int) ( $row['form_id'] ?? 0 ) && (int) ( $row['entries'] ?? 0 ) > 0 ) {
				$this->active_form_id = $stored;

				return $stored;
			}
		}

		return 0;
	}

	/**
	 * Persist (or, with `0`, clear) the graph's active-form selection in the
	 * widget settings.
	 *
	 * @since 2.0.2
	 *
	 * @param int $form_id Form ID to persist; `0` clears the selection.
	 */
	public function save_active_form_id( int $form_id ): void {

		$settings = $this->get_settings();

		if ( $form_id > 0 ) {
			$settings[ self::ACTIVE_FORM_SETTING ] = $form_id;
		} else {
			unset( $settings[ self::ACTIVE_FORM_SETTING ] );
		}

		$this->save_settings( $settings );

		// The render path memoizes the resolved selection; a save must re-resolve it.
		$this->active_form_id = null;
	}

	/**
	 * Resolve the active form's trend series for the selected range.
	 *
	 * The site-wide series is shipped separately (via `get_graph_data()`), so a
	 * reset can restore it client-side; this ships only the scoped series.
	 *
	 * @since 2.0.2
	 *
	 * @param array $data Aggregated dashboard data.
	 *
	 * @return array List of { date: 'Y-m-d', count: int } points, empty when scoped site-wide.
	 */
	protected function get_active_graph( array $data ): array {

		$form_id = $this->get_active_form_id( $data );

		if ( $form_id <= 0 ) {
			return [];
		}

		[ $start, $end ] = $this->get_range_dates( $data );

		if ( ! $start || ! $end ) {
			return [];
		}

		return $this->build_form_graph( $form_id, $start, $end );
	}

	/**
	 * Query a single form's entries-by-date series and reshape it for the chart.
	 *
	 * Shared by the render path and the re-scope AJAX handler. Mirrors the Pro
	 * cache's live entries-graph mapping, including its WP-local date conversion
	 * caveat: `EntriesCount` applies the site's GMT offset itself, so the range
	 * must keep its WP-local wall-clock digits and must not be converted to UTC.
	 *
	 * @since 2.0.2
	 *
	 * @param int               $form_id Form ID to fetch the series for.
	 * @param DateTimeImmutable $start   Range start date, in WP timezone.
	 * @param DateTimeImmutable $end     Range end date, in WP timezone.
	 *
	 * @return array List of { date: 'Y-m-d', count: int } points.
	 */
	public function build_form_graph( int $form_id, DateTimeImmutable $start, DateTimeImmutable $end ): array {

		if ( $form_id <= 0 ) {
			return [];
		}

		$rows  = ( new EntriesCount() )->get_by_date_sql( $form_id, $this->to_mutable_date( $start ), $this->to_mutable_date( $end ) );
		$graph = [];

		foreach ( $rows as $row ) {
			$graph[] = [
				'date'  => (string) $row->day,
				'count' => (int) $row->count,
			];
		}

		return $graph;
	}

	/**
	 * Count entries for the given forms over the range — one query per form.
	 *
	 * The count is live while the superset's may come from the rollup. That cannot
	 * reorder the table: the superset is the top N by count, so these forms sit below it
	 * either way, and `ensure_recent_days_fresh()` keeps the rollup within a day.
	 *
	 * @since 2.0.2
	 *
	 * @param array $form_ids Form IDs to count.
	 * @param array $data     Aggregated dashboard data (reads the range bounds).
	 *
	 * @return array Map of form_id => entry count.
	 */
	protected function get_entry_counts( array $form_ids, array $data ): array {

		[ $start, $end ] = $this->get_range_dates( $data );

		if ( ! $form_ids || ! $start || ! $end ) {
			return [];
		}

		$entries_count = new EntriesCount();
		$date_start    = $this->to_mutable_date( $start );
		$date_end      = $this->to_mutable_date( $end );
		$counts        = [];

		foreach ( $form_ids as $form_id ) {
			$counts[ (int) $form_id ] = $entries_count->get_total( (int) $form_id, $date_start, $date_end );
		}

		return $counts;
	}

	/**
	 * Convert a WP-timezone `DateTimeImmutable` into a mutable `DateTime` with the same
	 * wall-clock digits, without converting the underlying instant.
	 *
	 * `EntriesCount` shifts the date by the site's GMT offset, expecting WP-local digits.
	 * Converting to UTC first would apply that offset twice. Deliberate duplicate of
	 * `Pro\Admin\Dashboard\Cache::to_mutable_date()` — keep the two in sync.
	 *
	 * @since 2.0.2
	 *
	 * @param DateTimeImmutable $date Date in WP timezone.
	 *
	 * @return DateTime
	 */
	private function to_mutable_date( DateTimeImmutable $date ): DateTime {

		return new DateTime( $date->format( Datepicker::DATETIME_FORMAT ) );
	}
}
