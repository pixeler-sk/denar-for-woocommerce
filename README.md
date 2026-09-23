# Denár for WooCommerce

WordPress plugin, ktorý napojí WooCommerce e-shop na [Denár](https://denar.sk/)
cez REST API v1 (`https://api.denar.sk/v1`, kontrakt `openapi.yaml`). Tenký
konektor: fakturačná logika (číslovanie, DPH, PDF, PAY by square, e-faktúra)
je v Denári, plugin len posiela objednávky a prijíma webhooky.

## Stav

- [x] 0.1 kostra: nastavenia, overenie spojenia (`GET /me`), webhook endpoint s HMAC, aktualizácie z GitHub releases
- [ ] objednávka -> doklad (karta, dobierka = faktúra)
- [ ] prevod: proforma s PAY by square -> webhook `document.paid` -> faktúra, objednávka *Spracováva sa*
- [ ] refund -> dobropis, zrušenie -> storno proformy
- [ ] B2B polia (IČO, DIČ, IČ DPH) - ak je px-shop-core modul Firemné údaje, čítať z neho
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
