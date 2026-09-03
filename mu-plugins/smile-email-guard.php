<?php
/**
 * Plugin Name: Smile Creative — Email Guard
 * Description: Keeps email addresses out of the page source so harvesters cannot scrape them, without a third-party plugin, an external service or Cloudflare. Restores the real address automatically for anyone with JavaScript, and falls back to a working contact link for anyone without.
 * Version: 1.0.0
 * Author: Smile Creative
 *
 * WHY THIS EXISTS
 * ---------------
 * Address harvesters crawl pages looking for "mailto:" and anything shaped like an
 * email address. Whatever they find gets sold on and mailed forever. Removing the
 * literal address from the HTML is the single cheapest thing that reduces spam
 * arriving in a client's inbox.
 *
 * Note what this does NOT do: it has no effect on spam submitted through a contact
 * form. That is a separate problem with a separate fix. Do not let anyone believe
 * this covers both.
 *
 * THE RULE THIS IS BUILT AROUND
 * -----------------------------
 * An anti-spam measure whose failure mode is "the customer cannot reach you" is
 * worse than the spam it prevents. Seen in the wild on a live site: the address was
 * masked to he***@*****es.com, the script that reveals it was not on the page, and
 * clicking it opened a mail client addressed to a row of asterisks. Fully protected,
 * completely unreachable, and nobody would ever report it.
 *
 * So:
 *   - It decodes on page load, not on click. A visitor sees an ordinary address and
 *     is never asked to understand anything.
 *   - With JavaScript off, the placeholder is a working link to the contact page,
 *     not a masked string. Someone always has a route to you.
 *   - The decoder is inline in the page. It cannot fail to load separately, which is
 *     exactly how the example above broke.
 *
 * HOW STRONG IS IT
 * ----------------
 * Honest answer: it stops bulk harvesters, which are the large majority, and it does
 * not stop a crawler that runs JavaScript. That is also true of Cloudflare's version
 * and of every plugin that does this. It is worth doing because it is free and
 * costs the visitor nothing -- not because it is impassable.
 *
 * USAGE
 * -----
 *   Automatic:  any mailto: link in post content is converted.
 *   Shortcode:  [smile_email address="hello@example.com" text="Email us"]
 *   In a theme: echo smile_email_link( 'hello@example.com' );
 *
 * @package smile-baseline
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Encode a string the way Cloudflare does: one random key byte, then every character
 * XORed with it, the lot as hex.
 *
 * Chosen over base64 deliberately. A base64 address is still recognisably an address
 * to anything that bothers to decode it, and harvesters do. This produces a hex blob
 * with a different value on every page load, so it cannot be pattern-matched or
 * cached and re-identified.
 *
 * @param string $text Plain text.
 * @return string Hex string, first byte is the key.
 */
function smile_email_encode( $text ) {
	$key = wp_rand( 1, 255 );
	$out = sprintf( '%02x', $key );
	$len = strlen( $text );
	for ( $i = 0; $i < $len; $i++ ) {
		$out .= sprintf( '%02x', ord( $text[ $i ] ) ^ $key );
	}
	return $out;
}

/**
 * Where to send someone whose browser will not run the decoder.
 *
 * @return string
 */
function smile_email_fallback_url() {
	$page = get_page_by_path( 'contact' );
	$url  = $page ? get_permalink( $page ) : home_url( '/contact/' );

	/**
	 * Filter the no-JavaScript fallback destination.
	 *
	 * @param string $url Fallback URL.
	 */
	return apply_filters( 'smile_email_fallback_url', $url );
}

/**
 * Build the markup for one protected address.
 *
 * @param string $address The real email address.
 * @param string $text    Optional link text. Defaults to the address itself.
 * @param array  $attrs   Optional extra attributes, e.g. array( 'class' => 'x' ).
 * @return string
 */
