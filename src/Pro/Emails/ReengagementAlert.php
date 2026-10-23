<?php

namespace WPForms\Pro\Emails;

use WPForms\Emails\Summaries\Data;
use WPForms\Emails\Summaries\Slot\AbstractBlock;
use WPForms\Emails\Summaries\Week;
use WPForms\Emails\Templates\Summary;

/**
 * Re-engagement alert box for the weekly summary email.
 *
 * Renders a blue alert inside the weekly summary for paid users. Two conditional
 * states share the same block:
 *  - Tenure-based variants for sites that have forms but have never collected entries.
 *  - An engagement-drop state for sites whose forms were active and then went quiet.
 *
 * Stateless — the weekly cron cadence and the detection windows themselves ensure each
 * state fires at most once per event (see detect_drop() for the drop suppression logic).
 *
 * @since 1.10.1.1
 */
class ReengagementAlert {

	/**
	 * Hard stop day. No alerts after this tenure.
	 *
	 * @since 1.10.1.1
	 */
	private const HARD_STOP_DAY = 270;

	/**
	 * Cron cadence in days. Used as the trigger-window width.
	 *
	 * @since 1.10.1.1
	 */
	private const WINDOW_DAYS = 7;

	/**
	 * Variant definitions: slug => [ trigger_days, utm_content ].
	 *
	 * @since 1.10.1.1
	 */
	private const VARIANTS = [
		'phase-a-early' => [
			'trigger_days' => [ 30, 60, 90 ],
			'utm_content'  => '0entries-phase-a-early',
		],
		'phase-a-late'  => [
			'trigger_days' => [ 120, 150 ],
			'utm_content'  => '0entries-phase-a-late',
		],
		'phase-b'       => [
			'trigger_days' => [ 180, 210, 240, 270 ],
			'utm_content'  => '0entries-phase-b',
		],
	];

	/**
	 * Minimum entries in a week for a form to count as active for drop detection.
	 *
	 * @since 2.0.0
	 */
	private const DROP_MIN_ENTRIES = 5;

	/**
	 * Consecutive active weeks required before a drop to zero fires the alert.
	 *
	 * @since 2.0.0
	 */
	private const DROP_STREAK_WEEKS = 3;

	/**
	 * Slug and UTM content identifier for the engagement-drop state.
	 *
	 * @since 2.0.0
	 */
	private const DROP_VARIANT_SLUG = 'engagement-drop';

	/**
	 * UTM campaign the alert links report under.
	 *
	 * @since 2.0.3
	 */
	private const UTM_CAMPAIGN = 'weekly-summary-reengagement';

	/**
	 * Per-form weekly entry counts memoized by reported week end date.
	 *
	 * @since 2.0.3
	 *
	 * @var array
	 */
	private $weekly_counts = [];

	/**
	 * Initialize the class.
	 *
	 * @since 1.10.1.1
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 1.10.1.1
	 */
	private function hooks(): void {

		add_filter( 'wpforms_emails_summaries_cron_skip_empty', [ $this, 'maybe_allow_empty_entries' ] );
	}

	/**
	 * Resolve the active variant for the current site, or null when no alert should fire.
	 *
	 * @since 1.10.1.1
	 *
	 * @return string|null Variant slug or null.
	 */
	private function get_active_variant(): ?string {

		$tenure_days = $this->get_tenure_days();

		if ( $tenure_days < 0 ) {
			return null;
		}

		$variant = $this->resolve_variant( $tenure_days );

		if ( $variant === null ) {
			return null;
		}

		if ( ! $this->has_forms() || ! $this->has_zero_entries() ) {
			return null;
		}

		return $variant;
	}

