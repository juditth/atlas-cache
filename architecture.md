# Atlas Cache - architektura

Atlas Cache je jednoduchý WordPress plugin pro HTML page cache. Cílem je co nejrychleji servírovat předem vygenerované HTML a zároveň zachovat předvídatelné, bezpečné a snadno laditelné chování.

Plugin nedělá CSS optimalizace, JS optimalizace, image optimalizace, CDN, object cache ani databázové optimalizace.

## Cílové prostředí

- PHP: 8.0+
- WordPress: 6.0+
- Cache storage: `wp-content/cache/atlas-cache/`
- Primární runtime: `advanced-cache.php` drop-in
- Plugin menu: hlavní položka `Atlas Cache`
- Admin navigace: samostatné submenu stránky, ne taby na jedné stránce

## Hlavní princip

Pokud existuje platná HTML cache a request je bezpečný pro cache, WordPress se vůbec nenačte.

```text
HTTP request
    |
    v
wp-content/advanced-cache.php
    |
    +-- bezpečný cache HIT -> vrátí HTML a ukončí request
    |
    +-- MISS/BYPASS/STALE -> pokračuje WordPress bootstrap
                                   |
                                   v
                             plugin zachytí output
                                   |
                                   v
                             uloží HTML + metadata
```

`advanced-cache.php` musí být extrémně malý, auditovatelný a bez závislosti na WordPress funkcích. Nesmí spoléhat na databázi, autoload WordPressu ani plugin API.

## Vrstvy

### 1. Drop-in vrstva

Soubor:

```text
wp-content/advanced-cache.php
```

Odpovědnosti:

- načíst minimální bootstrap Atlas Cache drop-in runtime
- sestavit request kontext z `$_SERVER` a cookies
- rozhodnout, zda je request vůbec cacheovatelný
- najít cache soubor
- ověřit metadata, TTL a globální stale marker
- poslat HTTP hlavičky
- vypsat HTML
- ukončit request při HIT

Drop-in nesmí:

- volat WordPress funkce
- číst plugin nastavení z databáze
- měnit cache
- spouštět refresh queue
- dělat purge

Nastavení potřebná pro drop-in se ukládají do statického PHP konfiguráku generovaného pluginem.

```text
wp-content/cache/atlas-cache/config.php
```

Tento soubor obsahuje jen jednoduché pole s primitivními hodnotami. Musí být validovaný při zápisu a bezpečný při načtení.

### 2. Cache engine

Namespace:

```text
AtlasCache\Cache
```

Odpovědnosti:

- vytvořit cache key
- určit cestu k HTML souboru a metadatům
- uložit HTML atomicky
- číst metadata
- označit položku nebo celý web jako stale
- mazat cache pouze přes explicitní purge

Cache engine nezná admin UI ani WordPress hooks.

### 3. Storage vrstva

Namespace:

```text
AtlasCache\Storage
```

Odpovědnosti:

- bezpečná práce se soubory
- normalizace a validace cest
- atomické zápisy přes `.tmp` + `rename()`
- výpočty velikosti cache
- výpis cache položek pro service vrstvu

Storage vrstva nikdy nezná WordPress.

Bezpečnostní pravidla:

- všechny výsledné cesty musí zůstat uvnitř `wp-content/cache/atlas-cache/`
- žádná cesta nesmí vzniknout přímým slepením neověřeného URL vstupu
- host, path, jazyk a varianta musí projít sanitizací
- metadata se ukládají jako JSON, ale čtou se defenzivně
- HTML se nikdy nevkládá do PHP souborů

### 4. Request policy

Namespace:

```text
AtlasCache\Request
```

Odpovědnosti:

- rozhodnout, zda request smí použít cache
- rozhodnout, zda response smí být uložena do cache
- vrátit konkrétní důvod: `HIT`, `MISS`, `BYPASS`, `STALE`

Výchozí bypass:

- ne-GET/HEAD request
- přihlášený uživatel
- WordPress admin
- AJAX
- REST API
- XML-RPC
- WP Cron
- preview
- password-protected content
- request s neznámým query stringem
- request s citlivou cookie
- response s nevhodným HTTP statusem
- response se soukromými cache hlavičkami

Query stringy jsou ve výchozím stavu bypass. Později může vzniknout whitelist.

