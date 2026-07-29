=== Atlas Cache ===
Contributors: jitka88
Tags: cache, page cache, html cache, performance, advanced-cache
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.10
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Simple and predictable HTML page cache for WordPress, powered by the advanced-cache.php drop-in.

== Description ==

Atlas Cache is a focused HTML page cache plugin for WordPress.

It stores public HTML responses and serves cache hits early through the `advanced-cache.php` drop-in, before WordPress fully boots. The plugin is intentionally narrow: it does not optimize CSS, JavaScript, images, CDN delivery, databases or object cache.

Main features:

* Early public HTML cache through `advanced-cache.php`.
* File cache stored in `wp-content/cache/atlas-cache/`.
* Separate admin pages for settings, cache rules, queue, log, tools and diagnostics.
* Purge and revalidate actions for pages and the whole site.
* Background queue with debounce after content changes.
* Admin bar shortcuts for purge and revalidate actions.
* Cache status headers with optional detailed debug headers.
* Optional frontend debug comment with automatic expiry.
* Diagnostics for drop-in status, server cache headers, known page-cache plugin conflicts, form plugins and WooCommerce.
* Self-hosted update support through an HTTPS JSON manifest.

Atlas Cache only caches public HTML. Logged-in users, admin requests, POST requests, Ajax, REST, XML-RPC, excluded URLs, sensitive cookies and private responses are bypassed.

== Installation ==

1. Upload the `atlas-cache` folder to `/wp-content/plugins/`.
2. Activate Atlas Cache in WordPress admin.
3. Make sure `WP_CACHE` is enabled in `wp-config.php`.
4. Go to **Atlas Cache -> Diagnostics** and confirm that the drop-in is active.
5. Configure cache rules in **Atlas Cache -> Cache rules**.

== Frequently Asked Questions ==

= What does HIT mean? =

`HIT` means Atlas Cache served a stored HTML file.

= What does MISS mean? =

`MISS` means no stored HTML was served and WordPress generated the response.

= What does BYPASS mean? =

`BYPASS` means the request was intentionally skipped, for example because it is admin, logged-in, Ajax, POST, REST, excluded or has a sensitive cookie.

= Does Atlas Cache cache WooCommerce checkout or cart pages? =

No. Common WooCommerce cart, checkout, account and session cookies are bypassed by default. Stores should still use one WooCommerce-safe page cache setup only.

= Does Atlas Cache optimize CSS, JavaScript or images? =

No. Atlas Cache is only an HTML page cache.

== Changelog ==

= 0.1.10 =

* Keep Enable cache off by default and queue sitemap revalidation when it is turned on.
* Add post type warm-up priorities so important pages can be cached before lower-priority content.
* Exclude media URLs from sitemap revalidation and fall back to published pages and posts when no sitemap is available.
* Add .htaccess browser cache rules tools with uninstall cleanup.
* Improve WP_CACHE enable diagnostics.

= 0.1.9 =

* Use sitemap URLs for site-wide revalidation instead of enumerating all public post types.
* Skip query-string URLs in automatic revalidation queues.
* Add a WP_CACHE enable tool for wp-config.php with uninstall restore support.
* Add extra drop-in diagnostics for MISS/BYPASS investigation.

= 0.1.8 =

* Add ordered database migrations that run all missing schema changes across skipped versions.
* Improve the Queue page empty state and layout.
* Clean up Atlas Cache options, cron hooks, cache files and owned drop-in on uninstall.
* Ignore generated ZIP archives in Git.

= 0.1.6 =

* Run queue table migrations automatically after plugin updates.
* Show queue database errors instead of an empty table when the queue cannot be read.
* Make store log entries show the public URL first and the internal cache key second.

= 0.1.5 =

* Rename the immediate file deletion tool to Clear cache files.
* Move the Enable cache master switch into a more visible settings control.
* Remove the site-wide queued purge tool from Tools.

= 0.1.4 =

* Show detailed queue action results for new, requeued and already pending URLs.

= 0.1.3 =

* Fix queue revalidation when detailed debug headers are disabled.

= 0.1.2 =

* Switch self-hosted updates to Plugin Update Checker.
* Keep bundled Plugin Update Checker language files out of the plugin package.

= 0.1.1 =

* Add self-hosted update support.
* Add settings link in the WordPress plugins overview.
* Support GitHub tag ZIP package roots during plugin update.
* Add `info.json` update manifest.

= 0.1.0 =

* Initial release.
