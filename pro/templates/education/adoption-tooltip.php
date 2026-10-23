<?php
/**
 * Builder adoption tooltip card.
 *
 * @since 2.0.1
 *
 * @var string $key       Candidate key.
 * @var string $target    CTA deep-link target.
 * @var string $title     Card heading.
 * @var string $message   Card body text.
 * @var string $button    CTA button label.
 * @var string $ai_prompt Smart Edit prompt the CTA sends, empty when AI is unavailable.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wpforms-adoption-tooltip" data-key="<?php echo esc_attr( $key ); ?>" data-target="<?php echo esc_attr( $target ); ?>"<?php echo ! empty( $ai_prompt ) ? ' data-ai-prompt="' . esc_attr( $ai_prompt ) . '"' : ''; ?>>
	<div class="wpforms-adoption-tooltip-header">
		<span class="wpforms-adoption-tooltip-icon"><i class="fa fa-lightbulb-o" aria-hidden="true"></i></span>
		<h4 class="wpforms-adoption-tooltip-title"><?php echo esc_html( $title ); ?></h4>
	</div>
	<p class="wpforms-adoption-tooltip-message"><?php echo wp_kses( $message, [ 'strong' => [] ] ); ?></p>
	<div class="wpforms-adoption-tooltip-actions">
		<button type="button" class="wpforms-btn wpforms-btn-sm wpforms-btn-orange wpforms-adoption-tooltip-cta"><?php echo esc_html( $button ); ?></button>
		<button type="button" class="wpforms-adoption-tooltip-dismiss"><?php esc_html_e( 'Not Now', 'wpforms' ); ?></button>
	</div>
</div>
