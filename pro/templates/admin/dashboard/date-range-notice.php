<?php
/**
 * Dashboard date-range readiness notice.
 *
 * Shown while the rollup backfill is still processing. Rendered by
 * `Page::get_date_range_notice_html()` only when the backfill is incomplete.
 *
 * @since 2.0.2
 */

defined( 'ABSPATH' ) || exit;

?>
<span class="wpforms-dashboard-date-range-notice">
	<span class="wpforms-loading-spinner"></span>
	<span class="wpforms-dashboard-date-range-notice-text">
		<?php esc_html_e( "We're still processing your historical data. Longer date ranges will unlock automatically as it finishes.", 'wpforms' ); ?>
	</span>
</span>
<?php
/* Omit closing PHP tag at the end of PHP files to avoid "headers already sent" issues. */
