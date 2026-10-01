<?php
/**
 * Finds saved image data that no longer matches the files on disk, and repairs it.
 *
 * Every library check runs this. It only reads and compares; repairs change
 * WordPress data (paths, MIME type, dimensions, sizes, the ALPS marker), never
 * image files, and write column-level before-images so each repair can be
 * restored from the Backups tab.
 *
 * Problems it knows about include mistakes made by earlier WP Cleanup versions:
 * absolute file paths stored on Windows (0.2.0), and the ALPS marker set for a
 * policy that does not match ALPS or left behind after it was switched off.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Integrity {

	/**
	 * Check one attachment.
	 *
	 * @param int $id Attachment id.
	 * @return array{issues:array<int,array{code:string,message:string,fixable:bool}>,fix:array}
	 *   "fix" is the repaired state: path, mime, meta, outputs, flag (null = unchanged).
	 */
	public static function check( $id ) {
		$id      = (int) $id;
		$issues  = array();
		$fix     = array( 'path' => null, 'mime' => null, 'meta' => null, 'outputs' => null, 'flag' => null );
		$root    = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		$raw     = (string) get_post_meta( $id, '_wp_attached_file', true );
		$rel     = $raw;
		$add     = static function ( $code, $message, $fixable = true ) use ( &$issues ) {
			$issues[] = array( 'code' => $code, 'message' => $message, 'fixable' => $fixable );
		};

		if ( '' === $raw ) {
			return array( 'issues' => array(), 'fix' => $fix );
		}

		// 1. Stored path must be uploads-relative with forward slashes.
		$norm = wp_normalize_path( $raw );
		if ( $norm !== $raw || '/' === $norm[0] || preg_match( '/^[A-Za-z]:\//', $norm ) ) {
			$candidate = 0 === strpos( $norm, $root . '/' ) ? substr( $norm, strlen( $root ) + 1 ) : ( preg_match( '/^[A-Za-z]:\/|^\//', $norm ) ? '' : ltrim( $norm, '/' ) );
			if ( '' !== $candidate && is_file( $root . '/' . $candidate ) ) {
				$rel         = $candidate;
				$fix['path'] = $candidate;
				/* translators: 1: stored path, 2: corrected path */
				$add( 'path', sprintf( __( 'The file path is stored as an absolute or Windows path (%1$s); it should be %2$s.', 'wp-cleanup' ), $raw, $candidate ) );
			} else {
				/* translators: %s: stored path */
				$add( 'path', sprintf( __( 'The stored file path %s points outside uploads or to a missing file.', 'wp-cleanup' ), $raw ), false );
				return array( 'issues' => $issues, 'fix' => $fix );
			}
		}
		$file = $root . '/' . $rel;
		if ( ! is_file( $file ) ) {
			/* translators: %s: path */
			$add( 'missing', sprintf( __( 'The main file %s is missing. Restore it from the Backups tab or upload it again.', 'wp-cleanup' ), $rel ), false );
			return array( 'issues' => $issues, 'fix' => $fix );
		}
		$dir    = dirname( $file );
		$actual = Media_Files::describe( $file );

		// 2. MIME type must match the file's real format.
		$mime = (string) get_post_mime_type( $id );
		if ( $actual['mime'] && $actual['mime'] !== $mime ) {
			$fix['mime'] = $actual['mime'];
			/* translators: 1: saved type, 2: real type */
			$add( 'mime', sprintf( __( 'Saved as %1$s, but the file is %2$s.', 'wp-cleanup' ), $mime ? $mime : '?', $actual['mime'] ) );
		}

		// 3. Metadata must describe the main file and only existing sizes.
		$meta    = wp_get_attachment_metadata( $id, true );
		$meta    = is_array( $meta ) ? $meta : array();
		$changed = false;
		if ( isset( $meta['file'] ) && (string) $meta['file'] !== $rel ) {
			/* translators: 1: saved path, 2: real path */
			$add( 'meta_file', sprintf( __( 'Image metadata names %1$s instead of the main file %2$s.', 'wp-cleanup' ), $meta['file'], $rel ) );
			$meta['file'] = $rel;
			$changed      = true;
		}
		if ( $actual['width'] && ( (int) ( isset( $meta['width'] ) ? $meta['width'] : 0 ) !== $actual['width'] || (int) ( isset( $meta['height'] ) ? $meta['height'] : 0 ) !== $actual['height'] ) ) {
			/* translators: 1: saved dimensions, 2: real dimensions */
			$add( 'dims', sprintf( __( 'Saved dimensions are %1$s, but the main file is %2$s.', 'wp-cleanup' ), self::dims( isset( $meta['width'] ) ? $meta['width'] : 0, isset( $meta['height'] ) ? $meta['height'] : 0 ), self::dims( $actual['width'], $actual['height'] ) ) );
			$meta['width']  = $actual['width'];
			$meta['height'] = $actual['height'];
			$changed        = true;
		}
		if ( isset( $meta['filesize'] ) && (int) $meta['filesize'] !== $actual['bytes'] ) {
			/* translators: 1: saved size, 2: real size */
			$add( 'filesize', sprintf( __( 'Saved file size is %1$s, but the main file is %2$s.', 'wp-cleanup' ), size_format( (int) $meta['filesize'], 1 ), size_format( $actual['bytes'], 1 ) ) );
			$meta['filesize'] = $actual['bytes'];
			$changed          = true;
		}
		foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $name => $size ) {
			$sfile = isset( $size['file'] ) ? $dir . '/' . wp_basename( (string) $size['file'] ) : '';
			if ( ! $sfile || ! is_file( $sfile ) ) {
				/* translators: 1: size name, 2: file */
				$add( 'size_missing', sprintf( __( 'Size "%1$s" is listed, but its file %2$s does not exist; WordPress would show a broken image for it.', 'wp-cleanup' ), $name, isset( $size['file'] ) ? $size['file'] : '?' ) );
				unset( $meta['sizes'][ $name ] );
				$changed = true;
				continue;
			}
			$info = Media_Files::describe( $sfile );
			if ( $info['width'] && ( (int) ( isset( $size['width'] ) ? $size['width'] : 0 ) !== $info['width'] || (int) ( isset( $size['height'] ) ? $size['height'] : 0 ) !== $info['height'] ) ) {
				/* translators: 1: size name, 2: saved dimensions, 3: real dimensions */
				$add( 'size_dims', sprintf( __( 'Size "%1$s" is saved as %2$s, but the file is %3$s.', 'wp-cleanup' ), $name, self::dims( isset( $size['width'] ) ? $size['width'] : 0, isset( $size['height'] ) ? $size['height'] : 0 ), self::dims( $info['width'], $info['height'] ) ) );
				$meta['sizes'][ $name ]['width']  = $info['width'];
				$meta['sizes'][ $name ]['height'] = $info['height'];
				$changed                          = true;
			}
			if ( $info['mime'] && isset( $size['mime-type'] ) && $size['mime-type'] !== $info['mime'] ) {
				/* translators: 1: size name, 2: saved type, 3: real type */
				$add( 'size_mime', sprintf( __( 'Size "%1$s" is saved as %2$s, but the file is %3$s.', 'wp-cleanup' ), $name, $size['mime-type'], $info['mime'] ) );
				$meta['sizes'][ $name ]['mime-type'] = $info['mime'];
				$changed                             = true;
			}
			if ( isset( $size['filesize'] ) && (int) $size['filesize'] !== $info['bytes'] ) {
				$meta['sizes'][ $name ]['filesize'] = $info['bytes']; // Corrected silently together with other fixes.
				$changed                            = true;
			}
		}

		// 4. WP Cleanup's own record of generated files.
		$outputs = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
		$s       = Media_Policy::settings();
		if ( is_array( $outputs ) ) {
			$o_changed = false;
			foreach ( array( 'jpeg' => 'JPEG fallback', 'avif_full' => 'full AVIF', 'avif_small' => 'small AVIF' ) as $key => $label ) {
				if ( empty( $outputs[ $key ] ) ) {
					continue;
				}
				$path = $root . '/' . wp_normalize_path( (string) $outputs[ $key ] );
				if ( ! is_file( $path ) ) {
					/* translators: 1: variant, 2: file */
					$add( 'output_missing', sprintf( __( 'The %1$s %2$s is recorded but missing; it will no longer be offered to browsers.', 'wp-cleanup' ), $label, $outputs[ $key ] ) );
					$outputs[ $key ] = '';
					foreach ( array( '_width', '_height' ) as $suffix ) {
						if ( isset( $outputs[ $key . $suffix ] ) ) {
							$outputs[ $key . $suffix ] = 0;
						}
					}
					$o_changed = true;
					continue;
				}
				$info = Media_Files::describe( $path );
				if ( $info['width'] && isset( $outputs[ $key . '_width' ] ) && (int) $outputs[ $key . '_width' ] !== $info['width'] ) {
					/* translators: 1: variant, 2: saved width, 3: real width */
					$add( 'output_dims', sprintf( __( 'The %1$s is recorded as %2$d px wide, but it is %3$d px; browsers would pick the wrong file.', 'wp-cleanup' ), $label, (int) $outputs[ $key . '_width' ], $info['width'] ) );
					$outputs[ $key . '_width' ] = $info['width'];
					if ( isset( $outputs[ $key . '_height' ] ) ) {
						$outputs[ $key . '_height' ] = $info['height'];
					}
					$o_changed = true;
				}
			}
			// The small AVIF must be listed both in WordPress metadata and in our record.
			if ( 'image/avif' === $actual['mime'] ) {
				$small_meta = isset( $meta['sizes'][ $s['small_name'] ]['file'] ) ? $meta['sizes'][ $s['small_name'] ]['file'] : '';
				$small_out  = ! empty( $outputs['avif_small'] ) ? wp_basename( $outputs['avif_small'] ) : '';
				if ( $small_out && $small_meta !== $small_out && dirname( $root . '/' . $outputs['avif_small'] ) === $dir ) {
					$info = Media_Files::describe( $dir . '/' . $small_out );
					/* translators: %s: file */
					$add( 'small_sync', sprintf( __( 'The small AVIF %s exists but WordPress metadata does not list it, so WordPress never uses it.', 'wp-cleanup' ), $small_out ) );
					$meta['sizes'][ $s['small_name'] ] = array(
						'file'      => $small_out,
						'width'     => $info['width'],
						'height'    => $info['height'],
						'mime-type' => 'image/avif',
						'filesize'  => $info['bytes'],
					);
					$changed = true;
				} elseif ( ! $small_out && $small_meta && is_file( $dir . '/' . $small_meta ) && 'image/avif' === Media_Files::describe( $dir . '/' . $small_meta )['mime'] ) {
					$info                          = Media_Files::describe( $dir . '/' . $small_meta );
					$outputs['avif_small']         = ltrim( dirname( $rel ) . '/' . $small_meta, './' );
					$outputs['avif_small_width']   = $info['width'];
					$outputs['avif_small_height']  = $info['height'];
					$o_changed                     = true;
					/* translators: %s: file */
					$add( 'small_sync', sprintf( __( 'The small AVIF %s is missing from WP Cleanup\'s record, so the AVIF picture markup skips it.', 'wp-cleanup' ), $small_meta ) );
				}
				if ( ! empty( $outputs['avif_full'] ) && $outputs['avif_full'] !== $rel ) {
					$outputs['avif_full'] = $rel;
					$o_changed            = true;
				}
			}
			if ( $o_changed ) {
				$fix['outputs'] = $outputs;
			}

			// 5. ALPS marker: present exactly when the policy matches ALPS and it is switched on.
			$wants = Media_Policy::wants_flag( $s );
			$has   = (bool) get_post_meta( $id, Media_Policy::ALPS_FLAG, true );
			if ( $wants !== $has ) {
				$fix['flag'] = $wants;
				if ( $wants ) {
					$add( 'flag', __( 'The ALPS marker is missing, so the ALPS theme would regenerate its old image sizes for this image.', 'wp-cleanup' ) );
				} elseif ( ! $s['set_flag'] ) {
					$add( 'flag', __( 'The ALPS marker is still set although marking is switched off in the Image policy.', 'wp-cleanup' ) );
				} else {
					/* translators: 1: size name, 2: full limit, 3: small limit */
					$add( 'flag', sprintf( __( 'The ALPS marker is set, but the Image policy ("%1$s", %2$d/%3$d px) does not match the ALPS sizes (alps-small, 1920/768 px).', 'wp-cleanup' ), $s['small_name'], $s['full_max'], $s['small_max'] ) );
				}
			}
		}

		if ( $changed ) {
			$fix['meta'] = $meta;
		}
		return array( 'issues' => $issues, 'fix' => $fix );
	}

	/**
	 * Check many attachments.
	 *
	 * @param int[] $ids Attachment ids.
	 * @return array<int,array> id => issues (only attachments with problems).
	 */
	public static function scan( array $ids ) {
		$out = array();
		foreach ( $ids as $id ) {
			$r = self::check( $id );
			if ( $r['issues'] ) {
				$out[ (int) $id ] = $r['issues'];
			}
		}
		return $out;
	}

	/**
	 * Repair one attachment after a fresh check.
	 *
	 * @param int    $id     Attachment id.
	 * @param Backup $backup Backup set.
	 * @return array{id:int,status:string,message:string,fixed:string[]}
	 */
	public static function repair( $id, Backup $backup ) {
		global $wpdb;
		$id     = (int) $id;
		$r      = self::check( $id );
		$fixed  = array();
		foreach ( $r['issues'] as $issue ) {
			if ( $issue['fixable'] ) {
				$fixed[] = $issue['message'];
			}
		}
		$result = array( 'id' => $id, 'status' => 'clean', 'message' => __( 'Nothing to repair.', 'wp-cleanup' ), 'fixed' => array() );
		if ( ! $fixed ) {
			return $result;
		}
		$fix = $r['fix'];
		$n   = $backup->add_item(
			array(
				'type'   => 'media_repair',
				'id'     => (string) $id,
				'label'  => wp_basename( (string) get_post_meta( $id, '_wp_attached_file', true ) ),
				'status' => 'repair',
				'owner'  => '',
				'count'  => count( $fixed ),
				'bytes'  => 0,
			)
		);
		try {
			$mime = $wpdb->get_var( $wpdb->prepare( "SELECT post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $id ) );
			$backup->write_sql( $n, "UPDATE `{$wpdb->posts}` SET `post_mime_type` = " . Backup::sql_literal( $mime ) . ' WHERE `ID` = ' . $id . ';' );
			$keys = "'" . implode( "','", array_map( 'esc_sql', Media_Converter::META_KEYS ) ) . "'";
			$backup->write_sql( $n, "DELETE FROM `{$wpdb->postmeta}` WHERE `post_id` = " . $id . " AND `meta_key` IN ({$keys});" );
			$backup->write_rows( $n, $wpdb->postmeta, (array) $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id = " . $id . " AND meta_key IN ({$keys})", ARRAY_A ) ); // phpcs:ignore

			if ( null !== $fix['path'] ) {
				update_post_meta( $id, '_wp_attached_file', $fix['path'] );
			}
			if ( null !== $fix['mime'] ) {
				$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $fix['mime'] ), array( 'ID' => $id ) );
			}
			if ( null !== $fix['outputs'] ) {
				update_post_meta( $id, Media_Policy::OUTPUT_META, $fix['outputs'] );
			}
			if ( true === $fix['flag'] ) {
				update_post_meta( $id, Media_Policy::ALPS_FLAG, 1 );
			} elseif ( false === $fix['flag'] ) {
				delete_post_meta( $id, Media_Policy::ALPS_FLAG );
			}
			if ( null !== $fix['meta'] ) {
				wp_update_attachment_metadata( $id, $fix['meta'] );
			}
			clean_post_cache( $id );
			$after = self::check( $id );
			$left  = array_filter(
				$after['issues'],
				static function ( $i ) {
					return $i['fixable'];
				}
			);
			if ( $left ) {
				throw new \RuntimeException( __( 'The repair did not hold; the previous data was put back.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
			}
			$backup->set_extra( $n, 'meta_hash', self::meta_hash( $id ) );
			$backup->set_extra( $n, 'fixed', $fixed );
			$backup->set_result( $n, 'deleted', sprintf( _n( '%d problem repaired', '%d problems repaired', count( $fixed ), 'wp-cleanup' ), count( $fixed ) ) );
			$result['status']  = 'repaired';
			$result['fixed']   = $fixed;
			$result['message'] = implode( ' ', $fixed );
			return $result;
		} catch ( \Exception $e ) {
			try {
				$backup->replay_sql( $n );
			} catch ( \Exception $ignored ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
				// The before-images stay in the backup set for a manual restore.
			}
			clean_post_cache( $id );
			$backup->set_result( $n, 'failed', $e->getMessage() );
			$result['status']  = 'failed';
			$result['message'] = $e->getMessage();
			return $result;
		}
	}

	/**
	 * Undo one repair.
	 *
	 * @param Backup $backup Backup set.
	 * @param int    $n      Section.
	 * @param array  $item   Manifest item.
	 * @return string
	 * @throws \RuntimeException When the attachment changed since.
	 */
	public static function restore( Backup $backup, $n, array $item ) {
		$id = (int) $item['id'];
		if ( isset( $item['extra']['meta_hash'] ) && self::meta_hash( $id ) !== $item['extra']['meta_hash'] ) {
			throw new \RuntimeException( __( 'The attachment was changed after the repair; not restoring it.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
		}
		$backup->replay_sql( $n );
		clean_post_cache( $id );
		return __( 'Previous image data restored.', 'wp-cleanup' );
	}

	/**
	 * @param int $id Attachment id.
	 */
	private static function meta_hash( $id ) {
		$parts = array();
		foreach ( Media_Converter::META_KEYS as $key ) {
			$parts[] = wp_json_encode( get_post_meta( $id, $key ) );
		}
		$parts[] = get_post_mime_type( $id );
		return md5( implode( '|', $parts ) );
	}

	/**
	 * @param int $w Width.
	 * @param int $h Height.
	 */
	private static function dims( $w, $h ) {
		return (int) $w && (int) $h ? (int) $w . '×' . (int) $h : __( 'unknown', 'wp-cleanup' );
	}
}
