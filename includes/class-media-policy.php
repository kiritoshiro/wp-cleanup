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
	 * AVIF quality when none is saved: WordPress' own default (WP_Image_Editor
	 * uses 82 for AVIF), so images converted before the setting existed match.
	 */
	const DEFAULT_AVIF_QUALITY = 82;

	/**
	 * @return array{full_max:int,small_name:string,small_max:int,jpeg_max:int,jpeg_quality:int,avif_quality:int,jpeg_fallback:bool,set_flag:bool}
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
			'avif_quality' => self::DEFAULT_AVIF_QUALITY,
			'jpeg_fallback' => true,
			'set_flag'   => true,
		);
		$out               = wp_parse_args( $input, $defaults );
		$out['full_max']   = max( 320, min( 8192, (int) $out['full_max'] ) );
		$out['small_max']  = max( 64, min( $out['full_max'] - 1, (int) $out['small_max'] ) );
		$out['jpeg_max'] = max( 320, min( 8192, (int) $out['jpeg_max'] ) );
		$out['jpeg_quality'] = max( 40, min( 95, (int) $out['jpeg_quality'] ) );
		$out['avif_quality'] = max( 20, min( 95, (int) $out['avif_quality'] ) );
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
	 * Whether the policy matches the ALPS theme's own upload policy, which the
	 * marker promises: full <= 1920 px and an optional "alps-small" <= 768 px.
	 * ALPS marks single-size uploads too ("small sources may need only one display
	 * file"), and the marker keeps thumbnail regeneration from re-creating old sizes.
	 *
	 * @param array|null $s Settings.
	 */
	public static function alps_compatible( $s = null ) {
		$s = $s ? $s : self::settings();
		return 'alps-small' === $s['small_name'] && $s['full_max'] <= 1920 && $s['small_max'] <= 768;
	}

	/**
	 * Whether converted images should carry the ALPS marker.
	 *
	 * @param array|null $s Settings.
	 */
	public static function wants_flag( $s = null ) {
		$s = $s ? $s : self::settings();
		return $s['set_flag'] && self::alps_compatible( $s );
	}

	/**
	 * Whether the active theme runs the ALPS two-size image policy.
	 *
	 * @return array{active:bool,label:string}
	 */
	public static function alps_theme() {
		$theme  = wp_get_theme();
		$active = class_exists( 'App\\UploadImages' ) || has_filter( 'image_downsize', array( 'App\\UploadImages', 'downsize' ) );
		$label  = $theme->exists() ? $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) : '';
		return array(
			'active' => (bool) $active,
			'label'  => trim( $label ),
			// ALPS 3.29+ makes the full AVIF, small AVIF and JPEG fallback itself and records them in OUTPUT_META.
			'converts_uploads' => (bool) $active && method_exists( 'App\\UploadImages', 'fallback' ),
		);
	}

	/**
	 * Policy fields that change the generated files. The marker is a database
	 * flag only, so toggling it must never cause images to be re-encoded.
	 *
	 * @param array $s Settings.
	 * @return array
	 */
	public static function encoding_policy( array $s ) {
		unset( $s['set_flag'] );
		// Policies recorded before AVIF quality was a setting used WordPress' default.
		$s['avif_quality'] = isset( $s['avif_quality'] ) ? (int) $s['avif_quality'] : self::DEFAULT_AVIF_QUALITY;
		return $s;
	}

	/**
	 * Run $callback with WordPress' AVIF quality set to $quality. WordPress
	 * resets the quality whenever an image is converted to another format,
	 * so a set_quality() call before saving would be overwritten; the
	 * wp_editor_set_quality filter is applied at that reset.
	 *
	 * @param int      $quality  AVIF quality.
	 * @param callable $callback Work that creates AVIF files.
	 * @return mixed The callback's result.
	 */
	public static function with_avif_quality( $quality, callable $callback ) {
		$filter = static function ( $value, $mime_type = '' ) use ( $quality ) {
			return 'image/avif' === $mime_type ? (int) $quality : $value;
		};
		add_filter( 'wp_editor_set_quality', $filter, 1000, 2 );
		try {
			return $callback();
		} finally {
			remove_filter( 'wp_editor_set_quality', $filter, 1000 );
		}
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
