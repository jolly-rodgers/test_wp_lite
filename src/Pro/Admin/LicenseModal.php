<?php

namespace WPForms\Pro\Admin;

/**
 * License Validation Modal.
 *
 * Prompts a Pro user whose license is in a confirmed inactive state (no license,
 * expired, or invalid/disabled) to activate or renew. Shown at most once per 24
 * hours on WPForms admin pages, and never on a failed/unreachable license check
 * (fail open) since it reads only the stored license state.
 *
 * @since 2.0.1
 */
class LicenseModal {

	/**
	 * Site option storing modal state (last shown + wizard skip timestamps).
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const OPTION = 'wpforms_license_modal';

	/**
	 * Variant shown when no license key is entered.
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const VARIANT_NO_LICENSE = 'no_license';

	/**
	 * Variant shown when the license is expired.
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const VARIANT_EXPIRED = 'expired';

	/**
	 * Variant shown when the license is disabled.
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const VARIANT_DISABLED = 'disabled';

	/**
	 * Variant shown when the license is invalid (key no longer exists / user deleted).
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const VARIANT_INVALID = 'invalid';

	/**
	 * Resolved variant to display, empty string when the modal must not show.
	 * Null until computed.
	 *
	 * @since 2.0.1
	 *
	 * @var string|null
	 */
	private $variant;

	/**
	 * Initialize.
	 *
	 * @since 2.0.1
	 */
	public function init(): void {

		$this->hooks();
	}

	/**
	 * Register hooks.
	 *
	 * @since 2.0.1
	 */
	private function hooks(): void {

		// Recorded from the Setup Wizard REST flow, so it is attached on `init` (not `admin_init`).
		add_action( 'wpforms_setup_wizard_service_state_manager_complete', [ $this, 'record_wizard_skip' ] );

		// The modal state is obsolete once the license becomes active.
		add_action( 'update_option_wpforms_license', [ $this, 'maybe_delete_state' ], 10, 2 );

		add_action( 'admin_enqueue_scripts', [ $this, 'admin_enqueue_scripts' ] );
		add_action( 'admin_footer', [ $this, 'render' ] );
	}

	/**
	 * Delete the stored modal state once the license becomes active.
	 *
	 * @since 2.0.1
	 *
	 * @param mixed $old_value Previous option value.
	 * @param mixed $value     New option value.
	 */
	public function maybe_delete_state( $old_value, $value ): void {

		if ( ! is_array( $value ) || empty( $value['key'] ) ) {
			return;
		}

		$error_flags = [ 'is_expired', 'is_disabled', 'is_invalid', 'is_limit_reached', 'is_flagged' ];

		if ( array_filter( array_intersect_key( $value, array_flip( $error_flags ) ) ) ) {
			return;
		}

		delete_option( self::OPTION );
	}

	/**
	 * Record the moment the user skips (exits) the onboarding wizard.
	 *
	 * The wizard's first step already asks for the key, so the modal is suppressed
	 * for 24 hours after a skip to avoid prompting the user twice in a row.
	 *
	 * @since 2.0.1
	 *
	 * @param string $outcome Wizard completion outcome (build|import|exit|forms).
	 */
	public function record_wizard_skip( $outcome ): void {

		if ( (string) $outcome !== 'exit' ) {
			return;
		}

		$state                      = $this->get_state();
		$state['wizard_skipped_at'] = time();

		$this->update_state( $state );
	}

