<?php
/**
 * Decoy for STATIC version files of plugins & themes.
 *
 * Scanners read versions from static files the webserver serves directly —
 * e.g. Slider Revolution's release_log.html ("Version 6.7.32"), readme.txt
 * ("Stable tag: x"), changelog.txt. Decoy/?ver= can't touch these (no PHP),
 * so in Decoy mode we rewrite the version inside the files to the component's
 * LATEST known version (so the component reads as fully patched). Obfuscate
 * mode blocks these files via .htaccess instead (handled elsewhere).
 *
 * Mechanism: replace the component's installed-version token with its latest
 * token throughout each known version file. WordPress knows the latest even
 * for premium plugins/themes whose own updater registered an update (e.g.
 * Slider Revolution 6.7.57), via the update_plugins/update_themes data.
 *
 * Notes:
 *  - Runs only on settings save / plugin-theme update (not per request).
 *  - Re-applied after updates (a plugin update restores the real version).
 *  - readme.txt is NOT WordPress's local update source for plugins (that's the
 *    plugin's PHP header, which we never touch), so this does not break plugin
 *    update notifications.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_CompFiles {

	/** Static files scanners read for a component's version. */
	private static $files = array(
		'readme.txt', 'readme.html', 'changelog.txt', 'changelog.html',
		'changes.txt', 'release_log.html', 'release-log.html', 'release_log.txt',
	);

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		if ( empty( $this->s['mask_version_files'] ) && empty( $this->s['mask_core_readme'] ) ) {
			return;
		}
		// Re-apply after a plugin/theme/core update restores the real version.
		add_action( 'upgrader_process_complete', array( $this, 'apply' ), 25 );
	}

	/**
	 * Rewrite each installed plugin/theme's static version files so the version
	 * reads as its latest. Returns the list of files changed.
	 */
	public function apply() {
		$do_comp = ! empty( $this->s['mask_version_files'] );
		$do_core = ! empty( $this->s['mask_core_readme'] );
		if ( ! $do_comp && ! $do_core ) {
			return array();
		}

		SCShield_Versions::force_refresh(); // re-query wordpress.org for real latest
		$changed = array();
		$record  = array();

		// WordPress core /readme.html -> the decoy core version.
		if ( $do_core ) {
			$decoy     = $this->wp_decoy();
			$installed = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
			if ( '' !== $decoy && '' !== $installed && $decoy !== $installed ) {
				$file = ABSPATH . 'readme.html';
				if ( $this->rewrite( $file, $installed, $decoy, false ) ) {
					$changed[]               = $file;
					$record['__wp_core__']   = array( 'installed' => $installed, 'decoy' => $decoy );
				}
			}
		}

		if ( ! $do_comp ) {
			if ( $record ) {
				$prev = get_option( 'scshield_decoyed', array() );
				$prev = is_array( $prev ) ? $prev : array();
				update_option( 'scshield_decoyed', array_merge( $prev, $record ), false );
			}
			return $changed;
		}

		foreach ( $this->targets() as $t ) {
			if ( '' === $t['installed'] || '' === $t['latest'] || $t['installed'] === $t['latest'] ) {
				continue; // nothing to bump (already latest, or unknown)
			}
			$slug = isset( $t['slug'] ) ? $t['slug'] : '';
			$did  = false;
			// 1) Known static version files (readme/changelog/release_log),
			//    matched case-insensitively (e.g. README.txt vs readme.txt).
			foreach ( $this->version_files_in_dir( $t['dir'] ) as $file ) {
				if ( $this->rewrite( $file, $t['installed'], $t['latest'], false ) ) {
					$changed[] = $file;
					$did       = true;
				}
			}
			// 2) Asset banner comments (e.g. "elementor - v3.17.0" at the top of
			//    admin.min.css / admin-feedback.js). Only the file head is touched.
			foreach ( $this->asset_files( $t['dir'] ) as $file ) {
				if ( $this->rewrite( $file, $t['installed'], $t['latest'], true ) ) {
					$changed[] = $file;
					$did       = true;
				}
			}
			// Remember what we wrote so Off/Obfuscate can revert it exactly.
			if ( $did && '' !== $slug ) {
				$record[ $slug ] = array( 'installed' => $t['installed'], 'decoy' => $t['latest'] );
			}
		}

		if ( $record ) {
			$prev = get_option( 'scshield_decoyed', array() );
			$prev = is_array( $prev ) ? $prev : array();
			update_option( 'scshield_decoyed', array_merge( $prev, $record ), false );
		}
		return $changed;
	}

	/**
	 * Revert any decoy file edits back to the real installed versions, using the
	 * recorded decoy→installed mapping. Called when the components mode leaves
	 * Decoy (Off/Obfuscate) and on deactivation.
	 */
	public function restore() {
		$record   = get_option( 'scshield_decoyed', array() );
		$record   = is_array( $record ) ? $record : array();
		$reverted = array();

		// Core /readme.html: revert decoy -> real installed version.
		$file = ABSPATH . 'readme.html';
		$installed = function_exists( 'get_bloginfo' ) ? get_bloginfo( 'version' ) : '';
		$core_froms = array();
		if ( isset( $record['__wp_core__']['decoy'] ) ) {
			$core_froms[] = $record['__wp_core__']['decoy'];
		}
		$core_froms[] = $this->wp_decoy(); // covers edits with no record
		foreach ( array_unique( $core_froms ) as $from ) {
			if ( '' !== $from && '' !== $installed && $from !== $installed && $this->rewrite( $file, $from, $installed, false ) ) {
				$reverted[] = $file;
			}
		}

		foreach ( $this->targets() as $t ) {
			$slug = isset( $t['slug'] ) ? $t['slug'] : '';
			$to   = $t['installed']; // the real version to restore to
			if ( '' === $to ) {
				continue;
			}

			// Candidate "decoy" tokens to revert: the recorded one (precise) and
			// the currently-computed latest (covers files bumped by older builds
			// that didn't record anything). De-duplicated, never equal to real.
			$froms = array();
			if ( '' !== $slug && isset( $record[ $slug ]['decoy'] ) ) {
				$froms[] = $record[ $slug ]['decoy'];
			}
			if ( ! empty( $t['latest'] ) ) {
				$froms[] = $t['latest'];
			}
			$froms = array_unique( array_filter( $froms, function ( $v ) use ( $to ) {
				return '' !== $v && $v !== $to;
			} ) );
			if ( ! $froms ) {
				continue;
			}

			$vfiles = $this->version_files_in_dir( $t['dir'] );
			$afiles = $this->asset_files( $t['dir'] );
			foreach ( $froms as $from ) {
				foreach ( $vfiles as $file ) {
					if ( $this->rewrite( $file, $from, $to, false ) ) {
						$reverted[] = $file;
					}
				}
				foreach ( $afiles as $file ) {
					if ( $this->rewrite( $file, $from, $to, true ) ) {
						$reverted[] = $file;
					}
				}
			}
		}

		delete_option( 'scshield_decoyed' );
		return array_values( array_unique( $reverted ) );
	}

	/**
	 * Top-level version files in a component dir, matched case-insensitively
	 * (README.txt, readme.txt, Changelog.TXT, release_log.html, …).
	 */
	private function version_files_in_dir( $dir ) {
		$found = array();
		if ( ! is_dir( $dir ) ) {
			return $found;
		}
		$set     = array_flip( self::$files ); // lowercase known names
		$entries = @scandir( $dir );
		if ( ! is_array( $entries ) ) {
			return $found;
		}
		foreach ( $entries as $e ) {
			if ( '.' === $e || '..' === $e ) {
				continue;
			}
			if ( isset( $set[ strtolower( $e ) ] ) ) {
				$p = trailingslashit( $dir ) . $e;
				if ( is_file( $p ) ) {
					$found[] = $p;
				}
			}
		}
		return $found;
	}

	/**
	 * Collect .css/.js files under a component dir (recursive, capped) so we can
	 * rewrite version banner comments. Capped to bound work on large plugins.
	 */
	private function asset_files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) ) {
			return $files;
		}
		$cap = 400;
		try {
			$it = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$ext = strtolower( pathinfo( $f->getFilename(), PATHINFO_EXTENSION ) );
				if ( 'css' === $ext || 'js' === $ext ) {
					$files[] = $f->getPathname();
					if ( count( $files ) >= $cap ) {
						break;
					}
				}
			}
		} catch ( Exception $e ) {
			return $files;
		}
		return $files;
	}

	/**
	 * Replace the exact installed-version token with the latest one in a file.
	 * Targeted str_replace keeps the change safe and reversible-on-update.
	 */
	private function rewrite( $file, $from, $to, $head_only ) {
		if ( ! SCShield_FS::exists( $file ) || ! SCShield_FS::is_writable( $file ) ) {
			return false;
		}
		$contents = SCShield_FS::get( $file );
		if ( '' === $contents || false === strpos( $contents, $from ) ) {
			return false;
		}

		if ( $head_only ) {
			// Only rewrite the banner region (top of file) so we never touch the
			// version token if it appears as real code/data deeper in the file.
			$head_len = 2048;
			$head     = substr( $contents, 0, $head_len );
			if ( false === strpos( $head, $from ) ) {
				return false;
			}
			$new = str_replace( $from, $to, $head ) . substr( $contents, $head_len );
		} else {
			$new = str_replace( $from, $to, $contents );
		}

		if ( $new === $contents ) {
			return false;
		}
		return false !== SCShield_FS::put( $file, $new );
	}

	/**
	 * [ ['dir'=>..,'installed'=>..,'latest'=>..], ... ] for plugins + themes.
	 */
	private function targets() {
		$out = array();

		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$latest_plugins = SCShield_Versions::plugins();
		foreach ( get_plugins() as $file => $data ) {
			$slug = ( false !== strpos( $file, '/' ) ) ? substr( $file, 0, strpos( $file, '/' ) ) : preg_replace( '/\.php$/i', '', $file );
			if ( false !== strpos( $file, '/' ) ) {
				$installed = isset( $data['Version'] ) ? $data['Version'] : '';
				$out[] = array(
					'slug'      => $slug,
					'dir'       => trailingslashit( WP_PLUGIN_DIR ) . $slug,
					'installed' => $installed,
					'latest'    => isset( $latest_plugins[ $slug ] ) ? $latest_plugins[ $slug ] : $installed,
				);
			}
		}

		$latest_themes = SCShield_Versions::themes();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$installed = $theme->get( 'Version' );
			$out[] = array(
				'slug'      => $slug,
				'dir'       => $theme->get_stylesheet_directory(),
				'installed' => is_string( $installed ) ? $installed : '',
				'latest'    => isset( $latest_themes[ $slug ] ) ? $latest_themes[ $slug ] : $installed,
			);
		}

		// Apply in-memory manual overrides (the just-submitted settings aren't
		// persisted yet during a save, so SCShield_Versions::manual() can't see
		// them — read them straight from $this->s here).
		$manual = $this->manual_map();
		if ( $manual ) {
			foreach ( $out as &$t ) {
				if ( isset( $t['slug'] ) && isset( $manual[ $t['slug'] ] ) ) {
					$t['latest'] = $manual[ $t['slug'] ];
				}
			}
			unset( $t );
		}

		return $out;
	}

	/**
	 * The decoy WordPress core version: manual override if set, else latest.
	 */
	private function wp_decoy() {
		$manual = isset( $this->s['wp_version_spoof'] ) ? trim( (string) $this->s['wp_version_spoof'] ) : '';
		return ( '' !== $manual ) ? $manual : SCShield_Versions::latest_wp();
	}

	/**
	 * Parse the "slug = version" overrides from the current (in-memory) settings.
	 */
	private function manual_map() {
		$raw = isset( $this->s['manual_versions'] ) ? (string) $this->s['manual_versions'] : '';
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
}
