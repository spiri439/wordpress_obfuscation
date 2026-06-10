<?php
/**
 * Plugin Name:       WordPress Obfuscation
 * Plugin URI:        https://vesrl.ro
 * Description:        Reduces fingerprinting by mass scanners: hides plugin/core version leaks, neutralizes XML-RPC, and locks down WP-Cron. Hardening layer — NOT a substitute for keeping plugins updated.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      5.6
 * Author:            spiri439
 * Author URI:        https://vesrl.ro
 * License:           GPL-2.0-or-later
 * Text Domain:       wordpress-obfuscation
 *
 * SECURITY NOTE: This plugin obscures version/endpoint fingerprints to cut down
 * opportunistic automated scanning. It does NOT patch vulnerable code. Keep your
 * plugins, themes, and core updated — obscurity is a complement to patching, not
 * a replacement for it.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// Minimum PHP guard: bail gracefully on very old PHP instead of fataling.
// The plugin itself is written to run on PHP 5.6+ (no 7.0+ syntax).
if ( version_compare( PHP_VERSION, '5.6', '<' ) ) {
	add_action( 'admin_notices', 'scshield_php_notice' );
	if ( ! function_exists( 'scshield_php_notice' ) ) {
		function scshield_php_notice() {
			echo '<div class="notice notice-error"><p>WordPress Obfuscation requires PHP 5.6 or newer. It has been disabled.</p></div>';
		}
	}
	return; // Stop loading the rest of the plugin.
}

define( 'SCSHIELD_VERSION', '1.0.0' );
define( 'SCSHIELD_FILE', __FILE__ );
define( 'SCSHIELD_DIR', plugin_dir_path( __FILE__ ) );
define( 'SCSHIELD_OPTION', 'scshield_settings' );

/**
 * Default settings. Everything on by default except the more disruptive toggles.
 */
function scshield_default_settings() {
	return array(
		// PRIMARY mode dropdowns. Each: 'off' | 'obfuscate' | 'decoy'.
		//   obfuscate = remove/hide the version.
		//   decoy     = report the LATEST version (looks patched -> deters bots).
		'mode_wp'               => 'decoy', // WordPress core version.
		'mode_components'       => 'decoy', // Plugin & theme versions.

		// Manual decoy WP version; fallback when latest can't be auto-detected.
		'wp_version_spoof'      => '',

		// Manual "slug = version" overrides for premium plugins/themes WordPress
		// can't report a latest for (one per line, e.g. "revslider = 6.7.57").
		'manual_versions'       => '',

		// Theme style.css editing (advanced; edits files, affects theme update notice).
		'strip_theme_version'   => 0,

		// Other hardening (independent of the version modes).
		'hide_rest_users'       => 1, // Block the wp-json user-enumeration endpoint.
		'block_author_scan'     => 1, // Block ?author=N enumeration redirects.

		// XML-RPC.
		'xmlrpc_mode'           => 'disable', // 'off' | 'disable' | 'pingback_only_off'

		// WP-Cron.
		'disable_wp_cron'       => 1,
		'block_wpcron_external' => 1,
		'wpcron_secret'         => '',
	);
}

/**
 * Derive the granular behavior flags the modules read from the two primary
 * mode dropdowns. Keeps module logic simple and the two modes authoritative.
 */
function scshield_normalize_settings( $s ) {
	$wp   = isset( $s['mode_wp'] ) ? $s['mode_wp'] : 'decoy';
	$comp = isset( $s['mode_components'] ) ? $s['mode_components'] : 'decoy';

	// WordPress core version.
	$s['remove_generator']    = ( 'off' !== $wp ) ? 1 : 0;
	$s['wp_spoof_use_latest'] = ( 'decoy' === $wp ) ? 1 : 0;

	// Plugin & theme versions.
	$comp_on                    = ( 'off' !== $comp );
	$s['remove_query_versions'] = $comp_on ? 1 : 0;
	$s['strip_body_versions']   = $comp_on ? 1 : 0;
	$s['clean_html_output']     = $comp_on ? 1 : 0;
	$s['spoof_components_latest'] = ( 'decoy' === $comp ) ? 1 : 0;

	// Static version files (readme/changelog/release_log) for plugins & themes:
	//   obfuscate -> BLOCK them (.htaccess);  decoy -> REWRITE them to latest.
	$s['block_readme_files'] = ( 'obfuscate' === $comp ) ? 1 : 0;
	$s['mask_version_files'] = ( 'decoy' === $comp ) ? 1 : 0;

	return $s;
}

/**
 * Get merged settings (defaults + saved), with mode-derived flags applied.
 */
function scshield_get_settings() {
	$saved = get_option( SCSHIELD_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return scshield_normalize_settings( wp_parse_args( $saved, scshield_default_settings() ) );
}

// Load modules.
require_once SCSHIELD_DIR . 'includes/class-versions.php';
require_once SCSHIELD_DIR . 'includes/class-fingerprint.php';
require_once SCSHIELD_DIR . 'includes/class-xmlrpc.php';
require_once SCSHIELD_DIR . 'includes/class-wpcron.php';
require_once SCSHIELD_DIR . 'includes/class-theme.php';
require_once SCSHIELD_DIR . 'includes/class-compfiles.php';
require_once SCSHIELD_DIR . 'includes/class-htmlclean.php';
require_once SCSHIELD_DIR . 'includes/class-admin.php';
require_once SCSHIELD_DIR . 'includes/class-htaccess.php';

/**
 * Boot.
 */
function scshield_init() {
	$settings = scshield_get_settings();

	( new SCShield_Fingerprint( $settings ) )->hooks();
	( new SCShield_XMLRPC( $settings ) )->hooks();
	( new SCShield_WPCron( $settings ) )->hooks();
	( new SCShield_Theme( $settings ) )->hooks();
	( new SCShield_CompFiles( $settings ) )->hooks();
	( new SCShield_HTMLClean( $settings ) )->hooks();

	if ( is_admin() ) {
		( new SCShield_Admin( $settings ) )->hooks();
		// Capture premium plugin/theme "latest" versions whenever they're
		// visible in the admin, so Decoy can use them automatically later.
		add_action( 'admin_init', array( 'SCShield_Versions', 'learn' ) );
	}
}
add_action( 'plugins_loaded', 'scshield_init' );

/**
 * Activation: seed defaults + write .htaccess rules (Apache only).
 */
function scshield_activate() {
	if ( false === get_option( SCSHIELD_OPTION, false ) ) {
		add_option( SCSHIELD_OPTION, scshield_default_settings() );
	}
	SCShield_Htaccess::write( scshield_get_settings() );
}
register_activation_hook( __FILE__, 'scshield_activate' );

/**
 * Deactivation: revert all file edits to the real versions and clean our
 * .htaccess block, so disabling the plugin returns the site to normal.
 */
function scshield_deactivate() {
	$settings = scshield_get_settings();
	( new SCShield_CompFiles( $settings ) )->restore();
	( new SCShield_Theme( $settings ) )->restore();
	SCShield_Htaccess::remove();
}
register_deactivation_hook( __FILE__, 'scshield_deactivate' );