### 5. WordPress integrační vrstva

Namespace:

```text
AtlasCache\WordPress
```

Odpovědnosti:

- registrace hooks
- aktivace/deaktivace pluginu
- instalace a odstranění drop-inu
- zápis drop-in configu
- output buffering pro MISS
- invalidace při změnách obsahu
- plánování workeru přes WP-Cron
- izolace všech WordPress funkcí od core služeb

Tato vrstva může používat WordPress API. Ostatní vrstvy by přes ni měly komunikovat jen přes malé rozhraní.

### 6. Queue a worker

Namespace:

```text
AtlasCache\Queue
```

Odpovědnosti:

- uložit refresh úlohy
- deduplikovat URL
- držet prioritu
- zpracovat omezený počet položek v běhu
- zapisovat výsledek do logu

První implementace může použít custom databázovou tabulku, protože fronta má stav, priority, pokusy, chyby a locky. WordPress options nejsou vhodné pro větší frontu.

Tabulka:

```text
wp_atlas_cache_queue
```

Minimální sloupce:

```text
id
url
cache_key
priority
status
attempts
last_error
available_at
locked_until
created_at
updated_at
```

Worker běží přes WP-Cron a zpracuje například 4 URL za minutu. Hodnota bude nastavitelná.

### 7. Admin UI

Namespace:

```text
AtlasCache\Admin
```

Hlavní menu:

```text
Atlas Cache
```

Submenu stránky:

- Přehled
- Nastavení
- Pravidla cache
- Fronta obnovy
- Log
- Nástroje
- Diagnostika

Admin UI nikdy nečte cache soubory přímo. Používá aplikační služby.

#### Přehled

Zobrazí:

- stav cache
- stav drop-inu
- velikost cache
- počet položek
- délku fronty
- poslední refresh
- poslední chybu workeru
- stav `WP_CACHE`

#### Nastavení

Zobrazí:

- zapnout/vypnout cache
- TTL
- stale-while-revalidate
- počet URL na běh workeru
- debug headers
- HTML debug komentář
- automatické vypnutí frontend debug výstupů po X dnech
- debug log

První verze je pouze public cache. Přihlášení uživatelé se vždy bypassují a plugin pro ně neservíruje ani neukládá HTML cache. Variantu pro členské sekce lze navrhnout později jako samostatný runtime po WordPress autentizaci, ne přes `advanced-cache.php`.

#### Pravidla cache

Zobrazí:

- vyloučené URL patterny
- vyloučené cookies
- query string politika
- WooCommerce pravidla
- vlastní bypass pravidla

#### Fronta obnovy

Zobrazí:

- čekající URL
- probíhající URL
- poslední chyby
- retry
- ruční spuštění workeru
- vyčištění dokončených položek

#### Log

Zobrazí:

- `HIT`
- `MISS`
- `BYPASS`
- `STORE`
- `STALE`
- `REFRESH`
- `PURGE`
- `ERROR`

Log musí mít limit velikosti a rotaci.

#### Nástroje

Zobrazí:

- refresh celé cache
- purge celé cache
- refresh homepage
- refresh konkrétní URL
- reinstalace drop-inu
- export diagnostiky

Purge akce musí mít nonce, capability check a potvrzení.

#### Diagnostika

Zobrazí:

- zda je `WP_CACHE` aktivní
- zda existuje `advanced-cache.php`
- zda drop-in patří Atlas Cache
- zda je cache adresář zapisovatelný
- aktuální config pro drop-in
- detekované konflikty s jinými cache pluginy
- poslední důvody bypassu

## Cache key

Cache key vzniká z normalizovaného requestu:

```text
scheme
host
path
language
variant
```

Výchozí varianta:

```text
public
```

Příklad storage:

```text
wp-content/cache/atlas-cache/pages/
    example.com/
        cs/
            public/
                sluzby/
                    index.html
                    index.meta.json
```

Homepage:

```text
wp-content/cache/atlas-cache/pages/example.com/cs/public/index.html
```

Globální stav:

```text
wp-content/cache/atlas-cache/state/
    global-stale.json
    index.json
```

Runtime config:

```text
wp-content/cache/atlas-cache/config.php
```

Logy:

```text
wp-content/cache/atlas-cache/logs/
```

## Jazyk

