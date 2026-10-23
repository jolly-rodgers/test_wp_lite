<?php
/**
 * Dashboard "License / Update" widget — body (description), Pro.
 *
 * @since 2.0.2
 *
 * @var string $description Pre-escaped description HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<p class="wpforms-dashboard-license-desc">
	<?php echo $description; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped in Pro License::get_body_description(). ?>
</p>
