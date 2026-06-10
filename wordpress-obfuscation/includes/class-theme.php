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
		$changed = array();
		foreach ( $this->target_files() as $file ) {
			if ( $this->blank_version( $file ) ) {
				$changed[] = $file;
			}
		}
		return $changed;
	}

	/**
	 * style.css of the active stylesheet and its template (parent), de-duped.
	 */
	private function target_files() {
		$dirs = array( get_stylesheet_directory(), get_template_directory() );
		$dirs = array_unique( array_filter( $dirs ) );

		$files = array();
		foreach ( $dirs as $dir ) {
			$css = trailingslashit( $dir ) . 'style.css';
			if ( file_exists( $css ) ) {
				$files[] = $css;
			}
		}
		return $files;
	}

	/**
	 * Remove the value after `Version:` in the file header comment only.
	 * Leaves the rest of the stylesheet untouched. No-op if not writable or
	 * if the value is already blank.
	 */
	private function blank_version( $file ) {
		if ( ! is_writable( $file ) ) {
			return false;
		}

		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			return false;
		}

		// Match the header line `Version: 8.30` (case-insensitive), first
		// occurrence only, and blank the value. The header lives in the opening
		// comment block, so only the first match matters.
		$new = preg_replace(
			'/^([ \t\/*#@]*Version:)[ \t]*\S.*$/mi',
			'$1',
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