	/**
	 * Enqueue and localize modal assets when the modal will be shown.
	 *
	 * @since 2.0.1
	 */
	public function admin_enqueue_scripts(): void {

		if ( ! $this->should_show() && ! $this->should_render_deferred() ) {
			return;
		}

		$min = wpforms_get_min_suffix();

		wp_enqueue_style(
			'wpforms-license-modal',
			WPFORMS_PLUGIN_URL . "assets/pro/css/admin/license-modal{$min}.css",
			[],
			WPFORMS_VERSION
		);

		wp_enqueue_script(
			'wpforms-license-modal',
			WPFORMS_PLUGIN_URL . "assets/pro/js/admin/license-modal{$min}.js",
			[ 'jquery' ],
			WPFORMS_VERSION,
			true
		);

		$renew_url = wpforms_utm_link( 'https://wpforms.com/account/licenses/', 'license-modal', 'Renew Now' );

		// Clean expired-key notice per design: red lead + dark underlined renew link.
		$expired_message = sprintf(
			'<span class="wpforms-license-modal-note-lead">%1$s</span> %2$s',
			esc_html__( 'Your license is expired.', 'wpforms' ),
			sprintf(
				wp_kses( /* translators: %s - renew license URL. */
					__( '<a href="%s" class="wpforms-license-modal-note-link" target="_blank" rel="noopener noreferrer">Renew now</a> to continue.', 'wpforms' ),
					[
						'a' => [
							'href'   => [],
							'class'  => [],
							'target' => [],
							'rel'    => [],
						],
					]
				),
				esc_url( $renew_url )
			)
		);

		wp_localize_script(
			'wpforms-license-modal',
			'wpforms_license_modal',
			[
				'nonce'   => wp_create_nonce( 'wpforms-admin' ),
				'ajaxurl' => admin_url( 'admin-ajax.php' ),
				'strings' => [
					'empty'        => esc_html__( 'Please enter your license key to continue.', 'wpforms' ),
					'verifying'    => esc_html__( 'Verifying…', 'wpforms' ),
					'verifiedLead' => esc_html__( 'License verified!', 'wpforms' ),
					/* translators: %s - WPForms plan name, e.g. "WPForms Pro". */
					'verifiedPlan' => esc_html__( 'You have %s.', 'wpforms' ),
					'expired'      => $expired_message,
					'genericErr'   => esc_html__( 'There was an error verifying your license. Please try again later.', 'wpforms' ),
					'removeErr'    => esc_html__( 'There was an error removing your license key. Please try again later.', 'wpforms' ),
				],
			]
		);
	}

	/**
	 * Render the modal markup in the footer.
	 *
	 * @since 2.0.1
	 */
	public function render(): void {

		$is_deferred = ! $this->should_show();

		if ( $is_deferred && ! $this->should_render_deferred() ) {
			return;
		}

		if ( ! $is_deferred && ! $this->is_form_limit_blocking() ) {
			// Record the display so the once-per-24h cadence holds. The form-limit prompt
			// (deferred or blocking the builder) fires on every attempt and never burns the daily slot.
			$state               = $this->get_state();
			$state['last_shown'] = time();

			$this->update_state( $state );
		}

		$current_variant = $this->get_variant();
		$template        = $current_variant === self::VARIANT_NO_LICENSE ? 'admin/license-modal-activate' : 'admin/license-modal-inactive';
		$data            = $this->get_template_data( $current_variant );

		// The form-limit copy applies only when form creation is what opens the modal: a deferred
		// render on a form creation screen, or the immediate builder block. A deferred render
		// elsewhere (the Dashboard License widget CTA) keeps the standard activation copy.
		// Closing the builder-blocking modal must not leave the blocked builder open — it returns to All Forms.
		$data['is_deferred']  = $is_deferred;
		$data['is_limit']     = ( $is_deferred && $this->is_form_creation_context() ) || $this->is_form_limit_blocking();
		$data['redirect_url'] = $this->is_form_limit_blocking() ? admin_url( 'admin.php?page=wpforms-overview' ) : '';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo wpforms_render( $template, $data, true );
	}

