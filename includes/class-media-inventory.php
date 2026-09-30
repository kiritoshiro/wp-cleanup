<?php
/**
 * Every file on disk that belongs to an image attachment.
 *
 * Besides what the metadata lists (attached file, -scaled source, sub-sizes,
 * edit backups), it finds strays: size files from themes that are no longer
 * active, and ".jpg.webp"-style sidecars written by optimizer plugins.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Inventory {

	/** @var array<string,string[]> Directory listing cache for this request. */
	private static $listings = array();

	/**
	 * @param int $id Attachment id.
	 * @return array{
	 *   dir:string, rel_dir:string, base:string, meta:array,
	 *   files:array<string,array{role:string,size:string,width:int,height:int,bytes:int}>,
	 *   bytes:int, compliant:bool, edited:bool
	 * }|null Null when the attached file is missing.
	 */
	public static function attachment( $id ) {
		$attached = get_attached_file( $id, true );
		if ( ! $attached || ! is_file( $attached ) ) {
			return null;
		}
		$attached = wp_normalize_path( $attached );
		$dir      = dirname( $attached );
		$uploads  = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		$rel_dir  = ltrim( substr( $dir, strlen( $uploads ) ), '/' );
		$meta     = wp_get_attachment_metadata( $id, true );
		$meta     = is_array( $meta ) ? $meta : array();
		$files    = array();

		$add = static function ( $name, $role, $size = '', $width = 0, $height = 0 ) use ( &$files, $dir ) {
			$name = wp_basename( (string) $name );
			if ( '' === $name || isset( $files[ $name ] ) || ! is_file( $dir . '/' . $name ) ) {
				return;
			}
			$files[ $name ] = array(
				'role'   => $role,
				'size'   => $size,
				'width'  => (int) $width,
				'height' => (int) $height,
				'bytes'  => (int) filesize( $dir . '/' . $name ),
			);
		};

		$add( $attached, 'attached', 'full', isset( $meta['width'] ) ? $meta['width'] : 0, isset( $meta['height'] ) ? $meta['height'] : 0 );
		if ( ! empty( $meta['original_image'] ) ) {
			$add( $meta['original_image'], 'original', 'original' );
		}
		foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $name => $size ) {
			if ( ! empty( $size['file'] ) ) {
				$add( $size['file'], 'size', (string) $name, isset( $size['width'] ) ? $size['width'] : 0, isset( $size['height'] ) ? $size['height'] : 0 );
			}
		}
		$outputs = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
		if ( is_array( $outputs ) ) {
			foreach ( array( 'avif_full', 'avif_small' ) as $variant ) {
				if ( ! empty( $outputs[ $variant ] ) ) {
					$add( $outputs[ $variant ], $variant );
				}
			}
		}
		$backups = get_post_meta( $id, '_wp_attachment_backup_sizes', true );
		foreach ( (array) $backups as $name => $size ) {
			if ( is_array( $size ) && ! empty( $size['file'] ) ) {
				$add( $size['file'], 'backup', (string) $name, isset( $size['width'] ) ? $size['width'] : 0, isset( $size['height'] ) ? $size['height'] : 0 );
			}
		}

		// Strays: "<base>[-e123][-scaled][-WxH].<ext>" and "<that>.<ext>.webp|avif" sidecars.
		$bases = array( self::base_name( $attached ) );
		if ( ! empty( $meta['original_image'] ) ) {
			$bases[] = self::base_name( $meta['original_image'] );
		}
		$bases = array_unique( $bases );
		$other = null;
		foreach ( self::listing( $dir ) as $name ) {
			if ( isset( $files[ $name ] ) ) {
				continue;
			}
			foreach ( $bases as $base ) {
				if ( ! preg_match( '/^' . preg_quote( $base, '/' ) . '(?:-e\d{10,14})?(?:-scaled)?(?:-(\d+)x(\d+))?\.(?:jpe?g|png|gif|webp|avif)(?:\.(?:webp|avif))?$/i', $name, $m ) ) {
					continue;
				}
				// A file owned by any other attachment (its main file, sizes or backups) is never a stray.
				if ( null === $other ) {
					$other = self::files_owned_by_others( $rel_dir, $bases, $id );
				}
				if ( isset( $other[ strtolower( $name ) ] ) ) {
					continue 2;
				}
				$add( $name, 'stray', '', isset( $m[1] ) ? (int) $m[1] : 0, isset( $m[2] ) ? (int) $m[2] : 0 );
				continue 2;
			}
		}

		$bytes = 0;
		foreach ( $files as $file ) {
			$bytes += $file['bytes'];
		}

		return array(
			'dir'       => $dir,
			'rel_dir'   => $rel_dir,
			'base'      => self::base_name( ! empty( $meta['original_image'] ) ? $meta['original_image'] : $attached ),
			'meta'      => $meta,
			'files'     => $files,
			'bytes'     => $bytes,
			'edited'    => ! empty( $backups ),
			'compliant' => self::is_compliant( $id, $meta, $files ),
		);
	}

	/**
	 * Already in the target shape: one JPEG and optional recorded AVIF alternatives.
	 *
	 * @param int   $id    Attachment id.
	 * @param array $meta  Metadata.
	 * @param array $files Files.
	 */
	private static function is_compliant( $id, array $meta, array $files ) {
		$s = Media_Policy::settings();
		$outputs = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
		if ( is_array( $outputs ) && ! empty( $outputs['jpeg'] ) ) {
			if ( 'image/jpeg' !== get_post_mime_type( $id ) || ! empty( $meta['original_image'] ) || ! empty( $meta['sizes'] ) || ( ! isset( $outputs['policy'] ) || ! is_array( $outputs['policy'] ) ) ) {
				return false;
			}
			if ( $outputs['policy'] != $s || (string) $outputs['jpeg'] !== (string) $meta['file'] ) { // phpcs:ignore -- compare policy arrays.
				return false;
			}
			if ( ! isset( $files[ wp_basename( $outputs['jpeg'] ) ] ) || max( (int) $meta['width'], (int) $meta['height'] ) > $s['jpeg_max'] ) {
				return false;
			}
			foreach ( array( 'avif_full', 'avif_small' ) as $variant ) {
				if ( ! empty( $outputs[ $variant ] ) && ! isset( $files[ wp_basename( $outputs[ $variant ] ) ] ) ) {
					return false;
				}
			}
			foreach ( $files as $file ) {
				if ( ! in_array( $file['role'], array( 'attached', 'avif_full', 'avif_small' ), true ) ) {
					return false;
				}
			}
			return ! $s['set_flag'] || get_post_meta( $id, Media_Policy::ALPS_FLAG, true );
		}
		return false;
	}

	/**
	 * "photo" for photo.jpg, photo-scaled.jpg, photo-e1712345678901.jpg.
	 *
	 * @param string $file File name or path.
	 */
	public static function base_name( $file ) {
		$name = pathinfo( wp_basename( (string) $file ), PATHINFO_FILENAME );
		$name = preg_replace( '/-(?:scaled|fallback)$/', '', $name );
		return preg_replace( '/-e\d{10,14}$/', '', $name );
	}

	/**
	 * @param string $dir Directory.
	 * @return string[] File names.
	 */
	private static function listing( $dir ) {
		if ( ! isset( self::$listings[ $dir ] ) ) {
			$entries                = @scandir( $dir ); // phpcs:ignore
			self::$listings[ $dir ] = array_values(
				array_filter(
					(array) $entries,
					static function ( $e ) use ( $dir ) {
						return '.' !== $e[0] && is_file( $dir . '/' . $e );
					}
				)
			);
		}
		return self::$listings[ $dir ];
	}

	/**
	 * Forget cached listings (after files moved).
	 */
	public static function flush() {
		self::$listings = array();
	}

	/**
	 * Lower-case names of files in this folder that belong to other attachments.
	 *
	 * Every other attachment's main file counts; for those sharing a base name
	 * (photo.jpg next to photo.png) their sizes, originals and backups count too.
	 *
	 * @param string   $rel_dir Folder relative to uploads.
	 * @param string[] $bases   Base names of the attachment being inspected.
	 * @param int      $self_id That attachment.
	 * @return array<string,true>
	 */
	private static function files_owned_by_others( $rel_dir, array $bases, $self_id ) {
		global $wpdb;
		$like = '' === $rel_dir ? '%' : $wpdb->esc_like( $rel_dir . '/' ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value LIKE %s AND post_id <> %d", $like, $self_id ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			if ( dirname( $row->meta_value ) !== ( '' === $rel_dir ? '.' : $rel_dir ) ) {
				continue;
			}
			$out[ strtolower( wp_basename( $row->meta_value ) ) ] = true;
			if ( ! in_array( self::base_name( $row->meta_value ), $bases, true ) ) {
				continue;
			}
			$meta  = wp_get_attachment_metadata( (int) $row->post_id, true );
			$names = array();
			if ( ! empty( $meta['original_image'] ) ) {
				$names[] = $meta['original_image'];
			}
			foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $size ) {
				$names[] = isset( $size['file'] ) ? $size['file'] : '';
			}
			foreach ( (array) get_post_meta( (int) $row->post_id, '_wp_attachment_backup_sizes', true ) as $size ) {
				$names[] = is_array( $size ) && isset( $size['file'] ) ? $size['file'] : '';
			}
			$outputs = get_post_meta( (int) $row->post_id, Media_Policy::OUTPUT_META, true );
			if ( is_array( $outputs ) ) {
				$names[] = isset( $outputs['avif_full'] ) ? $outputs['avif_full'] : '';
				$names[] = isset( $outputs['avif_small'] ) ? $outputs['avif_small'] : '';
			}
			foreach ( array_filter( $names ) as $name ) {
				$out[ strtolower( wp_basename( $name ) ) ] = true;
			}
		}
		return $out;
	}

	/**
	 * Summary over all image attachments, in pages.
	 *
	 * @param int $offset Offset.
	 * @param int $limit  Page size.
	 * @return array{ids:int[],total:int}
	 */
	public static function image_ids( $offset = 0, $limit = 200 ) {
		global $wpdb;
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%'" );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type LIKE 'image/%%' ORDER BY ID LIMIT %d OFFSET %d", $limit, $offset ) );
		return array(
			'ids'   => array_map( 'intval', (array) $ids ),
			'total' => $total,
		);
	}
}
