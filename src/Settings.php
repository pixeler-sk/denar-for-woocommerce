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
