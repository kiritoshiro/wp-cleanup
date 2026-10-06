<?php
/**
 * Quarantine an unused image attachment and its database rows for restoration.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Remover {
	/** Back up an unused identical copy only while its chosen keeper is still used. */
	public static function remove_duplicate( $id, $keep, Backup $backup ) {
		$id = (int) $id;
		$keep = (int) $keep;
		$usage = Media_Usage::build(); // An incomplete scan throws; nothing is removed.
		if ( ! $keep || $keep === $id || empty( $usage['counts'][ $keep ] ) || Media_Policy::skip_reason( $keep ) || ! Media_Merger::identical_files( $id, $keep ) ) {
			return array( 'id' => $id, 'status' => 'refused', 'message' => __( 'An identical keeper must still be used. This copy was left untouched.', 'wp-cleanup' ) );
		}
		return self::remove( $id, $backup, $usage );
	}

	/**
	 * Remove one attachment only after a fresh usage check.
	 *
	 * @param int    $id     Attachment id.
	 * @param Backup $backup Backup set.
	 * @param array  $usage  Fresh Media_Usage::build() result.
	 * @return array Result for the admin screen.
	 */
	public static function remove( $id, Backup $backup, array $usage ) {
		global $wpdb;
		$id     = (int) $id;
		$result = array( 'id' => $id, 'status' => 'refused', 'message' => '' );
		if ( $id < 1 || 'attachment' !== get_post_type( $id ) || 0 !== strpos( (string) get_post_mime_type( $id ), 'image/' ) ) {
			$result['message'] = __( 'The image attachment no longer exists.', 'wp-cleanup' );
			return $result;
		}
		if ( ! empty( $usage['counts'][ $id ] ) ) {
			$result['message'] = __( 'The image is now used somewhere.', 'wp-cleanup' );
			return $result;
		}
		$reason = Media_Policy::skip_reason( $id );
		if ( $reason ) {
			$result['message'] = $reason;
			return $result;
		}
		$inv = Media_Inventory::attachment( $id );
		if ( ! $inv || ! $inv['files'] ) {
			$result['message'] = __( 'The attachment files could not be inventoried.', 'wp-cleanup' );
			return $result;
		}
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		$root    = realpath( $uploads );
		if ( ! $root || 0 !== strpos( wp_normalize_path( realpath( $inv['dir'] ) . '/' ), wp_normalize_path( $root ) . '/' ) ) {
			$result['message'] = __( 'The image is outside the local uploads folder.', 'wp-cleanup' );
			return $result;
		}
		$paths = array();
		foreach ( $inv['files'] as $name => $file ) {
			$path = $inv['dir'] . '/' . $name;
			if ( is_link( $path ) || ! is_file( $path ) || 0 !== strpos( wp_normalize_path( realpath( $path ) ), wp_normalize_path( $root ) . '/' ) ) {
				$result['message'] = __( 'An image file is missing or points outside uploads.', 'wp-cleanup' );
				return $result;
			}
			$paths[] = ltrim( $inv['rel_dir'] . '/' . $name, '/' );
		}
		if ( self::shared_files( $id, $inv['rel_dir'], $paths ) ) {
			$result['message'] = __( 'Another attachment shares an image file; nothing was removed.', 'wp-cleanup' );
			return $result;
		}

		$n       = null;
		$moved   = array();
		$deleted = false;
		try {
			$n = $backup->add_item( array(
				'type' => 'media_delete', 'id' => (string) $id,
				'label' => wp_basename( get_attached_file( $id, true ) ),
				'status' => 'unused', 'owner' => '', 'count' => count( $paths ), 'bytes' => $inv['bytes'],
			) );
			foreach ( array( $wpdb->posts => 'ID', $wpdb->postmeta => 'post_id', $wpdb->term_relationships => 'object_id', $wpdb->comments => 'comment_post_ID' ) as $table => $key ) {
				$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . Backup::ident( $table ) . '` WHERE `' . Backup::ident( $key ) . '` = %d', $id ), ARRAY_A ); // phpcs:ignore -- table and column names come from wpdb.
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( __( 'Could not read attachment rows for backup.', 'wp-cleanup' ) );
				}
				$backup->write_rows( $n, $table, (array) $rows );
			}
			$tt_ids = $wpdb->get_col( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_relationships} WHERE object_id = %d", $id ) );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( __( 'Could not read attachment terms for backup.', 'wp-cleanup' ) );
			}
			$backup->set_extra( $n, 'tt_ids', array_map( 'intval', (array) $tt_ids ) );
			$comment_ids = $wpdb->get_col( $wpdb->prepare( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d", $id ) );
			if ( $wpdb->last_error ) {
				throw new \RuntimeException( __( 'Could not read attachment comments for backup.', 'wp-cleanup' ) );
			}
			foreach ( (array) $comment_ids as $comment_id ) {
				$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id = %d", $comment_id ), ARRAY_A );
				if ( $wpdb->last_error ) {
					throw new \RuntimeException( __( 'Could not read attachment comment metadata for backup.', 'wp-cleanup' ) );
				}
				$backup->write_rows( $n, $wpdb->commentmeta, (array) $rows );
			}
			$backup->set_extra( $n, 'moved', $paths );
			$qdir = $backup->quarantine_dir( $n ) . '/orig';
			foreach ( $paths as $rel ) {
				$dst = $qdir . '/' . $rel;
				wp_mkdir_p( dirname( $dst ) );
				if ( ! apply_filters( 'wp_cleanup_media_move_file', true, $rel ) || ! @rename( $uploads . '/' . $rel, $dst ) ) { // phpcs:ignore -- reversible file move.
					throw new \RuntimeException( sprintf( /* translators: %s: file path */ __( 'Could not move %s into the backup set.', 'wp-cleanup' ), $rel ) );
				}
				$moved[] = $rel;
			}
			if ( ! wp_delete_attachment( $id, true ) ) {
				throw new \RuntimeException( __( 'WordPress refused to delete the attachment.', 'wp-cleanup' ) );
			}
			$deleted = true;
			$backup->set_result( $n, 'deleted', sprintf( /* translators: %d: number of files */ __( '%d file(s) moved into the backup set.', 'wp-cleanup' ), count( $moved ) ) );
			Media_Inventory::flush();
			$result['status']  = 'deleted';
			$result['message'] = __( 'Attachment removed; files are in the backup set.', 'wp-cleanup' );
		} catch ( \Exception $e ) {
			if ( $deleted && null !== $n ) {
				try {
					$backup->replay_sql( $n );
				} catch ( \Exception $ignored ) {
					// The backup remains available for manual recovery.
				}
			}
			foreach ( array_reverse( $moved ) as $rel ) {
				@rename( $backup->dir . '/files/' . $n . '/orig/' . $rel, $uploads . '/' . $rel ); // phpcs:ignore -- rollback.
			}
			if ( null !== $n ) {
				$backup->set_result( $n, 'failed', $e->getMessage() );
			}
			$result['status']  = 'failed';
			$result['message'] = $e->getMessage();
		}
		return $result;
	}

	/** Refuse files also named in another attachment's metadata. */
	private static function shared_files( $id, $rel_dir, array $paths ) {
		global $wpdb;
		$wanted = array_fill_keys( array_map( 'strtolower', $paths ), true );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND post_id <> %d AND meta_value LIKE %s", $id, ( '' === $rel_dir ? '' : $wpdb->esc_like( $rel_dir . '/' ) ) . '%' ) );
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( __( 'Could not check whether image files are shared.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; admin notices escape output.
		}
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		foreach ( (array) $rows as $row ) {
			$main = wp_normalize_path( (string) $row->meta_value );
			if ( 0 === strpos( $main, $uploads . '/' ) ) {
				$main = substr( $main, strlen( $uploads ) + 1 );
			}
			if ( dirname( $main ) !== ( '' === $rel_dir ? '.' : $rel_dir ) ) {
				continue;
			}
			$names = array( wp_basename( $main ) );
			$meta  = wp_get_attachment_metadata( (int) $row->post_id, true );
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
				if ( isset( $wanted[ strtolower( ltrim( $rel_dir . '/' . wp_basename( $name ), '/' ) ) ] ) ) {
					return true;
				}
			}
		}
		return false;
	}

	/** Restore rows and files, without replacing a recreated attachment. */
	public static function restore( Backup $backup, $n, array $item ) {
		$id = (int) $item['id'];
		if ( get_post( $id ) ) {
			throw new \RuntimeException( __( 'The attachment ID exists again; not overwriting it.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; admin notices escape output.
		}
		$uploads = wp_normalize_path( wp_upload_dir( null, false )['basedir'] );
		$moved   = (array) ( isset( $item['extra']['moved'] ) ? $item['extra']['moved'] : array() );
		foreach ( $moved as $rel ) {
			if ( file_exists( $uploads . '/' . $rel ) || ! is_file( $backup->dir . '/files/' . (int) $n . '/orig/' . $rel ) ) {
				throw new \RuntimeException( __( 'A backed-up file is missing or its original path is occupied.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; admin notices escape output.
			}
		}
		foreach ( $moved as $rel ) {
			wp_mkdir_p( dirname( $uploads . '/' . $rel ) );
			if ( ! @rename( $backup->dir . '/files/' . (int) $n . '/orig/' . $rel, $uploads . '/' . $rel ) ) { // phpcs:ignore -- restore.
				throw new \RuntimeException( __( 'Could not move a backed-up file into uploads.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; admin notices escape output.
			}
		}
		$backup->replay_sql( $n );
		clean_post_cache( $id );
		wp_cache_flush();
		Media_Inventory::flush();
		return sprintf( /* translators: %d: number of image files */ __( '%d image file(s) and the attachment restored.', 'wp-cleanup' ), count( $moved ) );
	}
}
