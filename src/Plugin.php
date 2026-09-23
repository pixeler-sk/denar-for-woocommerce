<?php
/**
 * Plugin bootstrap.
 *
 * @package DenarForWooCommerce
 */

namespace Denar\WooCommerce;

use Denar\WooCommerce\Admin\SettingsPage;
use Denar\WooCommerce\Webhook\Receiver;

defined( 'ABSPATH' ) || exit;

/**
 * Wires the modules once WooCommerce is loaded.
 */
final class Plugin {

	/**
	 * Hooked on plugins_loaded.
	 */
	public static function boot(): void {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		SettingsPage::register();
		Receiver::register();
	}

	/**
	 * Shared logger; everything the plugin writes ends up under
	 * WooCommerce -> Status -> Logs, source "denar".
	 *
	 * @param string $level   PSR-3 level.
	 * @param string $message Message; must never contain the API key.
	 * @param array  $context Extra data.
	 */
	public static function log( string $level, string $message, array $context = array() ): void {
		if ( function_exists( 'wc_get_logger' ) ) {
			wc_get_logger()->log( $level, $message, array_merge( array( 'source' => 'denar' ), $context ) );
		}
	}
}
