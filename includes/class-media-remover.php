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
		// Files another attachment still names stay where they are; only this row lets go of them.
		try {
			$shared = Media_Owners::shared( $id, $paths );
		} catch ( \Exception $e ) {
			$result['message'] = $e->getMessage();
			return $result;
		}
		$paths = array_values( array_diff( $paths, $shared ) );
		$bytes = 0;
		foreach ( $paths as $rel ) {
			$bytes += $inv['files'][ wp_basename( $rel ) ]['bytes'];
		}

		$n       = null;
		$moved   = array();
		$deleted = false;
		// WordPress deletes an attachment's files with its row; never the shared ones.
		$keep_abs = array();
		foreach ( $shared as $rel ) {
			$keep_abs[ strtolower( $uploads . '/' . $rel ) ] = true;
		}
		$protect = static function ( $file ) use ( $keep_abs ) {
			return isset( $keep_abs[ strtolower( wp_normalize_path( (string) $file ) ) ] ) ? '' : $file;
		};
		try {
			$n = $backup->add_item( array(
				'type' => 'media_delete', 'id' => (string) $id,
				'label' => wp_basename( get_attached_file( $id, true ) ),
				'status' => 'unused', 'owner' => '', 'count' => count( $paths ), 'bytes' => $bytes,
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
			if ( $shared ) {
				$backup->set_extra( $n, 'kept_shared', $shared );
			}
			$qdir = $backup->quarantine_dir( $n ) . '/orig';
			foreach ( $paths as $rel ) {
				$dst = $qdir . '/' . $rel;
				wp_mkdir_p( dirname( $dst ) );
				if ( ! apply_filters( 'wp_cleanup_media_move_file', true, $rel ) || ! @rename( $uploads . '/' . $rel, $dst ) ) { // phpcs:ignore -- reversible file move.
					throw new \RuntimeException( sprintf( /* translators: %s: file path */ __( 'Could not move %s into the backup set.', 'wp-cleanup' ), $rel ) );
				}
				$moved[] = $rel;
			}
			add_filter( 'wp_delete_file', $protect, PHP_INT_MAX );
			try {
				$gone = wp_delete_attachment( $id, true );
			} finally {
				remove_filter( 'wp_delete_file', $protect, PHP_INT_MAX );
			}
			if ( ! $gone ) {
				throw new \RuntimeException( __( 'WordPress refused to delete the attachment.', 'wp-cleanup' ) );
			}
			$deleted = true;
			$message = sprintf( /* translators: %d: number of files */ __( '%d file(s) moved into the backup set.', 'wp-cleanup' ), count( $moved ) );
			if ( $shared ) {
				$message .= ' ' . sprintf( /* translators: %d: number of files */ __( '%d file(s) another attachment still uses were left in place.', 'wp-cleanup' ), count( $shared ) );
			}
			$backup->set_result( $n, 'deleted', $message );
			Media_Inventory::flush();
			$result['status']  = 'deleted';
			$result['message'] = $shared ? __( 'Attachment removed; its own files are in the backup set and files another attachment uses were left in place.', 'wp-cleanup' ) : __( 'Attachment removed; files are in the backup set.', 'wp-cleanup' );
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
