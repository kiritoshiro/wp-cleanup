<?php
/**
 * Replace an image attachment with AVIF and an optional single JPEG fallback.
 *
 * New files are encoded and verified before database changes. References are
 * then rewritten to the main output, before-images are saved, and old files are moved
 * to the backup set. A failure rolls the image back.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Converter {

	/** Attachment meta keys whose before-images are kept. */
	const META_KEYS = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', Media_Policy::OUTPUT_META, Media_Policy::ALPS_FLAG, '_alps_original_delete_after', '_alps_retained_original' );

	/**
	 * @param int         $id      Attachment id.
	 * @param Backup|null $backup  Backup set (required unless dry run).
	 * @param bool        $dry_run Only report.
	 * @param int         $gap     Selected-image size gap in pixels.
	 * @return array{id:int,status:string,message:string,files:int,bytes_before:int,bytes_after:int}
	 */
	public static function convert( $id, $backup = null, $dry_run = false, $gap = 0 ) {
		if ( $dry_run || ! $backup instanceof Backup ) {
			return self::convert_unlocked( $id, $backup, $dry_run, $gap );
		}
		$id = (int) $id;
		// A gateway may have given up on an earlier request that is still converting this image.
		if ( ! Media_Guard::lock( $id, $backup->id ) ) {
			return array(
				'id'                => $id,
				'status'            => 'busy',
				'message'           => __( 'Another request is still converting this image. Wait for it to finish, then check the library.', 'wp-cleanup' ),
				'files'             => 0,
				'bytes_before'      => 0,
				'bytes_after'       => 0,
				'reference_changes' => array(),
				'backed_up'         => array(),
			);
		}
		try {
			$work    = Media_Guard::work( $id );
			$started = microtime( true );
			$result  = self::convert_unlocked( $id, $backup, false, $gap );
			if ( 'converted' === $result['status'] && empty( $result['trimmed'] ) ) {
				Media_Guard::record( microtime( true ) - $started, $work['work'] );
			}
			return $result;
		} finally {
			Media_Guard::unlock( $id );
		}
	}

	/**
	 * Convert without taking the per-image lock (see convert()).
	 *
	 * @param int         $id      Attachment id.
	 * @param Backup|null $backup  Backup set.
	 * @param bool        $dry_run Only report.
	 * @param int         $gap     Selected-image size gap in pixels.
	 * @return array
	 */
	private static function convert_unlocked( $id, $backup, $dry_run, $gap ) {
		global $wpdb;
		$id     = (int) $id;
		$s      = Media_Policy::settings();
		$gap    = max( 0, min( 8192, (int) $gap ) );
		$result = array(
			'id'           => $id,
			'status'       => 'skipped',
			'message'      => '',
			'files'        => 0,
			'bytes_before' => 0,
			'bytes_after'  => 0,
			'reference_changes' => array(),
			'backed_up'    => array(),
		);

		$reason = Media_Policy::skip_reason( $id );
		if ( $reason ) {
			$result['message'] = $reason;
			return $result;
		}
		Media_Inventory::flush();
		$inv = Media_Inventory::attachment( $id );
		if ( ! $inv ) {
			$result['message'] = __( 'The file is missing.', 'wp-cleanup' );
			return $result;
		}
		$result['files']        = count( $inv['files'] );
		$result['bytes_before'] = $inv['bytes'];
		if ( $inv['compliant'] ) {
			if ( self::can_trim_small( $id, $gap, $inv ) ) {
				if ( $dry_run ) {
					$result['status'] = 'planned';
					$result['message'] = __( 'The small AVIF would move to a restorable backup; the full AVIF and any JPEG fallback would stay.', 'wp-cleanup' );
					return $result;
				}
				if ( ! $backup instanceof Backup ) {
					throw new \RuntimeException( __( 'Images are only changed into a backup set.', 'wp-cleanup' ) );
				}
				return self::trim_small( $id, $backup, $inv, $gap, $result );
			}
			$result['status']  = 'compliant';
			$result['message'] = $gap ? __( 'Already follows the image policy or exceeds the selected size gap.', 'wp-cleanup' ) : __( 'Already follows the image policy.', 'wp-cleanup' );
			return $result;
		}
		if ( $dry_run ) {
			$result['status'] = 'planned';
			/* translators: %d: files */
			$result['message'] = sprintf( __( '%d file(s) would be replaced under the selected image policy.', 'wp-cleanup' ), count( $inv['files'] ) );
			return $result;
		}
		if ( ! $backup instanceof Backup ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( __( 'Images are only converted into a backup set.', 'wp-cleanup' ) );
		}
		if ( $s['jpeg_fallback'] && ! Media_Policy::jpeg_supported() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( __( 'This server cannot write JPEG images.', 'wp-cleanup' ) );
		}

		$created = array();
		$moved   = array();
		$n       = null;
		$applied = false;
		try {
			$jpeg = null;
			$full = null;
			$small = null;
			$rotated = false;
			$avif_error = '';
			if ( $s['jpeg_fallback'] ) {
				// Direct URLs point to the one JPEG on older devices.
				list( $jpeg, $rotated ) = self::encode_jpeg( $id, $inv, $s, $created );
			}
			if ( Media_Policy::avif_supported() ) {
				$avif_created = array();
				$avif_editor  = '';
				try {
					list( $full, $small, $avif_rotated ) = self::encode_avif( $id, $inv, $s, $avif_created, $avif_editor, false, $gap );
					$created = array_merge( $created, $avif_created );
					if ( ! $jpeg ) {
						$rotated = $avif_rotated;
					}
				} catch ( \Exception $e ) {
					$avif_error = $e->getMessage();
					self::discard_outputs( $avif_created );
					$full  = null;
					$small = null;
					// Retry with GD if WordPress first chose another editor and GD supports both formats.
					if ( 'WP_Image_Editor_GD' !== $avif_editor && self::gd_can_encode_avif( self::source( $id, $inv ) ) ) {
						$avif_created = array();
						try {
							list( $full, $small, $avif_rotated ) = self::encode_avif( $id, $inv, $s, $avif_created, $avif_editor, true, $gap );
							$created = array_merge( $created, $avif_created );
							$avif_error = '';
							if ( ! $jpeg ) {
								$rotated = $avif_rotated;
							}
						} catch ( \Exception $retry_error ) {
							self::discard_outputs( $avif_created );
							$full  = null;
							$small = null;
							$avif_error .= ' ' . sprintf( __( 'GD retry also failed: %s', 'wp-cleanup' ), $retry_error->getMessage() );
						}
					}
				}
			} else {
				$avif_error = __( 'This server cannot write AVIF images.', 'wp-cleanup' );
			}
			if ( ! $jpeg && ! $full ) {
				throw new \RuntimeException( $avif_error ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
			}

			$primary = $full ? $full : $jpeg;
			$primary_name = wp_basename( $primary['final'] );
			$small_name = $small ? wp_basename( $small['final'] ) : '';
			$map = array();
			$needles = array();
			$remove = array();
			foreach ( $inv['files'] as $name => $file ) {
				$remove[ $name ] = $file;
				$target = $primary_name;
				if ( $small_name && in_array( $file['role'], array( 'size', 'stray', 'backup' ), true ) && $file['width'] && $file['height'] && max( $file['width'], $file['height'] ) <= $s['small_max'] ) {
					$target = $small_name;
				}
				$map[ Reference_Rewriter::key( $inv['rel_dir'], $name ) ] = Reference_Rewriter::key( $inv['rel_dir'], $target );
				$needles[ Reference_Rewriter::key( $inv['rel_dir'], Media_Inventory::base_name( $name ) ) ] = true;
			}
			$widths = array( Reference_Rewriter::key( $inv['rel_dir'], $primary_name ) => $primary['width'] );
			if ( $small ) {
				$widths[ Reference_Rewriter::key( $inv['rel_dir'], $small_name ) ] = $small['width'];
			}
			$moved_info = array();
			foreach ( $remove as $name => $file ) {
				$moved_info[ ltrim( $inv['rel_dir'] . '/' . $name, '/' ) ] = Media_Files::describe( $inv['dir'] . '/' . $name );
			}
			$rewriter = new Reference_Rewriter( $map, $widths );
			$changes  = $rewriter->plan( array_keys( $needles ), $id );
			$reference_changes = self::reference_changes( $changes, $map );

			// 4. Before-images.
			$n = $backup->add_item(
				array(
					'type'   => 'media',
					'id'     => (string) $id,
					'label'  => wp_basename( get_attached_file( $id, true ) ),
					'status' => 'eligible',
					'owner'  => '',
					'count'  => count( $remove ),
					'bytes'  => array_sum( wp_list_pluck( $remove, 'bytes' ) ),
				)
			);
			self::write_before_images( $backup, $n, $id, $changes );

			// 5. Apply.
			$applied = true;
			Reference_Rewriter::apply( $changes );
			self::update_attachment( $id, $inv, $jpeg, $full, $small, $rotated, $s, $avif_error, $gap );

			// 6. Move old files into the backup set.
			$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
			$qdir    = $backup->quarantine_dir( $n ) . '/orig';
			foreach ( $remove as $name => $file ) {
				$rel = ltrim( $inv['rel_dir'] . '/' . $name, '/' );
				$dst = $qdir . '/' . $rel;
				wp_mkdir_p( dirname( $dst ) );
				/**
				 * Test seam: return false to simulate a file that cannot be moved.
				 *
				 * @param bool   $ok  Whether to proceed.
				 * @param string $rel Uploads-relative path.
				 */
				if ( ! apply_filters( 'wp_cleanup_media_move_file', true, $rel ) || ! @rename( $uploads . '/' . $rel, $dst ) ) { // phpcs:ignore
					/* translators: %s: file */
					throw new \RuntimeException( sprintf( __( 'Could not move %s into the backup set.', 'wp-cleanup' ), $rel ) );
				}
				$moved[] = $rel;
			}

			// 7. Outputs written under a temporary name take their clean name now that it is free.
			foreach ( array( 'jpeg', 'full', 'small' ) as $var ) {
				$out = ${$var};
				if ( ! $out || $out['path'] === $out['final'] ) {
					continue;
				}
				if ( file_exists( $out['final'] ) || ! @rename( $out['path'], $out['final'] ) ) { // phpcs:ignore
					/* translators: %s: file */
					throw new \RuntimeException( sprintf( __( 'Could not give the new file its final name %s.', 'wp-cleanup' ), wp_basename( $out['final'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
				}
				$created = array_values( array_diff( $created, array( $out['path'] ) ) );
				$created[] = $out['final'];
				$out['path'] = $out['final'];
				${$var} = $out;
			}
			$created_info = array();
			foreach ( array( 'jpeg' => __( 'JPEG fallback', 'wp-cleanup' ), 'full' => __( 'Full AVIF', 'wp-cleanup' ), 'small' => __( 'Small AVIF', 'wp-cleanup' ) ) as $var => $label ) {
				if ( ${$var} ) {
					$created_info[ self::rel( ${$var}['path'] ) ] = array_merge( Media_Files::describe( ${$var}['path'] ), array( 'role' => $label ) );
				}
			}
			foreach ( $reference_changes as $i => $change ) {
				$reference_changes[ $i ]['from_info'] = self::info_for_key( $change['from'], $moved_info );
				$reference_changes[ $i ]['to_info']   = self::info_for_key( $change['to'], $created_info );
			}

			$backup->set_extra( $n, 'created', array_map( array( __CLASS__, 'rel' ), $created ) );
			$backup->set_extra( $n, 'moved', $moved );
			$backup->set_extra( $n, 'moved_info', $moved_info );
			$backup->set_extra( $n, 'created_info', $created_info );
			$backup->set_extra( $n, 'reference_changes', $reference_changes );
			$backup->set_extra( $n, 'meta_hash', self::meta_hash( $id ) );
			$backup->set_extra(
				$n,
				'rows',
				array_map(
					static function ( $c ) {
						return array( $c['table'], $c['pk'], $c['id'], $c['column'], md5( $c['new'] ) );
					},
					$changes
				)
			);

			$after = ( $jpeg ? (int) filesize( $jpeg['path'] ) : 0 ) + ( $full ? (int) filesize( $full['path'] ) : 0 ) + ( $small ? (int) filesize( $small['path'] ) : 0 );
			$revision_count = count( array_filter( $changes, static function ( $change ) use ( $wpdb ) {
				return $wpdb->posts === $change['table'] && 'revision' === $change['row']['post_type'];
			} ) );
			$backup->set_result( $n, 'deleted', sprintf( '%d file(s) replaced; %d stored field(s) rewritten (%d in revisions)', count( $remove ), count( $changes ), $revision_count ) );

			$result['status']      = 'converted';
			$result['bytes_after'] = $after;
			$result['reference_changes'] = $reference_changes;
			$result['backed_up'] = $moved;
			$result['backed_up_info'] = self::info_list( $moved_info );
			$result['created_info'] = self::info_list( $created_info );
			$result['message']     = sprintf(
				/* translators: 1: files, 2: size before, 3: size after, 4: stored fields, 5: revisions */
				__( '%1$d file(s), %2$s → %3$s; %4$d stored field(s) rewritten (%5$d in revisions). These are database records, not separate image uses.', 'wp-cleanup' ),
				count( $inv['files'] ),
				size_format( $inv['bytes'], 1 ),
				size_format( $after, 1 ),
				count( $changes ),
				$revision_count
			);
			if ( $avif_error && $jpeg ) {
				$result['message'] .= ' ' . sprintf( __( 'The JPEG fallback is active; AVIF could not be made: %s', 'wp-cleanup' ), $avif_error );
			}
			return $result;
		} catch ( \Exception $e ) {
			self::rollback( $id, $backup, $n, $applied, $created, $moved, $inv );
			if ( null !== $n ) {
				$backup->set_result( $n, 'failed', $e->getMessage() );
			}
			$result['status']  = 'failed';
			$result['message'] = $e->getMessage();
			return $result;
		}
	}

	/** Whether an existing recorded small AVIF can be removed under a chosen gap. */
	public static function can_trim_small( $id, $gap, $inv = null ) {
		$gap = (int) $gap;
		if ( $gap < 1 || $gap > 8192 ) {
			return false;
		}
		$inv = $inv ? $inv : Media_Inventory::attachment( $id );
		if ( ! $inv || ! $inv['compliant'] || 'image/avif' !== get_post_mime_type( $id ) ) {
			return false;
		}
		$meta = $inv['meta'];
		$out  = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
		$s    = Media_Policy::settings();
		$name = isset( $meta['sizes'][ $s['small_name'] ]['file'] ) ? (string) $meta['sizes'][ $s['small_name'] ]['file'] : '';
		if ( ! is_array( $out ) || ! $name || wp_basename( $name ) !== $name || empty( $out['avif_small'] )
			|| empty( $out['avif_full'] ) || wp_basename( $out['avif_small'] ) !== $name || (string) $meta['file'] !== (string) $out['avif_full'] ) {
			return false;
		}
		$relative = ltrim( $inv['rel_dir'] . '/' . $name, '/' );
		if ( wp_normalize_path( (string) $out['avif_small'] ) !== $relative || ! isset( $inv['files'][ $name ] )
			|| is_link( $inv['dir'] . '/' . $name ) || ! is_file( $inv['dir'] . '/' . $name ) ) {
			return false;
		}
		if ( ! isset( $meta['width'], $meta['height'], $meta['sizes'][ $s['small_name'] ]['width'], $meta['sizes'][ $s['small_name'] ]['height'] ) ) {
			return false;
		}
		$full  = max( (int) $meta['width'], (int) $meta['height'] );
		$small = max( (int) $meta['sizes'][ $s['small_name'] ]['width'], (int) $meta['sizes'][ $s['small_name'] ]['height'] );
		if ( $full <= $small || $full - $small > $gap ) {
			return false;
		}
		$info = Media_Files::describe( $inv['dir'] . '/' . $name );
		return 'image/avif' === $info['mime'] && $info['width'] === (int) $meta['sizes'][ $s['small_name'] ]['width']
			&& $info['height'] === (int) $meta['sizes'][ $s['small_name'] ]['height'];
	}

	/** Back up only the selected small AVIF, keeping the full AVIF and JPEG bytes intact. */
	private static function trim_small( $id, Backup $backup, array $inv, $gap, array $result ) {
		global $wpdb;
		$s        = Media_Policy::settings();
		$meta     = $inv['meta'];
		$name     = $meta['sizes'][ $s['small_name'] ]['file'];
		$relative = ltrim( $inv['rel_dir'] . '/' . $name, '/' );
		$full     = wp_basename( $meta['file'] );
		$old_key  = Reference_Rewriter::key( $inv['rel_dir'], $name );
		$new_key  = Reference_Rewriter::key( $inv['rel_dir'], $full );
		$map      = array( $old_key => $new_key );
		$issues   = Media_Integrity::check( $id );
		if ( $issues['issues'] ) {
			$result['status'] = 'failed';
			$result['message'] = __( 'This image has data or file issues. Check and repair it before removing a size.', 'wp-cleanup' );
			return $result;
		}
		// A shared file must never be moved out from under another attachment.
		$shared = $wpdb->get_var( $wpdb->prepare(
			"SELECT post_id FROM {$wpdb->postmeta} WHERE post_id <> %d AND meta_key IN ('_wp_attached_file','_wp_attachment_metadata','_wp_attachment_backup_sizes','_wpcu_image_outputs') AND meta_value LIKE %s LIMIT 1",
			$id, '%' . $wpdb->esc_like( $name ) . '%'
		) );
		if ( $shared || $wpdb->last_error ) {
			$result['status'] = 'failed';
			$result['message'] = __( 'The small file may belong to another attachment; it was not moved.', 'wp-cleanup' );
			return $result;
		}
		$before   = Media_Files::describe( $inv['dir'] . '/' . $name );
		$info     = array( $relative => array_merge( $before, array( 'role' => __( 'Small AVIF', 'wp-cleanup' ) ) ) );
		$n        = null;
		$moved    = array();
		$applied  = false;
		try {
			$changes = ( new Reference_Rewriter( $map, array( $new_key => (int) $meta['width'] ) ) )->plan( array( $old_key ), $id );
			$n = $backup->add_item( array(
				'type' => 'media', 'id' => (string) $id, 'label' => $name,
				'status' => 'eligible', 'owner' => '', 'count' => 1, 'bytes' => (int) $before['bytes'],
			) );
			self::write_before_images( $backup, $n, $id, $changes );
			$applied = true;
			Reference_Rewriter::apply( $changes );
			unset( $meta['sizes'][ $s['small_name'] ] );
			wp_update_attachment_metadata( $id, $meta );
			$out = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
			$out = is_array( $out ) ? $out : array();
			$out['avif_small'] = '';
			$out['avif_small_width'] = 0;
			$out['avif_small_height'] = 0;
			$out['small_gap_px'] = (int) $gap;
			update_post_meta( $id, Media_Policy::OUTPUT_META, $out );
			$destination = $backup->quarantine_dir( $n ) . '/orig/' . $relative;
			wp_mkdir_p( dirname( $destination ) );
			if ( ! @rename( $inv['dir'] . '/' . $name, $destination ) ) { // phpcs:ignore -- Reversible move into the backup set.
				throw new \RuntimeException( __( 'Could not move the small AVIF into the backup set.', 'wp-cleanup' ) );
			}
			$moved[] = $relative;
			$refs = self::reference_changes( $changes, $map );
			$backup->set_extra( $n, 'created', array() );
			$backup->set_extra( $n, 'moved', $moved );
			$backup->set_extra( $n, 'moved_info', $info );
			$backup->set_extra( $n, 'created_info', array() );
			$backup->set_extra( $n, 'reference_changes', $refs );
			$backup->set_extra( $n, 'meta_hash', self::meta_hash( $id ) );
			$backup->set_extra( $n, 'rows', array_map( static function ( $c ) {
				return array( $c['table'], $c['pk'], $c['id'], $c['column'], md5( $c['new'] ) );
			}, $changes ) );
			$backup->set_result( $n, 'deleted', __( 'Small AVIF moved to backup; full AVIF and any JPEG fallback kept.', 'wp-cleanup' ) );
			Media_Inventory::flush();
			$result['status'] = 'converted';
			$result['trimmed'] = true;
			$result['bytes_after'] = $inv['bytes'] - (int) $before['bytes'];
			$result['reference_changes'] = $refs;
			$result['backed_up'] = $moved;
			$result['backed_up_info'] = self::info_list( $info );
			$result['created_info'] = array();
			$result['message'] = __( 'Small AVIF moved to a restorable backup; full AVIF and any JPEG fallback unchanged.', 'wp-cleanup' );
			return $result;
		} catch ( \Exception $e ) {
			self::rollback( $id, $backup, $n, $applied, array(), $moved, $inv );
			if ( null !== $n ) {
				$backup->set_result( $n, 'failed', $e->getMessage() );
			}
			$result['status'] = 'failed';
			$result['message'] = $e->getMessage();
			return $result;
		}
	}

	/**
	 * File info for a rewrite key ("uploads/2024/05/a.jpg").
	 *
	 * @param string $key   Reference key.
	 * @param array  $infos Uploads-relative path => info.
	 * @return array|null
	 */
	private static function info_for_key( $key, array $infos ) {
		$parts = explode( '/', (string) $key, 2 );
		$rel   = isset( $parts[1] ) ? $parts[1] : '';
		return isset( $infos[ $rel ] ) ? $infos[ $rel ] : null;
	}

	/**
	 * @param array $infos Path => info.
	 * @return array[] Each: path, bytes, width, height, mime, role.
	 */
	public static function info_list( array $infos ) {
		$out = array();
		foreach ( $infos as $path => $info ) {
			$out[] = array_merge( array( 'path' => $path, 'role' => '' ), (array) $info );
		}
		return $out;
	}

	/** Explain each rewritten row and its old and new image paths. */
	private static function reference_changes( array $changes, array $map ) {
		$out = array();
		global $wpdb;
		foreach ( $changes as $change ) {
			$row = $change['row'];
			if ( $wpdb->posts === $change['table'] && 'revision' === $row['post_type'] ) {
				$parent = get_post( (int) $row['post_parent'] );
				$where = sprintf( __( 'Revision #%1$d of %2$s (#%3$d), %4$s', 'wp-cleanup' ), $change['id'], $parent ? $parent->post_title : __( 'deleted post', 'wp-cleanup' ), (int) $row['post_parent'], $change['column'] );
			} elseif ( $wpdb->posts === $change['table'] ) {
				$where = sprintf( __( 'Post: %1$s (#%2$s), %3$s', 'wp-cleanup' ), wp_html_excerpt( (string) $row['post_title'], 100 ), $change['id'], $change['column'] );
			} elseif ( $wpdb->options === $change['table'] ) {
				$where = sprintf( __( 'Site option: %1$s (#%2$s)', 'wp-cleanup' ), $row['option_name'], $change['id'] );
			} elseif ( isset( $row['meta_key'] ) ) {
				$where = sprintf( __( 'Metadata: %1$s (#%2$s in %3$s)', 'wp-cleanup' ), $row['meta_key'], $change['id'], $change['table'] );
			} else {
				$where = $change['table'] . ' #' . $change['id'] . ' · ' . $change['column'];
			}
			$found = false;
			foreach ( $map as $from => $to ) {
				if ( false === strpos( $change['old'], $from ) && false === strpos( $change['old'], str_replace( '/', '\\/', $from ) ) ) {
					continue;
				}
				$out[] = array( 'where' => $where, 'from' => $from, 'to' => $to );
				$found = true;
			}
			if ( ! $found ) {
				$out[] = array( 'where' => $where, 'from' => __( 'Image width descriptors', 'wp-cleanup' ), 'to' => __( 'Actual JPEG width', 'wp-cleanup' ) );
			}
		}
		return $out;
	}

	/** Select the best original image available for both encoders. */
	public static function source( $id, array $inv ) {
		$source = wp_normalize_path( get_attached_file( $id, true ) );
		if ( ! $inv['edited'] && ! empty( $inv['meta']['original_image'] ) && is_file( $inv['dir'] . '/' . wp_basename( $inv['meta']['original_image'] ) ) ) {
			$source = $inv['dir'] . '/' . wp_basename( $inv['meta']['original_image'] );
		}
		return $source;
	}

	/** Make exactly one optimized JPEG, with no generated JPEG sub-sizes. */
	private static function encode_jpeg( $id, array $inv, array $s, array &$created ) {
		wp_raise_memory_limit( 'image' );
		$source = self::source( $id, $inv );
		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			throw new \RuntimeException( $editor->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
		}
		$rotated = method_exists( $editor, 'maybe_exif_rotate' ) ? $editor->maybe_exif_rotate() : false;
		if ( is_wp_error( $rotated ) ) {
			throw new \RuntimeException( $rotated->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
		}
		$size = $editor->get_size();
		if ( max( $size['width'], $size['height'] ) > $s['jpeg_max'] ) {
			$err = $editor->resize( $s['jpeg_max'], $s['jpeg_max'], false );
			if ( is_wp_error( $err ) ) {
				throw new \RuntimeException( $err->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
			}
		}
		$editor->set_quality( $s['jpeg_quality'] );
		$jpeg = self::save( $editor, $inv['dir'], $inv['base'] . '-fallback.jpg', $created, 'image/jpeg', $inv['files'] );
		$mime = wp_get_image_mime( $source );
		$load = 'image/png' === $mime ? 'imagecreatefrompng' : ( 'image/avif' === $mime ? 'imagecreatefromavif' : '' );
		$canvas = null;
		if ( $load && function_exists( $load ) ) {
			$transparent = @$load( $source ); // phpcs:ignore -- The editor already decoded this local file.
			if ( $transparent ) {
				$canvas = imagecreatetruecolor( $jpeg['width'], $jpeg['height'] );
				imagefill( $canvas, 0, 0, imagecolorallocate( $canvas, 255, 255, 255 ) );
				imagealphablending( $canvas, true );
				imagecopyresampled( $canvas, $transparent, 0, 0, 0, 0, $jpeg['width'], $jpeg['height'], imagesx( $transparent ), imagesy( $transparent ) );
				imagedestroy( $transparent );
				if ( ! imagejpeg( $canvas, $jpeg['path'], $s['jpeg_quality'] ) ) {
					imagedestroy( $canvas );
					throw new \RuntimeException( __( 'Could not save the JPEG fallback.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
				}
			}
		}
		self::verify( $jpeg, 'image/jpeg' );
		// Lower quality when needed to make the fallback smaller than its source.
		foreach ( array( 72, 62 ) as $quality ) {
			if ( (int) filesize( $jpeg['path'] ) < (int) filesize( $source ) || $quality >= $s['jpeg_quality'] ) {
				break;
			}
			if ( $canvas ) {
				if ( ! imagejpeg( $canvas, $jpeg['path'], $quality ) ) {
					imagedestroy( $canvas );
					throw new \RuntimeException( __( 'Could not optimize the JPEG fallback.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
				}
			} else {
				$editor->set_quality( $quality );
				$retry = $editor->save( $jpeg['path'], 'image/jpeg' );
				if ( is_wp_error( $retry ) ) {
					throw new \RuntimeException( $retry->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
				}
			}
			self::verify( $jpeg, 'image/jpeg' );
		}
		if ( $canvas ) {
			imagedestroy( $canvas );
		}
		return array( $jpeg, true === $rotated );
	}

	/** Make a full AVIF and, when needed, one small AVIF. */
	private static function encode_avif( $id, array $inv, array $s, array &$created, &$editor_class, $gd_only = false, $gap = 0 ) {
		$source = self::source( $id, $inv );
		$editor = self::avif_editor( $source, $gd_only );
		if ( is_wp_error( $editor ) ) {
			throw new \RuntimeException( $editor->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
		}
		$editor_class = get_class( $editor );
		$rotated = method_exists( $editor, 'maybe_exif_rotate' ) ? $editor->maybe_exif_rotate() : false;
		if ( is_wp_error( $rotated ) ) {
			throw new \RuntimeException( $rotated->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
		}
		$size = $editor->get_size();
		if ( max( $size['width'], $size['height'] ) > $s['full_max'] ) {
			$err = $editor->resize( $s['full_max'], $s['full_max'], false );
			if ( is_wp_error( $err ) ) {
				throw new \RuntimeException( $err->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
			}
		}
		$full = self::save( $editor, $inv['dir'], $inv['base'] . '.avif', $created, 'image/avif', $inv['files'] );
		self::verify( $full, 'image/avif' );

		$small = null;
		if ( max( $full['width'], $full['height'] ) > $s['small_max'] + $gap ) {
			$editor = self::avif_editor( $full['path'], $gd_only );
			if ( is_wp_error( $editor ) ) {
				throw new \RuntimeException( $editor->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
			}
			$err = $editor->resize( $s['small_max'], $s['small_max'], false );
			if ( is_wp_error( $err ) ) {
				throw new \RuntimeException( $err->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
			}
			$dims = $editor->get_size();
			$small = self::save( $editor, $inv['dir'], $inv['base'] . '-' . $dims['width'] . 'x' . $dims['height'] . '.avif', $created, 'image/avif', $inv['files'] );
			self::verify( $small, 'image/avif' );
		}
		return array( $full, $small, true === $rotated );
	}

	/** Choose GD for the retry without changing the editor used by other requests. */
	private static function avif_editor( $path, $gd_only ) {
		if ( ! $gd_only ) {
			return wp_get_image_editor( $path );
		}
		$only_gd = static function () { return array( 'WP_Image_Editor_GD' ); };
		add_filter( 'wp_image_editors', $only_gd, 999 );
		try {
			return wp_get_image_editor( $path );
		} finally {
			remove_filter( 'wp_image_editors', $only_gd, 999 );
		}
	}

	/** A retry is possible only if GD handles both input and AVIF output. */
	private static function gd_can_encode_avif( $source ) {
		if ( ! class_exists( 'WP_Image_Editor_GD' ) ) {
			require_once ABSPATH . WPINC . '/class-wp-image-editor-gd.php';
		}
		$source_mime = wp_get_image_mime( $source );
		return $source_mime
			&& \WP_Image_Editor_GD::supports_mime_type( 'image/avif' )
			&& \WP_Image_Editor_GD::supports_mime_type( $source_mime );
	}

	/** Remove incomplete AVIF files before trying another encoder or falling back to JPEG. */
	private static function discard_outputs( array $paths ) {
		foreach ( $paths as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore -- Incomplete new output.
			}
		}
	}

	/**
	 * @param \WP_Image_Editor $editor  Editor.
	 * @param string           $dir     Folder.
	 * @param string           $name    Desired file name.
	 * @param array            $created Created paths (by reference).
	 * @param string           $mime    Output MIME type.
	 * @param array            $replaced File names (keys) this conversion moves to the backup.
	 * @return array{path:string,final:string,width:int,height:int} path = where it was written, final = where it ends up.
	 */
	private static function save( $editor, $dir, $name, array &$created, $mime, array $replaced = array() ) {
		$name = sanitize_file_name( $name );
		$temp = false;
		if ( file_exists( $dir . '/' . $name ) ) {
			if ( isset( $replaced[ $name ] ) ) {
				// The clean name belongs to an old file that this conversion moves to the backup.
				// Encode under a temporary name and take the clean name once the old file is gone,
				// instead of drifting to "-1", "-1-1" on every re-conversion.
				$temp = true;
				$dest = $dir . '/' . wp_unique_filename( $dir, pathinfo( $name, PATHINFO_FILENAME ) . '-wpcu-new.' . pathinfo( $name, PATHINFO_EXTENSION ) );
			} else {
				$dest = $dir . '/' . wp_unique_filename( $dir, $name );
			}
		} else {
			$dest = $dir . '/' . $name;
		}
		$saved = $editor->save( $dest, $mime );
		if ( is_wp_error( $saved ) ) {
			if ( is_file( $dest ) ) {
				@unlink( $dest ); // phpcs:ignore -- Remove a partial new file after an encoder error.
			}
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( $saved->get_error_message() );
		}
		$created[] = wp_normalize_path( $saved['path'] );
		return array(
			'path'   => wp_normalize_path( $saved['path'] ),
			'final'  => $temp ? wp_normalize_path( $dir . '/' . $name ) : wp_normalize_path( $saved['path'] ),
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
		);
	}

	/**
	 * @param array $file path, width, height.
	 * @param string $mime Expected MIME type.
	 * @throws \RuntimeException When the file cannot be decoded at that size.
	 */
	private static function verify( array $file, $mime ) {
		clearstatcache( true, $file['path'] );
		$info = is_file( $file['path'] ) && filesize( $file['path'] ) > 0 ? wp_getimagesize( $file['path'] ) : false;
		$verified = $info && $mime === ( isset( $info['mime'] ) ? $info['mime'] : '' ) && (int) $info[0] === (int) $file['width'] && (int) $info[1] === (int) $file['height'];
		$observed = $info ? sprintf( '%1$d×%2$d %3$s', (int) $info[0], (int) $info[1], isset( $info['mime'] ) ? $info['mime'] : '?' ) : __( 'unreadable header', 'wp-cleanup' );
		// Some valid AVIF variants are not understood by PHP's header parser. Decode the image before refusing it.
		if ( ! $verified && is_file( $file['path'] ) && $mime === wp_get_image_mime( $file['path'] ) ) {
			$editor = wp_get_image_editor( $file['path'] );
			if ( is_wp_error( $editor ) ) {
				$observed .= '; ' . $editor->get_error_message();
			} else {
				$size = $editor->get_size();
				if ( is_array( $size ) ) {
					$observed .= sprintf( '; decoder %1$d×%2$d', (int) $size['width'], (int) $size['height'] );
					$verified = (int) $size['width'] === (int) $file['width'] && (int) $size['height'] === (int) $file['height'];
				}
			}
		}
		if ( ! $verified && 'image/avif' === $mime && self::gd_can_encode_avif( $file['path'] ) ) {
			$gd_editor = self::avif_editor( $file['path'], true );
			if ( is_wp_error( $gd_editor ) ) {
				$observed .= '; GD: ' . $gd_editor->get_error_message();
			} else {
				$gd_size = $gd_editor->get_size();
				if ( is_array( $gd_size ) ) {
					$observed .= sprintf( '; GD decoder %1$d×%2$d', (int) $gd_size['width'], (int) $gd_size['height'] );
					$verified = (int) $gd_size['width'] === (int) $file['width'] && (int) $gd_size['height'] === (int) $file['height'];
				}
			}
		}
		if ( ! apply_filters( 'wp_cleanup_media_verify', $verified, $file, $mime ) ) {
			/* translators: 1: MIME type, 2: file, 3: expected width, 4: expected height, 5: observed format and dimensions */
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( sprintf( __( 'The new %1$s file %2$s could not be decoded with the expected dimensions (%3$d×%4$d expected; %5$s).', 'wp-cleanup' ), $mime, wp_basename( $file['path'] ), (int) $file['width'], (int) $file['height'], $observed ) );
		}
	}

	/**
	 * Column-level before-images, so a restore touches nothing else.
	 */
	private static function write_before_images( Backup $backup, $n, $id, array $changes ) {
		global $wpdb;
		$mime = $wpdb->get_var( $wpdb->prepare( "SELECT post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $id ) );
		$backup->write_sql( $n, "UPDATE `{$wpdb->posts}` SET `post_mime_type` = " . Backup::sql_literal( $mime ) . ' WHERE `ID` = ' . (int) $id . ';' );

		$keys = "'" . implode( "','", array_map( 'esc_sql', self::META_KEYS ) ) . "'";
		$backup->write_sql( $n, "DELETE FROM `{$wpdb->postmeta}` WHERE `post_id` = " . (int) $id . " AND `meta_key` IN ({$keys});" );
		$rows = $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id = " . (int) $id . " AND meta_key IN ({$keys})", ARRAY_A ); // phpcs:ignore
		$backup->write_rows( $n, $wpdb->postmeta, (array) $rows );

		foreach ( $changes as $c ) {
			$backup->write_sql( $n, 'UPDATE `' . Backup::ident( $c['table'] ) . '` SET `' . Backup::ident( $c['column'] ) . '` = ' . Backup::sql_literal( $c['old'] ) . ' WHERE `' . Backup::ident( $c['pk'] ) . '` = ' . Backup::sql_literal( $c['id'] ) . ';' );
		}
	}

	private static function update_attachment( $id, array $inv, $jpeg, $full, $small, $rotated, array $s, $avif_error, $gap = 0 ) {
		global $wpdb;
		$primary = $full ? $full : $jpeg;
		$mime = $full ? 'image/avif' : 'image/jpeg';
		$meta = $inv['meta'];
		// Compute the path ourselves because Windows path separators differ from WordPress strings.
		$relative = self::rel( $primary['final'] );
		$meta['file'] = $relative;
		$meta['width'] = $primary['width'];
		$meta['height'] = $primary['height'];
		$meta['filesize'] = (int) filesize( $primary['path'] );
		$meta['sizes'] = array();
		if ( $small ) {
			$meta['sizes'][ $s['small_name'] ] = array(
				'file' => wp_basename( $small['final'] ),
				'width' => $small['width'],
				'height' => $small['height'],
				'mime-type' => 'image/avif',
				'filesize' => (int) filesize( $small['path'] ),
			);
		}
		unset( $meta['original_image'] );
		if ( $rotated ) {
			$meta['image_meta']['orientation'] = 1;
		}
		$outputs = array(
			'jpeg' => $jpeg ? self::rel( $jpeg['final'] ) : '',
			'jpeg_width' => $jpeg ? $jpeg['width'] : 0,
			'avif_full' => $full ? self::rel( $full['final'] ) : '',
			'avif_full_width' => $full ? $full['width'] : 0,
			'avif_full_height' => $full ? $full['height'] : 0,
			'avif_small' => $small ? self::rel( $small['final'] ) : '',
			'avif_small_width' => $small ? $small['width'] : 0,
			'avif_small_height' => $small ? $small['height'] : 0,
			'avif_error' => $avif_error,
			'small_gap_px' => $full && ! $small ? (int) $gap : 0,
			'policy' => $s,
		);
		update_attached_file( $id, $relative );
		if ( wp_normalize_path( (string) get_attached_file( $id, true ) ) !== $primary['final'] ) {
			throw new \RuntimeException( __( 'The new file path could not be stored correctly.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
		}
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => $mime ), array( 'ID' => $id ) );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );
		// The marker means "follows the ALPS size policy" (ALPS also marks single-size uploads),
		// so it is set only when the policy really matches ALPS, and removed otherwise.
		if ( Media_Policy::wants_flag( $s ) ) {
			update_post_meta( $id, Media_Policy::ALPS_FLAG, 1 );
		} else {
			delete_post_meta( $id, Media_Policy::ALPS_FLAG );
		}
		update_post_meta( $id, Media_Policy::OUTPUT_META, $outputs );
		wp_update_attachment_metadata( $id, $meta );
		clean_post_cache( $id );
	}

	private static function rollback( $id, $backup, $n, $applied, array $created, array $moved, array $inv ) {
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		// Every created path is a new file this run wrote (save() never overwrites), so it can go first.
		foreach ( $created as $path ) {
			if ( is_file( $path ) ) {
				@unlink( $path ); // phpcs:ignore
			}
		}
		if ( $backup && null !== $n ) {
			$qdir = $backup->quarantine_dir( $n ) . '/orig';
			foreach ( array_reverse( $moved ) as $rel ) {
				@rename( $qdir . '/' . $rel, $uploads . '/' . $rel ); // phpcs:ignore
			}
			if ( $applied ) {
				try {
					$backup->replay_sql( $n );
				} catch ( \Exception $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement
					// Reported through the item's failure; the before-images stay in the backup set.
				}
			}
		}
		clean_post_cache( $id );
		wp_cache_flush();
	}

	/**
	 * Restore one converted attachment from a backup set.
	 *
	 * @param Backup $backup Backup set.
	 * @param int    $n      Section.
	 * @param array  $item   Manifest item.
	 * @return string Message.
	 * @throws \RuntimeException On conflicts or failures.
	 */
	public static function restore( Backup $backup, $n, array $item ) {
		global $wpdb;
		$extra   = isset( $item['extra'] ) ? $item['extra'] : array();
		$id      = (int) $item['id'];
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		$qdir    = $backup->dir . '/files/' . (int) $n . '/orig';

		// Refuse rather than overwrite anything edited since the conversion.
		if ( isset( $extra['meta_hash'] ) && self::meta_hash( $id ) !== $extra['meta_hash'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( __( 'The attachment was changed after the cleanup; not restoring it.', 'wp-cleanup' ) );
		}
		foreach ( (array) ( isset( $extra['rows'] ) ? $extra['rows'] : array() ) as $row ) {
			list( $table, $pk, $row_id, $column, $hash ) = $row;
			$now = $wpdb->get_var( $wpdb->prepare( 'SELECT `' . Backup::ident( $column ) . '` FROM `' . Backup::ident( $table ) . '` WHERE `' . Backup::ident( $pk ) . '` = %s', $row_id ) ); // phpcs:ignore
			if ( null !== $now && md5( $now ) !== $hash ) {
				/* translators: 1: table, 2: row id */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( __( 'Row %2$s in %1$s was edited after the cleanup; not restoring this image.', 'wp-cleanup' ), $table, $row_id ) );
			}
		}
		$moved   = (array) ( isset( $extra['moved'] ) ? $extra['moved'] : array() );
		$created = (array) ( isset( $extra['created'] ) ? $extra['created'] : array() );
		$parked  = $backup->dir . '/files/' . (int) $n . '/created';
		$park    = static function ( $rel ) use ( $uploads, $parked ) {
			if ( is_file( $uploads . '/' . $rel ) ) {
				wp_mkdir_p( dirname( $parked . '/' . $rel ) );
				if ( ! @rename( $uploads . '/' . $rel, $parked . '/' . $rel ) ) { // phpcs:ignore
					/* translators: %s: file */
					throw new \RuntimeException( sprintf( __( 'Could not move %s out of the way.', 'wp-cleanup' ), $rel ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the caller.
				}
			}
		};
		foreach ( $moved as $rel ) {
			// A new file may carry the old file's clean name; that one is ours and is parked first.
			if ( file_exists( $uploads . '/' . $rel ) && ! in_array( $rel, $created, true ) ) {
				/* translators: %s: file */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( __( '%s exists again; not overwriting it.', 'wp-cleanup' ), $rel ) );
			}
			if ( ! is_file( $qdir . '/' . $rel ) ) {
				/* translators: %s: file */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( __( '%s is missing from the backup set.', 'wp-cleanup' ), $rel ) );
			}
		}

		foreach ( array_intersect( $created, $moved ) as $rel ) {
			$park( $rel );
		}
		// Old files back first: harmless while the other new images still exist.
		foreach ( $moved as $rel ) {
			if ( ! @rename( $qdir . '/' . $rel, $uploads . '/' . $rel ) ) { // phpcs:ignore
				/* translators: %s: file */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( __( 'Could not move %s back.', 'wp-cleanup' ), $rel ) );
			}
		}
		$backup->replay_sql( $n );
		// The new images are now unused; park them in the backup set instead of deleting.
		foreach ( array_diff( $created, $moved ) as $rel ) {
			$park( $rel );
		}
		clean_post_cache( $id );
		Media_Inventory::flush();
		/* translators: %d: files */
		return sprintf( __( '%d original file(s) restored.', 'wp-cleanup' ), count( $moved ) );
	}

	/**
	 * @param int $id Attachment id.
	 */
	private static function meta_hash( $id ) {
		global $wpdb;
		return md5( (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_wp_attachment_metadata'", $id ) ) );
	}

	/**
	 * @param string $path Absolute path.
	 * @return string Uploads-relative path.
	 */
	public static function rel( $path ) {
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		return ltrim( substr( wp_normalize_path( $path ), strlen( $uploads ) ), '/' );
	}
}
