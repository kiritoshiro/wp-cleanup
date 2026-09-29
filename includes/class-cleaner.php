<?php
/**
 * Deletes selected items after re-verifying them and backing them up.
 *
 * Selections are re-classified from a fresh scan at deletion time, so a
 * stale screen or a hand-crafted request can never delete something that
 * is core or in use now.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Cleaner {

	const BATCH = 500;

	/**
	 * @param string[] $keys    "type|id" keys.
	 * @param array    $options allow_unknown, allow_inactive, dry_run, skip_backup (bool).
	 * @return array{backup:?string,deleted:array,refused:array,failed:array,planned:array}
	 */
	public function clean( array $keys, array $options = array() ) {
		$options = wp_parse_args(
			$options,
			array(
				'allow_unknown'  => false,
				'allow_inactive' => false,
				'dry_run'        => false,
				'skip_backup'    => false,
			)
		);

		$wanted = array();
		foreach ( $keys as $key ) {
			$parts = explode( '|', (string) $key, 2 );
			if ( 2 === count( $parts ) && in_array( $parts[0], Plugin::TYPES, true ) ) {
				$wanted[ $parts[0] . '|' . $parts[1] ] = $parts[0];
			}
		}

		$report = array(
			'backup'  => null,
			'deleted' => array(),
			'refused' => array(),
			'failed'  => array(),
			'planned' => array(),
		);
		if ( ! $wanted ) {
			return $report;
		}

		// Fresh classification of just the involved types.
		$scan    = ( new Scanner() )->scan( array_values( array_unique( $wanted ) ) );
		$current = array();
		foreach ( $scan['items'] as $item ) {
			$current[ $item['type'] . '|' . $item['id'] ] = $item;
		}

		$todo = array();
		foreach ( array_keys( $wanted ) as $key ) {
			if ( ! isset( $current[ $key ] ) ) {
				$report['refused'][] = array( 'key' => $key, 'label' => $key, 'message' => __( 'No longer exists.', 'wp-cleanup' ) );
				continue;
			}
			$item = $current[ $key ];
			if ( ! Plugin::is_deletable( $item['status'], $options ) ) {
				$labels              = Plugin::status_labels();
				$report['refused'][] = array(
					'key'     => $key,
					'label'   => $item['label'],
					/* translators: %s: status */
					'message' => sprintf( __( 'Refused: status is "%s".', 'wp-cleanup' ), $labels[ $item['status'] ] ) . ' ' . $item['reason'],
				);
				continue;
			}
			$todo[ $key ] = $item;
		}

		if ( $options['dry_run'] ) {
			$report['planned'] = array_values( $todo );
			return $report;
		}
		if ( ! $todo ) {
			return $report;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore
		}
		ignore_user_abort( true );

		$backup           = $options['skip_backup'] ? null : Backup::start();
		$report['backup'] = $backup ? $backup->id : null;

		foreach ( $todo as $key => $item ) {
			$n = $backup ? $backup->add_item( $item ) : 0;
			try {
				$message = $this->delete_item( $item, $backup, $n );
				if ( $backup ) {
					$backup->set_result( $n, 'deleted', $message );
				}
				$report['deleted'][] = array( 'key' => $key, 'label' => $item['label'], 'message' => $message );
			} catch ( \Exception $e ) {
				if ( $backup ) {
					$backup->set_result( $n, 'failed', $e->getMessage() );
				}
				$report['failed'][] = array( 'key' => $key, 'label' => $item['label'], 'message' => $e->getMessage() );
			}
		}

		wp_cache_flush();
		Scanner::forget( wp_list_pluck( $report['deleted'], 'key' ) );
		return $report;
	}

	/**
	 * @param array       $item   Scanner item.
	 * @param Backup|null $backup Backup set.
	 * @param int         $n      Backup section.
	 * @return string Result message.
	 * @throws \RuntimeException On failure.
	 */
	private function delete_item( array $item, $backup, $n ) {
		switch ( $item['type'] ) {
			case 'option':
				return $this->delete_options( array( $item['id'] ), $backup, $n );
			case 'transient':
				$timeout = preg_replace( '/^_(site_)?transient_/', '_$1transient_timeout_', $item['id'] );
				return $this->delete_options( array( $item['id'], $timeout ), $backup, $n );
			case 'table':
				return $this->delete_table( $item['id'], $backup, $n );
			case 'cron':
				return $this->delete_cron( $item['id'], $backup, $n );
			case 'meta':
				return $this->delete_meta( $item['meta_type'], $item['label'], $backup, $n );
			case 'post_type':
				return $this->delete_post_type( $item['id'], $backup, $n );
			case 'orphan':
				return $this->delete_orphans( $item['id'], $backup, $n );
			case 'file':
				return $this->quarantine_folder( $item['id'], $backup, $n );
		}
		throw new \RuntimeException( __( 'Unsupported item type.', 'wp-cleanup' ) );
	}

	/**
	 * @param string[]    $names  Option names.
	 * @param Backup|null $backup Backup.
	 * @param int         $n      Section.
	 */
	private function delete_options( array $names, $backup, $n ) {
		global $wpdb;
		$placeholders = implode( ',', array_fill( 0, count( $names ), '%s' ) );
		$rows         = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $names ), ARRAY_A ); // phpcs:ignore
		if ( $backup ) {
			$backup->write_rows( $n, $wpdb->options, (array) $rows );
		}
		foreach ( $names as $name ) {
			delete_option( $name );
		}
		$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name IN ($placeholders)", $names ) ); // phpcs:ignore
		if ( $left ) {
			throw new \RuntimeException( __( 'The option still exists after deletion (a filter may have blocked it).', 'wp-cleanup' ) );
		}
		/* translators: %d: rows */
		return sprintf( __( '%d row(s) deleted.', 'wp-cleanup' ), count( (array) $rows ) );
	}

	private function delete_table( $table, $backup, $n ) {
		global $wpdb;
		if ( 0 !== strpos( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) ) {
			throw new \RuntimeException( __( 'Unexpected table name.', 'wp-cleanup' ) );
		}
		if ( $backup ) {
			$backup->write_table( $n, $table );
		}
		if ( false === $wpdb->query( 'DROP TABLE `' . Backup::ident( $table ) . '`' ) ) { // phpcs:ignore
			throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'DROP TABLE failed.', 'wp-cleanup' ) );
		}
		return __( 'Table dropped.', 'wp-cleanup' );
	}

	private function delete_cron( $hook, $backup, $n ) {
		$events = array();
		foreach ( (array) _get_cron_array() as $timestamp => $hooks ) {
			if ( empty( $hooks[ $hook ] ) ) {
				continue;
			}
			foreach ( $hooks[ $hook ] as $event ) {
				$events[] = array(
					'hook'      => $hook,
					'timestamp' => (int) $timestamp,
					'schedule'  => isset( $event['schedule'] ) ? $event['schedule'] : false,
					'interval'  => isset( $event['interval'] ) ? $event['interval'] : null,
					'args'      => isset( $event['args'] ) ? $event['args'] : array(),
				);
			}
		}
		if ( $backup ) {
			$backup->write_json( $n, $events );
		}
		$result = wp_unschedule_hook( $hook, true );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
		/* translators: %d: events */
		return sprintf( __( '%d event(s) unscheduled.', 'wp-cleanup' ), count( $events ) );
	}

	private function delete_meta( $meta_type, $key, $backup, $n ) {
		global $wpdb;
		$table = _get_meta_table( $meta_type );
		if ( ! $table ) {
			throw new \RuntimeException( __( 'Unknown meta type.', 'wp-cleanup' ) );
		}
		$pk    = 'user' === $meta_type ? 'umeta_id' : 'meta_id';
		$total = 0;
		$last  = 0;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE meta_key = %s AND {$pk} > %d ORDER BY {$pk} LIMIT %d", $key, $last, self::BATCH ), ARRAY_A ); // phpcs:ignore
			if ( ! $rows ) {
				break;
			}
			if ( $backup ) {
				$backup->write_rows( $n, $table, $rows );
			}
			$ids  = array_map( 'intval', wp_list_pluck( $rows, $pk ) );
			$last = max( $ids );
			$wpdb->query( "DELETE FROM {$table} WHERE {$pk} IN (" . implode( ',', $ids ) . ')' ); // phpcs:ignore
			$total += count( $ids );
		} while ( count( $rows ) === self::BATCH );
		/* translators: %d: rows */
		return sprintf( __( '%d row(s) deleted.', 'wp-cleanup' ), $total );
	}

	private function delete_post_type( $post_type, $backup, $n ) {
		global $wpdb;
		if ( in_array( $post_type, Protected_Names::CORE_POST_TYPES, true ) || post_type_exists( $post_type ) ) {
			throw new \RuntimeException( __( 'Post type is core or registered.', 'wp-cleanup' ) );
		}
		$posts    = 0;
		$tt_ids   = array();
		$attempts = 0;
		while ( true ) {
			$ids = array_map( 'intval', $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s ORDER BY ID LIMIT %d", $post_type, self::BATCH ) ) );
			if ( ! $ids || ++$attempts > 100000 ) {
				break;
			}
			// Include their revisions.
			$in       = implode( ',', $ids );
			$revision = array_map( 'intval', $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'revision' AND post_parent IN ($in)" ) ); // phpcs:ignore
			$all      = implode( ',', array_merge( $ids, $revision ) );

			$comment_ids = array_map( 'intval', $wpdb->get_col( "SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID IN ($all)" ) ); // phpcs:ignore
			$c_in        = $comment_ids ? implode( ',', $comment_ids ) : '0';
			$rels        = $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ($all)", ARRAY_A ); // phpcs:ignore

			if ( $backup ) {
				$backup->write_rows( $n, $wpdb->posts, (array) $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE ID IN ($all)", ARRAY_A ) ); // phpcs:ignore
				$backup->write_rows( $n, $wpdb->postmeta, (array) $wpdb->get_results( "SELECT * FROM {$wpdb->postmeta} WHERE post_id IN ($all)", ARRAY_A ) ); // phpcs:ignore
				$backup->write_rows( $n, $wpdb->term_relationships, (array) $rels );
				if ( $comment_ids ) {
					$backup->write_rows( $n, $wpdb->comments, (array) $wpdb->get_results( "SELECT * FROM {$wpdb->comments} WHERE comment_ID IN ($c_in)", ARRAY_A ) ); // phpcs:ignore
					$backup->write_rows( $n, $wpdb->commentmeta, (array) $wpdb->get_results( "SELECT * FROM {$wpdb->commentmeta} WHERE comment_id IN ($c_in)", ARRAY_A ) ); // phpcs:ignore
				}
			}

			foreach ( (array) $rels as $rel ) {
				$tt_ids[ (int) $rel['term_taxonomy_id'] ] = true;
			}
			if ( $comment_ids ) {
				$wpdb->query( "DELETE FROM {$wpdb->commentmeta} WHERE comment_id IN ($c_in)" ); // phpcs:ignore
				$wpdb->query( "DELETE FROM {$wpdb->comments} WHERE comment_ID IN ($c_in)" ); // phpcs:ignore
			}
			$wpdb->query( "DELETE FROM {$wpdb->term_relationships} WHERE object_id IN ($all)" ); // phpcs:ignore
			$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE post_id IN ($all)" ); // phpcs:ignore
			$deleted = $wpdb->query( "DELETE FROM {$wpdb->posts} WHERE ID IN ($all)" ); // phpcs:ignore
			if ( ! $deleted ) {
				throw new \RuntimeException( $wpdb->last_error ? $wpdb->last_error : __( 'Could not delete posts.', 'wp-cleanup' ) );
			}
			foreach ( array_merge( $ids, $revision ) as $id ) {
				clean_post_cache( $id );
			}
			$posts += count( $ids );
		}
		self::recount_terms( array_keys( $tt_ids ) );
		if ( $backup ) {
			$backup->set_extra( $n, 'tt_ids', array_keys( $tt_ids ) );
		}
		/* translators: %d: posts */
		return sprintf( __( '%d post(s) deleted with their meta, comments and term links.', 'wp-cleanup' ), $posts );
	}

	/**
	 * @param int[] $tt_ids Term taxonomy ids.
	 */
	public static function recount_terms( array $tt_ids ) {
		global $wpdb;
		if ( ! $tt_ids ) {
			return;
		}
		$rows = $wpdb->get_results( 'SELECT term_taxonomy_id, taxonomy FROM ' . $wpdb->term_taxonomy . ' WHERE term_taxonomy_id IN (' . implode( ',', array_map( 'intval', $tt_ids ) ) . ')' ); // phpcs:ignore
		$by   = array();
		foreach ( (array) $rows as $row ) {
			$by[ $row->taxonomy ][] = (int) $row->term_taxonomy_id;
		}
		foreach ( $by as $taxonomy => $ids ) {
			if ( taxonomy_exists( $taxonomy ) ) {
				wp_update_term_count_now( $ids, $taxonomy );
			}
		}
	}

	private function delete_orphans( $kind, $backup, $n ) {
		global $wpdb;
		$kinds = Scanner::orphan_kinds();
		if ( ! isset( $kinds[ $kind ] ) ) {
			throw new \RuntimeException( __( 'Unknown orphan kind.', 'wp-cleanup' ) );
		}
		$def   = $kinds[ $kind ];
		$total = 0;

		if ( 'term_relationships' === $kind ) {
			// Composite key; collect all pairs first.
			$pairs = $wpdb->get_results( "SELECT t.* FROM {$def['table']} t WHERE {$def['where']}", ARRAY_A ); // phpcs:ignore
			foreach ( array_chunk( (array) $pairs, self::BATCH ) as $chunk ) {
				if ( $backup ) {
					$backup->write_rows( $n, $def['table'], $chunk );
				}
				$tuples = array();
				foreach ( $chunk as $row ) {
					$tuples[] = '(' . (int) $row['object_id'] . ',' . (int) $row['term_taxonomy_id'] . ')';
				}
				$total += (int) $wpdb->query( "DELETE FROM {$def['table']} WHERE (object_id, term_taxonomy_id) IN (" . implode( ',', $tuples ) . ')' ); // phpcs:ignore
			}
		} else {
			// Collect ids up front: deleting some rows (e.g. transient timeouts) changes which others match.
			$ids = array_map( 'intval', $wpdb->get_col( "SELECT t.{$def['pk']} FROM {$def['table']} t WHERE {$def['where']}" ) ); // phpcs:ignore
			foreach ( array_chunk( $ids, self::BATCH ) as $chunk ) {
				$in = implode( ',', $chunk );
				if ( $backup ) {
					$backup->write_rows( $n, $def['table'], (array) $wpdb->get_results( "SELECT * FROM {$def['table']} WHERE {$def['pk']} IN ($in)", ARRAY_A ) ); // phpcs:ignore
				}
				$total += (int) $wpdb->query( "DELETE FROM {$def['table']} WHERE {$def['pk']} IN ($in)" ); // phpcs:ignore
			}
		}
		/* translators: %d: rows */
		return sprintf( __( '%d row(s) deleted.', 'wp-cleanup' ), $total );
	}

	private function quarantine_folder( $id, $backup, $n ) {
		$path = Scanner::folder_path( $id );
		if ( ! $path || ! is_dir( $path ) || is_link( $path ) ) {
			throw new \RuntimeException( __( 'Folder not found.', 'wp-cleanup' ) );
		}
		if ( ! $backup ) {
			// Without a backup set there is nowhere to quarantine; refuse rather than hard-delete.
			throw new \RuntimeException( __( 'Folders are only moved into a backup set; run with a backup.', 'wp-cleanup' ) );
		}
		$target = $backup->quarantine_dir( $n ) . '/' . basename( $path );
		if ( ! @rename( $path, $target ) ) { // phpcs:ignore
			throw new \RuntimeException( __( 'Could not move the folder (is it on another filesystem or locked?).', 'wp-cleanup' ) );
		}
		return __( 'Folder moved into the backup set.', 'wp-cleanup' );
	}
}
