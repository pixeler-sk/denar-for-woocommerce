# Vydávanie a nasadzovanie

Plugin nie je na wordpress.org. Distribuuje sa z verejného repozitára
[pixeler-sk/denar-for-woocommerce](https://github.com/pixeler-sk/denar-for-woocommerce) a na
klientskych weboch sa aktualizuje cez bežnú WordPress aktualizáciu.

## Ako to funguje

```
  git tag v0.1.1 → push
        │
        ▼
  GitHub Action (.github/workflows/release.yml)
        ├─ overí, že verzia sedí na všetkých 3 miestach
        ├─ zostaví denar-for-woocommerce-0.1.1.zip (bez vývojárskych súborov)
        └─ vytvorí GitHub release + priloží zip
        │
        ▼
  klientsky web: Plugin Update Checker sa raz za 12 h spýta GitHub API
        └─ nájde novšiu verziu → Nástenka → Aktualizácie
```

Na klientskych weboch nie sú žiadne tokeny ani prístupové údaje — repozitár je
verejný.

## Postup

1. **Zmeň verziu na troch miestach** (musia sedieť, CI to kontroluje):

   | Súbor | Riadok |
   |---|---|
   | `denar-for-woocommerce.php` | `* Version: 0.1.1` (hlavička) |
   | `denar-for-woocommerce.php` | `define( 'DENAR_WC_VERSION', '0.1.1' );` |
   | `readme.txt` | `Stable tag: 0.1.1` |

2. **Doplň changelog** do `readme.txt` — sekcia musí byť presne `= 0.1.1 =`,
   inak CI zlyhá. Text sekcie sa stane popisom releasu na GitHube aj
   changelogom v okne „Zobraziť podrobnosti" vo WordPresse.

3. **Commit, tag, push:**

   ```
   git commit -am "Popis zmeny"
   git push
   git tag v0.1.1
   git push origin v0.1.1
   ```

4. CI zostaví zip a vytvorí release. Weby s nainštalovaným pluginom ponúknu
   aktualizáciu do 12 hodín (alebo hneď cez Nástenka → Aktualizácie →
   Skontrolovať znova).

## Prvé nasadenie na web

Stiahni zip z [releases](https://github.com/pixeler-sk/denar-for-woocommerce/releases)
a nahraj cez Pluginy → Inštalovať nový → Nahrať plugin. Ďalšie aktualizácie už
chodia samé.

## Limit GitHub API

Zdieľaný hosting minie anonymný limit GitHubu (60 req/h na IP). Vtedy do
`wp-config.php` servera (nie do verzovaného) doplniť fine-grained token bez
oprávnení len na čítanie verejných repozitárov:

```php
define( 'DENAR_WC_GITHUB_TOKEN', '...' );
```