	/**
	 * Allow the weekly summary cron to send when this site qualifies for a re-engagement alert.
	 *
	 * @since 1.10.1.1
	 *
	 * @param bool $skip_empty Whether to skip sending when entries are empty.
	 *
	 * @return bool
	 */
	public function maybe_allow_empty_entries( $skip_empty ): bool {

		$skip_empty = (bool) $skip_empty;

		if ( ! $skip_empty ) {
			return false;
		}

		return $this->get_active_variant() === null;
	}

	/**
	 * Resolve the active variant slug for the given tenure days, or null if none.
	 *
	 * @since 1.10.1.1
	 *
	 * @param int $tenure_days Days since Pro plugin activation.
	 *
	 * @return string|null Variant slug or null when outside all trigger windows.
	 */
	private function resolve_variant( int $tenure_days ): ?string {

		if ( $tenure_days < 30 || $tenure_days >= self::HARD_STOP_DAY + self::WINDOW_DAYS ) {
			return null;
		}

		foreach ( self::VARIANTS as $slug => $config ) {
			foreach ( $config['trigger_days'] as $trigger ) {
				if ( $tenure_days >= $trigger && $tenure_days < $trigger + self::WINDOW_DAYS ) {
					return $slug;
				}
			}
		}

		return null;
	}

	/**
	 * Days elapsed since Pro plugin activation. Returns -1 if activation timestamp is missing.
	 *
	 * @since 1.10.1.1
	 *
	 * @return int
	 */
	private function get_tenure_days(): int {

		$activated        = get_option( 'wpforms_activated', [] );
		$pro_activated_at = isset( $activated['pro'] ) ? (int) $activated['pro'] : 0;

		if ( $pro_activated_at <= 0 ) {
			return -1;
		}

		return (int) floor( ( time() - $pro_activated_at ) / DAY_IN_SECONDS );
	}

	/**
	 * Whether the site has at least one form.
	 *
	 * @since 1.10.1.1
	 *
	 * @return bool
	 */
	private function has_forms(): bool {

		$form_obj = wpforms()->obj( 'form' );

		if ( ! $form_obj ) {
			return false;
		}

		$forms = $form_obj->get(
			'',
			[
				'fields'      => 'ids',
				'numberposts' => 1,
			]
		);

		return ! empty( $forms );
	}

	/**
	 * Whether the site has zero entries across all forms.
	 *
	 * @since 1.10.1.1
	 *
	 * @return bool
	 */
	private function has_zero_entries(): bool {

		$entry_obj = wpforms()->obj( 'entry' );

		if ( ! $entry_obj ) {
			return false;
		}

		return (int) $entry_obj->get_entries( [], true ) === 0;
	}

	/**
	 * Whether any form suddenly went quiet: active for several consecutive weeks
	 * and then zero entries in the week being reported.
	 *
	 * @since 2.0.0
	 * @since 2.0.3 Added the `$week` parameter.
	 *
	 * @param Week $week Reported week.
	 *
	 * @return bool
	 */
	private function has_engagement_drop( Week $week ): bool {

		return $this->detect_drop( $this->get_weekly_counts_by_form( $week ) );
	}

	/**
	 * Per-form entry counts for the reported week and the preceding streak weeks.
	 *
	 * @since 2.0.0
	 * @since 2.0.3 Added the `$week` parameter, memoized the result.
	 *
	 * @param Week $week Reported week.
	 *
	 * @return array
	 */
	private function get_weekly_counts_by_form( Week $week ): array {

		$week_key = $week->get_end()->format( 'Y-m-d' );

		if ( isset( $this->weekly_counts[ $week_key ] ) ) {
			return $this->weekly_counts[ $week_key ];
		}

		$this->weekly_counts[ $week_key ] = Data::resolve()->get_weekly_by_form( $week, self::DROP_STREAK_WEEKS + 1 );

		return $this->weekly_counts[ $week_key ];
	}

	/**
	 * Detect an engagement drop in the given per-week counts.
	 *
	 * @since 2.0.0
	 *
	 * @param array $weekly Per-form counts keyed by week index (0 = reported week).
	 *
	 * @return bool
	 */
	private function detect_drop( array $weekly ): bool {

		return $this->find_dropped_form( $weekly ) !== null;
	}