	/**
	 * Whether the modal must be rendered hidden, to be opened by the unlicensed
	 * form-limit error on the form creation screens, or by the Dashboard License
	 * widget CTA.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	private function should_render_deferred(): bool {

		if ( wp_doing_ajax() || ! wpforms_current_user_can() ) {
			return false;
		}

		$current_variant = $this->get_variant();

		// The Dashboard License widget renders a CTA for each of these states, and opens the modal with it.
		if ( wpforms_is_admin_page( 'dashboard' ) ) {
			return in_array( $current_variant, [ self::VARIANT_NO_LICENSE, self::VARIANT_DISABLED, self::VARIANT_INVALID ], true );
		}

		// Only the no-license state limits form creation.
		return $current_variant === self::VARIANT_NO_LICENSE && $this->is_form_creation_context();
	}

	/**
	 * Whether the current screen creates forms — the new-form builder or the Templates page.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	private function is_form_creation_context(): bool {

		return $this->is_new_form_builder() || wpforms_is_admin_page( 'templates' );
	}

	/**
	 * Whether the current screen is the new-form (setup) builder page.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	private function is_new_form_builder(): bool {

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return wpforms_is_admin_page( 'builder' ) && empty( $_GET['form_id'] );
	}

	/**
	 * Whether the unlicensed form limit must block the new-form builder right away.
	 *
	 * Mirrors the server-side limit in wpforms_new_form(): one published form without a license.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	private function is_form_limit_blocking(): bool {

		return $this->get_variant() === self::VARIANT_NO_LICENSE
			&& $this->is_new_form_builder()
			&& wp_count_posts( 'wpforms' )->publish >= 1;
	}

	/**
	 * Data passed to the modal template.
	 *
	 * @since 2.0.1
	 *
	 * @param string $variant Modal variant.
	 *
	 * @return array
	 */
	private function get_template_data( string $variant ): array {

		$logo_url = WPFORMS_PLUGIN_URL . 'assets/images/wpforms-logo.svg';

		// No-license (activation) modal.
		if ( $variant === self::VARIANT_NO_LICENSE ) {
			return [
				'account_url' => wpforms_utm_link( 'https://wpforms.com/account/', 'license-modal', 'Account Dashboard' ),
				'upgrade_url' => wpforms_admin_upgrade_link( 'license-modal', 'Get WPForms' ),
				'logo_url'    => $logo_url,
			];
		}

		$is_expired = $variant === self::VARIANT_EXPIRED;

		return [
			'is_expired'  => $is_expired,
			'logo_url'    => $logo_url,
			'title'       => $is_expired
				? __( 'Your License Has Expired', 'wpforms' )
				: __( 'Activate Your License to Get Started', 'wpforms' ),
			// Expired uses the bespoke design copy; other statuses reuse WPForms' per-status message.
			'description' => $is_expired
				? esc_html__( 'An active license is needed to create new forms and edit existing forms. It also provides access to new features & addons, plugin updates (including security improvements), and our world-class support.', 'wpforms' )
				: wpforms()->obj( 'license' )->get_info_message_escaped(),
			'renew_url'   => wpforms_utm_link( 'https://wpforms.com/account/licenses/', 'license-modal', 'Renew License' ),
			'upgrade_url' => wpforms_admin_upgrade_link( 'license-modal', 'Get WPForms' ),
			'features'    => $this->get_expired_features(),
		];
	}

	/**
	 * Feature list shown on the expired/invalid modal.
	 *
	 * Copy is pending product sign-off — some claims describe planned license-lock
	 * behavior (see issue discussion).
	 *
	 * @since 2.0.1
	 *
	 * @return array
	 */
	private function get_expired_features(): array {

		return [
			[
				'icon'  => 'fa-solid fa-envelope-open-text',
				'title' => __( 'Form Entries', 'wpforms' ),
				'desc'  => __( 'Entries are locked and can no longer be viewed, but are still stored.', 'wpforms' ),
			],
			[
				'icon'  => 'fa-solid fa-plug',
				'title' => __( 'Addons + Integrations', 'wpforms' ),
				'desc'  => __( 'You cannot activate new addons or connect to new integrations.', 'wpforms' ),
			],
			[
				'icon'  => 'fa-solid fa-credit-card',
				'title' => __( 'Payment Forms', 'wpforms' ),
				'desc'  => __( '3% payment processing fee applies to all transactions.', 'wpforms' ),
			],
			[
				// The AI sparkles glyph is not in Font Awesome Free, so it ships as an inline SVG (per Figma).
				'svg'   => '<svg width="20" height="20" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path fill="currentColor" d="M11.0328 15.6017C13.098 16.2364 13.7521 16.8908 14.3867 18.9557C14.4376 19.1218 14.6731 19.1218 14.724 18.9557C15.3586 16.8904 16.013 16.2364 18.078 15.6017C18.244 15.5509 18.244 15.3153 18.078 15.2645C16.0127 14.6298 15.3586 13.9754 14.724 11.9105C14.6731 11.7444 14.4376 11.7444 14.3867 11.9105C13.7521 13.9758 13.0977 14.6298 11.0328 15.2645C10.8667 15.3153 10.8667 15.5509 11.0328 15.6017Z"/><path fill="currentColor" d="M1.63204 9.59249C5.3796 8.44061 6.56697 7.25361 7.71849 3.50604C7.81103 3.205 8.23791 3.205 8.33046 3.50604C9.48235 7.25361 10.6693 8.44097 14.4169 9.59249C14.718 9.68504 14.718 10.1119 14.4169 10.2045C10.6693 11.3564 9.48198 12.5434 8.33046 16.2909C8.23791 16.592 7.81103 16.592 7.71849 16.2909C6.5666 12.5434 5.3796 11.356 1.63204 10.2045C1.33099 10.1119 1.33099 9.68504 1.63204 9.59249Z"/><path fill="currentColor" d="M11.0328 4.07876C13.098 3.44411 13.7521 2.7897 14.3867 0.724796C14.4376 0.558725 14.6731 0.558725 14.724 0.724796C15.3586 2.79007 16.013 3.44411 18.078 4.07876C18.244 4.12961 18.244 4.36518 18.078 4.41602C16.0127 5.05068 15.3586 5.70508 14.724 7.76999C14.6731 7.93606 14.4376 7.93606 14.3867 7.76999C13.7521 5.70472 13.0977 5.05068 11.0328 4.41602C10.8667 4.36518 10.8667 4.12961 11.0328 4.07876Z"/></svg>',
				'title' => __( 'WPForms AI', 'wpforms' ),
				'desc'  => __( 'All AI features have been disabled, including Smart Edit.', 'wpforms' ),
			],
			[
				'icon'  => 'fa-solid fa-chart-column',
				'title' => __( 'Form Analytics', 'wpforms' ),
				'desc'  => __( 'Interactions and conversion rates are no longer being tracked.', 'wpforms' ),
			],
			[
				'icon'  => 'fa-solid fa-ban',
				'title' => __( 'Plugin Updates', 'wpforms' ),
				'desc'  => __( 'You will no longer receive security updates and new features.', 'wpforms' ),
			],
		];
	}

