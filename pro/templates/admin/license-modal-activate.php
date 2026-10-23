<?php
/**
 * License Validation Modal — no-license (activate) variant.
 *
 * @since 2.0.1
 *
 * @var string $account_url  WPForms account URL.
 * @var string $upgrade_url  Get WPForms URL.
 * @var string $logo_url     WPForms logo URL.
 * @var bool   $is_deferred  Whether the modal stays hidden until opened by the form-limit error.
 * @var bool   $is_limit     Whether the modal is shown in the form-limit context (alternate copy).
 * @var string $redirect_url Where closing the modal must navigate, empty to stay on the page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$allowed_link = [
	'a' => [
		'href'   => [],
		'target' => [],
		'rel'    => [],
	],
];
?>
<div class="wpforms-license-modal" id="wpforms-license-modal" role="dialog" aria-modal="true" aria-labelledby="wpforms-license-modal-title" tabindex="-1" style="display: none;"<?php echo ! empty( $is_deferred ) ? ' data-deferred="1"' : ''; ?><?php echo ! empty( $redirect_url ) ? ' data-redirect="' . esc_url( $redirect_url ) . '"' : ''; ?>>

	<img class="wpforms-license-modal-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'WPForms', 'wpforms' ); ?>">

	<button type="button" class="wpforms-license-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wpforms' ); ?>">
		<i class="fa fa-times" aria-hidden="true"></i>
	</button>

	<div class="wpforms-license-modal-content">

		<div class="wpforms-license-modal-heading">
			<?php if ( ! empty( $is_limit ) ) : ?>
				<?php // The form-limit variant per design: same modal, alternate copy. ?>
				<h2 id="wpforms-license-modal-title"><?php esc_html_e( 'Activate Your License to Continue', 'wpforms' ); ?></h2>
				<p><?php esc_html_e( 'An active license is required to create additional forms.', 'wpforms' ); ?></p>
			<?php else : ?>
				<h2 id="wpforms-license-modal-title"><?php esc_html_e( 'Activate Your License to Get Started', 'wpforms' ); ?></h2>
				<p>
					<?php
					printf(
						wp_kses( /* translators: %s - WPForms account URL. */
							__( 'It can be found in your <a href="%s" target="_blank" rel="noopener noreferrer">WPForms account</a> or in the purchase receipt that was emailed to you.', 'wpforms' ),
							$allowed_link
						),
						esc_url( $account_url )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="wpforms-license-modal-form">
			<div class="wpforms-license-modal-field">
				<i class="fa fa-key wpforms-license-modal-field-icon" aria-hidden="true"></i>
				<input type="password" id="wpforms-license-modal-key" class="wpforms-license-modal-input" placeholder="<?php esc_attr_e( 'Paste License Key', 'wpforms' ); ?>" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true">
				<i class="far wpforms-license-modal-status-icon" aria-hidden="true"></i>
				<span class="wpforms-license-modal-spinner" aria-hidden="true"></span>
			</div>
			<p class="wpforms-license-modal-note" aria-live="polite"></p>
			<p class="wpforms-license-modal-get">
				<?php
				printf(
					wp_kses( /* translators: %s - Get WPForms URL. */
						__( 'Don\'t have a license key? <a href="%s" target="_blank" rel="noopener noreferrer">Get WPForms</a>', 'wpforms' ),
						$allowed_link
					),
					esc_url( $upgrade_url )
				);
				?>
			</p>
		</div>

		<div class="wpforms-license-modal-actions">
			<button type="button" class="wpforms-license-modal-continue">
				<?php esc_html_e( 'Continue to WPForms', 'wpforms' ); ?>
				<i class="fa fa-arrow-right" aria-hidden="true"></i>
			</button>
			<p class="wpforms-license-modal-empty-notice" role="alert" style="display: none;">
				<i class="fa fa-circle-info" aria-hidden="true"></i>
				<span class="wpforms-license-modal-empty-notice-text"></span>
			</p>
		</div>

	</div>
</div>
