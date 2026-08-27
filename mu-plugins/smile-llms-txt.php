<?php
/**
 * Plugin Name: Smile Creative — llms.txt
 * Description: Serves /llms.txt, the plain-language summary AI assistants read when someone asks about the business.
 * Version:     1.0.0
 * Author:      Smile Creative
 *
 * WHY A PLUGIN AND NOT A FLAT FILE
 *
 * A flat file has to sit in the document root, which differs from site to site
 * (this one runs WordPress in its own /wp/ directory, with the site served from
 * the domain root). A plugin does not care where the root is, drops into the one
 * folder every site in the estate has, and travels through a migration.
 *
 * The body is filterable, so a site can override it without touching this file:
 *
 *     add_filter( 'smile_llms_txt', function () { return "..."; } );
 *
 * @package smile-creative
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Serve /llms.txt before WordPress decides the request is a 404.
 */
add_action(
	'parse_request',
	function () {
		$path = (string) wp_parse_url( isset( $_SERVER['REQUEST_URI'] ) ? esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '', PHP_URL_PATH );

		if ( 'llms.txt' !== trim( $path, '/' ) ) {
			return;
		}

		/**
		 * The body of /llms.txt.
		 *
		 * @param string $body Markdown-flavoured plain text.
		 */
		$body = apply_filters( 'smile_llms_txt', smile_llms_txt_default() );

		status_header( 200 );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file, not HTML.
		exit;
	}
);

/**
 * Default body, assembled from what the site itself says.
 *
 * Everything here is read from the site rather than typed in a second time, so
 * it cannot drift out of step with the pages the way a hand-written file would.
 *
 * @return string
 */
function smile_llms_txt_default() {
	// get_bloginfo() hands back display-escaped text: an ampersand in the tagline
	// arrives as "&amp;". Harmless in HTML, wrong in a plain-text file.
	$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	$desc = wp_specialchars_decode( get_bloginfo( 'description' ), ENT_QUOTES );
	$home = home_url( '/' );

	$out  = '# ' . $name . "\n\n";
	$out .= '> ' . $desc . "\n\n";

	if ( function_exists( 'gif_opt' ) ) {
		$intro = gif_opt( 'seo_description' );
		if ( $intro ) {
			$out .= $intro . "\n\n";
		}
	}

	// Pages.
	$out  .= "## Pages\n\n";
	$pages = get_pages(
		array(
			'sort_column' => 'menu_order,post_title',
			'post_status' => 'publish',
		)
	);
	foreach ( $pages as $page ) {
		$out .= '- [' . wp_specialchars_decode( $page->post_title, ENT_QUOTES ) . '](' . get_permalink( $page ) . ")\n";
	}
	$out .= "\n";

	// Services, with the same one-line summary the cards show.
	$services = get_posts(
		array(
			'post_type'      => 'gif_service',
			'posts_per_page' => -1,
			'post_status'    => 'publish',
			'orderby'        => 'menu_order title',
			'order'          => 'ASC',
		)
	);
	if ( $services ) {
		$out .= "## Services\n\n";
		foreach ( $services as $svc ) {
			$blurb = get_the_excerpt( $svc );
			$blurb = trim( wp_specialchars_decode( wp_strip_all_tags( $blurb ), ENT_QUOTES ) );
			$out  .= '- [' . wp_specialchars_decode( $svc->post_title, ENT_QUOTES ) . '](' . get_permalink( $svc ) . ')';
			if ( $blurb ) {
				$out .= ': ' . $blurb;
			}
			$out .= "\n";
		}
		$out .= "\n";
	}

	// Contact block, straight off the Customizer values the pages use.
	if ( function_exists( 'gif_opt' ) ) {
		$out  .= "## Contact\n\n";
		$address = array_filter(
			array(
				gif_opt( 'address_line1' ),
				gif_opt( 'address_line2' ),
				gif_opt( 'address_line3' ),
			)
		);
		$bits    = array(
			'Telephone' => gif_opt( 'phone_main' ),
			'Mobile'    => gif_opt( 'phone_mobile' ),
			'Email'     => gif_opt( 'email' ),
			'Address'   => implode( ', ', $address ),
			'Turnaround' => gif_opt( 'turnaround' ),
		);
		foreach ( $bits as $label => $value ) {
			if ( $value ) {
				$out .= '- ' . $label . ': ' . $value . "\n";
			}
		}
		$out .= '- Enquiry form: ' . $home . "contact/\n\n";
	}

	$out .= "## Notes\n\n";
	$out .= "- All work is produced in-house at the Portglenone studio.\n";
	$out .= "- Trade enquiries from photographers and artists are welcome.\n";
	$out .= '- Sitemap: ' . $home . "wp-sitemap.xml\n";

	return $out;
}
