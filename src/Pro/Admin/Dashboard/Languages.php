<?php

namespace WPForms\Pro\Admin\Dashboard;

/**
 * Translation-variant resolution for the Dashboard behavior triggers.
 *
 * Resolves a language tag, stored with an entry or read from the site locale, to the
 * translation that serves it. The unit is a Universally variant, not a language: it
 * translates per variant, so `pt-BR` and `pt-PT` are two translations while every Latin
 * American Spanish locale shares `es-419`. Grouping by bare language would merge
 * translations that differ; grouping by raw locale would split an audience that needs
 * only one.
 *
 * The country map behind it resolves a tag's region subtag, so it carries a dominant
 * variant per country rather than every language spoken there. It errs toward silence:
 * a tag it cannot resolve is left out of the comparison instead of counted as foreign.
 *
 * @since 2.0.2
 */
class Languages {

	/**
	 * The translation variant per country, keyed by ISO code. Generated from the CLDR
	 * locale list, with the multilingual countries resolved to the language their sites
	 * are normally written in, and the Spanish, Portuguese and Chinese ones to the
	 * variant Universally ships. Uninhabited territories are absent on purpose.
	 *
	 * @since 2.0.2
	 */
	private const COUNTRY_VARIANTS = [
		'AD' => 'ca',
		'AE' => 'ar',
		'AF' => 'fa',
		'AG' => 'en',
		'AI' => 'en',
		'AL' => 'sq',
		'AM' => 'hy',
		'AO' => 'pt-PT',
		'AR' => 'es-419',
		'AS' => 'en',
		'AT' => 'de',
		'AU' => 'en',
		'AW' => 'nl',
		'AX' => 'sv',
		'AZ' => 'az',
		'BA' => 'bs',
		'BB' => 'en',
		'BD' => 'bn',
		'BE' => 'nl',
		'BF' => 'fr',
		'BG' => 'bg',
		'BH' => 'ar',
		'BI' => 'fr',
		'BJ' => 'fr',
		'BL' => 'fr',
		'BM' => 'en',
		'BN' => 'ms',
		'BO' => 'es-419',
		'BQ' => 'nl',
		'BR' => 'pt-BR',
		'BS' => 'en',
		'BT' => 'dz',
		'BW' => 'en',
		'BY' => 'be',
		'BZ' => 'en',
		'CA' => 'en',
		'CC' => 'en',
		'CD' => 'fr',
		'CF' => 'fr',
		'CG' => 'fr',
		'CH' => 'de',
		'CI' => 'fr',
		'CK' => 'en',
		'CL' => 'es-419',
		'CM' => 'fr',
		'CN' => 'zh-CN',
		'CO' => 'es-419',
		'CR' => 'es-419',
		'CU' => 'es-419',
		'CV' => 'pt-PT',
		'CW' => 'nl',
		'CX' => 'en',
		'CY' => 'el',
		'CZ' => 'cs',
		'DE' => 'de',
		'DJ' => 'fr',
		'DK' => 'da',
		'DM' => 'en',
		'DO' => 'es-419',
		'DZ' => 'ar',
		'EC' => 'es-419',
		'EE' => 'et',
		'EG' => 'ar',
		'EH' => 'ar',
		'ER' => 'ti',
		'ES' => 'es-ES',
		'ET' => 'am',
		'FI' => 'fi',
		'FJ' => 'en',
		'FK' => 'en',
		'FM' => 'en',
		'FO' => 'fo',
		'FR' => 'fr',
		'GA' => 'fr',
		'GB' => 'en',
		'GD' => 'en',
		'GE' => 'ka',
		'GF' => 'fr',
		'GG' => 'en',
		'GH' => 'en',
		'GI' => 'en',
		'GL' => 'kl',
		'GM' => 'en',
		'GN' => 'fr',
		'GP' => 'fr',
		'GQ' => 'es-ES',
		'GR' => 'el',
		'GS' => 'en',
		'GT' => 'es-419',
		'GU' => 'en',
		'GW' => 'pt-PT',
		'GY' => 'en',
		'HK' => 'zh-TW',
		'HN' => 'es-419',
		'HR' => 'hr',
		'HT' => 'fr',
		'HU' => 'hu',
		'ID' => 'id',
		'IE' => 'en',
		'IL' => 'he',
		'IM' => 'en',
		'IN' => 'hi',
		'IO' => 'en',
		'IQ' => 'ar',
		'IR' => 'fa',
		'IS' => 'is',
		'IT' => 'it',
		'JE' => 'en',
		'JM' => 'en',
		'JO' => 'ar',
		'JP' => 'ja',
		'KE' => 'sw',
		'KG' => 'ky',
		'KH' => 'km',
		'KI' => 'en',
		'KM' => 'fr',
		'KN' => 'en',
		'KP' => 'ko',
		'KR' => 'ko',
		'KW' => 'ar',
		'KY' => 'en',
		'KZ' => 'kk',
		'LA' => 'lo',
		'LB' => 'ar',
		'LC' => 'en',
		'LI' => 'de',
		'LK' => 'si',
		'LR' => 'en',
		'LS' => 'en',
		'LT' => 'lt',
		'LU' => 'fr',
		'LV' => 'lv',
		'LY' => 'ar',
		'MA' => 'ar',
		'MC' => 'fr',
		'MD' => 'ro',
		'ME' => 'sr',
		'MF' => 'fr',
		'MG' => 'mg',
		'MH' => 'en',
		'MK' => 'mk',
		'ML' => 'fr',
		'MM' => 'my',
		'MN' => 'mn',
		'MO' => 'zh-TW',
		'MP' => 'en',
		'MQ' => 'fr',
		'MR' => 'ar',
		'MS' => 'en',
		'MT' => 'mt',
		'MU' => 'en',
		'MV' => 'dv',
		'MW' => 'en',
		'MX' => 'es-419',
		'MY' => 'ms',
		'MZ' => 'pt-PT',
		'NA' => 'en',
		'NC' => 'fr',
		'NE' => 'fr',
		'NF' => 'en',
		'NG' => 'en',
		'NI' => 'es-419',
		'NL' => 'nl',
		'NO' => 'nb',
		'NP' => 'ne',
		'NR' => 'en',
		'NU' => 'en',
		'NZ' => 'en',
		'OM' => 'ar',
		'PA' => 'es-419',
		'PE' => 'es-419',
		'PF' => 'fr',
		'PG' => 'en',
		'PH' => 'fil',
		'PK' => 'ur',
		'PL' => 'pl',
		'PM' => 'fr',
		'PN' => 'en',
		'PR' => 'es-419',
		'PS' => 'ar',
		'PT' => 'pt-PT',
		'PW' => 'en',
		'PY' => 'es-419',
		'QA' => 'ar',
		'RE' => 'fr',
		'RO' => 'ro',
		'RS' => 'sr',
		'RU' => 'ru',
		'RW' => 'rw',
		'SA' => 'ar',
		'SB' => 'en',
		'SC' => 'en',
		'SD' => 'ar',
		'SE' => 'sv',
		'SG' => 'en',
		'SH' => 'en',
		'SI' => 'sl',
		'SJ' => 'nb',
		'SK' => 'sk',
		'SL' => 'en',
		'SM' => 'it',
		'SN' => 'fr',
		'SO' => 'so',
		'SR' => 'nl',
		'SS' => 'en',
		'ST' => 'pt-PT',
		'SV' => 'es-419',
		'SX' => 'nl',
		'SY' => 'ar',
		'SZ' => 'en',
		'TC' => 'en',
		'TD' => 'fr',
		'TG' => 'fr',
		'TH' => 'th',
		'TJ' => 'tg',
		'TK' => 'en',
		'TL' => 'pt-PT',
		'TM' => 'tk',
		'TN' => 'ar',
		'TO' => 'to',
		'TR' => 'tr',
		'TT' => 'en',
		'TV' => 'en',
		'TW' => 'zh-TW',
		'TZ' => 'sw',
		'UA' => 'uk',
		'UG' => 'en',
		'UM' => 'en',
		'US' => 'en',
		'UY' => 'es-419',
		'UZ' => 'uz',
		'VA' => 'it',
		'VC' => 'en',
		'VE' => 'es-419',
		'VG' => 'en',
		'VI' => 'en',
		'VN' => 'vi',
		'VU' => 'en',
		'WF' => 'fr',
		'WS' => 'en',
		'XK' => 'sq',
		'YE' => 'ar',
		'YT' => 'fr',
		'ZA' => 'en',
		'ZM' => 'en',
		'ZW' => 'en',
	];