	/**
	 * Whether the modal should be shown on the current request.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	private function should_show(): bool {

		// Only for capable users, outside AJAX.
		if ( wp_doing_ajax() || ! wpforms_current_user_can() ) {
			return false;
		}

		// Confirmed inactive license (empty variant means active/unknown → never show).
		if ( $this->get_variant() === '' ) {
			return false;
		}

		// The form-limit block ignores the daily cadence: the new-form builder must open the modal right away.
		if ( $this->is_form_limit_blocking() ) {
			return true;
		}

		// Only on WPForms admin pages — the slugless check excludes the builder by design.
		if ( ! wpforms_is_admin_page() ) {
			return false;
		}

		$state = $this->get_state();

		// Cadence: at most once per 24 hours.
		if ( ! empty( $state['last_shown'] ) && ( time() - (int) $state['last_shown'] ) < DAY_IN_SECONDS ) {
			return false;
		}

		// Suppress for 24 hours after a wizard skip.
		if ( ! empty( $state['wizard_skipped_at'] ) && ( time() - (int) $state['wizard_skipped_at'] ) < DAY_IN_SECONDS ) {
			return false;
		}

		return true;
	}

	/**
	 * Resolve the modal variant from the stored license state.
	 *
	 * Reads only the persisted `wpforms_license` flags — it never performs a remote
	 * check — so a network failure can never trigger the modal (fail open).
	 *
	 * @since 2.0.1
	 *
	 * @return string One of the VARIANT_* constants, or '' when the modal must not show.
	 */
	private function get_variant(): string {

		if ( $this->variant !== null ) {
			return $this->variant;
		}

		$this->variant = '';

		$license = wpforms()->obj( 'license' );

		if ( ! $license ) {
			return $this->variant;
		}

		// No key entered — a local fact, always safe to prompt.
		if ( $license->get() === '' ) {
			$this->variant = self::VARIANT_NO_LICENSE;

			return $this->variant;
		}

		// Active license — never show.
		if ( $license->is_active() ) {
			return $this->variant;
		}

		if ( $license->is_expired() ) {
			$this->variant = self::VARIANT_EXPIRED;
		} elseif ( $license->is_disabled() ) {
			$this->variant = self::VARIANT_DISABLED;
		} elseif ( $license->is_invalid() ) {
			$this->variant = self::VARIANT_INVALID;
		}

		// limit_reached / flagged mean the user has a paid license (just capped) — never nag them.
		// A failed/unreachable check leaves all flags unset, so it also stays '' (fail open).
		return $this->variant;
	}

	/**
	 * Get the stored modal state.
	 *
	 * @since 2.0.1
	 *
	 * @return array
	 */
	private function get_state(): array {

		return (array) get_option( self::OPTION, [] );
	}

	/**
	 * Persist the modal state.
	 *
	 * @since 2.0.1
	 *
	 * @param array $state Modal state.
	 */
	private function update_state( array $state ): void {

		update_option( self::OPTION, $state, false );
	}
}
