<?php

namespace WPForms\Pro\Migrations;

use WPForms\Integrations\ProductApi\Events\AdminEvents;
use WPForms\Migrations\UpgradeBase;

/**
 * Class upgrade for the 2.0.2.1 release.
 *
 * Remembers the license a site already holds, so the product events layer does
 * not report it as an activation. That layer learns about licenses from writes
 * to the option, and the first write after this upgrade is the daily
 * re-validation, which Pro runs while constructing its license object, before
 * the integrations that listen are loaded. Migrations run before either, on
 * the option as the previous release left it.
 *
 * @since 2.0.2.1
 *
 * @noinspection PhpUnused
 */
class Upgrade2_0_2_1 extends UpgradeBase {

	/**
	 * Run upgrade.
	 *
	 * @since 2.0.2.1
	 *
	 * @return bool
	 */
	public function run(): bool {

		// The key in force, from the WPFORMS_LICENSE_KEY constant or the option. An
		// expired or flagged key is remembered too: its renewal is not an activation.
		$key = wpforms_get_license_key();

		if ( $key === '' || (string) get_option( AdminEvents::LICENSE_OPTION, '' ) !== '' ) {
			return true;
		}

		update_option( AdminEvents::LICENSE_OPTION, wp_hash( $key ), false );

		return true;
	}
}