	/**
	 * The first form that went quiet, with its prior-week average, or null.
	 *
	 * @since 2.0.3
	 *
	 * @param array $weekly Per-form counts keyed by week index (0 = reported week).
	 *
	 * @return array|null Keys `form_id`, `average`.
	 */
	private function find_dropped_form( array $weekly ): ?array {

		if ( count( $weekly ) <= self::DROP_STREAK_WEEKS ) {
			return null;
		}

		$reported_week = $weekly[0] ?? [];

		foreach ( $weekly[1] ?? [] as $form_id => $count ) {

			if ( $count < self::DROP_MIN_ENTRIES || ! empty( $reported_week[ $form_id ] ) ) {
				continue;
			}

			$streak = [ (int) $count ];

			for ( $week = 2; $week <= self::DROP_STREAK_WEEKS; $week++ ) {
				$week_count = (int) ( $weekly[ $week ][ $form_id ] ?? 0 );

				if ( $week_count < self::DROP_MIN_ENTRIES ) {
					continue 2;
				}

				$streak[] = $week_count;
			}

			return [
				'form_id' => (int) $form_id,
				'average' => (int) round( array_sum( $streak ) / self::DROP_STREAK_WEEKS ),
			];
		}

		return null;
	}

	/**
	 * Template args for a variant. Tenure slugs use the zero-entry copy; anything else is the drop state.
	 *
	 * @since 2.0.3
	 *
	 * @param string    $variant Variant slug.
	 * @param Week|null $week    Reported week, used when the drop copy needs a form.
	 *
	 * @return array Alert args (slug, title, content, button_text, button_url).
	 */
	public function build_variant_args( string $variant, ?Week $week = null ): array {

		return isset( self::VARIANTS[ $variant ] )
			? $this->build_args( $variant )
			: $this->build_drop_args( $week );
	}

	/**
	 * Build the alert template args for the resolved variant.
	 *
	 * @since 1.10.1.1
	 *
	 * @param string $variant Variant slug.
	 *
	 * @return array Alert args (slug, title, content, button_text, button_url).
	 */
	private function build_args( string $variant ): array {

		$utm_content = self::VARIANTS[ $variant ]['utm_content'];

		// Copy table per issue #17326.
		$copy = [
			'phase-a-early' => [
				'title'       => __( 'Your Forms Aren’t Collecting Entries Yet', 'wpforms' ),
				'content'     => __( 'Forms only collect entries when they\'re embedded on a page visitors can see. Check that each form is added to a page (via shortcode or block) and that the page actually gets traffic.', 'wpforms' ),
				'button_text' => __( 'Check Your Forms', 'wpforms' ),
				'admin_path'  => 'admin.php?page=wpforms-overview',
			],
			'phase-a-late'  => [
				'title'       => __( 'Your Forms Have Been Quiet for a While', 'wpforms' ),
				'content'     => __( 'After a few months at zero, the most common cause is page placement. Try moving your form to a higher-traffic page: homepage, pricing, or a top blog post.', 'wpforms' ),
				'button_text' => __( 'Check Your Forms', 'wpforms' ),
				'admin_path'  => 'admin.php?page=wpforms-overview',
			],
			'phase-b'       => [
				'title'       => __( 'Still No Entries? Try a Different Form Type', 'wpforms' ),
				'content'     => __( 'When placement isn\'t the issue, matching the form to visitor intent usually unsticks things: quote request for services, newsletter signup for content, or demo booking for software.', 'wpforms' ),
				'button_text' => __( 'Browse Templates', 'wpforms' ),
				'admin_path'  => 'admin.php?page=wpforms-builder',
			],
		][ $variant ];

		$button_url = AbstractBlock::admin_utm_url( $copy['admin_path'], $utm_content, self::UTM_CAMPAIGN );

		return [
			'slug'        => $variant,
			'title'       => $copy['title'],
			'content'     => $copy['content'],
			'button_text' => $copy['button_text'],
			'button_url'  => $button_url,
		];
	}

