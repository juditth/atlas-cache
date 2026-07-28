# WordPress HTML Cache

## Cíl projektu

Vytvořit jednoduchý, předvídatelný a spolehlivý WordPress plugin pro HTML page cache.

Plugin nemá konkurovat WP Rocket nebo LiteSpeed Cache. Jeho jediným cílem je co nejrychleji servírovat předem vygenerované HTML.

Nepatří sem:

- optimalizace obrázků
- CDN
- merge CSS/JS
- lazy loading
- databázové optimalizace
- object cache

Plugin má dělat jednu věc a dělat ji dobře.

---

# Hlavní princip

Pokud existuje platná HTML cache a není důvod ji obejít, WordPress se vůbec nenačte.

```
HTTP Request
        │
        ▼
advanced-cache.php
        │
        ├── Cache HIT
        │       │
        │       ▼
        │  Return HTML
        │
        └── Cache MISS
                │
                ▼
          WordPress bootstrap
                │
                ▼
          Generate HTML
                │
                ▼
           Save HTML
                │
                ▼
          Return response
```

---

# Filozofie

Plugin má být:

- jednoduchý
- transparentní
- předvídatelný
- bez magie
- bez automatických heuristik

Vývojář musí vždy vědět:

- proč byla cache použita
- proč nebyla použita
- odkud bylo HTML načteno
- kdy bylo vytvořeno

---

# Cache storage

Použít file cache.

Například:

```
/wp-content/cache/html/

    example.com/
        cs/
            index.html

            sluzby/
                index.html

            kontakt/
                index.html

        en/
            ...
```

Adresářová struktura odpovídá URL.

---

# Cache key

Cache je určena minimálně podle:

- URL
- jazyka (Polylang)

Do budoucna lze rozšířit například o:

- mobile variantu
- query whitelist

---

# Cache bypass

Cache se nikdy nepoužije při:

- přihlášeném uživateli
- POST requestu
- AJAX
- REST API
- WP Cron
- Preview
- XML-RPC

Možnost definovat vlastní cookies.

Například:

```
woocommerce_items_in_cart
woocommerce_cart_hash
```

---

# Ukládání HTML

Po vykreslení stránky:

- zachytit output buffer
- uložit HTML
- uložit metadata

Například:

```
generated
url
language
hash
generation_time
```

---

# Stale While Revalidate

Nikdy nemažeme cache okamžitě.

Místo toho:

1.

Stránka se označí jako zastaralá.

2.

Návštěvníci stále dostávají starou cache.

3.

Background worker vytvoří novou verzi.

4.

Po dokončení se atomicky nahradí soubor.

Výsledkem je:

- žádné pomalé první načtení
- žádný výpadek cache
- žádné rozpracované HTML

---

# Atomický zápis

Nikdy nepřepisovat existující cache přímo.

Použít:

```
index.html.tmp

↓

rename()

↓

index.html
```

Soubor je vždy kompletní.

---

# Invalidace

## Lokální změna

Například:

- stránka
- příspěvek
- produkt

Možnosti:

- smazat pouze konkrétní URL
- přidat URL do refresh fronty

---

## Globální změna

Například:

- Bricks template
- Header
- Footer
- Global Styles
- Menu
- Widgety

Označit celý web jako stale.

Do fronty přidat kompletní rebuild.

---

# Refresh queue

Všechny rebuildy probíhají na pozadí.

Například:

```
Homepage

↓

Nejdůležitější stránky

↓

Kategorie

↓

Příspěvky

↓

Archivy
```

Pořadí lze později upravit.

---

# Deduplikace

Pokud během několika minut proběhne více změn:

```
Header saved

↓

Header saved

↓

Header saved
```

Nevytvářet tři rebuildy.

Pouze jeden.

---

# Worker

Worker pravidelně zpracovává frontu.

Například:

- 4 stránky za minutu

Nastavitelná hodnota.

---

# Režimy obnovy

## Refresh

Preferovaný režim.

- stará cache zůstává
- vytváří se nová
- po dokončení proběhne výměna

---

## Purge

Pouze pokud je nutné.

Například:

- bezpečnostní problém
- kritická změna

Cache se okamžitě odstraní.

---

# Debug

Plugin musí být maximálně transparentní.

Například HTTP hlavičky:

```
X-HTML-Cache: HIT
X-HTML-Cache: MISS
X-HTML-Cache: BYPASS
X-Cache-Reason: LoggedIn
X-Cache-Reason: Cookie
X-Cache-Reason: Missing
X-Cache-Reason: Stale
```

---

Do HTML přidat komentář:

```
<!--
Cache: HIT
Generated: 2026-07-28 11:42
Age: 2h
Source: /cache/html/cs/sluzby/index.html
-->
```

---

# Administrace

Přehled:

- Cache enabled
- Cache size
- Cached pages
- Queue length
- Last rebuild
- Worker status

---

# Detail stránky

Na obrazovce editace:

```
Cache exists

Generated:
2026-07-28 11:42

Status:
Fresh

Age:
2h

Queue:
No
```

---

# Log

Volitelný debug log.

Například:

```
[HIT]

/sluzby/

Served from cache
```

```
[MISS]

/kontakt/

Cache file missing
```

```
[BYPASS]

Logged in user
```

```
[REFRESH]

Queued after page update
```

---

# Budoucí rozšíření

Do první verze NEPATŘÍ.

Možné moduly:

- HTML minifikace
- CSS minifikace
- JavaScript minifikace
- Browser cache
- Query string cache
- Mobile cache
- GZIP/Brotli
- Cache statistics
- Cache warming podle sitemap
- CDN integrace

Tyto moduly musí být oddělené od samotného cache enginu.

---

# Hlavní zásady

- jednoduchost před množstvím funkcí
- vždy raději stará cache než žádná cache
- žádné automatické mazání bez důvodu
- žádné skryté chování
- vše musí být snadno debugovatelné
- cache engine musí být oddělený od všech optimalizačních funkcí