<?php
/**
 * Target image policy: a full and optional small AVIF, plus an optional JPEG.
 *
 * Defaults use a 1920 px JPEG and full AVIF, plus an optional 768 px AVIF.
 * The ALPS flag remains available for theme compatibility.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Policy {

	const OPTION = 'wpcu_media_settings';

	/** Source formats that can be converted. GIF/WebP are excluded: they may be animated. */
	const SOURCE_MIMES = array( 'image/jpeg', 'image/png', 'image/avif' );

	/** Meta flag the ALPS theme uses for two-size attachments. */
	const ALPS_FLAG = '_alps_two_size_upload';

	/** Generated JPEG and AVIF paths plus the policy used to make them. */
	const OUTPUT_META = '_wpcu_image_outputs';

	/**
	 * @return array{full_max:int,small_name:string,small_max:int,jpeg_max:int,jpeg_quality:int,jpeg_fallback:bool,set_flag:bool}
	 */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );
		return self::sanitize( is_array( $saved ) ? $saved : array() );
	}

	/**
	 * @param array $input Raw values.
	 * @return array Sanitized settings.
	 */
	public static function sanitize( array $input ) {
		$defaults = array(
			'full_max'   => 1920,
			'small_name' => 'alps-small',
			'small_max'  => 768,
			'jpeg_max'   => 1920,
			'jpeg_quality' => 82,
			'jpeg_fallback' => true,
			'set_flag'   => true,
		);
		$out               = wp_parse_args( $input, $defaults );
		$out['full_max']   = max( 320, min( 8192, (int) $out['full_max'] ) );
		$out['small_max']  = max( 64, min( $out['full_max'] - 1, (int) $out['small_max'] ) );
		$out['jpeg_max'] = max( 320, min( 8192, (int) $out['jpeg_max'] ) );
		$out['jpeg_quality'] = max( 40, min( 95, (int) $out['jpeg_quality'] ) );
		$out['jpeg_fallback'] = (bool) $out['jpeg_fallback'];
		$out['small_name'] = sanitize_key( $out['small_name'] );
		if ( '' === $out['small_name'] || 'full' === $out['small_name'] ) {
			$out['small_name'] = $defaults['small_name'];
		}
		$out['set_flag'] = (bool) $out['set_flag'];
		return $out;
	}

	/**
	 * @param array $settings Settings.
	 */
	public static function save( array $settings ) {
		update_option( self::OPTION, self::sanitize( $settings ), false );
	}

	/**
	 * Whether this server's image editor can write AVIF.
	 */
	public static function avif_supported() {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			require_once ABSPATH . WPINC . '/media.php';
		}
		return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) );
	}

	/** Whether the image editor can write the required JPEG fallback. */
	public static function jpeg_supported() {
		if ( ! function_exists( 'wp_image_editor_supports' ) ) {
			require_once ABSPATH . WPINC . '/media.php';
		}
		return (bool) wp_image_editor_supports( array( 'mime_type' => 'image/jpeg' ) );
	}

	/**
	 * Why an attachment is never touched, or null when it may be converted.
	 *
	 * @param int $id Attachment id.
	 * @return string|null Reason.
	 */
	public static function skip_reason( $id ) {
		$mime = get_post_mime_type( $id );
		if ( ! in_array( $mime, self::SOURCE_MIMES, true ) ) {
			/* translators: %s: MIME type */
			return sprintf( __( 'Format %s is not converted (GIF/WebP may be animated).', 'wp-cleanup' ), $mime ? $mime : '?' );
		}
		$context = get_post_meta( $id, '_wp_attachment_context', true );
		if ( in_array( $context, array( 'site-icon', 'custom-header', 'custom-background' ), true ) ) {
			/* translators: %s: context */
			return sprintf( __( 'Used as %s; WordPress manages its sizes.', 'wp-cleanup' ), $context );
		}
		if ( get_post_meta( $id, '_wp_attachment_is_custom_header', true ) || get_post_meta( $id, '_wp_attachment_is_custom_background', true ) ) {
			return __( 'Used as a custom header or background.', 'wp-cleanup' );
		}
		$file = get_attached_file( $id, true );
		if ( ! $file || ! is_file( $file ) ) {
			return __( 'The file is not on this server (missing or offloaded).', 'wp-cleanup' );
		}
		return null;
	}
}
