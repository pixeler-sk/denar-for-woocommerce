<?php
/**
 * Removes the plugin options. Documents in Denár and order meta stay -
 * they are the shop's accounting trail.
 *
 * @package DenarForWooCommerce
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'denar_wc_api_url', 'denar_wc_api_key', 'denar_wc_webhook_secret', 'denar_wc_connection', 'denar_wc_enabled', 'denar_wc_bacs_proforma', 'denar_wc_attach_pdf', 'denar_wc_invoice_statuses', 'denar_wc_cod_invoice_statuses', 'denar_wc_attach_emails', 'denar_wc_paid_status', 'denar_wc_number_series', 'denar_wc_reverse_charge_regime' ) as $denar_wc_option ) {
	delete_option( $denar_wc_option );
}
