=== Denár pre WooCommerce ===
Contributors: pixeler
Tags: invoice, woocommerce, slovakia, pay by square, e-invoice
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
Stable tag: 0.1.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invoices for WooCommerce orders in Denár - proforma with PAY by square for bank transfers, invoice once paid, credit note on refund.

== Description ==

Connects a WooCommerce shop to [Denár](https://denar.sk/), invoicing for the
Slovak market. The plugin holds no invoicing logic of its own: numbering, VAT,
PDF, PAY by square and e-invoicing (Peppol, from 2027) happen in Denár, the
shop only sends orders and listens to webhooks.

* Connection to the Denár REST API v1 with an organization API key
* Signed webhook endpoint (`/wp-json/denar/v1/webhook`)
* Compatible with HPOS and the block checkout

== Installation ==

1. Upload the zip from GitHub releases via Plugins -> Add New -> Upload.
2. In Denár create an API key (Settings -> API keys) with the scopes
   documents and partners, read and write.
3. Paste it into WooCommerce -> Settings -> Denár and check the connection.
4. In Denár add a webhook to the URL shown on the settings screen and paste
   its secret into the plugin.

Credentials may also be defined in wp-config.php: `DENAR_WC_API_KEY`,
`DENAR_WC_WEBHOOK_SECRET`, `DENAR_WC_API_URL`.

== Changelog ==

= 0.1.1 =
* Icon and banner shown in Dashboard -> Updates and in the plugin details.
* Plugin name in Slovak: Denár pre WooCommerce.
* Tested up to WordPress 7.1.

= 0.1.0 =
* Plugin skeleton: settings, connection check, webhook endpoint with signature verification, updates from GitHub releases.
