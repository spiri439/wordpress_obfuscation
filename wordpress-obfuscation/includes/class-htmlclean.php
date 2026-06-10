<?php
/**
 * Final-output HTML cleaner.
 *
 * Some plugins print their own <meta name="generator"> advertising their
 * version (e.g. Slider Revolution -> "Powered by Slider Revolution 6.7.35",
 * WPBakery Page Builder). These are emitted directly on wp_head and there is no
 * single core filter to remove them, so we buffer the front-end response and
 * strip them from the final HTML.
 *
 * Front-end pages only — skips admin, AJAX, REST, cron, and feeds. Note this
 * runs an output buffer over the page; it nests fine with caching/optimization
 * plugins (LiteSpeed etc.), which will then cache the already-cleaned HTML.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_HTMLClean {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		if ( empty( $this->s['clean_html_output'] ) ) {
			return;
		}
		add_action( 'template_redirect', array( $this, 'start_buffer' ), 0 );
	}

	public function start_buffer() {
		if ( is_admin() || is_feed() ) {
			return;
		}
		if ( ( defined( 'DOING_AJAX' ) && DOING_AJAX )
			|| ( defined( 'REST_REQUEST' ) && REST_REQUEST )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON )
			|| ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) ) {
			return;
		}
		ob_start( array( $this, 'clean' ) );
	}

	/**
	 * Strip version-revealing markup from the buffered HTML.
	 */
	public function clean( $html ) {
		// Only touch full HTML documents, never JSON/XML/binary responses.
		if ( '' === $html || stripos( $html, '<html' ) === false ) {
			return $html;
		}

		// Remove every <meta name="generator" ...> regardless of attribute order.
		$html = preg_replace(
			'/<meta\b(?=[^>]*\bname=(["\'])generator\1)[^>]*>\s*/i',
			'',
			$html
		);

		// Strip ?ver= from asset URLs left inside inline CSS/markup (e.g.
		// @font-face url(".../fa-solid-900.woff2?ver=8.30")). The enqueue filter
		// only covers <link>/<script> tags, not URLs embedded in CSS text.
		$html = preg_replace(
			'/(\.(?:css|js|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp))\?ver=[0-9A-Za-z.\-]+/i',
			'$1',
			$html
		);

		return $html;
	}
}
