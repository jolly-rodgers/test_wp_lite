<?php

namespace WPForms\Pro\Emails\Summaries;

use WPForms\Emails\Summaries\Data as BaseData;
use WPForms\Emails\Summaries\Milestones;
use WPForms\Emails\Summaries\Week;
use WPForms\Pro\Analytics\Analytics;
use WPForms\Pro\Db\Analytics\DB;
use WPForms\Pro\Reports\EntriesCount;

/**
 * Weekly summary metrics (Pro).
 *
 * @since 2.0.3
 */
class Data extends BaseData {

	/**
	 * Per-form rows: forms with entries this or last week, ordered by entries descending, no cap.
	 *
	 * Built from the weekly buckets the headlines and drop detection read, so every number shares one window.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return array
	 */
	protected function get_forms( Week $week ): array {

		[ $current, $previous ] = $this->get_weekly_by_form( $week, 2 );

		$rows = [];

		foreach ( array_keys( $current + $previous ) as $form_id ) {
			$count          = $current[ $form_id ] ?? 0;
			$count_previous = $previous[ $form_id ] ?? 0;

			$rows[ $form_id ] = (object) [
				'count'               => $count,
				'count_previous_week' => $count_previous,
				'trends'              => self::percent_delta( $count, $count_previous ),
			];
		}

		uasort(
			$rows,
			static function ( $a, $b ): int {

				return $b->count <=> $a->count;
			}
		);

		return $this->map_form_rows( ( new EntriesCount() )->fill_forms_list_form_data( $rows ) );
	}

	/**
	 * Spam entries blocked during the week, as the Dashboard counts them.
	 *
	 * @since 2.0.3
	 *
	 * @param array $aggregates Dashboard aggregates for the week.
	 *
	 * @return int|null
	 */
	protected function get_spam( array $aggregates ): ?int {

		return (int) ( $aggregates['stats']['spam_entries'] ?? 0 );
	}

	/**
	 * Abandoned entries captured during the week. Null unless Form Abandonment is enabled on a form.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to report on.
	 *
	 * @return int|null
	 */
	protected function get_leads_saved( Week $week ): ?int {

		return $this->count_abandoned( $week );
	}

	/**
	 * Abandoned entries ever captured, whatever the current Form Abandonment settings.
	 *
	 * Settings-independent on purpose: the milestone baseline must see leads captured while the
	 * addon was active, or re-enabling it would celebrate an old lead as the first one.
	 *
	 * @since 2.0.3
	 *
	 * @return int|null
	 */
	protected function get_lifetime_leads(): ?int {

		return $this->count_entries( [ 'status' => 'abandoned' ] );
	}

	/**
	 * Spam entries ever blocked.
	 *
	 * @since 2.0.3
	 *
	 * @return int|null
	 */
	protected function get_lifetime_spam(): ?int {

		return $this->count_entries( [ 'status' => 'spam' ] );
	}

	/**
	 * Entries ever collected on published forms, spam and trash excluded, matching the weekly tile.
	 *
	 * @since 2.0.3
	 *
	 * @return int
	 */
	protected function get_lifetime_entries(): int {

		$form  = wpforms()->obj( 'form' );
		$forms = $form ? (array) $form->get( '', [ 'fields' => 'ids' ] ) : [];

		return $forms ? ( $this->count_entries( [ 'form_id' => $forms ] ) ?? 0 ) : 0;
	}

	/**
	 * Count entries matching the given arguments. Null when the entry handler is unavailable.
	 *
	 * @since 2.0.3
	 *
	 * @param array $args Entry query arguments.
	 *
	 * @return int|null
	 */
	private function count_entries( array $args ): ?int {

		$entry = wpforms()->obj( 'entry' );

		return $entry ? (int) $entry->get_entries( $args, true ) : null;
	}

	/**
	 * Weekly entry counts per form from the entries table, in one GROUP BY query.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week  Most recent week.
	 * @param int  $weeks Number of weeks including the given one.
	 *
	 * @return array
	 */
	public function get_weekly_by_form( Week $week, int $weeks ): array {

		if ( $weeks < 1 ) {
			return [];
		}

		$history = array_fill( 0, $weeks, [] );

		foreach ( $this->query_weekly_counts( $week, $weeks ) as $row ) {
			$index = (int) ( $row['week_index'] ?? -1 );

			if ( isset( $history[ $index ] ) ) {
				$history[ $index ][ (int) $row['form_id'] ] = (int) $row['count'];
			}
		}

		return $history;
	}

	/**
	 * Per-form entry counts for the trailing weeks, bucketed in a single query.
	 *
	 * Mirrors `EntriesCount::get_by( 'form' )` filters — published forms only, spam and trash
	 * excluded — but groups all weeks in one round trip instead of one query, with a forms
	 * `WP_Query`, per week. The date window follows `Week` (site timezone, no clock injection),
	 * the same bounds the rest of the summary already reports on.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week  Most recent week.
	 * @param int  $weeks Number of weeks including the given one.
	 *
	 * @return array Rows with `form_id`, `week_index`, and `count`.
	 */
	private function query_weekly_counts( Week $week, int $weeks ): array {

		global $wpdb;

		$entry = wpforms()->obj( 'entry' );
		$form  = wpforms()->obj( 'form' );
		$forms = $form ? (array) $form->get( '', [ 'fields' => 'ids' ] ) : [];

		if ( ! $entry || ! $forms ) {
			return [];
		}

		$bounds            = $this->query_week_bounds( $week, $weeks );
		$table             = $entry->table_name;
		$form_placeholders = implode( ', ', array_fill( 0, count( $forms ), '%d' ) );
		$statuses          = EntriesCount::EXCLUDED_STATUSES;
		$status_holders    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$case_parts        = [];
		$case_values       = [];

		foreach ( $bounds['week_ranges'] as $index => $range ) {
			$case_parts[]  = 'WHEN date >= %s AND date <= %s THEN %d';
			$case_values[] = $range['start_value'];
			$case_values[] = $range['end_value'];
			$case_values[] = $index;
		}

		$week_case = 'CASE ' . implode( ' ', $case_parts ) . ' ELSE -1 END';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$sql = $wpdb->prepare(
			"SELECT form_id,
				$week_case AS week_index,
				COUNT( entry_id ) AS count
			FROM $table
			WHERE form_id IN ( $form_placeholders )
				AND date >= %s AND date <= %s
				AND status NOT IN ( $status_holders )
			GROUP BY form_id, week_index",
			array_merge(
				$case_values,
				array_map( 'absint', $forms ),
				[ $bounds['start_value'], $bounds['end_value'] ],
				$statuses
			)
		);

		return (array) $wpdb->get_results( $sql, ARRAY_A );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
	}

