<?php
/**
 * Plugin Name:       WordPress Obfuscation
 * Plugin URI:        https://vesrl.ro
 * Description:        Reduces fingerprinting by mass scanners: hides plugin/core version leaks, neutralizes XML-RPC, and locks down WP-Cron. Hardening layer — NOT a substitute for keeping plugins updated.
 * Version:           1.0.0
 * Requires at least: 5.0
 * Requires PHP:      7.2
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
		'remove_query_versions' => 1, // Strip ?ver= from enqueued CSS/JS.
		'strip_body_versions'   => 1, // Strip version classes from <body> (e.g. js-comp-ver-X).
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
require_once SCSHIELD_DIR . 'includes/class-fingerprint.php';
require_once SCSHIELD_DIR . 'includes/class-xmlrpc.php';
require_once SCSHIELD_DIR . 'includes/class-wpcron.php';
require_once SCSHIELD_DIR . 'includes/class-theme.php';
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
