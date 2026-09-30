<?php
/**
 * Move an unregistered uploads image into a restorable backup set.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Orphan_Files {
	/** Move one file after a fresh catalog and database reference check. */
	public static function remove( $rel, Backup $backup, array $catalog ) {
		$rel = wp_normalize_path( (string) $rel );
		$result = array( 'path' => $rel, 'status' => 'refused', 'message' => '' );
		$known = wp_list_pluck( isset( $catalog['unregistered'] ) ? $catalog['unregistered'] : array(), 'path' );
		if ( ! in_array( $rel, $known, true ) || ! self::valid_path( $rel ) ) {
			$result['message'] = __( 'The file is not in the current unregistered images list.', 'wp-cleanup' );
			return $result;
		}
		$resolved_root = realpath( wp_upload_dir( null, false )['basedir'] );
		if ( ! $resolved_root ) {
			$result['message'] = __( 'The uploads folder could not be resolved.', 'wp-cleanup' );
			return $result;
		}
		$root = wp_normalize_path( $resolved_root );
		$path = $root . '/' . $rel;
		$real = realpath( $path );
		if ( ! $real || is_link( $path ) || 0 !== strpos( wp_normalize_path( $real ), $root . '/' ) || ! is_file( $path ) ) {
			$result['message'] = __( 'The file is missing or points outside uploads.', 'wp-cleanup' );
			return $result;
		}
		if ( self::referenced( wp_basename( $rel ) ) ) {
			$result['message'] = __( 'A WordPress database value mentions this file name; it was kept.', 'wp-cleanup' );
			return $result;
		}
		$n = $backup->add_item( array(
			'type' => 'media_file', 'id' => $rel, 'label' => $rel,
			'status' => 'unregistered', 'owner' => '', 'count' => 1, 'bytes' => (int) filesize( $path ),
		) );
		$dest = $backup->quarantine_dir( $n ) . '/orig/' . $rel;
		$backup->set_extra( $n, 'moved', array( $rel ) );
		wp_mkdir_p( dirname( $dest ) );
		if ( ! apply_filters( 'wp_cleanup_media_move_file', true, $rel ) || ! @rename( $path, $dest ) ) { // phpcs:ignore -- Restorable file move.
			$backup->set_result( $n, 'failed', __( 'Could not move the file into the backup set.', 'wp-cleanup' ) );
			$result['status'] = 'failed';
			$result['message'] = __( 'Could not move the file into the backup set.', 'wp-cleanup' );
			return $result;
		}
		$backup->set_result( $n, 'deleted', __( 'Unregistered file moved into the backup set.', 'wp-cleanup' ) );
		$result['status'] = 'deleted';
		$result['message'] = __( 'File moved into the backup set.', 'wp-cleanup' );
		return $result;
	}

	/** Restore a file without replacing anything new at its old path. */
	public static function restore( Backup $backup, $n, array $item ) {
		$rel = (string) $item['id'];
		if ( ! self::valid_path( $rel ) ) {
			throw new \RuntimeException( __( 'The saved file path is invalid.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text restore result is escaped by the admin UI.
		}
		$resolved_root = realpath( wp_upload_dir( null, false )['basedir'] );
		if ( ! $resolved_root ) {
			throw new \RuntimeException( __( 'The uploads folder could not be resolved.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the admin UI.
		}
		$root = wp_normalize_path( $resolved_root );
		$target = $root . '/' . $rel;
		$source = $backup->dir . '/files/' . (int) $n . '/orig/' . $rel;
		if ( file_exists( $target ) || ! is_file( $source ) ) {
			throw new \RuntimeException( __( 'The old path is occupied or the backed-up file is missing.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text restore result is escaped by the admin UI.
		}
		wp_mkdir_p( dirname( $target ) );
		$parent = realpath( dirname( $target ) );
		if ( ! $parent || 0 !== strpos( wp_normalize_path( $parent ) . '/', $root . '/' ) ) {
			throw new \RuntimeException( __( 'The restore folder points outside uploads.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the admin UI.
		}
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore -- Restorable file move.
			throw new \RuntimeException( __( 'Could not restore the file into uploads.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text restore result is escaped by the admin UI.
		}
		return __( 'Unregistered image file restored.', 'wp-cleanup' );
	}

	/** Refuse traversal and absolute paths even when they appear in a stale report. */
	private static function valid_path( $rel ) {
		return '' !== $rel && '/' !== $rel[0] && false === strpos( $rel, ':' ) && ! preg_match( '#(^|/)\.\.(/|$)#', $rel );
	}

	/** Any WordPress text reference makes file-only cleanup fail closed. */
	private static function referenced( $basename ) {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( $basename ) . '%';
		$columns = array(
			array( $wpdb->posts, 'post_content' ),
			array( $wpdb->posts, 'post_excerpt' ),
			array( $wpdb->postmeta, 'meta_value' ),
			array( $wpdb->options, 'option_value' ),
			array( $wpdb->comments, 'comment_content' ),
			array( $wpdb->termmeta, 'meta_value' ),
			array( $wpdb->usermeta, 'meta_value' ),
		);
		foreach ( $columns as $column ) {
			$found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM `' . Backup::ident( $column[0] ) . '` WHERE `' . Backup::ident( $column[1] ) . '` LIKE %s LIMIT 1', $like ) ); // phpcs:ignore -- Table and column names are fixed WP core names.
			if ( $wpdb->last_error || $found ) {
				return true;
			}
		}
		return false;
	}
}
