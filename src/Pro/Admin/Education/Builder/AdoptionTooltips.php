<?php

namespace WPForms\Pro\Admin\Education\Builder;

use WPForms\Admin\Education\EducationInterface;
use WPForms\Admin\Education\Helpers;
use WPForms\Helpers\EventCounter;
use WPForms\Integrations\AI\Helpers as AIHelpers;

/**
 * Builder Adoption Tooltips education feature.
 *
 * After a paid user saves a slim form (1 to 3 counted fields), shows
 * a single nudge card in the builder pointing at the highest-priority
 * high-value feature the form doesn't use yet.
 *
 * @since 2.0.1
 */
class AdoptionTooltips implements EducationInterface {

	/**
	 * Post meta key holding the per-form shown state.
	 *
	 * Stored outside the form data on purpose: the state is local UI history,
	 * so duplicates, exports, and imports must never inherit it.
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const META_KEY = 'wpforms_adoption_tooltips';

	/**
	 * Option name holding global shown/cta/dismiss counters for usage tracking.
	 *
	 * @since 2.0.1
	 *
	 * @var string
	 */
	private const EVENTS_OPTION = 'wpforms_adoption_tooltips_events';

	/**
	 * Maximum number of tooltips ever shown on a single form.
	 *
	 * @since 2.0.1
	 *
	 * @var int
	 */
	private const LIFETIME_CAP = 3;

	/**
	 * Maximum counted fields for a form to qualify as slim.
	 *
	 * @since 2.0.1
	 *
	 * @var int
	 */
	private const SLIM_FORM_MAX_FIELDS = 3;

	/**
	 * Tracking event names accepted by the AJAX endpoint.
	 *
	 * @since 2.0.1
	 *
	 * @var array
	 */
	private const EVENTS = [ 'shown', 'cta', 'dismiss' ];

	/**
	 * Global counters store.
	 *
	 * @since 2.0.3
	 *
	 * @var EventCounter|null
	 */
	private $event_counter;

	/**
	 * Indicate if the feature is allowed to load.
	 *
	 * The paid license gate also satisfies the spec's license-capability
	 * filter: every candidate in the catalog is unlocked on all paid tiers.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	public function allow_load(): bool {

		return ( wpforms_is_admin_page( 'builder' ) || wp_doing_ajax() ) && wpforms_get_license_type();
	}

	/**
	 * Init.
	 *
	 * @since 2.0.1
	 */
	public function init() {

		$this->hooks();
	}

	/**
	 * Hooks.
	 *
	 * @since 2.0.1
	 */
	private function hooks(): void {

		// Usage tracking collects data in cron context, where the builder gate below fails.
		add_filter( 'wpforms_integrations_usage_tracking_usage_tracking_get_adoption_tooltips_data', [ $this, 'get_events' ] );

		if ( ! $this->allow_load() ) {
			return;
		}

		add_filter( 'wpforms_builder_save_form_response_data', [ $this, 'add_tooltip_payload' ], 10, 3 );
		add_action( 'wp_ajax_wpforms_adoption_tooltip_event', [ $this, 'ajax_event' ] );
		add_action( 'admin_enqueue_scripts', [ $this, 'enqueues' ] );
	}

	/**
	 * Enqueue builder assets.
	 *
	 * @since 2.0.1
	 */
	public function enqueues(): void {

		$min = wpforms_get_min_suffix();

		wp_enqueue_script(
			'wpforms-adoption-tooltips',
			WPFORMS_PLUGIN_URL . "assets/pro/js/admin/education/adoption-tooltips{$min}.js",
			[ 'jquery' ],
			WPFORMS_VERSION,
			true
		);
	}

	/**
	 * Attach the winning tooltip payload to the form save AJAX response.
	 *
	 * @since 2.0.1
	 *
	 * @param array|mixed $response_data Response data.
	 * @param int         $form_id       Form ID.
	 * @param array       $form_data     Form data.
	 *
	 * @return array
	 */
	public function add_tooltip_payload( $response_data, $form_id, $form_data ): array {

		$response_data = (array) $response_data;
		$form_id       = (int) $form_id;

		// Use the stored form: lazily loaded panels (e.g., Marketing) are absent from the save payload.
		$saved_form_data = (array) wpforms()->obj( 'form' )->get( $form_id, [ 'content_only' => true ] );
		$candidate       = $this->get_candidate( $form_id, $saved_form_data );

		if ( ! $candidate ) {
			return $response_data;
		}

		$config = $this->get_catalog()[ $candidate ];

		$response_data['adoption_tooltip'] = [
			'key'    => $candidate,
			'target' => $config['target'],
			'html'   => wpforms_render(
				'education/adoption-tooltip',
				[
					'key'       => $candidate,
					'target'    => $config['target'],
					'title'     => $config['title'],
					'message'   => $config['message'],
					'button'    => $config['button'],
					'ai_prompt' => $this->is_ai_available() ? ( $config['ai_prompt'] ?? '' ) : '',
				],
				true
			),
		];

		return $response_data;
	}

