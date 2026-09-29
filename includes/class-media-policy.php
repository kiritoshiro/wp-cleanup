<?php
/**
 * Target image policy: one AVIF "full" image plus at most one smaller AVIF.
 *
 * Defaults match the Adventistai ALPS theme upload policy (full <= 1920 px,
 * "alps-small" <= 768 px, flag meta _alps_two_size_upload), so converted
 * legacy attachments behave exactly like new uploads under that theme.
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

	/**
	 * @return array{full_max:int,small_name:string,small_max:int,set_flag:bool}
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
			'set_flag'   => true,
		);
		$out               = wp_parse_args( $input, $defaults );
		$out['full_max']   = max( 320, min( 8192, (int) $out['full_max'] ) );
		$out['small_max']  = max( 64, min( $out['full_max'] - 1, (int) $out['small_max'] ) );
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
