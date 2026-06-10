<?php
/**
 * XML-RPC handling.
 *
 * xmlrpc.php is a PHP entry point that boots WordPress, so we can intercept it.
 * Scanners hit it to (a) confirm it exists and (b) abuse system.multicall for
 * amplified brute-force and pingback DDoS.
 *
 * Modes:
 *   'disable'           -> respond 404 so the endpoint looks absent entirely.
 *   'pingback_only_off' -> keep XML-RPC working but remove pingback methods.
 *   'off'               -> do nothing (leave WP default).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SCShield_XMLRPC {

	/** @var array */
	private $s;

	public function __construct( array $settings ) {
		$this->s = $settings;
	}

	public function hooks() {
		$mode = isset( $this->s['xmlrpc_mode'] ) ? $this->s['xmlrpc_mode'] : 'disable';

		switch ( $mode ) {
			case 'disable':
				// Turn the feature off...
				add_filter( 'xmlrpc_enabled', '__return_false' );
				// ...and make the endpoint itself look like it doesn't exist.
				add_action( 'xmlrpc_call', array( $this, 'kill_with_404' ), 0 );
				// Catch the request before XML-RPC dispatch when possible.
				if ( defined( 'XMLRPC_REQUEST' ) && XMLRPC_REQUEST ) {
					$this->kill_with_404();
				}
				// Strip pingback advertisement header (X-Pingback).
				add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
				// Remove the RSD link that points scanners at xmlrpc.php.
				remove_action( 'wp_head', 'rsd_link' );
				break;

			case 'pingback_only_off':
				add_filter( 'xmlrpc_methods', array( $this, 'drop_pingback_methods' ) );
				add_filter( 'wp_headers', array( $this, 'remove_pingback_header' ) );
				break;

			case 'off':
			default:
				// Leave WordPress defaults untouched.
				break;
		}
	}

	/**
	 * Return a clean 404 so the endpoint is indistinguishable from "not here".
	 */
	public function kill_with_404() {
		status_header( 404 );
		nocache_headers();
		// Mimic a generic not-found rather than emitting an XML-RPC fault,
		// which would itself confirm the endpoint exists.
		header( 'Content-Type: text/html; charset=UTF-8' );
		echo "<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>Not Found</h1></body></html>";
		exit;
	}

	/**
	 * Remove pingback/ping methods, leaving any legitimate XML-RPC use intact.
	 */
	public function drop_pingback_methods( $methods ) {
		unset(
			$methods['pingback.ping'],
			$methods['pingback.extensions.getPingbacks'],
			$methods['system.multicall'] // amplification vector
		);
		return $methods;
	}

	/**
	 * Drop the X-Pingback response header that advertises the endpoint.
	 */
	public function remove_pingback_header( $headers ) {
		if ( isset( $headers['X-Pingback'] ) ) {
			unset( $headers['X-Pingback'] );
		}
		return $headers;
	}
}
