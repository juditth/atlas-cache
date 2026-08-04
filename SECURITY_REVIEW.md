# Atlas Cache – bezpečnostní a kódový review

Datum: 2026-08-04 (aktualizováno po opravách téhož dne, verze 0.1.13)
Rozsah: celý plugin (`atlas-cache.php`, `src/`, `bin/advanced-cache.php`, `uninstall.php`).
Knihovna `plugin-update-checker/` (cizí, Yahnis Elsts) nebyla auditována do hloubky – jde o
široce používanou, udržovanou knihovnu.

**Stav: všechny nálezy níže (kromě úklidu starých .zip buildů, který si uživatel přál
ponechat beze změny – jde o záměrně uchovávané archivy vydaných verzí) byly opraveny ve
verzi 0.1.13.** Text nálezů je ponechán pro dokumentaci toho, co bylo opraveno a proč;
u každého je poznámka **Oprava:**.

Celkově je kód psán poměrně disciplinovaně (striktní typy, nonce + `current_user_can`
na všech admin akcích, `esc_html`/`esc_attr` v šablonách, `$wpdb->prepare` u SQL s
proměnnými, ochrana proti path traversalu v cache storage). Níže jsou nálezy seřazené
podle závažnosti.

---

## 1. Střední závažnost

### 1.1 Cache klíč se odvozuje z nedůvěryhodné hlavičky `Host`

- `bin/advanced-cache.php` (`atlas_cache_dropin_cache_key`, řádek ~205) i
  `src/WordPress/PageCacheMiddleware.php` (`storeResponse`, řádek ~99) berou hostname
  z `$_SERVER['HTTP_HOST']`, sanitizují ho jen regexem (`PathSanitizer::host()` /
  `atlas_cache_dropin_sanitize_host`), ale **nikdy ho neporovnají s reálnou doménou webu**
  (`home_url()`).
- Důsledek:
  - Pokud webserver obsluhuje tento web jako *catch-all*/výchozí vhost (běžné u sdíleného
    hostingu), útočník může posílat libovolné hodnoty `Host:` a nechat si cache ukládat
    obsah pod libovolnými „doménami“ – roste počet adresářů/souborů v
    `wp-content/cache/atlas-cache/pages/<host>/...` bez omezení → **vyčerpání diskového
    prostoru / inode limitu (DoS)**, protože žádná část kódu nelimituje počet
    hostname-variant cache adresářů.
  - Obecně jde o known-pattern „unkeyed/self-keyed Host header“ u cache pluginů – bezpečnější
    je host buď validovat proti `wp_parse_url(home_url('/'), PHP_URL_HOST)` (příp. proti
    seznamu multisite domén), nebo ho z cache klíče úplně vypustit a používat jen
    kanonický host webu.
- Doporučení: přidat allow-list host hodnot (home_url host + případné multisite domény) do
  `RequestPolicy::bypassReason()` / drop-inu; požadavky s neznámým Host → BYPASS.
- **Oprava:** `RuntimeConfigWriter` teď do `config.php` zapisuje `allowed_hosts` (host
  `home_url()`, `site_url()` a u multisite hosty všech sites, max 200). `bin/advanced-cache.php`
  (`atlas_cache_dropin_host_allowed()`) i `RequestPolicy::bypassReason()` /
  `PageCacheMiddleware::allowedHosts()` teď požadavek s neznámým Host hlavičkou vždy
  bypassují (`HostMismatch`) místo vytváření nového cache adresáře.

### 1.2 `wp-config.php` a `.htaccess` se upravují automaticky, regexovým parserem

- `src/WordPress/WpConfigEditor.php` a `src/WordPress/HtaccessBrowserCacheRules.php` zapisují
  přímo do `wp-config.php`, resp. `.htaccess`, na základě regulárních výrazů, které hledají
  `define('WP_CACHE', ...)` a marker komentáře.
- Je to akce chráněná `manage_options` + nonce, takže to není zneužitelné neautorizovaným
  útočníkem – ale jde o citlivý soubor: chybný/edge-case regex (např. neobvyklé formátování
  `wp-config.php`, `require` bez `ABSPATH .`, apod.) může vést k nekonzistentnímu stavu
  (`assertEffectiveMarker` to částečně hlídá, ale ne všechny scénáře). Doporučuji před
  zápisem vytvořit zálohu a/nebo notice v UI, že jde o riskantní operaci na kritickém
  souboru.
