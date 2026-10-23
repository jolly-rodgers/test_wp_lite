<?php

namespace WPForms\Pro\Admin\Dashboard\Widgets;

use WPForms\Admin\Dashboard\AccessContext;
use WPForms\Admin\Dashboard\WidgetState;
use WPForms\Admin\Dashboard\Widgets\Locations as LocationsBase;
use WPForms\Pro\Admin\Dashboard\Widgets\Locations\UniversallyPromo;

/**
 * Dashboard "Top Locations" widget (Pro).
 *
 * Resolves the three states from the Geolocation addon feed entitlement and the
 * addon-active check: the education upsell (not entitled), the install prompt
 * (entitled, inactive), and the filled table + donut (active). Registers
 * automatically when the Pro plugin is active (FQCN resolution of
 * `Admin\Dashboard\Widgets\Locations`).
 *
 * @since 2.0.2
 */
class Locations extends LocationsBase {

	/**
	 * Default number of countries shown in the table.
	 *
	 * @since 2.0.2
	 */
	private const DEFAULT_COUNTRIES = 5;

	/**
	 * Minimum number of countries the gear menu allows.
	 *
	 * @since 2.0.2
	 */
	private const MIN_COUNTRIES = 3;

	/**
	 * Maximum number of countries the gear menu allows.
	 *
	 * @since 2.0.2
	 */
	private const MAX_COUNTRIES = 10;

	/**
	 * Cap on the gear-menu "Exclude" list — the top N submitted countries.
	 *
	 * @since 2.0.2
	 */
	private const EXCLUDE_LIMIT = 10;

	/**
	 * Memoized view data for the current render (shared by the body + settings schema).
	 *
	 * @since 2.0.2
	 *
	 * @var array|null
	 */
	private $view_data = null;

	/**
	 * Resolve the widget state from the Geolocation entitlement and addon status.
	 *
	 * @since 2.0.2
	 *
	 * @param AccessContext $access Access context (unused; entitlement comes from the addon feed).
	 *
	 * @return WidgetState
	 * @noinspection PhpUnusedParameterInspection
	 */
	public function get_state( AccessContext $access ): WidgetState { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $access is part of the contract; entitlement is resolved from the addon feed.

		$addon = $this->get_addon_data( 'geolocation' );

		// Not entitled (Lite / Basic / Plus) — educational upsell.
		if ( empty( $addon['plugin_allow'] ) ) {
			return new WidgetState( true, 'education' );
		}

		// Entitled but the addon is not active — install/activate prompt.
		if ( ! $this->is_addon_active() ) {
			return new WidgetState( true, 'install' );
		}

		// Entitled and active — filled widget, repositioned above Addons (#40) and Integrations.
		return new WidgetState( true, 'data', 25 );
	}

	/**
	 * Whether the Geolocation addon is active — i.e. the widget shows live data.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	private function is_addon_active(): bool {

		return wpforms_is_addon_initialized( 'geolocation' );
	}

	/**
	 * Render the widget body per variant.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param array         $data    Aggregated dashboard data.
	 * @param AccessContext $access  Access context.
	 *
	 * @return string
	 */
	protected function render_body( string $variant, array $data, AccessContext $access ): string {

		if ( $variant === 'install' ) {
			return $this->render_empty_state(
				__( 'Geolocation', 'wpforms' ),
				__( 'Quickly see where in the world your visitors are located and gather stats and trends.', 'wpforms' ),
				[ $this->get_install_cta(), $this->get_learn_more_cta() ]
			);
		}

		if ( $variant === 'data' ) {
			return $this->has_location_data( $data )
				? $this->render_data( $data, $access )
				: $this->render_no_data_state();
		}

		return parent::render_body( $variant, $data, $access );
	}

	/**
	 * Render the widget for a freshly selected date range (the AJAX stats response),
	 * so the client can swap the whole widget in one step — data table ↔ no-data notice,
	 * gear included. Only the data variant depends on the range; the static education
	 * and install cards return an empty string.
	 *
	 * @since 2.0.2
	 *
	 * @param array         $data   Aggregated dashboard data for the selected range.
	 * @param AccessContext $access Access context.
	 *
	 * @return string
	 */
	public function render_for_range( array $data, AccessContext $access ): string {

		$state = $this->get_state( $access );

		if ( ! $state->is_visible() || $state->get_variant() !== 'data' ) {
			return '';
		}

		return $this->render( 'data', $data, $access );
	}

