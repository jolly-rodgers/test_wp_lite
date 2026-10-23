<?php

namespace WPForms\Pro\Admin\Dashboard;

use WPForms\Admin\Dashboard\AccessResolver as AccessResolverBase;

/**
 * Builds the access context for the Dashboard page on Pro.
 *
 * @since 2.0.2
 */
class AccessResolver extends AccessResolverBase {

	/**
	 * Get the raw context data with license-derived values filled in.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	protected function get_data(): array {

		$data    = parent::get_data();
		$license = (array) get_option( 'wpforms_license', [] );
		$tier    = wpforms_get_license_type();

		if ( ! $tier ) {
			$tier = 'lite';
		}

		$data['tier']             = $tier;
		$data['is_pro']           = wpforms()->is_pro();
		$data['is_expired']       = ! empty( $license['is_expired'] );
		$data['is_disabled']      = ! empty( $license['is_disabled'] );
		$data['is_invalid']       = ! empty( $license['is_invalid'] );
		$data['is_limit_reached'] = ! empty( $license['is_limit_reached'] );
		$data['has_license_key']  = ! empty( wpforms_get_license_key() );

		return $data;
	}
}
