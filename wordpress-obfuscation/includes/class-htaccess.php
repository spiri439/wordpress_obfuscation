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
		$plugin_files = ! empty( $settings['block_readme_files'] );  // components Obfuscate
		$core_readme  = ! empty( $settings['block_core_readme'] );    // WP Obfuscate
		$install      = ! empty( $settings['block_install'] );        // WP not Off

		if ( ! $plugin_files && ! $core_readme && ! $install ) {
			return array();
		}

		$rewrite = array();

		if ( $plugin_files ) {
			// Plugin/theme static version files under wp-content (path-scoped so
			// it never touches the core /readme.html that Decoy may be rewriting).
			$rewrite[] = '    RewriteRule (?i)^wp-content/.*/(readme|changelog|change-?log|changes|release[_-]?log)\.(txt|html|md)$ - [F,L]';
		}

		if ( $core_readme ) {
			// Core version leaks: /readme.html ("Version x.y") and /license.txt.
			$rewrite[] = '    RewriteRule (?i)^readme\.html$ - [F,L]';
			$rewrite[] = '    RewriteRule (?i)^license\.txt$ - [F,L]';
		}

		if ( $install ) {
			// install.php/upgrade.php leak ?ver=<core> and run before plugins load.
			// Block ONLY for visitors without a logged-in cookie, so scanners are
			// denied but an admin running a real core update still gets through.
			$rewrite[] = '    RewriteCond %{HTTP_COOKIE} !wordpress_logged_in_ [NC]';
			$rewrite[] = '    RewriteRule (?i)^wp-admin/(install|upgrade)\.php$ - [F,L]';
		}

		if ( ! $rewrite ) {
			return array();
		}
		return array_merge(
			array( '<IfModule mod_rewrite.c>', '    RewriteEngine On' ),
			$rewrite,
			array( '</IfModule>' )
		);
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