	/**
	 * Chinese script subtags mapped to the variant that writes them, for the requests
	 * that name a script instead of a region (`zh-Hans` rather than `zh-CN`).
	 *
	 * @since 2.0.2
	 */
	private const SCRIPT_VARIANTS = [
		'hans' => 'zh-CN',
		'hant' => 'zh-TW',
	];

	/**
	 * The three languages Universally ships as two translations, mapped to the variant a
	 * region reads: the regions taking the minority variant, then the default for the rest.
	 *
	 * The country map cannot answer this, because it holds the language a country speaks,
	 * not which of two translations it writes: Singapore speaks English, yet a `zh-SG`
	 * visitor reads Simplified Chinese. Every other language is one translation, so its
	 * region needs no table.
	 *
	 * @since 2.0.2
	 */
	private const SPLIT_FAMILIES = [
		'es' => [
			'default' => 'es-419',
			'regions' => [
				'ES' => 'es-ES',
				'GQ' => 'es-ES',
			],
		],
		'pt' => [
			'default' => 'pt-PT',
			'regions' => [
				'BR' => 'pt-BR',
			],
		],
		'zh' => [
			'default' => 'zh-CN',
			'regions' => [
				'TW' => 'zh-TW',
				'HK' => 'zh-TW',
				'MO' => 'zh-TW',
			],
		],
	];

