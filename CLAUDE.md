# Denár for WooCommerce - pravidlá

- Tenký klient Denár API v1. Žiadna fakturačná logika (DPH, číslovanie, sumy
  dokladu) v plugine - chýba niečo v API, doplní sa v Denári
  (`/Users/roman/localhost/denar`, `resources/api/openapi-v1.yaml`).
- Volania do Denáru nikdy synchrónne v pokladni - Action Scheduler s retry.
- Idempotencia: `external_reference` dokladu `woo:{site}:{order_id}`.
- API kľúč ani webhook secret nikdy do logu, výnimky ani HTML.
- `Webhook\Signature` musí zostať zhodná s `App\Support\WebhookSignature` v Denári.
- WPCS (`vendor/bin/phpcs -q`) a PHPUnit musia prejsť; PHP 8.1+.
- Zdrojové texty anglicky, preklad `languages/denar-for-woocommerce-sk_SK.po`
  (po zmene textov: `wp i18n make-pot`, doplniť .po, `msgfmt`).
- Testovať len proti Testovacej firme v Denári (public_id 67945997).
- Vydanie a verzia na 3 miestach: RELEASING.md.
