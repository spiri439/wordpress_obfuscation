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

		// Auto-learned versions (captured whenever a component's update info was
		// visible in the admin, incl. premium plugins) then manual overrides win.
		$map = self::overlay( $map, self::learned() );
		$map = self::overlay( $map, self::manual() );

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

		$map = self::overlay( $map, self::learned() );
		$map = self::overlay( $map, self::manual() );

		set_transient( 'scshield_latest_themes', $map, 12 * HOUR_IN_SECONDS );
		return $map;
	}

	/**
	 * Overlay $b onto $a: $b wins when it has a strictly newer (or any, for
	 * manual) version. Manual entries always win; learned wins when newer.
	 */
	private static function overlay( $a, $b ) {
		foreach ( $b as $slug => $ver ) {
			if ( '' === $ver ) {
				continue;
			}
			if ( empty( $a[ $slug ] ) || version_compare( $ver, $a[ $slug ], '>' ) ) {
				$a[ $slug ] = $ver;
			}
		}
		return $a;
	}

	/**
	 * Versions the plugin has auto-captured from WordPress's update data over
	 * time (see learn()). Persisted so premium plugins stay covered even when
	 * their update info isn't currently in the transient.
	 */
	public static function learned() {
		$v = get_option( 'scshield_learned', array() );
		return is_array( $v ) ? $v : array();
	}

	/**
	 * Capture any plugin/theme "new_version" currently visible in WordPress's
	 * update data and remember the highest seen per slug. Cheap; runs in admin.
	 */
	public static function learn() {
		$learned = self::learned();
		$changed = false;

		$plugins = get_site_transient( 'update_plugins' );
		if ( is_object( $plugins ) && ! empty( $plugins->response ) && is_array( $plugins->response ) ) {
			foreach ( $plugins->response as $file => $obj ) {
				$slug = self::slug_from_plugin_file( $file );
				$nv   = self::new_version( $obj );
				if ( '' !== $slug && '' !== $nv && ( empty( $learned[ $slug ] ) || version_compare( $nv, $learned[ $slug ], '>' ) ) ) {
					$learned[ $slug ] = $nv;
					$changed          = true;
				}
			}
		}

		$themes = get_site_transient( 'update_themes' );
		if ( is_object( $themes ) && ! empty( $themes->response ) && is_array( $themes->response ) ) {
			foreach ( $themes->response as $slug => $obj ) {
				$nv = self::new_version( $obj );
				if ( '' !== $nv && ( empty( $learned[ $slug ] ) || version_compare( $nv, $learned[ $slug ], '>' ) ) ) {
					$learned[ $slug ] = $nv;
					$changed          = true;
				}
			}
		}

		if ( $changed ) {
			update_option( 'scshield_learned', $learned, false );
		}
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
	 * Refresh "latest" data NON-destructively. We ask WordPress to update its
	 * plugin/theme info if it's due (this never wipes existing data on failure),
	 * then clear only our own caches. Premium plugins WordPress can't see are
	 * covered by the manual override (see manual()).
	 */
	public static function force_refresh() {
		if ( function_exists( 'wp_update_plugins' ) ) {
			wp_update_plugins();
		}
		if ( function_exists( 'wp_update_themes' ) ) {
			wp_update_themes();
		}
		self::flush();
	}

	/**
	 * Manual "slug = version" overrides for components WordPress can't tell us
	 * the latest of (premium plugins/themes, e.g. "revslider = 6.7.57").
	 * Read from the plugin settings textarea.
	 */
	public static function manual() {
		$opt = get_option( SCSHIELD_OPTION, array() );
		$raw = ( is_array( $opt ) && isset( $opt['manual_versions'] ) ) ? (string) $opt['manual_versions'] : '';
		$map = array();
		foreach ( preg_split( '/[\r\n]+/', $raw ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $slug, $ver ) = explode( '=', $line, 2 );
			$slug = trim( $slug );
			$ver  = trim( $ver );
			if ( '' !== $slug && '' !== $ver ) {
				$map[ $slug ] = $ver;
			}
		}
		return $map;
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