	/**
	 * Get the site's own translation variant.
	 *
	 * Reads the site locale rather than `wpforms_get_language_code()`, which is based on
	 * the user locale: an admin browsing a Ukrainian site in English must not turn every
	 * Ukrainian visitor into a foreign one.
	 *
	 * @since 2.0.2
	 *
	 * @return string Variant, or an empty string when it cannot be resolved.
	 */
	public static function get_site_variant(): string {

		return self::get_tag_variant( get_locale() );
	}

	/**
	 * Get the translation variant of a language tag, whether it came from a locale
	 * (`es_MX`) or from a request header (`pt-br`, `zh-hans-cn`, `de`).
	 *
	 * The tag's region resolves through the country map, so `es_MX` and `es-AR` both land
	 * on `es-419`. A tag with no usable region falls back to its bare language, which
	 * resolves only for the languages Universally ships as a single translation: a bare
	 * `es` or `pt` returns an empty string on purpose, because it does not say which of
	 * the two translations to offer, and callers skip it rather than guess.
	 *
	 * @since 2.0.2
	 *
	 * @param string $tag Language tag, with either separator.
	 *
	 * @return string Variant, or an empty string when it cannot be resolved.
	 */
	public static function get_tag_variant( string $tag ): string {

		$parts    = preg_split( '/[_-]/', strtolower( $tag ) );
		$language = $parts[0] ?? '';
		$variant  = '';

		// Read the subtags rather than take the second one: a tag can carry a script
		// (`zh-hans-cn`), and WordPress ships variant locales like `de_DE_formal`.
		foreach ( array_slice( $parts, 1 ) as $subtag ) {
			$variant = self::SCRIPT_VARIANTS[ $subtag ] ?? self::get_subtag_variant( $language, $subtag );

			if ( $variant !== '' ) {
				break;
			}
		}

		// Keep the subtag's variant only when it belongs to the tag's own language:
		// WordPress ships locales like `fr_CA`, whose country reads another language.
		if ( $variant === $language || strpos( $variant, $language . '-' ) === 0 ) {
			return $variant;
		}

		// The display names double as the set of variants Universally ships as one
		// translation, so a bare `es` or `pt` finds no key and resolves to nothing.
		return array_key_exists( $language, self::get_variant_names() ) ? $language : '';
	}

