<?php
/**
 * Thin wrapper over the WP_Filesystem API so file operations use the
 * WordPress-blessed methods (satisfies WordPress.WP.AlternativeFunctions and
 * works across direct/FTP/SSH filesystem methods). All file edits in this
 * plugin happen in admin (settings save) or during updates, where the
 * filesystem can be initialised.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_FS {

	/** @var WP_Filesystem_Base|false|null */
	private static $fs = null;

	/**
	 * Lazily initialise and return the global WP_Filesystem instance, or false.
	 */
	private static function fs() {
		if ( null !== self::$fs ) {
			return self::$fs;
		}
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem(); // 'direct' when files are owned by the web user.
		}
		self::$fs = ! empty( $wp_filesystem ) ? $wp_filesystem : false;
		return self::$fs;
	}

	public static function exists( $file ) {
		$fs = self::fs();
		return $fs ? $fs->exists( $file ) : false;
	}

	public static function is_writable( $file ) {
		$fs = self::fs();
		return $fs ? $fs->is_writable( $file ) : false;
	}

	public static function get( $file ) {
		$fs = self::fs();
		$c  = $fs ? $fs->get_contents( $file ) : false;
		return ( false === $c ) ? '' : $c;
	}

	public static function put( $file, $contents ) {
		$fs = self::fs();
		return $fs ? $fs->put_contents( $file, $contents, FS_CHMOD_FILE ) : false;
	}
}
