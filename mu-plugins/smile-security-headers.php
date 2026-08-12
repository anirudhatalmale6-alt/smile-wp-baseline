<?php
/**
 * Plugin Name: Smile Creative — security headers
 * Description: Sends the standard response headers on every request: HSTS, nosniff, frame options, referrer policy and permissions policy.
 * Version:     1.0.0
 * Author:      Smile Creative
 *
 * Kept as a must-use plugin rather than .htaccess lines so it travels with the
 * site through a migration and cannot be lost when a plugin rewrites .htaccess.
 *
 * @package smile-creative
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The headers themselves.
 *
 * Strict-Transport-Security is only sent over https. Sending it on a plain
 * http response is ignored by browsers anyway, and on a site that has not
 * finished its certificate it would be a foot-gun.
 */
function smile_security_headers() {
	if ( headers_sent() ) {
		return;
	}

	$headers = array(
		'X-Content-Type-Options'  => 'nosniff',
		'X-Frame-Options'         => 'SAMEORIGIN',
		'Referrer-Policy'         => 'strict-origin-when-cross-origin',
		'Permissions-Policy'      => 'geolocation=(), camera=(), microphone=(), interest-cohort=()',
		'Cross-Origin-Opener-Policy' => 'same-origin',
	);

	if ( is_ssl() ) {
		// includeSubDomains is opt-in on purpose. Sent from an apex domain it
		// binds EVERY subdomain to https, including staging and preview ones
		// that might not have a certificate yet, and it cannot be taken back
		// for a year. Define SMILE_HSTS_SUBDOMAINS true once you are sure.
		$hsts = 'max-age=31536000';
		if ( defined( 'SMILE_HSTS_SUBDOMAINS' ) && SMILE_HSTS_SUBDOMAINS ) {
			$hsts .= '; includeSubDomains';
		}
		$headers['Strict-Transport-Security'] = $hsts;
	}

	/**
	 * Allow a site to add or override a header.
	 *
	 * @param array $headers Header name => value.
	 */
	$headers = apply_filters( 'smile_security_headers', $headers );

	foreach ( $headers as $name => $value ) {
		header( sprintf( '%s: %s', $name, $value ) );
	}
}
add_action( 'send_headers', 'smile_security_headers' );

/**
 * Same headers on admin and login screens, which do not fire send_headers.
 */
function smile_security_headers_admin( $headers ) {
	$headers['X-Content-Type-Options'] = 'nosniff';
	$headers['X-Frame-Options']        = 'SAMEORIGIN';
	$headers['Referrer-Policy']        = 'strict-origin-when-cross-origin';
	if ( is_ssl() ) {
		$headers['Strict-Transport-Security'] = 'max-age=31536000';
	}
	return $headers;
}
add_filter( 'wp_headers', 'smile_security_headers_admin' );

/**
 * Remove the WordPress version from the head and from asset URLs. Not a
 * defence in itself, but it stops the site advertising which release to
 * target when a core vulnerability is published.
 */
remove_action( 'wp_head', 'wp_generator' );

add_filter(
	'the_generator',
	function () {
		return '';
	}
);

/**
 * Turn off XML-RPC. Nothing we build uses it; it exists mainly as a brute
 * force amplifier via system.multicall.
 */
add_filter( 'xmlrpc_enabled', '__return_false' );

add_filter(
	'wp_headers',
	function ( $headers ) {
		unset( $headers['X-Pingback'] );
		return $headers;
	}
);

/**
 * Username enumeration.
 *
 * Two routes give away every username on the site to anyone who asks, and
 * a username is half of a brute-force attempt:
 *   /wp-json/wp/v2/users   — the REST endpoint, open by default
 *   /?author=1             — redirects to /author/<username>/
 *
 * Nothing we build has public author pages, so both are closed. If a site
 * genuinely needs author archives, define SMILE_ALLOW_AUTHOR_ARCHIVES true.
 */
add_filter(
	'rest_endpoints',
	function ( $endpoints ) {
		if ( is_user_logged_in() ) {
			return $endpoints;
		}
		unset( $endpoints['/wp/v2/users'] );
		unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
		return $endpoints;
	}
);

add_action(
	'template_redirect',
	function () {
		if ( defined( 'SMILE_ALLOW_AUTHOR_ARCHIVES' ) && SMILE_ALLOW_AUTHOR_ARCHIVES ) {
			return;
		}
		if ( is_author() || isset( $_GET['author'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			global $wp_query;
			$wp_query->set_404();
			status_header( 404 );
			nocache_headers();
		}
	},
	1
);

/**
 * Do not tell an attacker which half of the login was correct.
 */
add_filter(
	'login_errors',
	function () {
		return __( 'Those details were not recognised.', 'default' );
	}
);