	/**
	 * Build the alert template args for the engagement-drop state.
	 *
	 * @since 2.0.0
	 * @since 2.0.3 Added the optional `$week` parameter.
	 *
	 * @param Week|null $week Reported week. Defaults to the current summary week.
	 *
	 * @return array Alert args (slug, title, content, content_plain, button_text, button_url).
	 */
	public function build_drop_args( ?Week $week = null ): array {

		$drop       = $this->find_dropped_form( $this->get_weekly_counts_by_form( $week ?? Week::current() ) );
		$form_id    = (int) ( $drop['form_id'] ?? 0 );
		$form_title = $form_id ? (string) get_the_title( $form_id ) : '';
		$form_title = $form_title !== '' ? $form_title : __( 'Contact Form', 'wpforms' );
		$average    = (int) ( $drop['average'] ?? 9 );
		$admin_path = $form_id > 0
			? 'admin.php?page=wpforms-builder&view=fields&form_id=' . $form_id
			: 'admin.php?page=wpforms-overview';

		$button_url = AbstractBlock::admin_utm_url( $admin_path, self::DROP_VARIANT_SLUG, self::UTM_CAMPAIGN );

		$title = sprintf(
			/* translators: %s - form name. */
			__( '“%s” Went Quiet This Week', 'wpforms' ),
			$form_title
		);
		$content = sprintf(
			/* translators: %s - average weekly entry count. */
			__( 'It was averaging about %s entries a week, then dropped to 0. That is usually worth a quick look, a broken embed or a form change can cause it.', 'wpforms' ),
			number_format_i18n( $average )
		);

		return [
			'slug'          => self::DROP_VARIANT_SLUG,
			'title'         => $title,
			'content'       => $content,
			'content_plain' => $content,
			'button_text'   => __( 'Check Your Form', 'wpforms' ),
			'button_url'    => $button_url,
		];
	}

	// phpcs:disable WPForms.Comments.DeprecatedTag.InvalidDeprecatedVersion -- The release script substitutes the placeholder.
	/**
	 * Inject the re-engagement alert args into the summary template when conditions are met.
	 *
	 * @since      1.10.1.1
	 * @deprecated 2.0.3 The alert is a slot block, see Emails\Summaries\Slot\Blocks\ActivityDropoff.
	 *
	 * @param Summary $template Summary email template.
	 *
	 * @return Summary
	 */
	public function maybe_add_alert_args( $template ) {
		// phpcs:enable WPForms.Comments.DeprecatedTag.InvalidDeprecatedVersion

		_deprecated_function( __METHOD__, '2.0.3 of the WPForms plugin', 'WPForms\Emails\Summaries\Slot\Blocks\ActivityDropoff' );

		if ( ! $template instanceof Summary ) {
			return $template;
		}

		$alert_args = $this->get_alert_args();

		if ( $alert_args === null ) {
			return $template;
		}

		$template->set_args(
			[
				'body' => [
					'reengagement_alert' => $alert_args,
				],
			]
		);

		return $template;
	}

	/**
	 * Resolve the alert args for whichever conditional state applies, or null when none does.
	 *
	 * @since 2.0.0
	 * @since 2.0.3 Added the optional `$week` parameter.
	 *
	 * @param Week|null $week Reported week. Defaults to the current summary week.
	 *
	 * @return array|null Alert args or null.
	 */
	public function get_alert_args( ?Week $week = null ): ?array {

		$week = $week ?? Week::current();

		if ( $this->has_engagement_drop( $week ) ) {
			return $this->build_drop_args( $week );
		}

		$variant = $this->get_active_variant();

		return $variant === null ? null : $this->build_args( $variant );
	}
}
