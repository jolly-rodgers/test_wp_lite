<?php

namespace WPForms\Pro\Admin\Builder\Settings;

use WPForms_Builder_Panel_Settings;
use WPForms\Admin\Builder\Settings\QrCode as QrCodeBase;

/**
 * QR Code setting: the Pro and Elite licenses add full control over the center logo.
 *
 * @since 2.0.1
 */
class QrCode extends QrCodeBase {

	/**
	 * Image types no browser draws into a canvas, so they can never become a logo.
	 *
	 * @since 2.0.1
	 */
	private const UNSUPPORTED_LOGO_MIMES = [
		'image/tiff',
		'image/heic',
		'image/heif',
		'image/heic-sequence',
		'image/heif-sequence',
	];

	/**
	 * Initialize class.
	 *
	 * @since 2.0.1
	 */
	public function init(): void {

		parent::init();

		$this->hooks();
	}

	/**
	 * Register Pro-only hooks.
	 *
	 * @since 2.0.1
	 */
	private function hooks(): void {

		// Basic and Plus get the locked control from the education class instead.
		if ( ! self::is_logo_allowed() ) {
			return;
		}

		add_action( 'wpforms_admin_builder_settings_qr_code_logo', [ $this, 'render_logo' ] );
		add_filter( 'wpforms_builder_js_modules', [ $this, 'add_js_modules' ] );

		// Runs after the parent, which assigns the whole qr_code strings array.
		add_filter( 'wpforms_builder_strings', [ $this, 'add_logo_strings' ], 11 );
	}

	/**
	 * Narrow the logo picker to image types the browser can actually render.
	 *
	 * @since 2.0.1
	 *
	 * @param array $strings Builder strings.
	 *
	 * @return array
	 */
	public function add_logo_strings( $strings ): array {

		$strings    = (array) $strings;
		$allowed    = (array) ( $strings['upload_image_extensions'] ?? [] );
		$renderable = array_values( array_diff( $allowed, self::UNSUPPORTED_LOGO_MIMES ) );

		$strings['qr_code']['logo_extensions'] = $renderable;

		return $strings;
	}

	/**
	 * Register the Pro logo JS module.
	 *
	 * @since 2.0.1
	 *
	 * @param array $modules List of JS modules.
	 *
	 * @return array
	 */
	public function add_js_modules( $modules ): array {

		$min = wpforms_get_min_suffix();

		$modules = (array) $modules;

		$modules['SettingsQrCodeLogo'] = WPFORMS_PLUGIN_URL . "assets/pro/js/admin/builder/modules/settings-qr-code-logo$min.js";

		return $modules;
	}

	/**
	 * Render the QR Code Logo control: None / WPForms / Custom Logo.
	 *
	 * @since 2.0.1
	 *
	 * @param WPForms_Builder_Panel_Settings $settings Builder panel settings.
	 */
	public function render_logo( WPForms_Builder_Panel_Settings $settings ): void {

		$form_data   = (array) $settings->form_data;
		$qr_settings = $form_data['settings'] ?? [];
		$logo        = $qr_settings['qr_code_logo'] ?? 'wpforms';
		$logo_id     = absint( $qr_settings['qr_code_logo_id'] ?? 0 );
		$logo_url    = $this->get_custom_logo_url( $logo_id );
		// The row is 40px, so it loads the same small size the media library uses instead of the original.
		$preview_url = $this->get_custom_logo_url( $logo_id, 'medium' );
		$is_custom   = $logo === 'custom';
		// The row follows the stored logo, not the choice: a saved logo must resurface when Custom is re-picked.
		$has_logo = $logo_url !== '';

		wpforms_panel_field(
			'select',
			'settings',
			'qr_code_logo',
			$settings->form_data,
			esc_html__( 'Logo', 'wpforms' ),
			[
				'class'   => 'wpforms-panel-field-qr-code-logo',
				'default' => 'wpforms',
				'options' => [
					'none'    => esc_html__( 'None', 'wpforms' ),
					'wpforms' => esc_html__( 'WPForms', 'wpforms' ),
					'custom'  => esc_html__( 'Custom Logo', 'wpforms' ),
				],
				'after'   => '<p class="note wpforms-qr-code-logo-note' . ( $is_custom ? ' wpforms-hidden' : '' ) . '">' .
					esc_html__( 'Choose the image shown in the middle.', 'wpforms' ) . '</p>',
			]
		);
		?>
		<div class="wpforms-panel-field wpforms-qr-code-logo-custom<?php echo $is_custom ? '' : ' wpforms-hidden'; ?>">
			<a href="#" class="wpforms-qr-code-logo-upload<?php echo $has_logo ? ' wpforms-hidden' : ''; ?>">
				<?php esc_html_e( 'Upload an Image', 'wpforms' ); ?>
			</a>

			<div class="wpforms-qr-code-logo-file<?php echo $has_logo ? '' : ' wpforms-hidden'; ?>">
				<img src="<?php echo esc_url( $preview_url ); ?>" width="40" height="40" alt="<?php esc_attr_e( 'Custom QR code logo', 'wpforms' ); ?>">
				<div class="wpforms-qr-code-logo-meta">
					<span class="wpforms-qr-code-logo-name"><?php echo esc_html( $logo_url ? wp_basename( $logo_url ) : '' ); ?></span>
					<button type="button" class="wpforms-qr-code-logo-remove"><?php esc_html_e( 'Remove Image', 'wpforms' ); ?></button>
				</div>
			</div>

			<input type="hidden" name="settings[qr_code_logo_id]" value="<?php echo absint( $logo_id ); ?>">
		</div>
		<?php
	}
}
