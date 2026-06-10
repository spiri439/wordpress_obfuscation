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
		if ( empty( $settings['block_readme_files'] ) ) {
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

		$rules = self::rules();
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
	 * The actual rules. Block direct access to version-revealing static files.
	 */
	private static function rules() {
		// Filenames that scanners read for versions: readme, changelog, license,
		// and plugin "release log" files (e.g. Slider Revolution's release_log.html).
		return array(
			'<FilesMatch "(?i)^(readme|changelog|change-?log|changes|license|readme-[a-z]+|release[_-]?log)\.(txt|html|md)$">',
			'    Require all denied',
			'</FilesMatch>',
			'# Also block them anywhere under wp-content via a rewrite (mod_rewrite).',
			'<IfModule mod_rewrite.c>',
			'    RewriteEngine On',
			'    RewriteRule (?i)^wp-content/.*/(readme|changelog|change-?log|changes|release[_-]?log)\.(txt|html|md)$ - [F,L]',
			'    RewriteRule (?i)^readme\.html$ - [F,L]',
			'    RewriteRule (?i)^license\.txt$ - [F,L]',
			'</IfModule>',
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
