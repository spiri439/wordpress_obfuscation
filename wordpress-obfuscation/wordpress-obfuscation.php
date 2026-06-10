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
		// Fingerprint hardening.
		'remove_generator'      => 1, // Strip <meta generator> + version from feeds/scripts.
		'wp_spoof_use_latest'   => 0,  // Decoy = latest WP version (looks patched -> deters bots).
		'wp_version_spoof'      => '', // Manual decoy version; fallback when "use latest" is off/unavailable.
		'remove_query_versions' => 1, // Strip ?ver= from enqueued CSS/JS.
		'spoof_components_latest' => 0, // Rewrite plugin/theme versions to their LATEST instead of removing them.
		'strip_body_versions'   => 1, // Strip version classes from <body> (e.g. Zephyr_8.30, js-comp-ver-X).
		'clean_html_output'     => 1, // Buffer front-end HTML and strip plugin <meta generator> tags.
		'block_readme_files'    => 1, // Block readme/changelog via .htaccess (Apache).
		'hide_rest_users'       => 1, // Block the wp-json user-enumeration endpoint.
		'block_author_scan'     => 1, // Block ?author=N enumeration redirects.
		'strip_theme_version'   => 0, // Blank Version: in theme style.css (edits files; off by default).

		// XML-RPC.
		'xmlrpc_mode'           => 'disable', // 'off' | 'disable' | 'pingback_only_off'
		                                      // off = leave default behavior
		                                      // disable = return 404 (hidden)
		                                      // pingback_only_off = keep xmlrpc, kill pingback.

		// WP-Cron.
		'disable_wp_cron'       => 1, // Set DISABLE_WP_CRON-equivalent: stop the HTTP self-trigger.
		'block_wpcron_external' => 1, // 403 direct external hits to wp-cron.php.
		'wpcron_secret'         => '', // Optional token to still allow ?doing_wp_cron=<secret>.
	);
}

/**
 * Get merged settings (defaults + saved).
 */
function scshield_get_settings() {
	$saved = get_option( SCSHIELD_OPTION, array() );
	if ( ! is_array( $saved ) ) {
		$saved = array();
	}
	return wp_parse_args( $saved, scshield_default_settings() );
}

// Load modules.
require_once SCSHIELD_DIR . 'includes/class-versions.php';
require_once SCSHIELD_DIR . 'includes/class-fingerprint.php';
require_once SCSHIELD_DIR . 'includes/class-xmlrpc.php';
require_once SCSHIELD_DIR . 'includes/class-wpcron.php';
require_once SCSHIELD_DIR . 'includes/class-theme.php';
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
	( new SCShield_HTMLClean( $settings ) )->hooks();

	if ( is_admin() ) {
		( new SCShield_Admin( $settings ) )->hooks();
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
 * Deactivation: clean our .htaccess block so the site is never left broken.
 */
function scshield_deactivate() {
	SCShield_Htaccess::remove();
}
register_deactivation_hook( __FILE__, 'scshield_deactivate' );
