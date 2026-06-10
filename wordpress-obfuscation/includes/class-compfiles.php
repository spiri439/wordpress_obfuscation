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
		if ( empty( $this->s['mask_version_files'] ) ) {
			return;
		}
		// Re-apply after a plugin/theme update restores the real version.
		add_action( 'upgrader_process_complete', array( $this, 'apply' ), 25 );
	}

	/**
	 * Rewrite each installed plugin/theme's static version files so the version
	 * reads as its latest. Returns the list of files changed.
	 */
	public function apply() {
		if ( empty( $this->s['mask_version_files'] ) ) {
			return array();
		}

		SCShield_Versions::flush(); // ensure freshest "latest" data
		$changed = array();

		foreach ( $this->targets() as $t ) {
			if ( '' === $t['installed'] || '' === $t['latest'] || $t['installed'] === $t['latest'] ) {
				continue; // nothing to bump (already latest, or unknown)
			}
			// 1) Known static version files (readme/changelog/release_log),
			//    matched case-insensitively (e.g. README.txt vs readme.txt).
			foreach ( $this->version_files_in_dir( $t['dir'] ) as $file ) {
				if ( $this->rewrite( $file, $t['installed'], $t['latest'], false ) ) {
					$changed[] = $file;
				}
			}
			// 2) Asset banner comments (e.g. "elementor - v3.17.0" at the top of
			//    admin.min.css / admin-feedback.js). Only the file head is touched.
			foreach ( $this->asset_files( $t['dir'] ) as $file ) {
				if ( $this->rewrite( $file, $t['installed'], $t['latest'], true ) ) {
					$changed[] = $file;
				}
			}
		}
		return $changed;
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
		if ( ! file_exists( $file ) || ! is_writable( $file ) ) {
			return false;
		}
		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents || false === strpos( $contents, $from ) ) {
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
		return false !== file_put_contents( $file, $new );
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
				'dir'       => $theme->get_stylesheet_directory(),
				'installed' => is_string( $installed ) ? $installed : '',
				'latest'    => isset( $latest_themes[ $slug ] ) ? $latest_themes[ $slug ] : $installed,
			);
		}

		return $out;
	}
}
