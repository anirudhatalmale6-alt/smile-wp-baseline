<?php
/**
 * Plugin Name: Smile Creative — sitemap status fix
 * Description: Serves WordPress core sitemaps with a 200 status on brochure sites that have no published blog posts.
 * Version:     1.0.0
 * Author:      Smile Creative
 *
 * WHY THIS EXISTS
 *
 * On a site with no published posts, /wp-sitemap.xml renders perfectly valid XML
 * but WordPress sends it with a 404 status. Search engines discard a sitemap that
 * answers 404, so robots.txt ends up advertising a sitemap that is never read.
 *
 * The cause is two pieces of core behaviour meeting:
 *
 *   1. WP_Query::parse_query() refuses to set is_home on a sitemap request
 *      (class-wp-query.php — `... || $this->is_sitemap ) ) { $this->is_home = true; }`).
 *
 *   2. WP::handle_404() only spares a post-less query from a 404 when it is one of
 *      is_author / is_tag / is_category / is_tax / is_post_type_archive / is_home /
 *      is_search / is_feed. Sitemaps are not on that list.
 *
 * So the sitemap query runs, finds no posts, matches none of the exemptions, and is
 * marked 404 during wp() — long before WP_Sitemaps::render_sitemaps() prints the XML
 * on template_redirect. Core never re-asserts a 200. Publish a single blog post and
 * the query is no longer empty, so the problem disappears; that is why it is only
 * ever seen on brochure sites.
 *
 * The fix short-circuits handle_404() for sitemap requests only, and sets the status
 * explicitly. Core's own "no URLs in this sitemap" 404 (class-wp-sitemaps.php) runs
 * later on template_redirect and still wins, so a genuinely empty sub-sitemap keeps
 * answering 404 as it should.
 *
 * @package smile-creative
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'pre_handle_404',
	function ( $preempt, $wp_query ) {
		if ( false !== $preempt ) {
			return $preempt;
		}

		// is_sitemap is only true for core sitemap requests, so nothing else is touched.
		if ( empty( $wp_query->is_sitemap ) ) {
			return $preempt;
		}

		status_header( 200 );

		// Tell handle_404() to stop here rather than fall through to its 404 branch.
		return true;
	},
	10,
	2
);