	/**
	 * Run the selection pipeline and return the winning candidate key.
	 *
	 * @since 2.0.1
	 *
	 * @param int   $form_id   Form ID.
	 * @param array $form_data Form data.
	 *
	 * @return string Candidate key or empty string when nothing should fire.
	 */
	public function get_candidate( int $form_id, array $form_data ): string {

		if ( ! $this->is_slim_form( $form_data ) ) {
			return '';
		}

		$state = $this->get_state( $form_id );

		if ( $state['count'] >= self::LIFETIME_CAP ) {
			return '';
		}

		foreach ( array_keys( $this->get_catalog() ) as $key ) {
			if ( in_array( $key, $state['shown'], true ) ) {
				continue;
			}

			if ( $this->is_relevant( $key, $form_data ) ) {
				return $key;
			}
		}

		return '';
	}

	/**
	 * Determine whether the form qualifies as slim.
	 *
	 * @since 2.0.1
	 *
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	private function is_slim_form( array $form_data ): bool {

		$count = Helpers::count_fillable_fields( $form_data );

		// A blank form is not a slim form: at least one counted field is required.
		return $count > 0 && $count <= self::SLIM_FORM_MAX_FIELDS;
	}

	/**
	 * Check whether the candidate's gap is present on the form.
	 *
	 * @since 2.0.1
	 *
	 * @param string $key       Candidate key.
	 * @param array  $form_data Form data.
	 *
	 * @return bool
	 */
	private function is_relevant( string $key, array $form_data ): bool {

		if ( $key === 'email_marketing' ) {
			return ! $this->has_marketing_connection( $form_data );
		}

		if ( $key === 'file_upload' ) {
			return ! wpforms_has_field_type( 'file-upload', $form_data );
		}

		if ( $key === 'notification' ) {
			return $this->get_active_notifications_count( $form_data ) === 1;
		}

		if ( $key === 'themes' ) {
			return ! $this->is_theme_edited( $form_data );
		}

		return false;
	}

	/**
	 * Check whether the form has at least one marketing provider connection.
	 *
	 * @since 2.0.1
	 *
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	private function has_marketing_connection( array $form_data ): bool {

		$providers = (array) ( $form_data['providers'] ?? [] );

		if ( empty( $providers ) ) {
			return false;
		}

		// Keep only the connections that belong to an email marketing addon, so CRM
		// and other Providers-API addons on the Marketing tab do not count.
		$providers = array_intersect_key( $providers, array_flip( $this->get_email_marketing_slugs() ) );

		foreach ( $providers as $connections ) {
			$connections = (array) $connections;

			// The builder posts a `__lock__` sentinel for a provider even when it has no connections.
			unset( $connections['__lock__'] );

			if ( ! empty( $connections ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get the clear slugs of the email marketing addons.
	 *
	 * Sourced from addon category metadata instead of a static list, so it stays
	 * correct as addons change. CRM and other Providers-API addons are excluded
	 * because they carry a different `settings_integrations.category`.
	 *
	 * @since 2.0.1
	 *
	 * @return array
	 */
	private function get_email_marketing_slugs(): array {

		$addons_obj = wpforms()->obj( 'addons' );
		$addons     = $addons_obj ? $addons_obj->get_all() : [];
		$slugs      = [];

		foreach ( $addons as $addon ) {
			$categories = (array) ( $addon['settings_integrations']['category'] ?? [] );

			if ( in_array( 'email-marketing', $categories, true ) ) {
				$slugs[] = str_replace( 'wpforms-', '', $addon['slug'] ?? '' );
			}
		}

		return $slugs;
	}

	/**
	 * Count enabled notifications on the form.
	 *
	 * @since 2.0.1
	 *
	 * @param array $form_data Form data.
	 *
	 * @return int
	 */
	private function get_active_notifications_count( array $form_data ): int {

		if ( empty( $form_data['settings']['notification_enable'] ) ) {
			return 0;
		}

		$active = 0;

		foreach ( (array) ( $form_data['settings']['notifications'] ?? [] ) as $notification ) {
			// A notification without the `enable` key is enabled (legacy data).
			if ( ! isset( $notification['enable'] ) || (int) $notification['enable'] !== 0 ) {
				++$active;
			}
		}

		return $active;
	}

	/**
	 * Check whether the form theme was edited from the default settings.
	 *
	 * Editing default styles in the builder always creates a custom theme,
	 * so an untouched form keeps the `default` theme with no custom flag.
	 *
	 * @since 2.0.1
	 *
	 * @param array $form_data Form data.
	 *
	 * @return bool
	 */
	private function is_theme_edited( array $form_data ): bool {

		$themes = (array) ( $form_data['settings']['themes'] ?? [] );

		return ( $themes['wpformsTheme'] ?? 'default' ) !== 'default' || ! empty( $themes['isCustomTheme'] );
	}

