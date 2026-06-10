<?php
/**
 * Theme version-leak hardening.
 *
 * Scanners (e.g. WPScan "Style" detection) read the active theme's style.css
 * directly and parse its `Version:` header. style.css is a STATIC file served
 * by the webserver — PHP cannot intercept the request, so the only way to
 * remove that version is to edit the file on disk.
 *
 * Trade-offs (by design, opt-in only):
 *   - This edits theme files. A theme update overwrites style.css, so we
 *     re-strip automatically on `upgrader_process_complete`.
 *   - It does NOT patch anything. An outdated theme stays vulnerable. UPDATE
 *     the theme — this only quiets passive version fingerprinting.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_Theme {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		if ( empty( $this->s['strip_theme_version'] ) ) {
			return;
		}
		// Re-apply after any theme/plugin/core update restores the header.
		add_action( 'upgrader_process_complete', array( $this, 'strip' ), 20 );
		// Re-apply when the active theme changes.
		add_action( 'switch_theme', array( $this, 'strip' ) );
	}

	/**
	 * Blank the `Version:` header value in the active (child) and parent
	 * theme's style.css. Returns the list of files changed.
	 */
	public function strip() {
		$spoof = ! empty( $this->s['spoof_components_latest'] );
		if ( $spoof ) {
			// This runs only on save/update/switch — refresh so we write the
			// genuinely-latest version, not a stale cached one.
			SCShield_Versions::flush();
		}
		$themes = $spoof ? SCShield_Versions::themes() : array();

		$changed = array();
		foreach ( $this->target_map() as $slug => $file ) {
			// Decoy mode writes the latest version for this theme; otherwise blank.
			$version = ( $spoof && isset( $themes[ $slug ] ) ) ? $themes[ $slug ] : '';
			if ( $this->set_version( $file, $version ) ) {
				$changed[] = $file;
			}
		}
		return $changed;
	}

	/**
	 * [ stylesheet-slug => style.css path ] for the active and parent theme.
	 */
	private function target_map() {
		$map = array();
		$pairs = array(
			get_stylesheet() => get_stylesheet_directory(),
			get_template()   => get_template_directory(),
		);
		foreach ( $pairs as $slug => $dir ) {
			if ( ! $dir ) {
				continue;
			}
			$css = trailingslashit( $dir ) . 'style.css';
			if ( file_exists( $css ) && ! isset( $map[ $slug ] ) ) {
				$map[ $slug ] = $css;
			}
		}
		return $map;
	}

	/**
	 * Set the `Version:` header value in the file header comment only ('' blanks
	 * it). Leaves the rest of the stylesheet untouched. No-op if not writable.
	 */
	private function set_version( $file, $version ) {
		if ( ! is_writable( $file ) ) {
			return false;
		}

		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			return false;
		}

		// Match the header line `Version: 8.30` (case-insensitive), first
		// occurrence only. Use a callback so the replacement version can't be
		// interpreted as a regex backreference.
		$new = preg_replace_callback(
			'/^([ \t\/*#@]*Version:)[ \t]*\S.*$/mi',
			function ( $m ) use ( $version ) {
				return $m[1] . ( '' !== $version ? ' ' . $version : '' );
			},
			$contents,
			1
		);

		if ( null === $new || $new === $contents ) {
			return false; // nothing to change or regex failed safely
		}

		// Write back, preserving everything else verbatim.
		return false !== file_put_contents( $file, $new );
	}
}