	/**
	 * Render the no-data state: a single notice, no sample chart and no buttons.
	 * Shown in the data variant when the addon is active but the selected range has
	 * not collected any location rows, so the widget shrinks to the notice instead of
	 * occupying a full card with placeholder data.
	 *
	 * @since 2.0.2
	 *
	 * @return string
	 */
	private function render_no_data_state(): string {

		return (string) wpforms_render(
			'admin/dashboard/widget-notice',
			[
				'label'   => __( 'Insufficient Data', 'wpforms' ),
				'message' => __( 'Sorry, there’s not enough location data from this date range.', 'wpforms' ),
			],
			true
		);
	}

	/**
	 * Whether the aggregated data holds any location rows for the selected range.
	 *
	 * @since 2.0.2
	 *
	 * @param array $data Aggregated dashboard data.
	 *
	 * @return bool
	 */
	private function has_location_data( array $data ): bool {

		return ! empty( $data['locations']['countries'] );
	}

	/**
	 * Declare the gear-menu settings — in every data state, including the no-data
	 * notice, where the row count stays configurable. The framework renders the
	 * popover + Save button and persists the values.
	 *
	 * @since 2.0.2
	 *
	 * @param string        $variant Template variant.
	 * @param array         $data    Aggregated dashboard data.
	 * @param AccessContext $access  Access context (unused).
	 *
	 * @return array
	 * @noinspection PhpUnusedParameterInspection
	 */
	protected function get_settings_schema( string $variant, array $data, AccessContext $access ): array { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- $access is part of the contract signature.

		if ( $variant !== 'data' ) {
			return [];
		}

		$view    = $this->get_view_data( isset( $data['locations'] ) && is_array( $data['locations'] ) ? $data['locations'] : [] );
		$options = [];

		for ( $number = self::MIN_COUNTRIES; $number <= self::MAX_COUNTRIES; $number++ ) {
			$options[ $number ] = number_format_i18n( $number );
		}

		$fields = [
			[
				'type'    => 'select',
				'name'    => 'number_of_countries',
				'label'   => __( 'Number of Countries', 'wpforms' ),
				'options' => $options,
				'value'   => $view['settings']['number_of_countries'],
			],
		];

		// The Exclude list only appears once the site has submitted countries to offer.
		if ( ! empty( $view['exclude_choices'] ) ) {
			$fields[] = [
				'type'          => 'checklist',
				'name'          => 'excluded',
				'label'         => __( 'Exclude', 'wpforms' ),
				'panel'         => true,
				'options'       => $view['exclude_choices'],
				'value'         => $view['settings']['excluded'],
				'min_unchecked' => 1,
			];
		}

		return $fields;
	}

	/**
	 * Render the filled data body: the country table + donut canvas + gear menu.
	 *
	 * @since 2.0.2
	 *
	 * @param array         $data   Aggregated dashboard data (reads the `locations` block).
	 * @param AccessContext $access Access context (dismissals for the Universally promo).
	 *
	 * @return string
	 */
	private function render_data( array $data, AccessContext $access ): string {

		$locations = isset( $data['locations'] ) && is_array( $data['locations'] ) ? $data['locations'] : [];
		$view      = $this->get_view_data( $locations );

		$view['universally'] = ( new UniversallyPromo() )->get_promo( $access );

		return (string) wpforms_render(
			'admin/dashboard/widgets/locations-chart',
			$view,
			true
		);
	}

	/**
	 * Build the data-state view data from the cached locations: sort by visitors,
	 * drop excluded countries, slice to the configured count, resolve names, and
	 * assign each surviving row a palette color. The donut total is the full
	 * location-tagged count — it is not reduced by exclusions or the row limit.
	 *
	 * The raw (unsliced) country list and the resolved settings ride along so the
	 * JS can re-run the same transform on date change and gear save.
	 *
	 * @since 2.0.2
	 *
	 * @param array $locations Cached `locations` block: `countries` + `donut_total`.
	 *
	 * @return array
	 */
	private function get_view_data( array $locations ): array {

		if ( $this->view_data === null ) {
			$this->view_data = $this->build_view_data( $locations );
		}

		return $this->view_data;
	}

