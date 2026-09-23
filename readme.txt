=== Denár for WooCommerce ===
Contributors: pixeler
Tags: invoice, invoicing, slovakia, pay by square, e-invoice
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
Stable tag: 0.1.2
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invoices for WooCommerce orders in Denár - proforma with PAY by square for bank transfers, invoice once paid, credit note on refund.

== Description ==

Connects a WooCommerce shop to [Denár](https://denar.sk/), invoicing and bank
reconciliation for the Slovak market. The plugin holds no invoicing logic of
its own: numbering, VAT, PDF, PAY by square QR payments and e-invoicing
(Peppol, mandatory in Slovakia from 2027) happen in Denár, the shop only sends
orders and listens to webhooks.

* Connection to the Denár REST API with an organization API key
* Signed webhook endpoint (`/wp-json/denar/v1/webhook`) - Denár tells the shop when a document was paid or cancelled
* Compatible with HPOS and the block checkout

A Denár account is required.

== Installation ==

1. Install and activate the plugin (WooCommerce must be active).
2. In Denár create an API key (Settings -> API keys) with the scopes
   documents and partners, read and write.
3. Paste it into WooCommerce -> Settings -> Denár and click Check connection.
4. In Denár add a webhook (Settings -> Webhooks) to the URL shown on the
   settings screen and paste its secret into the plugin.

Credentials may also be defined in wp-config.php: `DENAR_WC_API_KEY`,
`DENAR_WC_WEBHOOK_SECRET`, `DENAR_WC_API_URL`.

== External services ==

This plugin connects to the Denár API (`https://api.denar.sk/v1`, operated by
Pixeler s. r. o., Slovakia), the invoicing service the shop uses to issue its
documents. Without it the plugin does nothing.

* When the administrator clicks "Check connection", the plugin sends the API
  key and receives the name and tax identifiers of the connected organization.
* When an order is to be invoiced, the plugin sends the order data needed on
  an invoice: order number, items, prices, taxes, shipping, payment method and
  the customer's billing details (name, company, company and VAT
  identification numbers, address, e-mail, phone). The shop's own data is
  sent under its API key.
* Denár sends webhooks back to the shop (document paid, cancelled); those
  carry document data, not personal data beyond what the shop sent.

Nothing is sent before the administrator enters an API key.

* Terms of service: https://denar.sk/obchodne-podmienky
* Privacy policy: https://denar.sk/ochrana-osobnych-udajov

== Frequently Asked Questions ==

= Does the plugin work without Denár? =

No. It is a connector - invoices are issued in Denár.

= Where are errors logged? =

WooCommerce -> Status -> Logs, source "denar". The API key is never logged.

== Changelog ==

= 0.1.2 =
* Ready for wordpress.org: separate build without the GitHub updater, External services section in the readme.
* Plugin name back to "Denár for WooCommerce" (trademark rules); Slovak "Denár pre WooCommerce" comes from the translation.

= 0.1.1 =
* Icon and banner shown in Dashboard -> Updates and in the plugin details.
* Plugin name in Slovak: Denár pre WooCommerce.
* Tested up to WordPress 7.1.

= 0.1.0 =
* Plugin skeleton: settings, connection check, webhook endpoint with signature verification, updates from GitHub releases.
