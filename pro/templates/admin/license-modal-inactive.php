<?php
/**
 * License Validation Modal — inactive-license variant (expired / disabled / invalid).
 *
 * @since 2.0.1
 *
 * @var bool   $is_expired  Whether the license is expired (adds the Renew CTA + features grid).
 * @var string $title       Modal heading.
 * @var string $description Modal description (pre-escaped; may contain a status link).
 * @var string $renew_url   Renew license URL.
 * @var string $upgrade_url Get WPForms URL (shown after the key is removed).
 * @var string $logo_url    WPForms logo URL.
 * @var array  $features    Feature cards shown in the expired grid.
 * @var bool   $is_deferred Whether the modal stays hidden until opened by a trigger.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wpforms-license-modal wpforms-license-modal-inactive" id="wpforms-license-modal" role="dialog" aria-modal="true" aria-labelledby="wpforms-license-modal-title" tabindex="-1" style="display: none;"<?php echo ! empty( $is_deferred ) ? ' data-deferred="1"' : ''; ?>>

	<img class="wpforms-license-modal-logo" src="<?php echo esc_url( $logo_url ); ?>" alt="<?php esc_attr_e( 'WPForms', 'wpforms' ); ?>">

	<button type="button" class="wpforms-license-modal-close" aria-label="<?php esc_attr_e( 'Close', 'wpforms' ); ?>">
		<i class="fa fa-times" aria-hidden="true"></i>
	</button>

	<div class="wpforms-license-modal-content">

		<div class="wpforms-license-modal-body">

			<div class="wpforms-license-modal-heading">
				<h2 id="wpforms-license-modal-title"><?php echo esc_html( $title ); ?></h2>
				<?php // Pre-escaped: plain text for expired, wp_kses'd status message otherwise. ?>
				<p><?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></p>
			</div>

			<div class="wpforms-license-modal-form">
				<div class="wpforms-license-modal-field-row">
					<div class="wpforms-license-modal-field wpforms-license-modal-field-error">
						<i class="fa fa-key wpforms-license-modal-field-icon" aria-hidden="true"></i>
						<input type="password" class="wpforms-license-modal-input" value="<?php echo esc_attr( str_repeat( '•', 30 ) ); ?>" placeholder="<?php esc_attr_e( 'Paste License Key', 'wpforms' ); ?>" autocomplete="off" spellcheck="false" data-1p-ignore data-lpignore="true" readonly>
						<i class="fa fa-circle-exclamation wpforms-license-modal-status-icon" aria-hidden="true"></i>
						<span class="wpforms-license-modal-spinner" aria-hidden="true"></span>
					</div>
					<?php if ( $is_expired ) : ?>
						<a href="<?php echo esc_url( $renew_url ); ?>" class="wpforms-license-modal-renew" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'Renew License', 'wpforms' ); ?>
						</a>
					<?php endif; ?>
					<button type="button" class="wpforms-license-modal-remove">
						<?php esc_html_e( 'Remove Key', 'wpforms' ); ?>
					</button>
				</div>
				<?php if ( $is_expired ) : ?>
					<p class="wpforms-license-modal-refresh-line">
						<?php
						printf(
							wp_kses( /* translators: %1$s,%2$s - opening and closing anchor tags. */
								__( 'If your license has been renewed or upgraded, then please %1$sforce a refresh%2$s.', 'wpforms' ),
								[
									'a' => [
										'href'  => [],
										'class' => [],
									],
								]
							),
							'<a href="#" class="wpforms-license-modal-refresh">',
							'</a>'
						);
						?>
					</p>
				<?php endif; ?>
				<p class="wpforms-license-modal-note" aria-live="polite"></p>
				<?php // Hidden until the stored key is removed — then it replaces the refresh prompt. ?>
				<p class="wpforms-license-modal-get" style="display: none;">
					<?php
					printf(
						wp_kses( /* translators: %s - Get WPForms URL. */
							__( 'Don\'t have a license key? <a href="%s" target="_blank" rel="noopener noreferrer">Get WPForms</a>', 'wpforms' ),
							[
								'a' => [
									'href'   => [],
									'target' => [],
									'rel'    => [],
								],
							]
						),
						esc_url( $upgrade_url )
					);
					?>
				</p>
			</div>

			<?php // Shown after a new key is verified — replaces the Renew/Remove CTAs, matching the no-license variant. ?>
			<button type="button" class="wpforms-license-modal-continue" style="display: none;">
				<?php esc_html_e( 'Continue to WPForms', 'wpforms' ); ?>
				<i class="fa fa-arrow-right" aria-hidden="true"></i>
			</button>

		</div>

		<?php if ( $is_expired ) : ?>
			<div class="wpforms-license-modal-features">
				<h3><?php esc_html_e( 'How an Expired License Affects Your Experience', 'wpforms' ); ?></h3>
				<div class="wpforms-license-modal-features-grid">
					<?php foreach ( $features as $feature ) : ?>
						<div class="wpforms-license-modal-feature">
							<span class="wpforms-license-modal-feature-icon">
								<?php if ( ! empty( $feature['svg'] ) ) : ?>
									<?php
									echo wp_kses(
										$feature['svg'],
										[
											'svg'  => [
												'width'   => [],
												'height'  => [],
												'viewbox' => [],
												'fill'    => [],
												'xmlns'   => [],
												'aria-hidden' => [],
											],
											'path' => [
												'fill' => [],
												'd'    => [],
											],
										]
									);
									?>
								<?php else : ?>
									<i class="<?php echo esc_attr( $feature['icon'] ); ?>" aria-hidden="true"></i>
								<?php endif; ?>
							</span>
							<span class="wpforms-license-modal-feature-text">
								<strong><?php echo esc_html( $feature['title'] ); ?></strong>
								<span><?php echo esc_html( $feature['desc'] ); ?></span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

	</div>
</div>