function smile_email_link( $address, $text = '', $attrs = array() ) {
	$address = trim( $address );
	if ( ! is_email( $address ) ) {
		// Never invent a link for something that is not an address. Returning the
		// input unchanged is the honest failure -- it is visible and fixable.
		return esc_html( $address );
	}

	$show_address = ( '' === $text );
	$label        = $show_address ? $address : $text;

	$extra = '';
	foreach ( $attrs as $k => $v ) {
		$extra .= sprintf( ' %s="%s"', esc_attr( $k ), esc_attr( $v ) );
	}

	return sprintf(
		'<span class="smile-eml" data-eml="%1$s" data-lbl="%2$s"%3$s><a href="%4$s" class="smile-eml-fb">%5$s</a></span>',
		esc_attr( smile_email_encode( $address ) ),
		$show_address ? '' : esc_attr( smile_email_encode( $text ) ),
		$extra,
		esc_url( smile_email_fallback_url() ),
		esc_html( $show_address ? __( 'Email us', 'smile' ) : $label )
	);
}

/**
 * Shortcode wrapper.
 *
 * @param array $atts Shortcode attributes.
 * @return string
 */
function smile_email_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'address' => get_option( 'admin_email' ),
			'text'    => '',
			'class'   => '',
		),
		$atts,
		'smile_email'
	);

	$extra = ( '' !== $atts['class'] ) ? array( 'class' => 'smile-eml ' . $atts['class'] ) : array();

	return smile_email_link( $atts['address'], $atts['text'], $extra );
}
add_shortcode( 'smile_email', 'smile_email_shortcode' );

/**
 * Convert any mailto: link already sitting in post content.
 *
 * Runs late so page builders have finished rendering. Only touches anchors whose
 * href is a mailto: -- it does not go hunting for bare text that looks like an
 * address, because that would rewrite things like a code sample or an address a
 * client deliberately wrote out in prose.
 *
 * @param string $html Rendered content.
 * @return string
 */
function smile_email_filter_content( $html ) {
	if ( is_admin() || is_feed() || false === strpos( $html, 'mailto:' ) ) {
		return $html;
	}

	return preg_replace_callback(
		'#<a\b([^>]*?)href=(["\'])\s*mailto:([^"\'?]+?)(\?[^"\']*)?\2([^>]*)>(.*?)</a>#is',
		function ( $m ) {
			$address = trim( html_entity_decode( $m[3], ENT_QUOTES, 'UTF-8' ) );
			if ( ! is_email( $address ) ) {
				return $m[0];
			}

			$inner = trim( wp_strip_all_tags( $m[6] ) );
			// If the link text is the address itself, let the decoder write the real
			// one back in. Otherwise keep the wording the client chose.
			$decoded_inner = html_entity_decode( $inner, ENT_QUOTES, 'UTF-8' );
			$text          = ( $decoded_inner === $address || '' === $inner ) ? '' : $decoded_inner;

			// Carry any classes across so styling survives.
			$attrs = array();
			if ( preg_match( '/class=(["\'])(.*?)\1/i', $m[1] . $m[5], $c ) ) {
				$attrs['class'] = 'smile-eml ' . $c[2];
			}

			return smile_email_link( $address, $text, $attrs );
		},
		$html
	);
}
add_filter( 'the_content', 'smile_email_filter_content', 999 );
add_filter( 'widget_text', 'smile_email_filter_content', 999 );

/**
 * Take email addresses out of a plain-text string.
 *
 * @param string $text Plain text.
 * @return string
 */