	/**
	 * Build the data-state view data (see get_view_data()).
	 *
	 * @since 2.0.2
	 *
	 * @param array $locations Cached `locations` block: `countries` + `donut_total`.
	 *
	 * @return array
	 */
	private function build_view_data( array $locations ): array {

		$donut_total = (int) ( $locations['donut_total'] ?? 0 );
		$settings    = $this->get_resolved_settings();
		$all         = $this->map_countries( $locations['countries'] ?? [] );

		// Apply the user's exclusions, keeping one country whatever they excluded, then cap
		// to the configured count and color by row index.
		$visible = array_values(
			array_filter(
				$all,
				static function ( $country ) use ( $settings ) {

					return ! in_array( $country['code'], $settings['excluded'], true );
				}
			)
		);

		if ( ! $visible && $all ) {
			$visible = [ $all[0] ];
		}

		$visible = array_slice( $visible, 0, $settings['number_of_countries'] );

		return [
			'countries'       => $this->apply_palette( $visible ),
			'all_countries'   => $all,
			'donut_total'     => $donut_total,
			'settings'        => $settings,
			'exclude_choices' => $this->get_exclude_choices( $all, $settings['excluded'] ),
			'palette'         => self::PALETTE,
		];
	}

	/**
	 * Build the gear menu's Exclude list: everything already excluded, so it can be put
	 * back, plus the top submitted countries among the rest.
	 *
	 * Offering the top N of the full list instead would strand the user: exclude those N
	 * and the countries that take their place in the table are never offered, so the list
	 * stops matching what the widget shows.
	 *
	 * Every exclusion is listed even when the selected range holds no visitors from it,
	 * because the checklist only submits the options it rendered, so an exclusion left out
	 * of the list would be dropped by the next save.
	 *
	 * @since 2.0.2
	 *
	 * @param array $all      Sorted [ code, name, share, visitors ] rows.
	 * @param array $excluded Excluded country ISO codes.
	 *
	 * @return array ISO code => country name, the range's own countries first.
	 */
	private function get_exclude_choices( array $all, array $excluded ): array {

		$choices   = [];
		$remaining = self::EXCLUDE_LIMIT;

		foreach ( $all as $country ) {
			$is_excluded = in_array( $country['code'], $excluded, true );

			if ( ! $is_excluded && $remaining < 1 ) {
				continue;
			}

			$choices[ $country['code'] ] = $country['name'];
			$remaining                  -= $is_excluded ? 0 : 1;
		}

		$names = $this->get_country_names();

		foreach ( $excluded as $code ) {
			if ( ! isset( $choices[ $code ] ) ) {
				$choices[ $code ] = $names[ $code ] ?? $code;
			}
		}

		return $choices;
	}

	/**
	 * Get an addon's feed data (entitlement, action, install payload).
	 *
	 * @since 2.0.2
	 *
	 * @param string $slug Addon slug.
	 *
	 * @return array Addon data, or an empty array when the addons service is unavailable.
	 */
	private function get_addon_data( string $slug ): array {

		$addons = wpforms()->obj( 'addons' );

		return $addons ? (array) $addons->get_addon( $slug ) : [];
	}

	/**
	 * Build the in-place install/activate CTA payload for an addon from the feed.
	 *
	 * @since 2.0.2
	 *
	 * @param string $slug Addon slug.
	 *
	 * @return array {
	 *     @type string $label    CTA label ("Activate" or "Install & Activate").
	 *     @type bool   $activate Whether the addon is installed-inactive (activate vs install).
	 *     @type array  $attrs    Education toggle-plugin data attributes.
	 * }
	 */
	private function get_addon_cta( string $slug ): array {

		$addon    = $this->get_addon_data( $slug );
		$activate = ( $addon['action'] ?? '' ) === 'activate';

		return [
			'label'    => $activate ? __( 'Activate', 'wpforms' ) : __( 'Install & Activate', 'wpforms' ),
			'activate' => $activate,
			'attrs'    => [
				'data-plugin' => $activate ? ( $addon['path'] ?? '' ) : ( $addon['url'] ?? '' ),
				'data-action' => $activate ? 'activate' : 'install',
				'data-type'   => 'addon',
				'data-nonce'  => $addon['nonce'] ?? '',
			],
		];
	}

	/**
	 * Sort the cached country rows by visitors (desc) and resolve each ISO code
	 * to a localized country name. Rows without a country code are dropped.
	 *
	 * @since 2.0.2
	 *
	 * @param mixed $countries Raw cached country rows.
	 *
	 * @return array List of [ code, name, share, visitors ] rows, highest visitors first.
	 */
	private function map_countries( $countries ): array {

		$countries = is_array( $countries ) ? $countries : [];
		$names     = $this->get_country_names();
		$mapped    = [];

		foreach ( $countries as $country ) {
			$code = (string) ( $country['country'] ?? '' );

			if ( $code === '' ) {
				continue;
			}

			$mapped[] = [
				'code'     => $code,
				'name'     => $names[ $code ] ?? $code,
				'share'    => (float) ( $country['share'] ?? 0 ),
				'visitors' => (int) ( $country['visitors'] ?? 0 ),
			];
		}

		usort(
			$mapped,
			static function ( $a, $b ) {

				return $b['visitors'] <=> $a['visitors'];
			}
		);

		return $mapped;
	}