- **Oprava:** `WpConfigEditor::backup()` ukládá obsah `wp-config.php` před úpravou do
  options tabulky (`atlas_cache_wp_config_backup`, `autoload=false`), ne jako soubor vedle
  `wp-config.php`. Záměrně **ne** jako soubor na disk – predikovatelně pojmenovaný
  `wp-config.php.bak` by běžný webserver servíroval jako čistý text a unikly by z něj DB
  přihlašovací údaje a secret keys; to je přesně ta věc, kterou bezpečnostní scannery na
  WordPress weby aktivně hledají. Uložení do DB vyžaduje buď DB přístup (na který je stejně
  potřeba znát údaje z wp-config.php), nebo `manage_options` v adminu – tedy stejnou úroveň
  oprávnění, jaká už je potřeba ke spuštění nástroje „Enable WP_CACHE“. `uninstall.php` tuto
  option při odinstalaci maže.

### 1.3 SSRF-lite přes sledování `<loc>` odkazů v sitemap indexu

- `src/WordPress/SitemapUrlCollector.php::collectFromSitemap()` stahuje URL ze
  `sitemapindex` (`<loc>` uvnitř `<sitemap>`) **bez ověření, že jde o stejný host** jako
  web (na rozdíl od `isCacheableSitemapUrl()`, které se aplikuje jen na finální `<url><loc>`
  záznamy, ne na vnořené sitemap-indexy).
- Prakticky to vyžaduje, aby útočník už ovládal obsah `wp-sitemap.xml`/`sitemap_index.xml`
  (jiný plugin, kompromitovaný XML feed přes `atlas_cache_sitemap_urls` filtr) – tedy nejde
  o přímo zneužitelnou díru zvenčí, ale chybí defense-in-depth: `wp_remote_get()` na
  libovolnou externí adresu, kterou určuje obsah nedůvěryhodného XML, spuštěné z
  `save_post`/admin akce serveru. Doporučuji omezit i sitemap-index `<loc>` na stejný host
  jako `home_url()`.
- **Oprava:** `SitemapUrlCollector::collectFromSitemap()` teď u `sitemapindex` větve
  filtruje vnořené `<sitemap><loc>` odkazy přes novou `isSameHost()` a rekurzivně stahuje
  jen ty, které patří na stejnou doménu jako `home_url()`. Počáteční seznam sitemap URL
  (`home_url()` + `atlas_cache_sitemap_urls` filtr) zůstává neomezený záměrně – ten určuje
  vývojář/kód, ne obsah staženého XML.

---

## 2. Nízká závažnost / hardening

### 2.1 Refresh token v query stringu

- `refresh_token` (48 hex znaků, `random_bytes(24)`) se posílá jako `?atlas_cache_refresh=...`
  v URL při interním revalidačním requestu (`QueueWorker::run()`) i v drop-inu
  (`hash_equals`, správně proti timing útokům). Token v query stringu ale typicky skončí
  v access logech webserveru / u CDN / v Referer hlavičce odchozích requestů z cílové
  stránky. Šance zneužití je nízká (token slouží jen k obejití cache, ne k autentizaci), ale
  je to zbytečné rozšíření attack surface – zvážit přenos přes vlastní hlavičku
  (`X-Atlas-Cache-Refresh-Token`) místo query parametru.
- **Oprava:** Token se teď posílá přes hlavičku `X-Atlas-Cache-Refresh-Token`
  (`QueueWorker::run()`) a čte se z `HTTP_X_ATLAS_CACHE_REFRESH_TOKEN` jak v drop-inu
  (`atlas_cache_dropin_is_refresh_request()`), tak v `RequestPolicy::isRefreshRequest()`.
  V URL/query stringu se už nikde neposílá.

### 2.2 Frontend debug komentář

- `frontend_debug_enabled` vkládá do veřejného HTML komentář se stavem cache; hodnoty jsou
  správně escapovány (`htmlspecialchars`/`esc_html`), takže to není XSS, ale je to úmyslně
  časově omezené (auto-expire) – dobře ošetřeno, jen pro úplnost zmiňuji, že jde o menší
  information disclosure (cache klíč, čas generování) veřejně viditelné, pokud admin zapomene
  vypnout.

