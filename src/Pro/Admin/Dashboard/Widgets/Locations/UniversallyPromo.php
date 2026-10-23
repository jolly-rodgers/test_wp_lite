<?php

namespace WPForms\Pro\Admin\Dashboard\Widgets\Locations;

use WPForms\Admin\Dashboard\AccessContext;
use WPForms\Pro\Admin\Dashboard\Languages;

/**
 * Universally cross-promo for the Top Locations widget.
 *
 * Decides whether the widget should suggest the Universally translation plugin, based on
 * how much of the audience needs a different translation than the site's, and builds the
 * install CTA. It reads the languages stored with the entries rather than the widget's own
 * geo data, so the same verdict can serve any surface that carries the promo.
 *
 * @since 2.0.2
 */
class UniversallyPromo {

	/**
	 * Universally plugin main file (folder/file.php). Universally is a free
	 * WordPress.org partner plugin, not a licensed WPForms addon.
	 *
	 * @since 2.0.2
	 */
	private const UNIVERSALLY_BASENAME = 'universally-language-translation-multilingual-tool/universally.php';

	/**
	 * Universally WordPress.org install package.
	 *
	 * @since 2.0.2
	 */
	private const UNIVERSALLY_ZIP = 'https://downloads.wordpress.org/plugin/universally-language-translation-multilingual-tool.zip';

	/**
	 * Days the trigger evaluates, regardless of the range the user picked. A promo that
	 * appeared and disappeared as the datepicker moved would read as a glitch.
	 *
	 * @since 2.0.2
	 */
	private const WINDOW_DAYS = 30;

	/**
	 * Visitors the window needs before the trigger evaluates at all, so the share is
	 * not derived from a handful of submissions. Team-owned value, not a site setting.
	 *
	 * @since 2.0.2
	 */
	private const MIN_VISITORS = 25;

	/**
	 * Combined share of visitors needing any translation other than the site's before the
	 * promo appears, however that share splits across languages. Ten languages at 2% each
	 * is a stronger case for translating than one at 21%, not a weaker one: that audience
	 * is the one a site owner cannot serve on their own.
	 *
	 * @since 2.0.2
	 */
	private const OTHER_VARIANT_THRESHOLD = 0.20;

	/**
	 * Share a single translation needs before the notice names it. Half the trigger, so a
	 * fragmented audience leads with the combined share and names nobody: with ten
	 * languages at 2% each, naming the largest would undersell the case.
	 *
	 * @since 2.0.2
	 */
	private const NAMED_VARIANT_THRESHOLD = 0.10;

	/**
	 * Most translations the notice names before it reads as a list.
	 *
	 * @since 2.0.2
	 */
	private const MAX_NAMED_VARIANTS = 2;

	/**
	 * Build the promo data for the trigger window, or an empty array when it should stay hidden.
	 *
	 * The promo answers "would enough visitors need this site translated?", so the trigger
	 * compares translation variants: the site's own against the one each visitor's stored
	 * language resolves to. Entries without a language stay out of the comparison, so the
	 * share is never diluted by the ones that predate the capture.
	 *
	 * @since 2.0.2
	 *
	 * @param AccessContext $access Access context.
	 *
	 * @return array Promo data (share, languages, days, cta), or [] when hidden.
	 */
	public function get_promo( AccessContext $access ): array {

		// Hidden once the plugin is active, or once the user dismissed it.
		if ( $this->is_active() ) {
			return [];
		}

		if ( ! empty( $access->get_dismissals()['edu-dashboard-universally-promo'] ) ) {
			return [];
		}

		$other = $this->get_other_variant_share(
			wpforms()->obj( 'dashboard_cache' )->get_languages_window( self::WINDOW_DAYS )
		);

		if ( ! $other ) {
			return [];
		}

		return array_merge(
			$other,
			[
				'days' => self::WINDOW_DAYS,
				'cta'  => $this->get_cta(),
			]
		);
	}