	/**
	 * Country display names: the localized country list with a handful of verbose
	 * official ISO names replaced by their common short form (e.g. "United States
	 * of America" → "United States"), so the compact table and donut labels stay
	 * readable.
	 *
	 * Names are returned raw (unescaped); callers escape them on output. The
	 * `wpforms_countries()` values arrive HTML-escaped, so they are decoded here to
	 * avoid double-escaping once re-escaped downstream.
	 *
	 * @since 2.0.2
	 *
	 * @return array ISO code => display name.
	 */
	private function get_country_names(): array {

		static $names = null;

		if ( $names !== null ) {
			return $names;
		}

		$short = [
			'BO' => __( 'Bolivia', 'wpforms' ),
			'CD' => __( 'DR Congo', 'wpforms' ),
			'FM' => __( 'Micronesia', 'wpforms' ),
			'GB' => __( 'United Kingdom', 'wpforms' ),
			'IE' => __( 'Ireland', 'wpforms' ),
			'IR' => __( 'Iran', 'wpforms' ),
			'KP' => __( 'North Korea', 'wpforms' ),
			'KR' => __( 'South Korea', 'wpforms' ),
			'LA' => __( 'Laos', 'wpforms' ),
			'MD' => __( 'Moldova', 'wpforms' ),
			'MK' => __( 'North Macedonia', 'wpforms' ),
			'PS' => __( 'Palestine', 'wpforms' ),
			'SY' => __( 'Syria', 'wpforms' ),
			'SZ' => __( 'Eswatini', 'wpforms' ),
			'TW' => __( 'Taiwan', 'wpforms' ),
			'TZ' => __( 'Tanzania', 'wpforms' ),
			'US' => __( 'United States', 'wpforms' ),
			'VE' => __( 'Venezuela', 'wpforms' ),
		];

		// `wpforms_countries()` values are HTML-escaped; decode so downstream
		// output escaping (PHP `esc_html`, JS `_.escape`) does not double-escape.
		$countries = array_map(
			static function ( $name ) {

				return html_entity_decode( $name, ENT_QUOTES );
			},
			wpforms_countries()
		);

		$names = array_merge( $countries, $short );

		return $names;
	}

	/**
	 * Get the per-user gear settings, clamped to the allowed range.
	 *
	 * @since 2.0.2
	 *
	 * @return array {
	 *     @type int   $number_of_countries Row count, clamped to MIN..MAX.
	 *     @type array $excluded            Excluded country ISO codes.
	 * }
	 */
	private function get_resolved_settings(): array {

		$settings = $this->get_settings();
		$number   = (int) ( $settings['number_of_countries'] ?? self::DEFAULT_COUNTRIES );
		$number   = max( self::MIN_COUNTRIES, min( self::MAX_COUNTRIES, $number ) );
		// The list posts a hidden sentinel so an all-unchecked save still submits, so the
		// stored value can carry an empty entry that matches no country.
		$excluded = isset( $settings['excluded'] ) && is_array( $settings['excluded'] )
			? array_values( array_filter( array_map( 'strval', $settings['excluded'] ) ) )
			: [];

		return [
			'number_of_countries' => $number,
			'excluded'            => $excluded,
		];
	}

	/**
	 * The widget's default gear settings, in the shape its popover submits.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	public function get_default_settings(): array {

		return [
			'number_of_countries' => (string) self::DEFAULT_COUNTRIES,
			'excluded'            => [],
		];
	}

	/**
	 * Build the install/activate CTA from the Geolocation addon feed data.
	 *
	 * @since 2.0.2
	 *
	 * @return array
	 */
	private function get_install_cta(): array {

		$cta = $this->get_addon_cta( 'geolocation' );

		return [
			'label'   => $cta['label'],
			'url'     => '#',
			'classes' => 'wpforms-btn wpforms-btn-md wpforms-btn-blue wpforms-education-toggle-plugin-btn ' . ( $cta['activate'] ? 'status-installed' : 'status-missing' ),
			'target'  => '',
			'attrs'   => $cta['attrs'],
		];
	}
}
