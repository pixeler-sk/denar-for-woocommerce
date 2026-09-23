# Denár for WooCommerce

WordPress plugin, ktorý napojí WooCommerce e-shop na [Denár](https://denar.sk/)
cez REST API v1 (`https://api.denar.sk/v1`, kontrakt `openapi.yaml`). Tenký
konektor: fakturačná logika (číslovanie, DPH, PDF, PAY by square, e-faktúra)
je v Denári, plugin len posiela objednávky a prijíma webhooky.

## Stav

- [x] 0.1 kostra: nastavenia, overenie spojenia (`GET /me`), webhook endpoint s HMAC, aktualizácie z GitHub releases
- [x] 0.2 objednávka -> doklad (karta = faktúra hneď, dobierka pri vybavení), prevod: proforma s PAY by square (VS = číslo objednávky) -> webhook `document.paid` -> `payment_complete()` -> faktúra z proformy; refund -> dobropis, zrušenie -> storno proformy; kontrola súm (nesedí = koncept); box v objednávke s PDF
- [x] B2B údaje z `_billing_ic` / `_billing_dic` / `_billing_dic_dph` (px-shop-core, WPify), prenesenie DPH a vývoz z px-shop-core
- [ ] zaokrúhlenie pri cenách s DPH - Woo počíta DPH z ceny s DPH, Denár zo základu; rozdiel o cent nechá doklad konceptom (riešenie v Denári)
- [ ] B2B polia v blokovej pokladni, keď px-shop-core nie je
- [ ] PDF v e-maile objednávky a v Môj účet (podpísaný odkaz, Denár T081)

## Vývoj

```
composer install
vendor/bin/phpunit
vendor/bin/phpcs -q
```

Lokálne: symlink do `wp-content/plugins/denar-for-woocommerce` testovacieho WP,
API URL `https://api.denar.test/v1`, kľúč z Testovacej firmy (public_id 67945997).
Webhooky z lokálneho Denáru na lokálny WP potrebujú v Denári
`WEBHOOK_ALLOW_INSECURE=true`.

Ikona a banner: zdroje v `assets-src/` (HTML/SVG v štýle denar.sk, písmo
Lexend Deca), `assets-src/render.sh` ich cez headless Chrome vyrenderuje do
`.wordpress-org/` (názvy podľa wordpress.org). Aktualizácie ich berú z vetvy
`main`, nová grafika teda nepotrebuje release.

Vydanie: [RELEASING.md](RELEASING.md).
