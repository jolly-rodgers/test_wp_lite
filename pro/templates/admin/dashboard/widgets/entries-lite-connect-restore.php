<?php
/**
 * Dashboard "Entries" widget Lite Connect restore bar (Pro only).
 *
 * Offers to restore the entries Lite Connect backed up before the upgrade. Only
 * rendered while a restore is still outstanding, so it disappears for good once
 * the import runs. Reuses the Lite band's `wpforms-education-lite-connect-*`
 * classes for the shared full-width band styling — the dashboard binds no script
 * to them, so nothing here needs the Lite toggle's view-swapping markup.
 *
 * @since 2.0.2
 *
 * @var string $entries_since_info Entries information string, already escaped by its builder.
 * @var string $restore_url        Nonced URL that starts the restore.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

?>
<div class="wpforms-education-lite-connect-wrapper">
	<div class="wpforms-education-lite-connect-enabled-info">
		<i class="fa fa-info-circle" aria-hidden="true"></i>
		<span><?php echo $entries_since_info; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped by the builder; re-escaping renders entities literally in translated strings. ?></span>
		<a href="<?php echo esc_url( $restore_url ); ?>">
			<?php esc_html_e( 'Restore Entries Now', 'wpforms' ); ?>
		</a>
	</div>
</div>
