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
	}

	/** Wrap WordPress-generated attachment images in a picture element. */
	public static function picture( $html, $id, $size ) {
		if ( is_admin() || false === strpos( $html, '<img ' ) || false !== strpos( $html, '<picture' ) ) {
			return $html;
		}
		$outputs = get_post_meta( (int) $id, Media_Policy::OUTPUT_META, true );
		if ( ! is_array( $outputs ) || empty( $outputs['avif_full'] ) || empty( $outputs['avif_full_width'] ) ) {
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
		return '<picture><source type="image/avif" srcset="' . esc_attr( implode( ', ', $source ) ) . '" sizes="' . esc_attr( $sizes ) . '">' . $html . '</picture>';
	}
}
