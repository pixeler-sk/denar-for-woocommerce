<?php
/**
 * Stored plugin settings.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Typed access to the plugin options. Credentials may also come from
 * wp-config constants (DENAR_WC_API_KEY, DENAR_WC_WEBHOOK_SECRET, DENAR_WC_API_URL),
 * which win over the database - handy for staging copies of a live shop.
 */
final class Settings {

	public const DEFAULT_API_URL = 'https://api.denar.sk/v1';

	public const OPTION_API_URL = 'denar_wc_api_url';

	public const OPTION_API_KEY = 'denar_wc_api_key';

	public const OPTION_WEBHOOK_SECRET = 'denar_wc_webhook_secret';

	public const OPTION_CONNECTION = 'denar_wc_connection';

	public const OPTION_ENABLED = 'denar_wc_enabled';

	public const OPTION_BACS_PROFORMA = 'denar_wc_bacs_proforma';

	public const OPTION_NUMBER_SERIES = 'denar_wc_number_series';

	public const OPTION_REVERSE_CHARGE = 'denar_wc_reverse_charge_regime';

	public const OPTION_SITE_KEY = 'denar_wc_site_key';

	/**
	 * Base URL of the API without a trailing slash.
	 */
	public static function api_url(): string {
		$url = self::constant( 'DENAR_WC_API_URL' ) ?? (string) get_option( self::OPTION_API_URL, '' );

		return untrailingslashit( '' !== trim( $url ) ? trim( $url ) : self::DEFAULT_API_URL );
	}

	/**
	 * API key of the organization, empty when not set.
	 */
	public static function api_key(): string {
		return trim( self::constant( 'DENAR_WC_API_KEY' ) ?? (string) get_option( self::OPTION_API_KEY, '' ) );
	}

	/**
	 * Webhook signing secret (whsec_...), empty when not set.
	 */
	public static function webhook_secret(): string {
		return trim( self::constant( 'DENAR_WC_WEBHOOK_SECRET' ) ?? (string) get_option( self::OPTION_WEBHOOK_SECRET, '' ) );
	}

	/**
	 * Whether orders are sent to Denár automatically (needs an API key).
	 */
	public static function enabled(): bool {
		return 'no' !== get_option( self::OPTION_ENABLED, 'yes' ) && '' !== self::api_key();
	}

	/**
	 * Bank transfer: proforma with PAY by square first (yes), or only the
	 * invoice once the payment arrives (no).
	 */
	public static function bacs_proforma(): bool {
		return 'no' !== get_option( self::OPTION_BACS_PROFORMA, 'yes' );
	}

	/**
	 * Number series code in Denár, '' = the one of the API key / default.
	 */
	public static function number_series(): string {
		return trim( (string) get_option( self::OPTION_NUMBER_SERIES, '' ) );
	}

	/**
	 * Denár VAT regime for EU reverse charge: eu_goods or eu_service.
	 */
	public static function reverse_charge_regime(): string {
		return 'eu_service' === get_option( self::OPTION_REVERSE_CHARGE ) ? 'eu_service' : 'eu_goods';
	}

	/**
	 * Stable key of this shop inside external references (`woo:<key>:<order id>`).
	 * Fixed at first use so a later domain change does not break idempotency;
	 * several shops can feed one Denár organization.
	 */
	public static function site_key(): string {
		$key = (string) get_option( self::OPTION_SITE_KEY, '' );

		if ( '' === $key ) {
			$key = (string) wp_parse_url( home_url(), PHP_URL_HOST );
			$key = '' !== $key ? strtolower( $key ) : 'shop';
			update_option( self::OPTION_SITE_KEY, $key, false );
		}

		return $key;
	}

	/**
	 * Whether a credential is pinned in wp-config (the field is then read-only).
	 *
	 * @param string $name Constant name.
	 */
	public static function is_pinned( string $name ): bool {
		return null !== self::constant( $name );
	}

	/**
	 * Last connection check result, see Admin\SettingsPage::check_connection().
	 *
	 * @return array{ok: bool, checked_at: int, organization?: array, scopes?: array, error?: string}|null
	 */
	public static function connection(): ?array {
		$value = get_option( self::OPTION_CONNECTION );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Non-empty string constant or null.
	 *
	 * @param string $name Constant name.
	 */
	private static function constant( string $name ): ?string {
		if ( defined( $name ) && is_string( constant( $name ) ) && '' !== trim( constant( $name ) ) ) {
			return constant( $name );
		}

		return null;
	}
}
