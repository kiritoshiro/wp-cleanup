<?php
/**
 * Serve verified AVIF alternatives while retaining one JPEG for older clients.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Delivery {
	public static function register() {
		add_filter( 'wp_get_attachment_image', array( __CLASS__, 'picture' ), 10, 3 );
		// Images saved in post content are static HTML, so the filter above never sees them.
		// After WordPress adds srcset (priority 12), wrap them the same way.
		add_filter( 'the_content', array( __CLASS__, 'content' ), 20 );
	}

	/**
	 * Give block/classic images in post content the same AVIF + JPEG picture markup.
	 * Images already inside a <picture> are left alone.
	 *
	 * @param string $content Post content HTML.
	 * @return string
	 */
	public static function content( $content ) {
		if ( is_admin() || is_feed() || ! is_string( $content ) || false === stripos( $content, 'wp-image-' ) ) {
			return $content;
		}
		return (string) preg_replace_callback(
			'~<picture\b.*?</picture>|<img\b[^>]*>~is',
			static function ( $m ) {
				$tag = $m[0];
				if ( 0 === stripos( $tag, '<picture' ) || ! preg_match( '/\bwp-image-(\d+)\b/', $tag, $id ) ) {
					return $tag;
				}
				$size = preg_match( '/\bsize-([a-z0-9_-]+)\b/i', $tag, $named ) ? $named[1] : 'full';
				return self::picture( $tag, (int) $id[1], $size );
			},
			$content
		);
	}

	/** Wrap WordPress-generated attachment images in a picture element. */
	public static function picture( $html, $id, $size ) {
		if ( is_admin() || false === strpos( $html, '<img ' ) || false !== strpos( $html, '<picture' ) ) {
			return $html;
		}
		$outputs = get_post_meta( (int) $id, Media_Policy::OUTPUT_META, true );
		if ( ! is_array( $outputs ) || empty( $outputs['jpeg'] ) || empty( $outputs['avif_full'] ) || empty( $outputs['avif_full_width'] ) ) {
			return $html;
		}
		$uploads = wp_upload_dir( null, false );
		$root = wp_normalize_path( $uploads['basedir'] );
		$sizes = '100vw';
		if ( preg_match( '/\\bsizes="([^"]+)"/', $html, $match ) ) {
			$sizes = html_entity_decode( $match[1], ENT_QUOTES, 'UTF-8' );
		} elseif ( preg_match( '/\\bwidth="(\\d+)"/', $html, $match ) ) {
			$sizes = (int) $match[1] . 'px';
		}
		$limit = 0;
		if ( is_array( $size ) ) {
			$limit = isset( $size[0] ) ? (int) $size[0] : 0;
		} elseif ( is_string( $size ) ) {
			$policy = Media_Policy::settings();
			$named = array(
				'thumbnail' => (int) get_option( 'thumbnail_size_w', 150 ),
				'medium' => (int) get_option( 'medium_size_w', 300 ),
				'medium_large' => (int) get_option( 'medium_large_size_w', 768 ),
				'large' => (int) get_option( 'large_size_w', 1024 ),
				$policy['small_name'] => (int) $policy['small_max'],
			);
			$limit = isset( $named[ $size ] ) ? $named[ $size ] : 0;
		}
		if ( $limit > 0 ) {
			$sizes = '(max-width: ' . $limit . 'px) 100vw, ' . $limit . 'px';
		}
		$source = array();
		foreach ( array( 'avif_small', 'avif_full' ) as $key ) {
			$rel = isset( $outputs[ $key ] ) ? wp_normalize_path( (string) $outputs[ $key ] ) : '';
			$width = isset( $outputs[ $key . '_width' ] ) ? (int) $outputs[ $key . '_width' ] : 0;
			if ( ! $rel || ! $width || '/' === $rel[0] || false !== strpos( $rel, ':' ) || preg_match( '#(^|/)\\.\\.(/|$)#', $rel ) || ! is_file( $root . '/' . $rel ) ) {
				continue;
			}
			$url = trailingslashit( $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $rel ) );
			$source[] = esc_url( $url ) . ' ' . $width . 'w';
		}
		if ( ! $source ) {
			return $html;
		}
		// When AVIF is the attachment's main file, the img element must point to JPEG
		// so browsers that ignore the AVIF source have a real fallback.
		if ( 'image/avif' === get_post_mime_type( $id ) ) {
			$jpeg_rel = wp_normalize_path( (string) $outputs['jpeg'] );
			if ( ! $jpeg_rel || '/' === $jpeg_rel[0] || false !== strpos( $jpeg_rel, ':' ) || preg_match( '#(^|/)\.\.(/|$)#', $jpeg_rel ) || ! is_file( $root . '/' . $jpeg_rel ) ) {
				return $html;
			}
			$jpeg_url = esc_url( trailingslashit( $uploads['baseurl'] ) . str_replace( '%2F', '/', rawurlencode( $jpeg_rel ) ) );
			$html = (string) preg_replace( '/\ssrc="[^"]*"/i', ' src="' . $jpeg_url . '"', $html, 1 );
			$html = (string) preg_replace( '/\ssrcset="[^"]*"/i', '', $html );
			$jpeg_width = isset( $outputs['jpeg_width'] ) ? (int) $outputs['jpeg_width'] : 0;
			$jpeg_candidate = $jpeg_url . ( $jpeg_width ? ' ' . $jpeg_width . 'w' : '' );
			$html = (string) preg_replace( '/<img\s/i', '<img srcset="' . esc_attr( $jpeg_candidate ) . '" ', $html, 1 );
		}
		return '<picture><source type="image/avif" srcset="' . esc_attr( implode( ', ', $source ) ) . '" sizes="' . esc_attr( $sizes ) . '">' . $html . '</picture>';
	}
}
