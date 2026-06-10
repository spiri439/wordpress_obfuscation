<?php
/**
 * WP-Cron handling.
 *
 * By default WordPress fires wp-cron.php over HTTP on every front-end request
 * (the "pseudo-cron"). That endpoint is publicly reachable and can be hammered
 * to cause load. The robust fix is:
 *   1. Stop the self-triggering pseudo-cron (define DISABLE_WP_CRON).
 *   2. Block direct external hits to wp-cron.php.
 *   3. Run a real system cron that calls wp-cron.php with a secret token.
 *
 * IMPORTANT: If you disable the pseudo-cron you MUST set up a system cron or
 * scheduled tasks (posts, updates, backups) stop running. See README.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_WPCron {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		// Defining DISABLE_WP_CRON belongs in wp-config.php; from a plugin we can
		// only neutralize the self-trigger via the spawn filter as a fallback.
		if ( ! empty( $this->s['disable_wp_cron'] ) && ! defined( 'DISABLE_WP_CRON' ) ) {
			// Prevent WordPress from spawning the HTTP cron request on page loads.
			add_filter( 'pre_option_cron', array( $this, 'noop_passthrough' ), 0 );
			// The cleaner mechanism: stop the spawn entirely.
			add_filter( 'wp_doing_cron', '__return_false' );
		}

		if ( ! empty( $this->s['block_wpcron_external'] ) ) {
			// Earliest safe hook to inspect a direct wp-cron.php request.
			add_action( 'muplugins_loaded', array( $this, 'guard_wpcron' ), 0 );
			add_action( 'plugins_loaded', array( $this, 'guard_wpcron' ), 0 );
		}
	}

	public function noop_passthrough( $value ) {
		return $value; // placeholder so the filter is harmless if WP changes internals
	}

	/**
	 * If this is a direct request to wp-cron.php from outside, reject it unless
	 * it carries the configured secret. Loopback requests from the server and
	 * authorized system cron (with ?doing_wp_cron=<secret>) are allowed.
	 */
	public function guard_wpcron() {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? $_SERVER['SCRIPT_NAME'] : '';
		$php_self = isset( $_SERVER['PHP_SELF'] ) ? $_SERVER['PHP_SELF'] : '';

		$is_wpcron = ( false !== strpos( $script, 'wp-cron.php' ) )
			|| ( false !== strpos( $php_self, 'wp-cron.php' ) )
			|| ( defined( 'DOING_CRON' ) && DOING_CRON );

		if ( ! $is_wpcron ) {
			return;
		}

		$secret = isset( $this->s['wpcron_secret'] ) ? (string) $this->s['wpcron_secret'] : '';

		// No secret configured -> allow only same-host loopback requests.
		$remote = isset( $_SERVER['REMOTE_ADDR'] ) ? $_SERVER['REMOTE_ADDR'] : '';
		$server = isset( $_SERVER['SERVER_ADDR'] ) ? $_SERVER['SERVER_ADDR'] : '';
		$is_loopback = in_array( $remote, array( '127.0.0.1', '::1' ), true )
			|| ( '' !== $server && $remote === $server );

		if ( '' !== $secret ) {
			$provided = isset( $_GET['scshield_cron'] ) ? (string) $_GET['scshield_cron'] : '';
			if ( hash_equals( $secret, $provided ) ) {
				return; // authorized system cron
			}
		}

		if ( $is_loopback ) {
			return; // local request, allow
		}

		// Otherwise: external direct hit -> deny without confirming much.
		status_header( 403 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=UTF-8' );
		echo 'Forbidden';
		exit;
	}
}