	/**
	 * Get the variant a tag's subtag stands for.
	 *
	 * A request can name the variant itself, since `pt-br` and the `es-419` browsers send
	 * for Latin America are the units Universally ships. Failing that, a language split
	 * across two translations reads its own region table, and everything else resolves
	 * through the country map, which is what turns `es-mx` into the same `es-419`.
	 *
	 * @since 2.0.2
	 *
	 * @param string $language The tag's language.
	 * @param string $subtag   Subtag following it.
	 *
	 * @return string Variant, or an empty string when the subtag resolves to none.
	 */
	private static function get_subtag_variant( string $language, string $subtag ): string {

		$named = $language . '-' . strtoupper( $subtag );

		if ( array_key_exists( $named, self::get_variant_names() ) ) {
			return $named;
		}

		$country_variant = self::get_country_variant( $subtag );

		// A split family answers from its own table, but only for a region the country map
		// knows, so an invented `es-zz` still resolves to nothing rather than to the default.
		if ( $country_variant !== '' && isset( self::SPLIT_FAMILIES[ $language ] ) ) {
			$family = self::SPLIT_FAMILIES[ $language ];

			return $family['regions'][ strtoupper( $subtag ) ] ?? $family['default'];
		}

		return $country_variant;
	}

	/**
	 * Get the translation variant a country reads.
	 *
	 * @since 2.0.2
	 *
	 * @param string $country Country ISO code.
	 *
	 * @return string Variant, or an empty string for an unknown country.
	 */
	private static function get_country_variant( string $country ): string {

		return self::COUNTRY_VARIANTS[ strtoupper( $country ) ] ?? '';
	}

	/**
	 * Get a variant's display name.
	 *
	 * @since 2.0.2
	 *
	 * @param string $variant Variant.
	 *
	 * @return string Display name, or an empty string for an unknown variant.
	 */
	public static function get_variant_name( string $variant ): string {

		return self::get_variant_names()[ $variant ] ?? '';
	}

