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

## Dve distribúcie: GitHub a wordpress.org

`bin/build.sh <github|wporg> <verzia>` zostaví obe, release workflow priloží
k releasu oba zipy:

| | GitHub (`denar-for-woocommerce-X.Y.Z.zip`) | wordpress.org (`…-wporg-X.Y.Z.zip`) |
|---|---|---|
| aktualizácie | Plugin Update Checker (`lib/`, `src/SelfHosted.php`) | WordPress sám; updater je tam zakázaný (guideline 8) |
| preklad | pribalený `languages/*.mo` | language pack z translate.wordpress.org |

Všetko, čo wordpress.org nesmie dostať, patrí do `src/SelfHosted.php` -
build ho vyhodí a hlavný súbor ho volá len cez `class_exists`. CI na každý push
pustí oficiálny Plugin Check nad wporg buildom.

Názov pluginu musí zostať **„Denár for WooCommerce"** (hlavička aj readme) -
„WooCommerce" je chránená značka, povolený je len tvar „… for WooCommerce".
Slovenský „Denár pre WooCommerce" ide cez preklad.

### Prvé podanie na wordpress.org

1. Účet Pixeler na wordpress.org (2FA); jeho meno doplniť do `Contributors:`
   v readme.txt.
2. Denár musí mať verejnú registráciu - recenzent si službu musí vedieť skúsiť.
3. Plugin musí niečo robiť (nie len kostra).
4. Nahrať `…-wporg-X.Y.Z.zip` na https://wordpress.org/plugins/developers/add/.
5. Po schválení: v GitHub repo nastaviť premennú `WPORG_DEPLOY=true` a secrets
   `SVN_USERNAME` / `SVN_PASSWORD` (SVN heslo z profilu wordpress.org). Odvtedy
   tag nasadí aj do SVN (kód aj `.wordpress-org/` grafiku).
6. `languages/denar-for-woocommerce-sk_SK.po` importovať na
   translate.wordpress.org (sk_SK), aby slovenčina išla aj z language packu.

Weby s GitHub verziou po zverejnení dostanú aktualizácie aj z wordpress.org
(rovnaký slug); PUC aj WordPress ponúknu tú istú verziu.
