<?php
/**
 * Removes the plugin options. Documents in Denár and order meta stay -
 * they are the shop's accounting trail.
 *
 * @package DenarForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'denar_wc_api_url', 'denar_wc_api_key', 'denar_wc_webhook_secret', 'denar_wc_connection' ) as $denar_wc_option ) {
	delete_option( $denar_wc_option );
}
