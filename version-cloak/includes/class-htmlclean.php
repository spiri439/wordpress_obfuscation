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

	/** Nesting level of the buffer we open, so we can close exactly ours. */
	private $buffer_level = 0;

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
		$this->buffer_level = ob_get_level();

		// Explicitly close the buffer we opened instead of leaving it to PHP's
		// end-of-request flush. Runs last so any caching/optimization plugin that
		// reads the buffer on shutdown does so first.
		add_action( 'shutdown', array( $this, 'flush_buffer' ), PHP_INT_MAX );
	}

	/**
	 * Close the output buffer this instance opened, pairing the ob_start() above.
	 * Only acts when our buffer is still open and is the topmost one, so we never
	 * disturb a buffer another component stacked on top of (or already closed)
	 * ours. ob_end_flush() triggers our clean() callback as it flushes.
	 */
	public function flush_buffer() {
		if ( $this->buffer_level > 0 && ob_get_level() === $this->buffer_level ) {
			ob_end_flush();
		}
		$this->buffer_level = 0;
	}

	/**
	 * Strip version-revealing markup from the buffered HTML.
	 */
	public function clean( $html ) {
		// Only touch full HTML documents, never JSON/XML/binary responses.
		if ( '' === $html || stripos( $html, '<html' ) === false ) {
			return $html;
		}

		// Remove <meta name="generator"> tags. If the WordPress core version is
		// in Decoy mode (a fake "WordPress <ver>" generator is intentionally
		// emitted), keep that one and strip only the others (plugin-emitted).
		$keep_wp = ! empty( $this->s['wp_spoof_use_latest'] )
			|| '' !== trim( (string) ( isset( $this->s['wp_version_spoof'] ) ? $this->s['wp_version_spoof'] : '' ) );

		if ( $keep_wp ) {
			$html = preg_replace(
				'/<meta\b(?=[^>]*\bname=(?:["\'])generator(?:["\']))(?![^>]*content=(?:["\'])WordPress)[^>]*>\s*/i',
				'',
				$html
			);
		} else {
			$html = preg_replace(
				'/<meta\b(?=[^>]*\bname=(?:["\'])generator(?:["\']))[^>]*>\s*/i',
				'',
				$html
			);
		}

		// Handle ver= on asset URLs left inside markup/inline CSS (e.g. a <link>
		// the enqueue filter missed, or @font-face url(".../fa.woff2?ver=8.30")).
		// Matches the asset URL plus its whole query string so ver= is caught in
		// any position (?ver=, ?x=1&ver=, ?x=1&amp;ver=), not just first.
		$pattern = '#([^\s"\'()]+\.(?:css|js|woff2?|ttf|otf|eot|svg|png|jpe?g|gif|webp))\?([^\s"\'()]*)#i';
		$html    = preg_replace_callback( $pattern, array( $this, 'handle_asset_ver' ), $html );

		// Plugin version strings embedded in HTML comments. Yoast SEO prints
		// "<!-- ... optimized with the Yoast SEO plugin v27.6 - ... -->".
		// Decoy -> bump to the plugin's latest; Obfuscate -> drop the version.
		$html = $this->handle_comment_version(
			$html,
			'/(Yoast SEO plugin v)([0-9][0-9.]*)/i',
			'wordpress-seo'
		);

		return $html;
	}

	/**
	 * Rewrite a "<name> vX.Y" version token in HTML comments to the component's
	 * latest (Decoy) or remove the number (Obfuscate). $pattern must capture the
	 * label in group 1 and the version in group 2.
	 */
	private function handle_comment_version( $html, $pattern, $slug ) {
		$decoy  = ! empty( $this->s['spoof_components_latest'] );
		$latest = $decoy ? SCShield_Versions::latest_plugin( $slug ) : '';
		return preg_replace_callback(
			$pattern,
			function ( $m ) use ( $decoy, $latest ) {
				if ( $decoy && '' !== $latest ) {
					return $m[1] . $latest; // bump to latest
				}
				return $m[1]; // drop the version number
			},
			$html
		);
	}

	/**
	 * Callback for asset URLs carrying a query string: drop the real ver= token
	 * wherever it sits, then in Decoy mode re-add the owning component's latest
	 * version (looks patched). Other query args are preserved in order, and the
	 * original separator (& or &amp;) is kept so the URL stays valid.
	 */
	public function handle_asset_ver( $m ) {
		$path  = $m[1];
		$query = $m[2];

		$sep   = ( false !== strpos( $query, '&amp;' ) ) ? '&amp;' : '&';
		$parts = preg_split( '/&amp;|&/', $query );
		$kept  = array();
		$found = false;
		foreach ( $parts as $p ) {
			if ( '' === $p ) {
				continue;
			}
			if ( preg_match( '/^ver=/i', $p ) ) {
				$found = true; // drop the real version
				continue;
			}
			$kept[] = $p;
		}
		if ( ! $found ) {
			return $m[0]; // no ver= param — leave the URL exactly as-is
		}

		// Decoy mode: re-add the component's latest version so it reads as patched.
		if ( ! empty( $this->s['spoof_components_latest'] ) ) {
			$latest = SCShield_Versions::latest_for_url( $path );
			if ( '' !== $latest ) {
				$kept[] = 'ver=' . $latest;
			}
		}

		return empty( $kept ) ? $path : $path . '?' . implode( $sep, $kept );
	}
}
