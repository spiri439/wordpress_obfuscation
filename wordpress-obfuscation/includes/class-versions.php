<?php
/**
 * Resolves the LATEST available version for each installed plugin and theme,
 * using the data WordPress already caches from its own update checks
 * (update_plugins / update_themes site transients) — no external calls.
 *
 * "Latest" = the new_version offered if an update is available, otherwise the
 * installed version (which is itself the latest when nothing newer exists).
 *
 * Used to make components advertise as up-to-date so version-matching scanners
 * treat the site as patched. Results cached 12h; cache cleared on settings save.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_Versions {

	/**
	 * [ plugin-dir-slug => latest-version ]
	 */
	public static function plugins() {
		$cached = get_transient( 'scshield_latest_plugins' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map    = array();
		$update = get_site_transient( 'update_plugins' );

		if ( is_object( $update ) ) {
			// Installed versions first (these are "latest" unless an update exists).
			if ( ! empty( $update->checked ) && is_array( $update->checked ) ) {
				foreach ( $update->checked as $file => $ver ) {
					$slug = self::slug_from_plugin_file( $file );
					if ( '' !== $slug && '' !== (string) $ver ) {
						$map[ $slug ] = $ver;
					}
				}
			}
			// Override with the newer version where an update is available.
			if ( ! empty( $update->response ) && is_array( $update->response ) ) {
				foreach ( $update->response as $file => $obj ) {
					$slug = self::slug_from_plugin_file( $file );
					$nv   = self::new_version( $obj );
					if ( '' !== $slug && '' !== $nv ) {
						$map[ $slug ] = $nv;
					}
				}
			}
		}

		set_transient( 'scshield_latest_plugins', $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * [ theme-stylesheet-slug => latest-version ]
	 */
	public static function themes() {
		$cached = get_transient( 'scshield_latest_themes' );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$map    = array();
		$update = get_site_transient( 'update_themes' );

		if ( is_object( $update ) ) {
			if ( ! empty( $update->checked ) && is_array( $update->checked ) ) {
				foreach ( $update->checked as $stylesheet => $ver ) {
					if ( '' !== (string) $ver ) {
						$map[ $stylesheet ] = $ver;
					}
				}
			}
			if ( ! empty( $update->response ) && is_array( $update->response ) ) {
				foreach ( $update->response as $stylesheet => $obj ) {
					$nv = self::new_version( $obj );
					if ( '' !== $nv ) {
						$map[ $stylesheet ] = $nv;
					}
				}
			}
		}

		set_transient( 'scshield_latest_themes', $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Latest version for the plugin/theme that owns an asset URL, or '' if
	 * the URL isn't under wp-content/plugins|themes or the slug is unknown.
	 */
	public static function latest_for_url( $url ) {
		if ( preg_match( '#/plugins/([^/?]+)/#', $url, $m ) ) {
			$plugins = self::plugins();
			return isset( $plugins[ $m[1] ] ) ? $plugins[ $m[1] ] : '';
		}
		if ( preg_match( '#/themes/([^/?]+)/#', $url, $m ) ) {
			$themes = self::themes();
			return isset( $themes[ $m[1] ] ) ? $themes[ $m[1] ] : '';
		}
		return '';
	}

	/**
	 * Latest version for a plugin by its directory slug, or ''.
	 */
	public static function latest_plugin( $slug ) {
		$plugins = self::plugins();
		return isset( $plugins[ $slug ] ) ? $plugins[ $slug ] : '';
	}

	/**
	 * Latest version for a theme by its stylesheet slug, or ''.
	 */
	public static function latest_theme( $slug ) {
		$themes = self::themes();
		return isset( $themes[ $slug ] ) ? $themes[ $slug ] : '';
	}

	/**
	 * Clear caches (call on settings save / after updates).
	 */
	public static function flush() {
		delete_transient( 'scshield_latest_plugins' );
		delete_transient( 'scshield_latest_themes' );
	}

	/**
	 * Force WordPress to re-check wordpress.org for the newest plugin/theme
	 * versions, then clear our caches so the next read sees fresh "latest" data.
	 * Heavy (external calls) — call only on settings save / after updates.
	 */
	public static function force_refresh() {
		// Clear WP's throttle so the check actually re-queries wordpress.org.
		delete_site_transient( 'update_plugins' );
		delete_site_transient( 'update_themes' );

		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		self::flush();
	}

	private static function slug_from_plugin_file( $file ) {
		// "akismet/akismet.php" => "akismet"; "hello.php" => "hello".
		if ( false !== strpos( $file, '/' ) ) {
			$parts = explode( '/', $file );
			return $parts[0];
		}
		return preg_replace( '/\.php$/i', '', $file );
	}

	private static function new_version( $obj ) {
		if ( is_object( $obj ) && isset( $obj->new_version ) ) {
			return (string) $obj->new_version;
		}
		if ( is_array( $obj ) && isset( $obj['new_version'] ) ) {
			return (string) $obj['new_version'];
		}
		return '';
	}
}
