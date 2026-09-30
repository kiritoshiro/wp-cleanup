<?php
/**
 * Converts one image attachment to the two-size AVIF policy.
 *
 * Order of operations, each step reversible until the end:
 *   1. Encode the new full AVIF (and small AVIF) next to the old files.
 *   2. Verify both files decode with the expected dimensions and MIME type.
 *   3. Plan every URL rewrite in content/meta/options (nothing written yet).
 *   4. Write before-images of every value that will change into the backup set.
 *   5. Update the attachment and the rewritten rows.
 *   6. Move every old file into the backup set.
 * Any failure rolls back what was done so far.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Converter {

	/** Attachment meta keys whose before-images are kept. */
	const META_KEYS = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', Media_Policy::ALPS_FLAG, '_alps_original_delete_after', '_alps_retained_original' );

	/**
	 * @param int         $id      Attachment id.
	 * @param Backup|null $backup  Backup set (required unless dry run).
	 * @param bool        $dry_run Only report.
	 * @return array{id:int,status:string,message:string,files:int,bytes_before:int,bytes_after:int}
	 */
	public static function convert( $id, $backup = null, $dry_run = false ) {
		$id     = (int) $id;
		$s      = Media_Policy::settings();
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
			$result['status']  = 'compliant';
			$result['message'] = __( 'Already one AVIF within the size limits.', 'wp-cleanup' );
			return $result;
		}
		if ( $dry_run ) {
			$result['status'] = 'planned';
			/* translators: %d: files */
			$result['message'] = sprintf( __( '%d file(s) would be replaced by at most two AVIF files.', 'wp-cleanup' ), count( $inv['files'] ) );
			return $result;
		}
		if ( ! $backup instanceof Backup ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( __( 'Images are only converted into a backup set.', 'wp-cleanup' ) );
		}
		if ( ! Media_Policy::avif_supported() ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( __( 'This server cannot write AVIF images.', 'wp-cleanup' ) );
		}

		$created = array();
		$moved   = array();
		$n       = null;
		$applied = false;
		try {
			// 1–2. Encode and verify.
			list( $full, $small, $rotated ) = self::encode( $id, $inv, $s, $created );

			// 3. Map every old file to the file that replaces it, and plan rewrites.
			$full_name  = wp_basename( $full['path'] );
			$small_name = $small ? wp_basename( $small['path'] ) : null;
			$map        = array();
			$needles    = array();
			$remove     = array();
			foreach ( $inv['files'] as $name => $file ) {
				if ( $name === $full_name ) {
					continue; // The source is reused as the new full image.
				}
				$remove[ $name ] = $file;
				$target          = $full_name;
				if ( $small_name && in_array( $file['role'], array( 'size', 'stray', 'backup' ), true ) && $file['width'] && $file['height'] && max( $file['width'], $file['height'] ) <= $s['small_max'] ) {
					$target = $small_name;
				}
				$map[ Reference_Rewriter::key( $inv['rel_dir'], $name ) ] = Reference_Rewriter::key( $inv['rel_dir'], $target );

				$needles[ Reference_Rewriter::key( $inv['rel_dir'], Media_Inventory::base_name( $name ) ) ] = true;
			}
			$widths = array( Reference_Rewriter::key( $inv['rel_dir'], $full_name ) => $full['width'] );
			if ( $small ) {
				$widths[ Reference_Rewriter::key( $inv['rel_dir'], $small_name ) ] = $small['width'];
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
			self::update_attachment( $id, $inv, $full, $small, $rotated, $s );

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

			$backup->set_extra( $n, 'created', array_map( array( __CLASS__, 'rel' ), $created ) );
			$backup->set_extra( $n, 'moved', $moved );
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

			$after = (int) filesize( $full['path'] ) + ( $small ? (int) filesize( $small['path'] ) : 0 );
			$backup->set_result( $n, 'deleted', sprintf( '%d file(s) replaced; %d reference(s) rewritten', count( $remove ), count( $changes ) ) );

			$result['status']      = 'converted';
			$result['bytes_after'] = $after;
			$result['reference_changes'] = $reference_changes;
			$result['backed_up'] = $moved;
			$result['message']     = sprintf(
				/* translators: 1: files, 2: size before, 3: size after, 4: references */
				__( '%1$d file(s), %2$s → %3$s; %4$d reference(s) rewritten.', 'wp-cleanup' ),
				count( $inv['files'] ),
				size_format( $inv['bytes'], 1 ),
				size_format( $after, 1 ),
				count( $changes )
			);
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

	/** Explain each rewritten row and its old and new image paths. */
	private static function reference_changes( array $changes, array $map ) {
		$out = array();
		foreach ( $changes as $change ) {
			$where = $change['table'] . ' #' . $change['id'] . ' · ' . $change['column'];
			foreach ( array( 'post_title', 'option_name', 'meta_key' ) as $label ) {
				if ( ! empty( $change['row'][ $label ] ) ) {
					$where .= ' (' . wp_html_excerpt( (string) $change['row'][ $label ], 100 ) . ')';
					break;
				}
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
				$out[] = array( 'where' => $where, 'from' => __( 'Image width descriptors', 'wp-cleanup' ), 'to' => __( 'Actual AVIF widths', 'wp-cleanup' ) );
			}
		}
		return $out;
	}

	/**
	 * @return array{0:array,1:array|null,2:bool} full, small, rotated.
	 * @throws \RuntimeException On failure.
	 */
	private static function encode( $id, array $inv, array $s, array &$created ) {
		wp_raise_memory_limit( 'image' );
		$attached = wp_normalize_path( get_attached_file( $id, true ) );
		$meta     = $inv['meta'];
		// Best-quality source: the pre-"-scaled" original, unless the image was edited in WordPress.
		$source = $attached;
		if ( ! $inv['edited'] && ! empty( $meta['original_image'] ) && is_file( $inv['dir'] . '/' . wp_basename( $meta['original_image'] ) ) ) {
			$source = $inv['dir'] . '/' . wp_basename( $meta['original_image'] );
		}

		$editor = wp_get_image_editor( $source );
		if ( is_wp_error( $editor ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( $editor->get_error_message() );
		}
		$rotated = method_exists( $editor, 'maybe_exif_rotate' ) ? $editor->maybe_exif_rotate() : false;
		if ( is_wp_error( $rotated ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( $rotated->get_error_message() );
		}
		$size   = $editor->get_size();
		$resize = max( $size['width'], $size['height'] ) > $s['full_max'];

		if ( 'image/avif' === wp_get_image_mime( $source ) && ! $resize && true !== $rotated ) {
			$full = array(
				'path'   => $source,
				'width'  => $size['width'],
				'height' => $size['height'],
			);
		} else {
			if ( $resize && is_wp_error( $err = $editor->resize( $s['full_max'], $s['full_max'], false ) ) ) { // phpcs:ignore
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( $err->get_error_message() );
			}
			$full = self::save( $editor, $inv['dir'], $inv['base'] . '.avif', $created );
		}
		self::verify( $full );

		$small = null;
		if ( max( $full['width'], $full['height'] ) > $s['small_max'] ) {
			$editor = wp_get_image_editor( $full['path'] );
			if ( is_wp_error( $editor ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( $editor->get_error_message() );
			}
			$err = $editor->resize( $s['small_max'], $s['small_max'], false );
			if ( is_wp_error( $err ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( $err->get_error_message() );
			}
			$dims  = $editor->get_size();
			$small = self::save( $editor, $inv['dir'], $inv['base'] . '-' . $dims['width'] . 'x' . $dims['height'] . '.avif', $created );
			self::verify( $small );
		}
		return array( $full, $small, true === $rotated );
	}

	/**
	 * @param \WP_Image_Editor $editor  Editor.
	 * @param string           $dir     Folder.
	 * @param string           $name    Desired file name.
	 * @param array            $created Created paths (by reference).
	 * @return array{path:string,width:int,height:int}
	 */
	private static function save( $editor, $dir, $name, array &$created ) {
		// A JPEG or PNG with the same stem is expected; only an existing AVIF must force a new name.
		$name  = sanitize_file_name( $name );
		$dest  = $dir . '/' . ( file_exists( $dir . '/' . $name ) ? wp_unique_filename( $dir, $name ) : $name );
		$saved = $editor->save( $dest, 'image/avif' );
		if ( is_wp_error( $saved ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( $saved->get_error_message() );
		}
		$created[] = wp_normalize_path( $saved['path'] );
		return array(
			'path'   => wp_normalize_path( $saved['path'] ),
			'width'  => (int) $saved['width'],
			'height' => (int) $saved['height'],
		);
	}

	/**
	 * @param array $file path, width, height.
	 * @throws \RuntimeException When the file is not a readable AVIF of that size.
	 */
	private static function verify( array $file ) {
		clearstatcache( true, $file['path'] );
		$info = is_file( $file['path'] ) && filesize( $file['path'] ) > 0 ? wp_getimagesize( $file['path'] ) : false;
		$verified = $info && 'image/avif' === ( isset( $info['mime'] ) ? $info['mime'] : '' ) && (int) $info[0] === (int) $file['width'] && (int) $info[1] === (int) $file['height'];
		// Some valid AVIF variants are not understood by PHP's header parser. Decode the image before refusing it.
		if ( ! $verified && is_file( $file['path'] ) && 'image/avif' === wp_get_image_mime( $file['path'] ) ) {
			$editor = wp_get_image_editor( $file['path'] );
			if ( ! is_wp_error( $editor ) ) {
				$size = $editor->get_size();
				$verified = is_array( $size ) && (int) $size['width'] === (int) $file['width'] && (int) $size['height'] === (int) $file['height'];
			}
		}
		if ( ! $verified ) {
			/* translators: %s: file */
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( sprintf( __( 'The new AVIF file %s could not be decoded with the expected dimensions. The original image was kept.', 'wp-cleanup' ), wp_basename( $file['path'] ) ) );
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

	private static function update_attachment( $id, array $inv, array $full, $small, $rotated, array $s ) {
		global $wpdb;
		$meta             = $inv['meta'];
		// Compute the uploads-relative path ourselves: _wp_relative_upload_path() compares raw strings,
		// so on Windows "C:/…" (normalized) never matches a "C:\…" basedir and an absolute path is stored.
		$relative         = self::rel( $full['path'] );
		$meta['file']     = $relative;
		$meta['width']    = $full['width'];
		$meta['height']   = $full['height'];
		$meta['filesize'] = (int) filesize( $full['path'] );
		$meta['sizes']    = array();
		if ( $small ) {
			$meta['sizes'][ $s['small_name'] ] = array(
				'file'      => wp_basename( $small['path'] ),
				'width'     => $small['width'],
				'height'    => $small['height'],
				'mime-type' => 'image/avif',
				'filesize'  => (int) filesize( $small['path'] ),
			);
		}
		unset( $meta['original_image'] );
		if ( $rotated ) {
			$meta['image_meta']['orientation'] = 1;
		}

		update_attached_file( $id, $relative ); // Already relative, so it is stored as given.
		if ( wp_normalize_path( (string) get_attached_file( $id, true ) ) !== $full['path'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( __( 'The new file path could not be stored correctly.', 'wp-cleanup' ) );
		}
		$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/avif' ), array( 'ID' => $id ) );
		delete_post_meta( $id, '_wp_attachment_backup_sizes' );
		if ( $s['set_flag'] ) {
			update_post_meta( $id, Media_Policy::ALPS_FLAG, 1 );
		}
		wp_update_attachment_metadata( $id, $meta );
		clean_post_cache( $id );
	}

	private static function rollback( $id, $backup, $n, $applied, array $created, array $moved, array $inv ) {
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
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
		foreach ( $created as $path ) {
			if ( is_file( $path ) && ! isset( $inv['files'][ wp_basename( $path ) ] ) ) {
				@unlink( $path ); // phpcs:ignore
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
		$moved = (array) ( isset( $extra['moved'] ) ? $extra['moved'] : array() );
		foreach ( $moved as $rel ) {
			if ( file_exists( $uploads . '/' . $rel ) ) {
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

		// Old files back first: harmless while the AVIFs still exist.
		foreach ( $moved as $rel ) {
			if ( ! @rename( $qdir . '/' . $rel, $uploads . '/' . $rel ) ) { // phpcs:ignore
				/* translators: %s: file */
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
				throw new \RuntimeException( sprintf( __( 'Could not move %s back.', 'wp-cleanup' ), $rel ) );
			}
		}
		$backup->replay_sql( $n );
		// The AVIFs are now unused; park them in the backup set instead of deleting.
		$parked = $backup->dir . '/files/' . (int) $n . '/created';
		foreach ( (array) ( isset( $extra['created'] ) ? $extra['created'] : array() ) as $rel ) {
			if ( is_file( $uploads . '/' . $rel ) ) {
				wp_mkdir_p( dirname( $parked . '/' . $rel ) );
				@rename( $uploads . '/' . $rel, $parked . '/' . $rel ); // phpcs:ignore
			}
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
