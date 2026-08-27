<?php
/**
 * Plugin Name: Smile — Elementor CSS guard
 * Description: Serves an Elementor generated stylesheet even when the file has been deleted, rebuilding it on the spot, and purges the page cache whenever Elementor clears its file cache.
 * Version:     1.0.0
 * Author:      Smile Creative
 *
 * WHY THIS EXISTS
 * ---------------
 * Elementor does not keep page styles in the page. It writes one generated
 * stylesheet per page to wp-content/uploads/elementor/css/post-<id>.css and links
 * to it. Any plugin, theme, translation or core upgrade fires
 * `upgrader_process_complete`, and Elementor answers that by deleting every one of
 * those files together with the `_elementor_css` post meta that records them. The
 * files are meant to be rebuilt lazily, on the next render of each page.
 *
 * On a LiteSpeed site they often are not, because a cache HIT is served straight
 * from disk without ever starting PHP. So the visitor is handed yesterday's HTML,
 * that HTML still links to post-<id>.css, the file is gone, and the only thing that
 * could recreate it -- a full PHP render -- is exactly what the cache is preventing.
 * The page loads, returns 200, and arrives with no styling. It stays that way until
 * the cached copy expires, which on a 7-day TTL means a week.
 *
 * The rewrite rules WordPress already ships send a request for a file that does not
 * exist to index.php, so WordPress is handed these requests whether it wants them or
 * not. This plugin takes them: it rebuilds the stylesheet and returns it, first
 * request, with no visible failure. If Elementor legitimately has no styles for that
 * page it returns an empty stylesheet rather than let WordPress answer a .css request
 * with a 97KB HTML 404 page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Intercept a request for a generated Elementor stylesheet that is not on disk.
 *
 * Hooked as early as parse_request allows: nothing below this point in the request
 * matters once we have decided to answer it ourselves.
 */
add_action(
	'parse_request',
	function () {
		if ( is_admin() ) {
			return;
		}

		$uri = isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		if ( ! preg_match( '#/elementor/css/post-(\d+)\.css$#', $path ) ) {
			return;
		}

		$uploads = wp_upload_dir( null, false );
		if ( empty( $uploads['basedir'] ) || ! empty( $uploads['error'] ) ) {
			return;
		}

		// Only ever act on this site's own uploads path, never on a lookalike URL.
		$prefix = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		if ( '' !== $prefix && 0 !== strpos( $path, $prefix . '/elementor/css/' ) ) {
			return;
		}

		preg_match( '#post-(\d+)\.css$#', $path, $m );
		$post_id = (int) $m[1];
		$file    = $uploads['basedir'] . '/elementor/css/post-' . $post_id . '.css';

		if ( ! file_exists( $file ) ) {
			$post = get_post( $post_id );

			// A real Elementor-built post, or we have no business generating anything.
			if ( ! $post || '' === (string) get_post_meta( $post_id, '_elementor_data', true ) ) {
				return;
			}

			// One rebuild per post per minute. A burst of misses after a cache clear
			// must not turn into a burst of CSS generation.
			$lock = 'smile_elcss_' . $post_id;
			if ( ! get_transient( $lock ) ) {
				set_transient( $lock, 1, MINUTE_IN_SECONDS );

				if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
					try {
						\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
					} catch ( \Throwable $e ) {
						// Never let a generation failure take the request down with it.
						if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
							error_log( 'smile-elementor-css-guard: rebuild of post-' . $post_id . ' failed: ' . $e->getMessage() );
						}
					}
				}
			}
		}

		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/css; charset=UTF-8' );

		if ( file_exists( $file ) ) {
			header( 'X-Smile-Elementor-CSS: rebuilt' );
			header( 'Content-Length: ' . filesize( $file ) );
			readfile( $file );
		} else {
			// Elementor records a page whose CSS comes out empty as status "empty" and
			// writes no file at all, so this is a normal outcome, not a failure. An
			// empty stylesheet is the honest answer; a themed 404 page is not.
			header( 'X-Smile-Elementor-CSS: empty' );
			echo '/* no generated styles for this post */';
		}

		exit;
	},
	1
);

/**
 * Purge the page cache whenever Elementor throws its generated files away.
 *
 * This is the other half of the same bug. Deleting the files is fine; leaving cached
 * HTML in place that still points at them is not. Purging here means the next visitor
 * gets a fresh render, which regenerates the CSS the ordinary way.
 */
function smile_elcss_purge_page_cache() {
	static $done = false;
	if ( $done ) {
		return;
	}
	$done = true;

	// LiteSpeed Cache plugin.
	do_action( 'litespeed_purge_all' );

	// Server-level LSCache, which the plugin hook does not always reach.
	if ( ! headers_sent() ) {
		@header( 'X-LiteSpeed-Purge: *' );
	}

	// Anything else that is listening.
	do_action( 'smile_purge_page_cache' );
}

add_action( 'elementor/core/files/clear_cache', 'smile_elcss_purge_page_cache', 99 );
add_action( 'elementor/core/files/after_generate_css', 'smile_elcss_purge_page_cache', 99 );

// Belt and braces: whatever Elementor does or does not fire, an upgrade of anything
// is the event that starts this whole sequence.
add_action(
	'upgrader_process_complete',
	function () {
		smile_elcss_purge_page_cache();
	},
	99
);
