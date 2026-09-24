=== Denár for WooCommerce ===
Contributors: pixeler
Tags: invoice, invoicing, slovakia, pay by square, e-invoice
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
WC requires at least: 8.0
Stable tag: 0.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Invoices for WooCommerce orders in Denár - proforma with PAY by square for bank transfers, invoice once paid, credit note on refund.

== Description ==

Connects a WooCommerce shop to [Denár](https://denar.sk/), invoicing and bank
reconciliation for the Slovak market. The plugin holds no invoicing logic of
its own: numbering, VAT, PDF, PAY by square QR payments and e-invoicing
(Peppol, mandatory in Slovakia from 2027) happen in Denár, the shop only sends
orders and listens to webhooks.

* **Bank transfer:** a proforma with PAY by square is issued when the order is placed, with the order number as the variable symbol. Once Denár pairs the payment from the bank, the order moves to Processing and the invoice is issued from the proforma.
* **Card and payment gateways:** the invoice is issued and marked paid right away.
* **Cash on delivery:** the invoice is issued when the order is completed.
* **Refunds:** a credit note to the invoice, for the refunded lines or the refunded amount.
* **Cancelled orders:** an unpaid proforma is cancelled.
* Coupons become a document discount, shipping and fees are lines; company details (IČO, DIČ, IČ DPH) are read from the common `_billing_ic` / `_billing_dic` / `_billing_dic_dph` fields; EU reverse charge and export from the px-shop-core company module.
* Every document is checked against the order total - a document whose total differs (rounding of prices with VAT) stays a draft instead of being issued with a wrong amount.
* The order screen lists the documents with a PDF download; "Send to Denár again" retries after a fix.
* Runs in the background (Action Scheduler) with retries - the checkout never waits for Denár.
* Compatible with HPOS and the block checkout.

A Denár account is required. Denár is in early access - request an account at [denar.sk](https://denar.sk/) (info@denar.sk).

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

= How do I get a Denár account? =

Denár is in early access. Request an account at [denar.sk](https://denar.sk/) or info@denar.sk.

= My prices include VAT. Will the invoice match the order? =

Yes. The plugin sends the prices with VAT and Denár works the base out of them, so the invoice total is the order total to the cent, coupons included.

= Where are errors logged? =

WooCommerce -> Status -> Logs, source "denar". The API key is never logged.

== Changelog ==

= 0.2.1 =
* Every PHP file refuses direct access.
* Readme: how to get a Denár account, prices with VAT.

= 0.2.0 =
* Documents for orders: proforma for bank transfers, invoice for paid orders (from the proforma when there is one), invoice on completion for cash on delivery, credit notes for refunds, cancelling unpaid proformas.
* Order box with the documents and PDF download, order action "Send to Denár again".
* Shops with prices including VAT send the prices with VAT (Denár prices_include_vat), so the document total is exactly the order total, coupons included.
* Totals check - a document that does not match the order stays a draft; a retry updates the draft with the current order.

= 0.1.2 =
* Ready for wordpress.org: separate build without the GitHub updater, External services section in the readme.
* Plugin name back to "Denár for WooCommerce" (trademark rules); Slovak "Denár pre WooCommerce" comes from the translation.

= 0.1.1 =
* Icon and banner shown in Dashboard -> Updates and in the plugin details.
* Plugin name in Slovak: Denár pre WooCommerce.
* Tested up to WordPress 7.1.

= 0.1.0 =
* Plugin skeleton: settings, connection check, webhook endpoint with signature verification, updates from GitHub releases.