	/**
	 * Whether the Universally plugin is active.
	 *
	 * @since 2.0.2
	 *
	 * @return bool
	 */
	private function is_active(): bool {

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		return is_plugin_active( self::UNIVERSALLY_BASENAME );
	}

	/**
	 * Build the in-place install/activate CTA for the Universally partner plugin.
	 * Unlike a licensed addon, it installs from WordPress.org (`type => plugin`):
	 * missing → install from the package, installed-inactive → activate by path.
	 *
	 * @since 2.0.2
	 *
	 * @return array {
	 *     @type string $label CTA label.
	 *     @type array  $attrs Education toggle-plugin data attributes.
	 * }
	 */
	private function get_cta(): array {

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$installed = array_key_exists( self::UNIVERSALLY_BASENAME, get_plugins() );

		return [
			'label' => $installed ? __( 'Activate', 'wpforms' ) : __( 'Install & Activate', 'wpforms' ),
			'attrs' => [
				'data-plugin' => $installed ? self::UNIVERSALLY_BASENAME : self::UNIVERSALLY_ZIP,
				'data-action' => $installed ? 'activate' : 'install',
				'data-type'   => 'plugin',
			],
		];
	}

	/**
	 * Weigh the window's languages against the site's own translation: the combined share
	 * that needs another one, and the few translations big enough for the copy to name.
	 *
	 * Languages the map cannot resolve, and every language when the site locale itself
	 * cannot be resolved, stay out of the comparison rather than count as foreign.
	 *
	 * @since 2.0.2
	 *
	 * @param array $counts Entry counts keyed by stored language tag.
	 *
	 * @return array {
	 *     Empty when the window is too small, or the share too low, to surface the promo.
	 *
	 *     @type int   $share     Combined share needing another translation, in percent.
	 *     @type array $languages Display names of the translations worth naming, biggest first.
	 * }
	 */
	private function get_other_variant_share( array $counts ): array {

		$site_variant = Languages::get_site_variant();

		if ( $site_variant === '' ) {
			return [];
		}

		$groups = [];

		foreach ( $counts as $tag => $entries ) {
			// Languages group by variant: several of them can share one translation, and
			// a language we cannot resolve counts on neither side of the share.
			$variant = Languages::get_tag_variant( (string) $tag );

			if ( $variant !== '' ) {
				$groups[ $variant ] = ( $groups[ $variant ] ?? 0 ) + $entries;
			}
		}

		$total = array_sum( $groups );

		unset( $groups[ $site_variant ] );

		$other = array_sum( $groups );

		// The floor doubles as the division guard: a window where no tag resolves has no
		// total at all, and that must not depend on the constant being above zero, which
		// is the first thing lowered to see the promo.
		if ( $total < max( self::MIN_VISITORS, 1 ) || $other / $total <= self::OTHER_VARIANT_THRESHOLD ) {
			return [];
		}

		arsort( $groups );

		return [
			'share'     => (int) round( $other / $total * 100 ),
			'languages' => $this->get_named_variants( $groups, $total ),
		];
	}

	/**
	 * Pick the translations the notice names: the biggest ones that carry a share of their
	 * own. An audience split across many small languages names none of them and leads with
	 * the combined share instead.
	 *
	 * @since 2.0.2
	 *
	 * @param array $groups Entry counts per variant, biggest first.
	 * @param int   $total  Entries whose variant is known, the share's base.
	 *
	 * @return array Display names, biggest first. Empty when no single one is big enough.
	 */
	private function get_named_variants( array $groups, int $total ): array {

		$named = [];

		foreach ( $groups as $variant => $entries ) {
			if ( count( $named ) >= self::MAX_NAMED_VARIANTS || $entries / $total < self::NAMED_VARIANT_THRESHOLD ) {
				break;
			}

			$name = Languages::get_variant_name( $variant );

			if ( $name !== '' ) {
				$named[] = $name;
			}
		}

		return $named;
	}
}