První verze počítá s Polylangem, ale cache engine na něm nesmí přímo záviset.

Rozhraní:

```text
LanguageResolverInterface
```

Implementace:

- `PolylangLanguageResolver`
- `DefaultLanguageResolver`

V drop-in runtime nelze bezpečně volat Polylang funkce, proto musí být jazyk odvoditelný z URL nebo ze statické mapy v drop-in configu.

## WooCommerce a košík

Košík nelze zaručeně poznat univerzálně pro každý plugin a každý custom checkout. Pro WooCommerce ale existují spolehlivé signály, které se mají ve výchozím stavu bypassovat:

- cookies `woocommerce_items_in_cart`
- `woocommerce_cart_hash`
- `wp_woocommerce_session_`
- URL checkoutu
- URL košíku
- URL účtu
- endpointy typu `wc-ajax`

Atlas Cache tedy může mít bezpečný výchozí WooCommerce preset, ale ne obecný slib, že pozná každý košík na každém webu. Pro custom e-commerce nebo členské funkce musí existovat vlastní bypass pravidla.

## Ukládání HTML

Při MISS WordPress běží normálně. Plugin spustí output buffer, po dokončení requestu vyhodnotí response a případně uloží HTML.

Ukládat se smí pouze:

- HTTP status 200
- `Content-Type: text/html`
- GET request
- veřejný request bez citlivých cookies
- response bez `Set-Cookie`
- response bez soukromých cache hlaviček

Neukládat:

- redirect
- 404 v první verzi
- REST/AJAX/XML
- přihlášený uživatel
- přihlášený admin/admin-bar obsah
- stránky s nonce určenými pro konkrétního uživatele

Zápis:

```text
index.html.tmp
index.meta.json.tmp
rename -> index.html
rename -> index.meta.json
```

Metadata:

```json
{
  "url": "https://example.com/sluzby/",
  "host": "example.com",
  "path": "/sluzby/",
  "language": "cs",
  "variant": "public",
  "generated_at": "2026-07-28T12:00:00+00:00",
  "status": 200,
  "content_type": "text/html",
  "hash": "sha256:...",
  "tags": ["post:12", "type:page"]
}
```

## Invalidace

Preferovaný režim je refresh, ne purge.

### Lokální změna

Při uložení příspěvku, stránky nebo produktu:

- označit konkrétní URL jako stale
- přidat URL do fronty
- přidat související archivy, pokud je známe

### Globální změna

Při změně menu, šablony, globálního nastavení nebo widgetů:

- označit celý web jako stale
- přidat rebuild důležitých URL do fronty

Globální stale znamená, že cache se může stále servírovat, ale musí být viditelně označená jako stale a postupně obnovovaná.

### Purge

Purge je nouzový režim:

- bezpečnostní problém
- únik personalizovaného obsahu
- ruční zásah správce

Purge okamžitě smaže cache soubory.

## Debug

HTTP hlavičky:

```text
X-Atlas-Cache: HIT
X-Atlas-Cache-Reason: Fresh
X-Atlas-Cache-Key: example.com/cs/public/sluzby
X-Atlas-Cache-Generated: 2026-07-28T12:00:00+00:00
```

Příklady reason hodnot:

```text
Fresh
Missing
Stale
LoggedIn
PostRequest
Admin
RestApi
Ajax
XmlRpc
Cron
Preview
QueryString
SensitiveCookie
SetCookie
PrivateHeaders
UnsupportedStatus
Disabled
DropInMissing
StorageError
```

HTML debug komentář je volitelný:

```html
<!-- Atlas Cache: HIT; generated=2026-07-28T12:00:00+00:00; age=3600; key=example.com/cs/public/sluzby -->
```

Frontend debug výstupy nesmí být trvale zapnuté bez časového omezení. Týká se hlavně HTML debug komentáře a jakýchkoliv informací vypisovaných do veřejného HTML.

Nastavení:

```text
frontend_debug_enabled
frontend_debug_expires_after_days
frontend_debug_enabled_at
```

Výchozí hodnota:

```text
frontend_debug_expires_after_days = 14
```

Po uplynutí nastaveného času se frontend debug výstupy automaticky deaktivují. Cílem je umožnit bezpečné testování bez rizika, že veřejně viditelné diagnostické informace zůstanou dlouhodobě zapnuté jen proto, že se správce znovu nepřihlásil do administrace.

