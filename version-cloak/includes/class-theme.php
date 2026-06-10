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

	/** Option storing the real installed version per theme slug. */
	const REAL_OPT = 'scshield_theme_real';

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		if ( empty( $this->s['strip_theme_version'] ) ) {
			return;
		}
		// A theme update/switch replaces style.css with the genuine new version,
		// so re-capture the real version there, then re-mask.
		add_action( 'upgrader_process_complete', array( $this, 'recapture_and_strip' ), 20 );
		add_action( 'switch_theme', array( $this, 'recapture_and_strip' ) );

		// Because masking style.css hides WordPress's own theme-update notice,
		// surface our own (we know the real version) + a temporary-unmask action.
		add_action( 'admin_notices', array( $this, 'update_notice' ) );
		add_action( 'admin_post_scshield_unmask_theme', array( $this, 'handle_unmask' ) );
	}

	/**
	 * Mask the `Version:` header in the active (child) and parent theme's
	 * style.css — blanked, or set to the latest version in spoof mode. Captures
	 * the real version first so we can still report updates. Returns files changed.
	 */
	public function strip() {
		$spoof = ! empty( $this->s['spoof_components_latest'] );
		if ( $spoof ) {
			// This runs only on save/update/switch — refresh so we write the
			// genuinely-latest version, not a stale cached one.
			SCShield_Versions::flush();
		}
		$themes = $spoof ? SCShield_Versions::themes() : array();
		$reals  = get_option( self::REAL_OPT, array() );
		if ( ! is_array( $reals ) ) {
			$reals = array();
		}

		$changed = array();
		foreach ( $this->target_map() as $slug => $file ) {
			// Capture the genuine version BEFORE we overwrite it (first time only;
			// recapture_and_strip() clears the entry after a real update/switch).
			if ( ! isset( $reals[ $slug ] ) ) {
				$current = $this->read_version( $file );
				if ( '' !== $current ) {
					$reals[ $slug ] = $current;
				}
			}

			// Decoy mode writes the latest version for this theme; otherwise blank.
			$version = ( $spoof && isset( $themes[ $slug ] ) ) ? $themes[ $slug ] : '';
			if ( $this->set_version( $file, $version ) ) {
				$changed[] = $file;
			}
		}

		update_option( self::REAL_OPT, $reals );
		return $changed;
	}

	/**
	 * Restore the real version into style.css (revert masking). Called when
	 * "Mask theme version" is turned off and on deactivation.
	 */
	public function restore() {
		$reals = get_option( self::REAL_OPT, array() );
		$reals = is_array( $reals ) ? $reals : array();
		$reverted = array();
		foreach ( $this->target_map() as $slug => $file ) {
			if ( ! empty( $reals[ $slug ] ) && $this->set_version( $file, $reals[ $slug ] ) ) {
				$reverted[] = $file;
			}
		}
		return $reverted;
	}

	/**
	 * After a genuine theme update/switch, drop the stored real versions for the
	 * active theme so strip() re-captures the new genuine version, then re-mask.
	 */
	public function recapture_and_strip() {
		$reals = get_option( self::REAL_OPT, array() );
		if ( is_array( $reals ) ) {
			foreach ( array_keys( $this->target_map() ) as $slug ) {
				unset( $reals[ $slug ] );
			}
			update_option( self::REAL_OPT, $reals );
		}
		$this->strip();
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
	 * Read the current `Version:` header value from a style.css, or '' if none.
	 */
	private function read_version( $file ) {
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$contents = file_get_contents( $file );
		if ( false === $contents || '' === $contents ) {
			return '';
		}
		if ( preg_match( '/^[ \t\/*#@]*Version:[ \t]*(\S.*)$/mi', $contents, $m ) ) {
			return trim( $m[1] );
		}
		return '';
	}

	/**
	 * Show our own "theme update available" notice, since masking style.css
	 * hides WordPress's native one. Compares the captured real version against
	 * the latest known, and offers a one-click temporary unmask to update.
	 */
	public function update_notice() {
		if ( ! current_user_can( 'update_themes' ) ) {
			return;
		}

		$reals  = get_option( self::REAL_OPT, array() );
		$reals  = is_array( $reals ) ? $reals : array();
		$themes = SCShield_Versions::themes();

		$pending = array();
		foreach ( array_keys( $this->target_map() ) as $slug ) {
			$real   = isset( $reals[ $slug ] ) ? $reals[ $slug ] : '';
			$latest = isset( $themes[ $slug ] ) ? $themes[ $slug ] : '';
			if ( '' !== $real && '' !== $latest && version_compare( $real, $latest, '<' ) ) {
				$pending[ $slug ] = array( 'real' => $real, 'latest' => $latest );
			}
		}

		if ( empty( $pending ) ) {
			// Confirm an unmask just happened, if flagged.
			if ( get_transient( 'scshield_unmasked' ) ) {
				delete_transient( 'scshield_unmasked' );
				echo '<div class="notice notice-info is-dismissible"><p><strong>Version Cloak:</strong> theme version temporarily unmasked. Update your theme now from Appearance &rarr; Themes; it will be re-masked automatically afterward.</p></div>';
			}
			return;
		}

		$lines = array();
		foreach ( $pending as $slug => $v ) {
			$lines[] = sprintf( '%s (installed %s &rarr; latest %s)', esc_html( $slug ), esc_html( $v['real'] ), esc_html( $v['latest'] ) );
		}
		$unmask_url = wp_nonce_url( admin_url( 'admin-post.php?action=scshield_unmask_theme' ), 'scshield_unmask' );

		echo '<div class="notice notice-warning"><p><strong>Version Cloak — theme update available:</strong> ' . wp_kses_post( implode( ', ', $lines ) ) . '.<br>'
			. 'WordPress\'s own update notice is hidden because the theme version is masked. '
			. '<a href="' . esc_url( $unmask_url ) . '">Temporarily unmask to update</a> (it re-masks automatically after the update).</p></div>';
	}

	/**
	 * Restore the real version into style.css so the theme can be updated
	 * normally. Re-masking happens via upgrader_process_complete after updating.
	 */
	public function handle_unmask() {
		if ( ! current_user_can( 'update_themes' ) ) {
			wp_die( 'Insufficient permissions.' );
		}
		check_admin_referer( 'scshield_unmask' );

		$reals = get_option( self::REAL_OPT, array() );
		$reals = is_array( $reals ) ? $reals : array();
		foreach ( $this->target_map() as $slug => $file ) {
			if ( ! empty( $reals[ $slug ] ) ) {
				$this->set_version( $file, $reals[ $slug ] );
			}
		}
		set_transient( 'scshield_unmasked', 1, 30 * MINUTE_IN_SECONDS );

		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'themes.php' ) );
		exit;
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