function smile_email_redact( $text ) {
	if ( ! is_string( $text ) || false === strpos( $text, '@' ) ) {
		return $text;
	}
	$out = preg_replace( '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '', $text );
	// Tidy the gap the removal leaves behind.
	return trim( preg_replace( '/\s{2,}/', ' ', (string) $out ), " \t\n\r\0\x0B,.;:-" );
}
add_filter( 'get_the_excerpt', 'smile_email_redact', 999 );
add_filter( 'wp_trim_excerpt', 'smile_email_redact', 999 );
add_filter( 'wpseo_metadesc', 'smile_email_redact', 999 );           // Yoast.
add_filter( 'wpseo_opengraph_desc', 'smile_email_redact', 999 );     // Yoast.
add_filter( 'rank_math/frontend/description', 'smile_email_redact', 999 );

/**
 * Strip addresses out of the meta description tags, whoever wrote them.
 *
 * Obfuscating the visible link is only half the job. An auto-generated description
 * is built from the page's own text, so the address that was carefully removed from
 * the body gets published a second time in the <head>, in plain text, where it is
 * read just as easily. Found by grepping the rendered page for an address pattern
 * after the visible one was protected -- the page looked clean and was not.
 *
 * The filters above catch WordPress's excerpt and the two big SEO plugins. This
 * catches everything else, including a theme that builds its own description from
 * post_content, which is what ours does.
 *
 * ⚠ It deliberately touches ONLY the description meta tags. The business email in
 * LocalBusiness JSON-LD is there on purpose -- Google reads it, and it is how the
 * contact details reach the knowledge panel. Blanket-scrubbing the head would trade
 * a real SEO asset for a marginal gain against harvesters. That is the client's
 * call, not a default.
 */
function smile_email_head_open() {
	if ( is_admin() || is_feed() ) {
		return;
	}
	ob_start();
}
add_action( 'wp_head', 'smile_email_head_open', -PHP_INT_MAX );

/**
 * Close the head buffer and clean the description tags.
 */
function smile_email_head_close() {
	if ( is_admin() || is_feed() || ! ob_get_level() ) {
		return;
	}
	$head = ob_get_clean();
	if ( false === strpos( $head, '@' ) ) {
		echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		return;
	}

	$head = preg_replace_callback(
		'#<meta\s[^>]*(?:name=(["\'])(?:description|twitter:description)\1|property=(["\'])og:description\2)[^>]*>#i',
		function ( $m ) {
			return preg_replace_callback(
				'#content=(["\'])(.*?)\1#is',
				function ( $c ) {
					return 'content=' . $c[1] . esc_attr( smile_email_redact( $c[2] ) ) . $c[1];
				},
				$m[0]
			);
		},
		$head
	);

	echo $head; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
}
add_action( 'wp_head', 'smile_email_head_close', PHP_INT_MAX );

/**
 * The decoder.
 *
 * Printed inline in the footer on purpose. A separate .js file is one more request
 * that can 404, be blocked, be purged by a cache or be deferred into oblivion -- and
 * when it fails the visitor is left holding an address they cannot use. Inline, it
 * either arrives with the page or there is no page.
 */
function smile_email_decoder() {
	if ( is_admin() ) {
		return;
	}
	?>
<script>
(function(){
	function d(h){
		var k=parseInt(h.substr(0,2),16),o='';
		for(var i=2;i<h.length;i+=2){o+=String.fromCharCode(parseInt(h.substr(i,2),16)^k);}
		return o;
	}
	function go(){
		var n=document.querySelectorAll('span.smile-eml[data-eml]');
		for(var i=0;i<n.length;i++){
			var s=n[i],a;
			try{a=d(s.getAttribute('data-eml'));}catch(e){continue;}
			if(a.indexOf('@')<0){continue;}          /* leave the fallback in place */
			var lbl=s.getAttribute('data-lbl');
			lbl=lbl?d(lbl):a;
			var link=document.createElement('a');
			link.href='mailto:'+a;
			link.textContent=lbl;
			var fb=s.querySelector('a.smile-eml-fb');
			if(fb&&fb.className){link.className=fb.className.replace('smile-eml-fb','').trim();}
			s.innerHTML='';
			s.appendChild(link);
			s.removeAttribute('data-eml');
			s.removeAttribute('data-lbl');
		}
	}
	if(document.readyState==='loading'){document.addEventListener('DOMContentLoaded',go);}else{go();}
})();
</script>
	<?php
}
add_action( 'wp_footer', 'smile_email_decoder', 999 );
