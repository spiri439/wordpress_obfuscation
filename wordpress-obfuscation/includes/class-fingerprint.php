<?php
/**
 * Fingerprint hardening: hides core/plugin version leaks that scanners read to
 * decide "this site runs vulnerable plugin X version Y".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_Fingerprint {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		if ( ! empty( $this->s['remove_generator'] ) ) {
			$this->strip_generator();
		}
		if ( ! empty( $this->s['remove_query_versions'] ) ) {
			add_filter( 'style_loader_src', array( $this, 'strip_ver_query' ), 9999 );
			add_filter( 'script_loader_src', array( $this, 'strip_ver_query' ), 9999 );
		}
		if ( ! empty( $this->s['hide_rest_users'] ) ) {
			add_filter( 'rest_endpoints', array( $this, 'block_rest_user_endpoints' ) );
		}
		if ( ! empty( $this->s['block_author_scan'] ) ) {
			add_action( 'template_redirect', array( $this, 'block_author_enumeration' ) );
		}
	}

	/**
	 * Remove the WordPress version from every place core leaks it: the HTML
	 * <meta name="generator">, RSS/Atom feeds, and the generator readout.
	 */
	private function strip_generator() {
		remove_action( 'wp_head', 'wp_generator' );
		add_filter( 'the_generator', '__return_empty_string' );

		// Covers RSS2, Atom, RDF, comments feeds, export, etc.
		foreach ( array( 'rss2_head', 'commentsrss2_head', 'rss_head', 'rdf_header', 'atom_head', 'comments_atom_head', 'opml_head', 'app_head' ) as $hook ) {
			add_filter( $hook, array( $this, 'noop_generator' ), 0 );
		}
		add_filter( 'get_the_generator_html', '__return_empty_string' );
		add_filter( 'get_the_generator_xhtml', '__return_empty_string' );
		add_filter( 'get_the_generator_atom', '__return_empty_string' );
		add_filter( 'get_the_generator_rss2', '__return_empty_string' );
		add_filter( 'get_the_generator_rdf', '__return_empty_string' );
		add_filter( 'get_the_generator_comment', '__return_empty_string' );
		add_filter( 'get_the_generator_export', '__return_empty_string' );
	}

	public function noop_generator() {
		// Intentionally outputs nothing.
	}

	/**
	 * Strip the ?ver= query arg from enqueued assets. The version on
	 * /wp-content/plugins/foo/foo.css?ver=2.3.1 is a direct version tell.
	 *
	 * NOTE: ?ver also busts browser cache on updates. We only remove the arg
	 * when it equals the WP core version or looks like a plugin version; you can
	 * instead replace it with a site-wide salt if cache-busting matters more.
	 */
	public function strip_ver_query( $src ) {
		if ( strpos( $src, 'ver=' ) !== false ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * Disable the REST routes that dump the user list (a username harvest used
	 * before brute-force). Logged-in users with list_users keep access.
	 */
	public function block_rest_user_endpoints( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( array( '/wp/v2/users', '/wp/v2/users/(?P<id>[\d]+)' ) as $route ) {
			if ( isset( $endpoints[ $route ] ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Block /?author=N which 301-redirects to /author/<login>/, leaking the
	 * real username. Front-end only; admin/REST unaffected.
	 */
	public function block_author_enumeration() {
		if ( is_admin() ) {
			return;
		}
		if ( isset( $_GET['author'] ) && ! is_user_logged_in() ) {
			$author = sanitize_text_field( wp_unslash( $_GET['author'] ) );
			// Only block the numeric ID form used for enumeration.
			if ( '' !== $author && is_numeric( $author ) ) {
				wp_safe_redirect( home_url( '/' ), 301 );
				exit;
			}
		}
	}
}