### 2.3 Diagnostika volá `wp_remote_head(home_url('/'))` při každém načtení stránky Diagnostics

- `AdminMenu::detectExternalCacheHeaders()` dělá síťový request při každém zobrazení admin
  stránky Diagnostics. Nejde o bezpečnostní chybu, ale o zbytečnou latenci/possible SSRF
  pivot bod, pokud by `home_url()` byl někdy ovlivnitelný (v běžném nastavení není).
- **Oprava:** Výsledek se teď cachuje přes `get_transient`/`set_transient`
  (`atlas_cache_external_cache_headers`, 5 minut), takže se skutečný request dělá nanejvýš
  jednou za 5 minut bez ohledu na to, kolikrát admin stránku Diagnostics načte.
  `uninstall.php` transient při odinstalaci maže.

### 2.4 Staré ZIP buildy v kořeni repozitáře

- V kořeni repa leží `atlas-cache-0.1.6 (1).zip` … `atlas-cache-1.0.7.zip` (několik starých
  buildů, včetně jednoho s vyšším číslem verze `1.0.7` než aktuální `0.1.12`). Jsou v
  `.gitignore`, takže se necommitují, ale stojí za úklid – hlavně `1.0.7.zip` působí jako
  omylem pojmenovaný/zapomenutý build, který by neměl skončit na produkčním serveru vedle
  pluginu.
- **Ponecháno beze změny na výslovné přání uživatele** – jde o záměrně uchovávané archivy
  vydaných verzí pluginu, ne o odpad.

### 2.5 Auto-update endpoint

- `SelfHostedUpdater` i admin diagnostika správně vynucují HTTPS a platný host pro
  `ATLAS_CACHE_UPDATE_INFO_URL` – dobře. Pouze upozornění: URL je filtrovatelná přes
  `atlas_cache_update_info_url` filtr, což je funkčně žádoucí, ale i teoreticky umožňuje
  jinému pluginu/kódu s stejnými právy přesměrovat auto-update na jiný manifest (běžné
  riziko každého filtrovatelného update URL, ne specifická chyba tohoto pluginu).

---

## 3. Co je uděláno dobře (pro kontext, není potřeba měnit)

- Path traversal do cache adresáře je důsledně ošetřen (`ensureInsideRoot()` ve
  `FileCacheStorage`, `atlas_cache_dropin_inside_root()` v drop-inu) – i segmenty cesty se
  sanitizují na `[a-z0-9_-]`.
- Všechny stavové admin akce (`handleActions`, `handleToolbarAction`) mají
  `current_user_can('manage_options')` **i** `check_admin_referer()`/nonce.
- SQL dotazy s proměnnými vstupy jdou přes `$wpdb->prepare()`; názvy tabulek/sloupců jsou
  vždy pevně dané (`$wpdb->prefix . 'atlas_cache_queue'`), ne z uživatelského vstupu.
- Výstup v admin šablonách je escapovaný (`esc_html`, `esc_attr`, `esc_url_raw`, `esc_js`).
- Refresh token se porovnává přes `hash_equals()` (odolnost proti timing attacku).
- Cache se automaticky vypíná pro přihlášené uživatele, POST požadavky, wp-admin, REST/XML-RPC,
  AJAX a citlivé cookies (košík, session, přihlášení) – rozumné výchozí bypass chování.
- `uninstall.php` maže jen přesně definovaný adresář/tabulku a ověřuje vlastnictví
  `advanced-cache.php` (`Atlas Cache drop-in` marker) před smazáním, takže neodstraní cizí
  drop-in.

---

## Shrnutí – stav oprav (verze 0.1.13)

1. ✅ `Host` hlavička se validuje proti `home_url()`/`site_url()` (a multisite doménám)
   v `RequestPolicy` i v `bin/advanced-cache.php`.
2. ✅ Sledování `<loc>` v sitemap-indexu je omezené na stejný host jako web.
3. ✅ Refresh token se posílá přes hlavičku, ne přes query string.
4. ✅ `wp-config.php` se před automatickou úpravou zálohuje (do options tabulky, ne jako
   veřejně dostupný soubor).
5. ⏭️ Staré `.zip` buildy ponechány beze změny na výslovné přání uživatele – jde o záměrně
   uchovávané archivy vydaných verzí.
