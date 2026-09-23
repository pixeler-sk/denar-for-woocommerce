<?php
/**
 * Plugin Name: Denár for WooCommerce
 * Plugin URI: https://denar.sk/
 * Description: Issues invoices for WooCommerce orders in Denár - proforma with PAY by square for bank transfers, invoice once paid, credit note on refund.
 * Version: 0.2.0
 * Author: Pixeler
 * Author URI: https://pixeler.sk/
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * Requires Plugins: woocommerce
 * WC requires at least: 8.0
 * License: GPL v2 or later
 * Text Domain: denar-for-woocommerce
 * Domain Path: /languages
 *
 * @package DenarForWooCommerce
 */

defined( 'ABSPATH' ) || exit;

define( 'DENAR_WC_VERSION', '0.2.0' );
define( 'DENAR_WC_FILE', __FILE__ );
define( 'DENAR_WC_DIR', plugin_dir_path( __FILE__ ) );

spl_autoload_register(
	static function ( string $class_name ): void {
		$prefix = 'Denar\\WooCommerce\\';
		if ( ! str_starts_with( $class_name, $prefix ) ) {
			return;
		}

		$file = DENAR_WC_DIR . 'src/' . str_replace( '\\', '/', substr( $class_name, strlen( $prefix ) ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

// GitHub distribution only (updates + bundled translation); the wordpress.org
// build leaves the class out, the autoloader then finds nothing.
if ( class_exists( Denar\WooCommerce\SelfHosted::class ) ) {
	Denar\WooCommerce\SelfHosted::boot();
}

add_action(
	'before_woocommerce_init',
	static function (): void {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DENAR_WC_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DENAR_WC_FILE, true );
		}
	}
);

add_action( 'plugins_loaded', array( Denar\WooCommerce\Plugin::class, 'boot' ) );