HTTP debug hlavičky mohou mít vlastní nastavení, ale i u nich má být doporučené stejné časové omezení, pokud obsahují interní cesty, cache key nebo jiné citlivější diagnostické detaily.

## Bezpečnost

Bezpečnost má přednost před výkonem.

Zásady:

- přihlášení uživatelé se vždy bypassují
- request se `Set-Cookie` se neukládá
- request s citlivou cookie se nepoužije z cache
- purge a tools akce vyžadují nonce a capability
- všechny admin výstupy se escapují
- všechny vstupy se validují podle typu
- drop-in config se zapisuje atomicky
- drop-in config nesmí obsahovat uživatelský PHP kód
- file path traversal musí být nemožný
- cache nesmí servírovat soubory mimo cache root
- veřejné debug výstupy se po nastavené době automaticky vypnou
- při nejistotě se použije BYPASS

## Navržená struktura souborů

```text
atlas-cache.php
composer.json
uninstall.php

bin/
    advanced-cache.php

src/
    Admin/
    Cache/
    Config/
    Debug/
    DropIn/
    Http/
    Queue/
    Request/
    Storage/
    Support/
    WordPress/

templates/
    admin/

assets/
    admin.css
    admin.js

tests/
    Unit/
    Integration/
```

`bin/advanced-cache.php` je zdrojová verze drop-inu. Při aktivaci se kopíruje do `wp-content/advanced-cache.php` s jasným markerem, že patří Atlas Cache.

## Hlavní rozhraní

```text
CacheStorageInterface
CacheKeyGeneratorInterface
RequestPolicyInterface
ResponsePolicyInterface
CacheMetadataRepositoryInterface
QueueRepositoryInterface
LoggerInterface
LanguageResolverInterface
ClockInterface
```

Rozhraní drží hranice mezi vrstvami. Implementace může být jednoduchá, ale závislosti musí být čitelné a injektované.

## Bootstrap

Plugin bootstrap:

```text
atlas-cache.php
    -> Composer autoload
    -> PluginFactory
    -> ServiceContainer
    -> HookRegistrar
```

Nepoužívat globální singleton jako hlavní pattern. Pokud bude potřeba globální přístup kvůli WordPress hooks, má být omezený na bootstrap vrstvu.

## Aktivace

Při aktivaci:

- ověřit PHP a WP verzi
- vytvořit cache adresáře
- vytvořit queue tabulku
- zapsat výchozí nastavení
- vygenerovat drop-in config
- nainstalovat `advanced-cache.php`
- ověřit nebo nastavit `WP_CACHE`
- zapsat diagnostický výsledek

Pokud nelze bezpečně upravit `wp-config.php`, plugin to nesmí dělat agresivně. Místo toho zobrazí přesný návod a diagnostický blocker.

## Deaktivace

Při deaktivaci:

- vypnout runtime config
- odstranit Atlas Cache drop-in pouze pokud marker potvrzuje vlastnictví
- nechat cache soubory na disku, pokud správce nezvolí purge
- nechat nastavení, pokud nejde o uninstall

## Uninstall

Při uninstall:

- odstranit nastavení
- odstranit queue tabulku
- odstranit drop-in pouze pokud patří Atlas Cache
- volitelně odstranit cache adresář podle nastavení

## První implementační milníky

1. Skeleton pluginu, PSR-4 autoload, admin menu.
2. Storage, cache key a bezpečné path API.
3. Drop-in installer a diagnostika.
4. Read-only drop-in HIT path.
5. Output buffer pro MISS a atomické ukládání.
6. Základní bypass policy.
7. Admin přehled a nastavení.
8. Refresh queue a worker.
9. Invalidace při změně obsahu.
10. WooCommerce preset a custom pravidla.
11. Debug log a diagnostický export.

## Architektonická pravidla

- Storage layer nikdy nezná WordPress.
- Queue nikdy nezná HTTP.
- Cache engine nikdy nezná admin UI.
- Admin UI nikdy nečte cache soubory přímo.
- Drop-in runtime je samostatný a minimální.
- Moduly komunikují přes rozhraní.
- Každá třída má jednu odpovědnost.
- Když je chování nejisté, výsledek je BYPASS.
