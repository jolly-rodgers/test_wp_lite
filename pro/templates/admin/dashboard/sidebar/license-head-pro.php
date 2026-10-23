<?php
/**
 * Dashboard "License / Update" widget — head (title row), Pro.
 *
 * The Pro counterpart of the Lite `license-head` template: the crown or the
 * alert icon, the edition + version, and the per-variant license CTA. It carries
 * its own file name on purpose — the two heads take different arguments, so
 * shadowing the Lite name by template path priority would hand this template the
 * Lite view data whenever `wpforms_allow_pro_version` turns a Pro build into a
 * Lite one after the Pro files have already loaded.
 *
 * The activate/update CTAs carry `data-wpforms-license-modal` so they open the
 * license validation modal; the href is the no-JS fallback.
 *
 * @since 2.0.2
 *
 * @var string $variant          Widget variant: 'data', 'expired', 'no-license', 'disabled', 'invalid', 'limit-reached'.
 * @var string $edition_label    e.g. "WPForms Pro".
 * @var string $version          Plugin version.
 * @var bool   $update_available Whether a plugin update is pending.
 * @var string $renew_url        Renew URL (expired).
 * @var string $settings_url     Settings license section URL (no-license, disabled, invalid, limit-reached).
 * @var string $plugins_url      WP Plugins page URL (Update Now).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Every non-data variant is a license problem: show the alert icon (recolored via CSS mask) instead of the crown.
$has_alert_icon = $variant !== 'data';
?>
<div class="wpforms-dashboard-license-titlerow">
	<?php if ( $has_alert_icon ) : ?>
		<span class="wpforms-dashboard-license-icon" aria-hidden="true"></span>
	<?php else : ?>
		<i class="fa fa-crown wpforms-dashboard-license-icon wpforms-dashboard-license-crown" aria-hidden="true"></i>
	<?php endif; ?>

	<span class="wpforms-dashboard-license-title">
		<?php echo esc_html( $edition_label ); ?>
		<span class="wpforms-dashboard-license-version"><?php echo esc_html( $version ); ?></span>
	</span>

	<?php if ( $variant === 'expired' ) : ?>
		<a href="<?php echo esc_url( $renew_url ); ?>" class="wpforms-btn wpforms-btn-sm wpforms-btn-red" target="_blank" rel="noopener noreferrer">
			<?php esc_html_e( 'Renew Now', 'wpforms' ); ?>
		</a>
	<?php elseif ( $variant === 'no-license' ) : ?>
		<a href="<?php echo esc_url( $settings_url ); ?>" class="wpforms-btn wpforms-btn-sm wpforms-btn-orange" data-wpforms-license-modal>
			<?php esc_html_e( 'Activate License', 'wpforms' ); ?>
		</a>
	<?php elseif ( $variant === 'disabled' || $variant === 'invalid' ) : ?>
		<a href="<?php echo esc_url( $settings_url ); ?>" class="wpforms-btn wpforms-btn-sm wpforms-btn-red" data-wpforms-license-modal>
			<?php esc_html_e( 'Update License', 'wpforms' ); ?>
		</a>
	<?php elseif ( $variant === 'limit-reached' ) : ?>
		<a href="<?php echo esc_url( $settings_url ); ?>" class="wpforms-btn wpforms-btn-sm wpforms-btn-red">
			<?php esc_html_e( 'Upgrade License', 'wpforms' ); ?>
		</a>
	<?php elseif ( $update_available ) : ?>
		<a href="<?php echo esc_url( $plugins_url ); ?>" class="wpforms-btn wpforms-btn-sm wpforms-btn-blue">
			<?php esc_html_e( 'Update Now', 'wpforms' ); ?>
		</a>
	<?php endif; ?>
</div>
