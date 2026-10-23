<?php

namespace WPForms\Pro\Admin\Dashboard\Widgets;

use WPForms\Admin\Dashboard\AccessContext;
use WPForms\Admin\Dashboard\Helpers;
use WPForms\Admin\Dashboard\WidgetState;
use WPForms\Admin\Dashboard\Widgets\License as LicenseBase;

/**
 * Dashboard "License / Update" sidebar widget (Pro).
 *
 * Adds the license problem states — no-license, expired, disabled, invalid, and
 * limit-reached — on top of the Lite edition/version card: the crown or alert
 * icon, the per-state head CTA, and the description body. Registers
 * automatically when the Pro plugin is active (FQCN resolution of
 * `Admin\Dashboard\Widgets\License`).
 *
 * @since 2.0.2
 */
class License extends LicenseBase {

	/**
	 * Resolve the widget state.
	 *
	 * The Pro class only loads when Pro is active, so the license key check needs
	 * no `is_pro()` guard. A healthy license falls through to the Lite base.
	 *
	 * @since 2.0.2
	 *
	 * @param AccessContext $access Access context.
	 *
	 * @return WidgetState
	 */
	public function get_state( AccessContext $access ): WidgetState {

		if ( ! $access->has_license_key() ) {
			return new WidgetState( true, 'no-license' );
		}

		if ( $access->is_expired() ) {
			return new WidgetState( true, 'expired' );
		}

		if ( $access->is_disabled() ) {
			return new WidgetState( true, 'disabled' );
		}

		if ( $access->is_invalid() ) {
			return new WidgetState( true, 'invalid' );
		}

		if ( $access->is_limit_reached() ) {
			return new WidgetState( true, 'limit-reached' );
		}

		return parent::get_state( $access );
	}

	/**
	 * Mark the card for the responsive "attention" hoist: every license problem
	 * state, plus a healthy card with a pending plugin update.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param array         $data    Aggregated data.
	 * @param AccessContext $access  Access context.
	 *
	 * @return array
	 * @noinspection PhpUnusedParameterInspection
	 */
	protected function get_extra_classes( string $variant, array $data, AccessContext $access ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data and $access are part of the contract signature.

		$needs_attention = $variant !== 'data' || $this->is_update_available();

		return $needs_attention ? [ 'wpforms-dashboard-widget-attention' ] : [];
	}

	/**
	 * Render the widget head — the title row (icon + edition + version + CTA).
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param array         $data    Aggregated data (unused).
	 * @param AccessContext $access  Access context.
	 *
	 * @return string
	 * @noinspection PhpUnusedParameterInspection
	 */
	protected function render_head( string $variant, array $data, AccessContext $access ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data is part of the contract signature.

		return (string) wpforms_render(
			'admin/dashboard/sidebar/license-head-pro',
			$this->get_pro_view_data( $variant, $access ),
			true
		);
	}

	/**
	 * Render the widget body — the description text for the license problem states.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param array         $data    Aggregated data (unused).
	 * @param AccessContext $access  Access context (unused).
	 *
	 * @return string
	 * @noinspection PhpUnusedParameterInspection
	 */
	protected function render_body( string $variant, array $data, AccessContext $access ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $data and $access are part of the contract signature.

		$description = $this->get_body_description( $variant );

		if ( $description === '' ) {
			return '';
		}

		return (string) wpforms_render(
			'admin/dashboard/sidebar/license-body',
			[ 'description' => $description ],
			true
		);
	}

	/**
	 * Build the escaped body description for a variant. Empty string means no body.
	 *
	 * @since 2.0.2
	 *
	 * @param string $variant Template variant.
	 *
	 * @return string
	 */
	private function get_body_description( string $variant ): string {

		$text = '';

		switch ( $variant ) {
			case 'expired':
				$text = Helpers::is_payment_gateway_connected()
					? __( '<strong>Important:</strong> Your payment forms are being charged a 3% platform fee because your license has expired. Renew to continue receiving updates and waive the platform fee.', 'wpforms' )
					: __( '<strong>Your license has expired.</strong> Renew to continue receiving updates and new features.', 'wpforms' );
				break;

			case 'disabled':
				$text = __( '<strong>Your license key has been disabled.</strong> Please use a different key to continue receiving automatic updates.', 'wpforms' );
				break;

			case 'invalid':
				$text = __( '<strong>Your license key is invalid.</strong> The key no longer exists or the user associated with the key has been deleted. Please use a different key to continue receiving automatic updates.', 'wpforms' );
				break;

			case 'limit-reached':
				$text = sprintf(
					/* translators: %s - WPForms.com account URL. */
					__( '<strong>Sorry, but this license has no activations left.</strong> You can manage your site activations, upgrade your license, or purchase a new one in <a href="%s" target="_blank" rel="noopener noreferrer">your account</a>.', 'wpforms' ),
					esc_url( wpforms_utm_link( 'https://wpforms.com/account/licenses/', 'Dashboard - License', 'Limit Reached - Account Inline' ) )
				);
				break;

			case 'no-license':
				$text = __( 'An active license is needed to create new forms and edit existing forms. It also provides access to new features & addons, plugin updates, security improvements, and our world-class support.', 'wpforms' );
				break;

			default:
				// Healthy licenses ('data') and any future variant render no body.
				break;
		}

		if ( $text === '' ) {
			return '';
		}

		return wp_kses(
			$text,
			[
				'strong' => [],
				'a'      => [
					'href'   => [],
					'target' => [],
					'rel'    => [],
				],
			]
		);
	}

	/**
	 * Build the view data for the head template.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param AccessContext $access  Access context.
	 *
	 * @return array
	 */
	private function get_pro_view_data( string $variant, AccessContext $access ): array {

		return [
			'variant'          => $variant,
			'edition_label'    => $this->get_edition_label( $access ),
			'version'          => WPFORMS_VERSION,
			'update_available' => $this->is_update_available(),
			'renew_url'        => wpforms_utm_link( 'https://wpforms.com/account/licenses/', 'Dashboard - License', 'Renew Now' ),
			'settings_url'     => admin_url( 'admin.php?page=wpforms-settings&view=general' ),
			'plugins_url'      => self_admin_url( 'plugins.php' ),
		];
	}

	/**
	 * Build the edition label, e.g. "WPForms Pro".
	 *
	 * @since 2.0.2
	 *
	 * @param AccessContext $access Access context.
	 *
	 * @return string
	 */
	private function get_edition_label( AccessContext $access ): string {

		$tier = $access->get_tier();

		// An unlicensed Pro build resolves the tier to 'lite'; show the generic paid label.
		if ( $tier === 'lite' ) {
			return __( 'WPForms Pro', 'wpforms' );
		}

		return sprintf( /* translators: %s - license edition, e.g. "Pro" or "Elite". */
			__( 'WPForms %s', 'wpforms' ),
			ucwords( $tier )
		);
	}
}
