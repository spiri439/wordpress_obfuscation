<?php
/**
 * Apache .htaccess management.
 *
 * The biggest version leak is static text files served directly by the
 * webserver, never touching PHP:
 *   /wp-content/plugins/<plugin>/readme.txt   -> "Stable tag: 2.3.1"
 *   /wp-content/plugins/<plugin>/changelog.txt
 *   /readme.html                              -> WordPress core version
 * A PHP plugin cannot intercept these, so we manage a marked block in the root
 * .htaccess. On Nginx these rules do nothing — see README for the Nginx config.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_Htaccess {

	const MARKER = 'WordPress Obfuscation';

	/**
	 * Write our rules into the root .htaccess (idempotent, marked block).
	 */
	public static function write( array $settings ) {
		$rules = self::rules( $settings );

		if ( empty( $rules ) ) {
			self::remove();
			return;
		}
		if ( ! self::is_apache() ) {
			return; // Nothing to do on Nginx/other; handled at server level.
		}

		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$htaccess = self::path();
		if ( ! self::ensure_writable( $htaccess ) ) {
			return;
		}

		insert_with_markers( $htaccess, self::MARKER, $rules );
	}

	/**
	 * Remove our marked block, leaving the rest of .htaccess intact.
	 */
	public static function remove() {
		if ( ! self::is_apache() ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		$htaccess = self::path();
		if ( file_exists( $htaccess ) && is_writable( $htaccess ) ) {
			insert_with_markers( $htaccess, self::MARKER, array() );
		}
	}

	/**
	 * Build the rule lines from the current settings. Empty array => no block.
	 *   - block_readme_files (components Obfuscate): version-revealing static files.
	 *   - block_install (WP version not Off): /wp-admin/install.php, which leaks
	 *     the real core version via ?ver and runs before plugins load (so PHP
	 *     filters can't touch it).
	 */
	private static function rules( $settings = array() ) {
		$readme  = ! empty( $settings['block_readme_files'] );
		$install = ! empty( $settings['block_install'] );

		if ( ! $readme && ! $install ) {
			return array();
		}

		$rules   = array();
		$rewrite = array();

		if ( $readme ) {
			// Plugin/theme static version files scanners read (readme/changelog/
			// release_log). FilesMatch matches by basename anywhere under the site.
			$rules[] = '<FilesMatch "(?i)^(readme|changelog|change-?log|changes|release[_-]?log)\.(txt|html|md)$">';
			$rules[] = '    Require all denied';
			$rules[] = '</FilesMatch>';
			$rewrite[] = '    RewriteRule (?i)^wp-content/.*/(readme|changelog|change-?log|changes|release[_-]?log)\.(txt|html|md)$ - [F,L]';
		}

		if ( $install ) {
			// WordPress core version leaks, tied to the WP-version mode.
			// /readme.html ("Version x.y") and /license.txt: always safe to block.
			$rewrite[] = '    RewriteRule (?i)^readme\.html$ - [F,L]';
			$rewrite[] = '    RewriteRule (?i)^license\.txt$ - [F,L]';
			// install.php / upgrade.php enqueue admin CSS with ?ver=<core version>
			// and run before plugins load (PHP filters can't touch them). Block
			// them ONLY for visitors without a logged-in cookie, so scanners are
			// denied but an admin running a real core update still gets through.
			$rewrite[] = '    RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_ [NC]';
			$rewrite[] = '    RewriteRule (?i)^wp-admin/(install|upgrade)\.php$ - [F,L]';
		}

		if ( $rewrite ) {
			$rules[] = '<IfModule mod_rewrite.c>';
			$rules[] = '    RewriteEngine On';
			$rules   = array_merge( $rules, $rewrite );
			$rules[] = '</IfModule>';
		}

		return $rules;
	}

	private static function path() {
		return get_home_path() . '.htaccess';
	}

	private static function ensure_writable( $htaccess ) {
		if ( file_exists( $htaccess ) ) {
			return is_writable( $htaccess );
		}
		// Try to create it in a writable home dir.
		$dir = dirname( $htaccess );
		return is_writable( $dir );
	}

	private static function is_apache() {
		global $is_apache;
		return ! empty( $is_apache );
	}
}
