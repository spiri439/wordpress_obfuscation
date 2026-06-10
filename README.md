# WordPress Obfuscation

A WordPress hardening plugin that reduces what mass scanners can fingerprint on your site. It hides plugin/core **version leaks**, neutralizes **XML-RPC**, and locks down **WP-Cron**.

> **Read this first.** Obfuscation lowers the noise from opportunistic, version-matching scanners (the bots that scrape `readme.txt`, hit `/wp-json/wp/v2/users`, and spray `xmlrpc.php`). It does **not** patch vulnerable code. A scanner that actually exploits a bug succeeds whether or not the version string is visible. **Keep your plugins, themes, and core updated.** This plugin is a complementary layer, not a substitute for patching.

— Author: **spiri439** · Site: [vesrl.ro](https://vesrl.ro) · Requires WordPress 5.0+ and PHP 5.6+

## What it does

### Fingerprint hardening
- Removes the WordPress core version: `<meta generator>`, feed generators, the WLW manifest link, and version readouts. (Core asset `?ver=` and `readme.html` — the other core-version tells — are covered by the `?ver=` strip and the readme blocker.)
- **Optional decoy:** instead of removing the version, emit a *fake* one (e.g. `WordPress 4.9.8`). An absent generator can itself flag a hardened site; a wrong version actively misdirects version-matching bots. Set it in the *Decoy WordPress version* field.
- Strips `?ver=` from enqueued CSS/JS so plugin/theme versions aren't exposed in asset URLs.
- Strips version numbers from `<body>` classes — the "Body Tag" passive detection. Drops `js-comp-ver-6.7.0` (WPBakery), and removes the version from `Zephyr_8.30` / `us-core_8.31.1` / `…-ver-1.2.3` while keeping the base name so theme CSS keeps working.
- Strips plugin-emitted `<meta name="generator">` tags that core filters miss (e.g. `Powered by Slider Revolution 6.7.35`, WPBakery) by buffering the front-end HTML.
- Blocks direct access to `readme.txt`, `changelog.txt`, `license.txt`, `readme.html` (Apache; Nginx config below).
- Disables the REST user-enumeration endpoint (`/wp-json/wp/v2/users`) for anonymous visitors.
- Blocks `?author=N` enumeration that leaks usernames.
- **(Opt-in)** Strips the `Version:` header from the active/parent theme's `style.css` — the line WPScan's "Style" detection reads. See caveats below.

### Theme version (style.css)

Scanners like WPScan fetch `style.css` directly and parse its `Version:` header (e.g. `Version: 8.30`). `style.css` is a **static file** the webserver serves to browsers, so a plugin can't intercept the request — the only way to remove the version is to **edit the file**. The opt-in *Strip theme version* toggle does this:

- Blanks the `Version:` value in the active theme's and its parent's `style.css`.
- Re-applies automatically after theme updates and on theme switch (so an update doesn't silently re-expose it).
- Requires the `style.css` files to be writable; if they aren't, the settings page tells you.

> ⚠️ **This edits theme files and patches nothing.** An outdated theme stays exploitable. If a scanner reports your theme is out of date, the fix is to **update the theme** — this toggle only hides the version string from passive fingerprinting. It is **off by default**.
>
> Note: the theme *name* still appears in asset paths (`/wp-content/themes/<name>/`) in your HTML. Hiding that requires full path-rewriting, which is out of scope for this plugin.

### XML-RPC
Three modes (Settings → WP Obfuscation):
- **Disable & hide** *(default)* — `xmlrpc.php` returns a plain `404`, so it looks absent.
- **Keep, kill pingback** — leaves XML-RPC working (e.g. for Jetpack / the mobile app) but removes `pingback.ping` and `system.multicall` (the brute-force amplification + pingback-DDoS vectors).
- **Off** — WordPress default.

It also removes the `X-Pingback` header and the RSD link that advertise the endpoint.

### WP-Cron
- Disables the HTTP pseudo-cron that fires `wp-cron.php` on every page load.
- Returns `403` for direct **external** hits to `wp-cron.php` (loopback and secret-token requests are allowed).
- Optional secret token so your real system cron can still trigger it.

> ⚠️ If you disable the pseudo-cron you **must** set up a real cron or scheduled tasks (publishing, updates, backups) stop running. See below.

## Install

1. Copy the `wordpress-obfuscation/` folder into `wp-content/plugins/`.
2. Activate **WordPress Obfuscation** in the WordPress admin.
3. Configure under **Settings → WP Obfuscation**.

On activation (Apache) the plugin writes a marked block into the root `.htaccess`. Deactivation removes it, so the site is never left in a broken state.

## System cron setup (when the pseudo-cron is disabled)

Add to `wp-config.php` (above `/* That's all, stop editing! */`):

```php
define( 'DISABLE_WP_CRON', true );
```

Then add a server crontab entry (every 5 minutes):

```cron
*/5 * * * * curl -s "https://YOUR-SITE/wp-cron.php?doing_wp_cron&scshield_cron=YOUR_SECRET" >/dev/null 2>&1
```

Use the same `YOUR_SECRET` you set in the plugin's *System-cron secret* field. Leave the field blank to allow only same-host loopback requests instead.

## Nginx

The `.htaccess` rules are Apache-only. On Nginx, add this to your `server {}` block to block the static version-leak files:

```nginx
# Block version-revealing static files
location ~* ^/(readme|license)\.(txt|html)$ { deny all; }
location ~* /wp-content/.*/(readme|changelog|changes)\.(txt|html|md)$ { deny all; }

# Block direct external XML-RPC (optional — plugin also returns 404 in PHP)
location = /xmlrpc.php { deny all; }

# Restrict wp-cron.php to your server only (adjust the allow IP)
location = /wp-cron.php {
    allow 127.0.0.1;
    deny all;
}
```

The plugin's PHP-level XML-RPC and WP-Cron handling work on Nginx regardless; only the static-file `.htaccess` block needs the manual rule above.

## "I enabled it but the version is still showing"

Almost always one of these:

1. **Page caching.** If your site runs a full-page cache (LiteSpeed Cache, WP Rocket, Cloudflare APO, Varnish…), scanners read the *cached* HTML generated before the plugin ran. The PHP-level strips (body classes, generator tags, `?ver=`) only apply to freshly generated pages. **Purge the cache** after activating or changing settings. LiteSpeed: *LiteSpeed Cache → Toolbox → Purge All*.
2. **Plugin not active.** Confirm the plugin is uploaded to `wp-content/plugins/` and activated.
3. **Static-file leaks** (`style.css` `Version:`, `readme.txt`) — these need the *Strip theme version* toggle and the `.htaccess`/Nginx rules respectively; the body/generator strips don't cover them.

## Limitations / honest notes

- **Not a patch.** Version hiding defeats lazy fingerprinting, not targeted exploitation.
- `?ver=` removal also disables that asset's cache-busting; cached CSS/JS may persist across updates until the browser cache clears.
- Static-file blocking needs Apache `.htaccess` or the Nginx rules above — a PHP plugin can't intercept files the webserver serves directly.
- Determined attackers can still fingerprint via behavioral cues (unique file paths, HTML structure). This raises the bar; it doesn't make the site invisible.

## License

GPL-2.0-or-later.