	/**
	 * Handle the tooltip interaction event: persist state and bump counters.
	 *
	 * @since 2.0.1
	 */
	public function ajax_event(): void {

		check_ajax_referer( 'wpforms-builder', 'nonce' );

		$form_id = absint( $_POST['form_id'] ?? 0 );
		$key     = sanitize_key( $_POST['key'] ?? '' );
		$event   = sanitize_key( $_POST['event'] ?? '' );

		if ( ! $form_id || ! isset( $this->get_catalog()[ $key ] ) || ! in_array( $event, self::EVENTS, true ) ) {
			wp_send_json_error();
		}

		if ( ! wpforms_current_user_can( 'edit_form_single', $form_id ) ) {
			wp_send_json_error();
		}

		if ( $event === 'shown' ) {
			$this->mark_shown( $form_id, $key );
		}

		$this->bump_event_counter( $key, $event );

		wp_send_json_success();
	}

	/**
	 * Persist the shown candidate in the per-form state.
	 *
	 * @since 2.0.1
	 *
	 * @param int    $form_id Form ID.
	 * @param string $key     Candidate key.
	 */
	private function mark_shown( int $form_id, string $key ): void {

		$state = $this->get_state( $form_id );

		if ( in_array( $key, $state['shown'], true ) ) {
			return;
		}

		$state['shown'][] = $key;

		++$state['count'];

		update_post_meta( $form_id, self::META_KEY, $state );
	}

	/**
	 * Get the per-form shown state.
	 *
	 * @since 2.0.1
	 *
	 * @param int $form_id Form ID.
	 *
	 * @return array State with `shown` (candidate keys) and `count` (tooltips shown).
	 */
	private function get_state( int $form_id ): array {

		$state = (array) get_post_meta( $form_id, self::META_KEY, true );

		return [
			'shown' => array_values( array_filter( (array) ( $state['shown'] ?? [] ), 'is_string' ) ),
			'count' => (int) ( $state['count'] ?? 0 ),
		];
	}

	/**
	 * Get the global shown/cta/dismiss counters.
	 *
	 * @since 2.0.1
	 *
	 * @return array
	 */
	public function get_events(): array {

		return $this->get_event_counter()->get_all();
	}

	/**
	 * Increment the global event counter used by usage tracking.
	 *
	 * @since 2.0.1
	 *
	 * @param string $key   Candidate key.
	 * @param string $event Event name.
	 */
	private function bump_event_counter( string $key, string $event ): void {

		$this->get_event_counter()->bump( $key, $event );
	}

	/**
	 * Global counters store.
	 *
	 * @since 2.0.3
	 *
	 * @return EventCounter
	 */
	private function get_event_counter(): EventCounter {

		if ( $this->event_counter === null ) {
			$this->event_counter = new EventCounter( self::EVENTS_OPTION );
		}

		return $this->event_counter;
	}

	/**
	 * Check whether the AI Form Editor can handle the notification CTA.
	 *
	 * The client still verifies the Smart Edit FAB at click time; this gate
	 * only avoids shipping a prompt that can never be used.
	 *
	 * @since 2.0.1
	 *
	 * @return bool
	 */
	private function is_ai_available(): bool {

		return ! AIHelpers::is_disabled() && AIHelpers::is_license_active();
	}

	/**
	 * Get the candidate catalog in priority order.
	 *
	 * Copy and CTA labels live here only, so pending copy/UTM updates are
	 * a single-method change.
	 *
	 * @since 2.0.1
	 *
	 * @return array
	 */
	private function get_catalog(): array {

		return [
			'email_marketing' => [
				'title'   => __( 'Automatically Add These Contacts to Your Email List', 'wpforms' ),
				'message' => __( 'Connect this form to your email marketing service so new contacts are ready for follow-up.', 'wpforms' ),
				'button'  => __( 'Set Up Marketing', 'wpforms' ),
				'target'  => 'providers',
			],
			'file_upload'     => [
				'title'   => __( 'Collect File Attachments With This Form', 'wpforms' ),
				'message' => __( 'Add a file upload field so visitors can send a document, photo, or screenshot along with their message.', 'wpforms' ),
				'button'  => __( 'Add the Field', 'wpforms' ),
				'target'  => 'fields',
			],
			'notification'    => [
				'title'     => __( 'Never Leave a Submission Unanswered', 'wpforms' ),
				'message'   => __( 'Send an automatic email to the person who fills out this form, so no submission goes unanswered.', 'wpforms' ),
				'button'    => __( 'Add a Notification', 'wpforms' ),
				'target'    => 'notifications',
				'ai_prompt' => __( 'Add an email notification that goes to the email address entered in this form, letting the person know their submission was received.', 'wpforms' ),
			],
			'themes'          => [
				'title'   => __( 'Make Your Form Look Like It Belongs', 'wpforms' ),
				'message' => __( 'Style your form\'s colors, fonts, and layout so it looks like it belongs on your site. No code needed.', 'wpforms' ),
				'button'  => __( 'Style Your Form', 'wpforms' ),
				'target'  => 'themes',
			],
		];
	}
}
