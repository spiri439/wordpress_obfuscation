=== WordPress Obfuscation ===
Contributors: spiri439
Tags: security, version, hardening, xml-rpc, wp-cron
Requires at least: 5.0
Tested up to: 7.0
Requires PHP: 5.6
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reduce what automated scanners can fingerprint on your site: hide or decoy plugin, theme and WordPress core versions, neutralize XML-RPC, and lock down WP-Cron.

== Description ==

WordPress Obfuscation is a hardening plugin that reduces the information opportunistic, automated scanners can read about your site. Version-matching bots fingerprint a site, look up known issues for the detected versions, and probe the easy targets first. This plugin shrinks that fingerprint.

**Important:** this plugin obscures version and endpoint information. It does **not** patch vulnerable code. Keep your plugins, themes, and WordPress core updated — obscurity is a complement to patching, not a replacement for it.

= Two version modes (per dropdown) =

For **WordPress core** and for **plugins & themes**, choose one of:

* **Off** — leave the real version visible.
* **Obfuscate** — remove or block the version so it can't be read.
* **Decoy** — report a plausible current version (auto-detected latest, or a value you set) so the site reads as up to date.

= What it covers =

* The WordPress `<meta name="generator">` tag, feed generators and the WLW manifest.
* Version query strings (`?ver=`) on enqueued CSS/JS, and the same inside inline CSS.
* Version classes on the `<body>` tag (e.g. page-builder version classes).
* Plugin-emitted `<meta name="generator">` tags.
* Plugin version strings in HTML comments (e.g. SEO plugins).
* Static version files served directly by the web server — `readme.txt`, `changelog.txt`, `release_log.html` — and version banner comments in CSS/JS assets. In Obfuscate these are blocked (Apache/LiteSpeed `.htaccess`, or an Nginx rule you add); in Decoy their version strings are rewritten and automatically reverted when you switch back.
* WordPress core `readme.html` / `license.txt`, and the `install.php` / `upgrade.php` setup pages (blocked for non-logged-in visitors so admins can still run updates).

= Other hardening =

* **XML-RPC** — disable and return 404, or keep it but remove pingback and `system.multicall`.
* **WP-Cron** — disable the HTTP pseudo-cron and block external hits to `wp-cron.php` (with an optional secret token for your system cron).
* **REST user enumeration** — block the anonymous `/wp-json/wp/v2/users` endpoint.
* **Author enumeration** — block the `?author=N` redirect that leaks usernames.

= Reversible =

Setting a mode to **Off**, or deactivating the plugin, restores the real version strings and removes the `.htaccess` rules — the site returns to its normal state.

== Installation ==

1. Upload the `wordpress-obfuscation` folder to `/wp-content/plugins/`, or install the ZIP via **Plugins → Add New → Upload Plugin**.
2. Activate the plugin through the **Plugins** menu.
3. Configure under **Settings → WP Obfuscation**.
4. If you use a page cache (LiteSpeed, etc.) or a CDN, purge it after changing settings so the changes are served.

== Frequently Asked Questions ==

= Does this patch vulnerabilities? =

No. It hides or decoys version information to reduce automated scanning. The actual fix for an outdated component is to update it. Use this as an additional layer.

= What is the difference between Obfuscate and Decoy? =

Obfuscate removes or blocks the version so a scanner reports "could not determine the version". Decoy reports a plausible current version so the site reads as fully up to date. Use a real, recent version for Decoy — an implausible value may be ignored by scanners.

= Will it break my plugin or theme updates? =

WordPress detects updates from each component's real version (its main file header for plugins, `style.css` for themes), which is read independently. Core and plugin update notifications are unaffected. Masking a theme's `style.css` version does affect that theme's own update notice, so the plugin shows its own update notice in that case.

= I changed a setting but nothing changed. =

Almost always page caching. Purge your cache (e.g. LiteSpeed → Purge All) and any CDN after saving.

== Changelog ==

= 1.0.0 =
* Initial release.

== Upgrade Notice ==

= 1.0.0 =
Initial release.