	/**
	 * UTC query bounds and individual weekly buckets for the trailing weeks.
	 *
	 * Each bucket converts its own local boundaries to UTC, preserving historical DST offsets.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week  Most recent week.
	 * @param int  $weeks Number of weeks including the given one.
	 *
	 * @return array Keys `start_value`, `end_value`, and `week_ranges`.
	 */
	private function query_week_bounds( Week $week, int $weeks ): array {

		$week_ranges = [];

		for ( $index = 0; $index < $weeks; $index++ ) {
			$bucket = $index === 0 ? $week : $week->previous( $index );

			[ $start, $end ] = $bucket->get_utc_bounds();

			$week_ranges[] = [
				'start_value' => $start,
				'end_value'   => $end,
			];
		}

		return [
			'start_value' => $week_ranges[ $weeks - 1 ]['start_value'],
			'end_value'   => $week_ranges[0]['end_value'],
			'week_ranges' => $week_ranges,
		];
	}

	/**
	 * Conversion-rate goal set for a form on the Analytics page, in percent. Null when none.
	 *
	 * @since 2.0.3
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return float|null
	 */
	protected function get_form_goal( int $form_id ): ?float {

		$goal = get_post_meta( $form_id, 'wpforms_analytics_goal', true );

		return isset( $goal['conversion_rate'] ) && is_numeric( $goal['conversion_rate'] ) ? (float) $goal['conversion_rate'] : null;
	}

	/**
	 * Abandoned entries captured during a week. Null unless Form Abandonment is enabled on a form.
	 *
	 * @since 2.0.3
	 *
	 * @param Week $week Week to count within.
	 *
	 * @return int|null
	 */
	private function count_abandoned( Week $week ): ?int {

		if ( ! wpforms_is_addon_initialized( 'form-abandonment' ) || ! $this->has_form_with_setting( 'form_abandonment' ) ) {
			return null;
		}

		return $this->count_entries(
			[
				'status' => 'abandoned',
				'date'   => [ $week->get_start()->format( 'Y-m-d' ), $week->get_end()->format( 'Y-m-d' ) ],
			]
		);
	}

	/**
	 * Whether the license tier may see conversion rates.
	 *
	 * @since 2.0.3
	 *
	 * @return bool
	 */
	protected function is_conversion_allowed(): bool {

		return Analytics::is_allowed();
	}

	/**
	 * Whether any published form has a settings toggle switched on.
	 *
	 * @since 2.0.3
	 *
	 * @param string $setting Form settings key.
	 *
	 * @return bool
	 */
	private function has_form_with_setting( string $setting ): bool {

		return $this->has_form_matching(
			static function ( array $form_data ) use ( $setting ): bool {

				return ! empty( $form_data['settings'][ $setting ] );
			}
		);
	}

	/**
	 * Forms that met their conversion goal in at least one week on record, for the milestone baseline.
	 *
	 * One trend query per form with a goal set; the baseline runs once per site. Historical goals
	 * remain spent even when the current tier cannot display conversion data.
	 *
	 * @since 2.0.3
	 *
	 * @param string|null $to Last day to scan, Y-m-d; today when null.
	 *
	 * @return int[]
	 */
	public function get_lifetime_goal_forms( ?string $to = null ): array {

		$form_handler = wpforms()->obj( 'form' );
		$analytics    = wpforms()->obj( 'analytics_db' );

		if ( ! $form_handler || ! $analytics instanceof DB ) {
			return [];
		}

		$to  = $to ?? (string) current_time( 'Y-m-d' );
		$met = [];

		foreach ( (array) $form_handler->get( '', [ 'fields' => 'ids' ] ) as $form_id ) {
			$goal = $this->get_form_goal( (int) $form_id );

			if ( $goal !== null && $this->has_met_goal( $analytics, (int) $form_id, $goal, $to ) ) {
				$met[] = (int) $form_id;
			}
		}

		return $met;
	}

	/**
	 * Whether a form converted at or above its goal in any week up to the given day.
	 *
	 * @since 2.0.3
	 *
	 * @param DB     $analytics Analytics store.
	 * @param int    $form_id   Form ID.
	 * @param float  $goal      Conversion-rate goal, in percent.
	 * @param string $to        Last day to scan, Y-m-d.
	 *
	 * @return bool
	 */
	private function has_met_goal( DB $analytics, int $form_id, float $goal, string $to ): bool {

		$weeks = $analytics->get_form_stats_trend( $form_id, '1970-01-01', $to, 'week' );

		foreach ( $weeks as $week ) {
			$row = [
				'goal'       => $goal,
				'views'      => $week['views'] ?? 0,
				'conversion' => $week['conversion_rate'] ?? 0,
			];

			if ( Milestones::meets_goal( $row ) ) {
				return true;
			}
		}

		return false;
	}
}