	/**
	 * Display names of every variant the country map resolves to.
	 *
	 * @since 2.0.2
	 *
	 * @return array Variant => display name.
	 */
	private static function get_variant_names(): array {

		static $names = null;

		if ( $names !== null ) {
			return $names;
		}

		$names = [
			'am'     => __( 'Amharic', 'wpforms' ),
			'ar'     => __( 'Arabic', 'wpforms' ),
			'az'     => __( 'Azerbaijani', 'wpforms' ),
			'be'     => __( 'Belarusian', 'wpforms' ),
			'bg'     => __( 'Bulgarian', 'wpforms' ),
			'bn'     => __( 'Bengali', 'wpforms' ),
			'bs'     => __( 'Bosnian', 'wpforms' ),
			'ca'     => __( 'Catalan', 'wpforms' ),
			'cs'     => __( 'Czech', 'wpforms' ),
			'da'     => __( 'Danish', 'wpforms' ),
			'de'     => __( 'German', 'wpforms' ),
			'dv'     => __( 'Dhivehi', 'wpforms' ),
			'dz'     => __( 'Dzongkha', 'wpforms' ),
			'el'     => __( 'Greek', 'wpforms' ),
			'en'     => __( 'English', 'wpforms' ),
			'es-419' => __( 'Latin American Spanish', 'wpforms' ),
			'es-ES'  => __( 'Spanish', 'wpforms' ),
			'et'     => __( 'Estonian', 'wpforms' ),
			'fa'     => __( 'Persian', 'wpforms' ),
			'fi'     => __( 'Finnish', 'wpforms' ),
			'fil'    => __( 'Filipino', 'wpforms' ),
			'fo'     => __( 'Faroese', 'wpforms' ),
			'fr'     => __( 'French', 'wpforms' ),
			'he'     => __( 'Hebrew', 'wpforms' ),
			'hi'     => __( 'Hindi', 'wpforms' ),
			'hr'     => __( 'Croatian', 'wpforms' ),
			'hu'     => __( 'Hungarian', 'wpforms' ),
			'hy'     => __( 'Armenian', 'wpforms' ),
			'id'     => __( 'Indonesian', 'wpforms' ),
			'is'     => __( 'Icelandic', 'wpforms' ),
			'it'     => __( 'Italian', 'wpforms' ),
			'ja'     => __( 'Japanese', 'wpforms' ),
			'ka'     => __( 'Georgian', 'wpforms' ),
			'kk'     => __( 'Kazakh', 'wpforms' ),
			'kl'     => __( 'Greenlandic', 'wpforms' ),
			'km'     => __( 'Khmer', 'wpforms' ),
			'ko'     => __( 'Korean', 'wpforms' ),
			'ky'     => __( 'Kyrgyz', 'wpforms' ),
			'lo'     => __( 'Lao', 'wpforms' ),
			'lt'     => __( 'Lithuanian', 'wpforms' ),
			'lv'     => __( 'Latvian', 'wpforms' ),
			'mg'     => __( 'Malagasy', 'wpforms' ),
			'mk'     => __( 'Macedonian', 'wpforms' ),
			'mn'     => __( 'Mongolian', 'wpforms' ),
			'ms'     => __( 'Malay', 'wpforms' ),
			'mt'     => __( 'Maltese', 'wpforms' ),
			'my'     => __( 'Burmese', 'wpforms' ),
			'nb'     => __( 'Norwegian', 'wpforms' ),
			'ne'     => __( 'Nepali', 'wpforms' ),
			'nl'     => __( 'Dutch', 'wpforms' ),
			'pl'     => __( 'Polish', 'wpforms' ),
			'pt-BR'  => __( 'Brazilian Portuguese', 'wpforms' ),
			'pt-PT'  => __( 'Portuguese', 'wpforms' ),
			'ro'     => __( 'Romanian', 'wpforms' ),
			'ru'     => __( 'Russian', 'wpforms' ),
			'rw'     => __( 'Kinyarwanda', 'wpforms' ),
			'si'     => __( 'Sinhala', 'wpforms' ),
			'sk'     => __( 'Slovak', 'wpforms' ),
			'sl'     => __( 'Slovenian', 'wpforms' ),
			'so'     => __( 'Somali', 'wpforms' ),
			'sq'     => __( 'Albanian', 'wpforms' ),
			'sr'     => __( 'Serbian', 'wpforms' ),
			'sv'     => __( 'Swedish', 'wpforms' ),
			'sw'     => __( 'Swahili', 'wpforms' ),
			'tg'     => __( 'Tajik', 'wpforms' ),
			'th'     => __( 'Thai', 'wpforms' ),
			'ti'     => __( 'Tigrinya', 'wpforms' ),
			'tk'     => __( 'Turkmen', 'wpforms' ),
			'to'     => __( 'Tongan', 'wpforms' ),
			'tr'     => __( 'Turkish', 'wpforms' ),
			'uk'     => __( 'Ukrainian', 'wpforms' ),
			'ur'     => __( 'Urdu', 'wpforms' ),
			'uz'     => __( 'Uzbek', 'wpforms' ),
			'vi'     => __( 'Vietnamese', 'wpforms' ),
			'zh-CN'  => __( 'Simplified Chinese', 'wpforms' ),
			'zh-TW'  => __( 'Traditional Chinese', 'wpforms' ),
		];

		return $names;
	}
}
