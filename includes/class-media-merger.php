<?php
/**
 * Merge two verified look-alike attachments, keeping the chosen image.
 *
 * @package WPCleanup
 */
namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Merger {
	/** Replace one attachment with another after a fresh similarity and usage check. */
	public static function merge( $drop, $keep, Backup $backup, $identical = false ) {
		global $wpdb;
		$drop = (int) $drop;
		$keep = (int) $keep;
		if ( ! $drop || ! $keep || $drop === $keep || 'attachment' !== get_post_type( $drop ) || 'attachment' !== get_post_type( $keep ) ) {
			throw new \RuntimeException( __( 'Choose two different image attachments.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		if ( Media_Policy::skip_reason( $drop ) || Media_Policy::skip_reason( $keep ) ) {
			throw new \RuntimeException( __( 'One image cannot be managed by this tool.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		if ( $identical && ! self::identical_files( $drop, $keep ) ) {
			throw new \RuntimeException( __( 'These image files are no longer identical. No copy was removed.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped by the admin UI.
		}
		$hash = Media_Similarity::hash_all( array( $drop, $keep ), 30.0 );
		if ( ! isset( $hash['hashes'][ $drop ], $hash['hashes'][ $keep ] ) || ! Media_Similarity::alike( $hash['hashes'][ $drop ], $hash['hashes'][ $keep ], $hash['colors'][ $drop ], $hash['colors'][ $keep ], $hash['ratios'][ $drop ], $hash['ratios'][ $keep ] ) ) {
			throw new \RuntimeException( __( 'These images are no longer verified look-alikes.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		$old = Media_Inventory::attachment( $drop );
		$new = Media_Inventory::attachment( $keep );
		if ( ! $old || ! $new ) {
			throw new \RuntimeException( __( 'Both image files must exist on this server.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		$main = wp_basename( get_attached_file( $keep, true ) );
		$target = Reference_Rewriter::key( $new['rel_dir'], $main );
		$map = array();
		$needles = array();
		foreach ( $old['files'] as $name => $file ) {
			$key = Reference_Rewriter::key( $old['rel_dir'], $name );
			$map[ $key ] = $target;
			$needles[] = $key;
		}
		$rewriter = new Reference_Rewriter( $map, array( $target => isset( $new['meta']['width'] ) ? (int) $new['meta']['width'] : 0 ) );
		$changes = $rewriter->plan( $needles, $drop );
		$indexed = array();
		foreach ( $changes as $i => $change ) {
			$indexed[ self::change_key( $change ) ] = $i;
		}

		// IDs in editor markup, block attributes and gallery shortcodes.
		$like = '%' . $wpdb->esc_like( (string) $drop ) . '%';
		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->posts} WHERE post_content LIKE %s OR post_excerpt LIKE %s OR post_content_filtered LIKE %s", $like, $like, $like ), ARRAY_A );
		self::check_db();
		foreach ( (array) $posts as $row ) {
			foreach ( array( 'post_content', 'post_excerpt', 'post_content_filtered' ) as $column ) {
				$original = (string) $row[ $column ];
				$key = self::change_key( array( 'table' => $wpdb->posts, 'id' => (string) $row['ID'], 'column' => $column ) );
				$current = isset( $indexed[ $key ] ) ? $changes[ $indexed[ $key ] ]['new'] : $original;
				$updated = self::content_ids( $current, $drop, $keep );
				self::add_change( $changes, $indexed, $wpdb->posts, 'ID', $row, $column, $updated );
			}
		}

		// Numeric IDs in image fields, including featured images and serialized lists.
		foreach ( array(
			array( $wpdb->postmeta, 'meta_id', 'meta_key', 'meta_value' ),
			array( $wpdb->termmeta, 'meta_id', 'meta_key', 'meta_value' ),
			array( $wpdb->usermeta, 'umeta_id', 'meta_key', 'meta_value' ),
			array( $wpdb->options, 'option_id', 'option_name', 'option_value' ),
		) as $spec ) {
			list( $table, $pk, $key_col, $column ) = $spec;
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$column} LIKE %s", $like ), ARRAY_A ); // phpcs:ignore -- Table and column identifiers come from fixed wpdb target lists or a validated backup manifest.
			self::check_db();
			foreach ( (array) $rows as $row ) {
				$field = (string) $row[ $key_col ];
				if ( 'meta_value' === $column && isset( $row['post_id'] ) && (int) $row['post_id'] === $drop && in_array( $field, array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes' ), true ) ) {
					continue;
				}
				if ( 'option_value' === $column && ( 0 === strpos( $field, 'wpcu_' ) || 0 === strpos( $field, '_transient' ) || in_array( $field, array( 'cron', 'rewrite_rules' ), true ) ) ) {
					continue;
				}
				$key = self::change_key( array( 'table' => $table, 'id' => (string) $row[ $pk ], 'column' => $column ) );
				$current = isset( $indexed[ $key ] ) ? $changes[ $indexed[ $key ] ]['new'] : (string) $row[ $column ];
				$updated = self::field_ids( $current, $field, $drop, $keep );
				self::add_change( $changes, $indexed, $table, $pk, $row, $column, $updated );
			}
		}

		$n = $backup->add_item( array(
			'type' => 'media_merge_rewrite', 'id' => (string) $drop,
			'label' => wp_basename( get_attached_file( $drop, true ) ) . ' → ' . $main,
			'status' => 'used', 'owner' => '', 'count' => count( $changes ), 'bytes' => 0,
		) );
		$before = array();
		foreach ( $changes as $change ) {
			$k = $change['table'] . '|' . $change['id'];
			$before[ $k ] = array( $change['table'], $change['pk'], $change['id'], $change['row'] );
		}
		foreach ( $before as $entry ) {
			$backup->write_rows( $n, $entry[0], array( $entry[3] ), 'REPLACE' );
		}
		$backup->set_extra( $n, 'changes', array_map( static function ( $c ) {
			return array( 'table' => $c['table'], 'pk' => $c['pk'], 'id' => $c['id'], 'column' => $c['column'], 'new_hash' => md5( $c['new'] ) );
		}, $changes ) );
		try {
			Reference_Rewriter::apply( $changes );
			$row_hashes = array();
			foreach ( $before as $entry ) {
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $entry[0] . ' WHERE ' . $entry[1] . ' = %s', $entry[2] ), ARRAY_A ); // phpcs:ignore -- Identifiers come from fixed wpdb targets.
				self::check_db();
				if ( ! $row ) {
					throw new \RuntimeException( __( 'A referenced row disappeared during the merge.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
				}
				$row_hashes[] = array( 'table' => $entry[0], 'pk' => $entry[1], 'id' => $entry[2], 'hash' => md5( serialize( $row ) ) );
			}
			$backup->set_extra( $n, 'rows', $row_hashes );
			wp_cache_flush();
			$usage = Media_Usage::build();
			if ( ! empty( $usage['counts'][ $drop ] ) ) {
				throw new \RuntimeException( __( 'Some image references could not be safely redirected. All changes were rolled back.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
			$removed = Media_Remover::remove( $drop, $backup, $usage );
			if ( 'deleted' !== $removed['status'] ) {
				throw new \RuntimeException( $removed['message'] );
			}
			$partner = count( $backup->manifest['items'] ) - 1;
			$backup->set_extra( $n, 'merge_partner', $partner );
			$backup->set_extra( $partner, 'merge_partner', $n );
			$backup->set_result( $n, 'deleted', sprintf( __( '%d reference fields redirected to image #%d.', 'wp-cleanup' ), count( $changes ), $keep ) );
			Media_Report::forget_removed( array( $drop ) );
			return $backup->id;
		} catch ( \Exception $e ) {
			self::rollback( $changes );
			$backup->set_result( $n, 'failed', $e->getMessage() );
			throw $e;
		}
	}

	/** Verify identical main files from disk, without relying on the cached report. */
	public static function identical_files( $drop, $keep ) {
		$a = get_attached_file( (int) $drop, true );
		$b = get_attached_file( (int) $keep, true );
		if ( ! $a || ! $b || ! is_file( $a ) || ! is_file( $b ) ) {
			return false;
		}
		$hash = hash_file( 'sha256', $a );
		return $hash && hash_equals( $hash, (string) hash_file( 'sha256', $b ) );
	}

	private static function check_db() {
		global $wpdb;
		if ( $wpdb->last_error ) {
			throw new \RuntimeException( __( 'Could not check all image references.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
	}

	private static function change_key( array $c ) {
		return $c['table'] . '|' . $c['id'] . '|' . $c['column'];
	}

	private static function add_change( array &$changes, array &$indexed, $table, $pk, array $row, $column, $updated ) {
		$c = array( 'table' => $table, 'pk' => $pk, 'id' => (string) $row[ $pk ], 'column' => $column, 'old' => (string) $row[ $column ], 'new' => $updated, 'row' => $row );
		$key = self::change_key( $c );
		if ( isset( $indexed[ $key ] ) ) {
			$changes[ $indexed[ $key ] ]['new'] = $updated;
		} elseif ( $updated !== $c['old'] ) {
			$indexed[ $key ] = count( $changes );
			$changes[] = $c;
		}
	}

	private static function content_ids( $text, $drop, $keep ) {
		$text = preg_replace( '/\bwp-image-' . $drop . '\b/', 'wp-image-' . $keep, $text );
		$text = preg_replace_callback( '~<!--\s+wp:([a-z0-9/-]+)\s+(\{.*?\})\s+/?-->~s', static function ( $m ) use ( $drop, $keep ) {
			$attrs = json_decode( $m[2], true );
			if ( ! is_array( $attrs ) ) {
				return $m[0];
			}
			$core = in_array( preg_replace( '~^core/~', '', $m[1] ), array( 'image', 'cover', 'media-text', 'gallery', 'post-featured-image', 'site-logo' ), true );
			$changed = false;
			foreach ( $attrs as $name => &$value ) {
				if ( ( $core && in_array( $name, array( 'id', 'ids', 'mediaId' ), true ) ) || Media_Usage::is_id_key( $name ) ) {
					$value = self::replace_ids( $value, $drop, $keep, $changed );
				}
			}
			unset( $value );
			return $changed ? str_replace( $m[2], wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES ), $m[0] ) : $m[0];
		}, $text );
		return preg_replace_callback( '/\[gallery[^\]]*?\b(?:ids|include)\s*=\s*["\']?[\d,\s]+["\']?[^\]]*\]/', static function ( $m ) use ( $drop, $keep ) {
			return preg_replace_callback( '/(\b(?:ids|include)\s*=\s*["\']?)([\d,\s]+)/', static function ( $ids ) use ( $drop, $keep ) {
				return $ids[1] . preg_replace( '/(?<!\d)' . $drop . '(?!\d)/', (string) $keep, $ids[2] );
			}, $m[0], 1 );
		}, $text );
	}

	private static function field_ids( $text, $field, $drop, $keep ) {
		if ( is_serialized( $text ) ) {
			$value = @unserialize( trim( $text ), array( 'allowed_classes' => false ) );
			if ( is_object( $value ) ) {
				throw new \RuntimeException( __( 'An image ID is stored in a PHP object; merge it manually.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
			$changed = false;
			$value = self::replace_ids( $value, $drop, $keep, $changed, Media_Usage::is_id_key( $field ) || in_array( $field, array( 'site_icon', 'site_logo' ), true ) );
			return $changed ? serialize( $value ) : $text;
		}
		if ( ! Media_Usage::is_id_key( $field ) && ! in_array( $field, array( 'site_icon', 'site_logo' ), true ) ) {
			return $text;
		}
		$changed = false;
		return self::replace_ids( $text, $drop, $keep, $changed );
	}

	private static function replace_ids( $value, $drop, $keep, &$changed, $active = true ) {
		if ( is_object( $value ) ) {
			throw new \RuntimeException( __( 'An image ID is stored in a PHP object; merge it manually.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		if ( is_array( $value ) ) {
			foreach ( $value as $name => &$item ) {
				$child_active = is_string( $name ) ? ( Media_Usage::is_id_key( $name ) || ( $active && 'id' === $name ) ) : $active;
				$item = self::replace_ids( $item, $drop, $keep, $changed, $child_active );
			}
			unset( $item );
			return $value;
		}
		if ( $active && (string) $value === (string) $drop && ( is_int( $value ) || ctype_digit( (string) $value ) ) ) {
			$changed = true;
			return is_int( $value ) ? $keep : (string) $keep;
		}
		if ( $active && is_string( $value ) && preg_match( '/^\d+(\s*,\s*\d+)+$/', trim( $value ) ) ) {
			$new = preg_replace( '/(?<!\d)' . $drop . '(?!\d)/', (string) $keep, $value );
			$changed = $changed || $new !== $value;
			return $new;
		}
		return $value;
	}

	private static function rollback( array $changes ) {
		global $wpdb;
		foreach ( array_reverse( $changes ) as $c ) {
			$wpdb->query( $wpdb->prepare( "UPDATE {$c['table']} SET {$c['column']} = %s WHERE {$c['pk']} = %s AND {$c['column']} = %s", $c['old'], $c['id'], $c['new'] ) ); // phpcs:ignore -- Table and column identifiers come from fixed wpdb target lists or a validated backup manifest.
		}
		wp_cache_flush();
	}

	/** Allow only the tables, keys and columns this merger writes. */
	private static function safe_change( array $c ) {
		global $wpdb;
		$allowed = array(
			$wpdb->posts => array( 'ID', array( 'post_content', 'post_excerpt', 'post_content_filtered' ) ),
			$wpdb->postmeta => array( 'meta_id', array( 'meta_value' ) ),
			$wpdb->termmeta => array( 'meta_id', array( 'meta_value' ) ),
			$wpdb->usermeta => array( 'umeta_id', array( 'meta_value' ) ),
			$wpdb->options => array( 'option_id', array( 'option_value' ) ),
			$wpdb->comments => array( 'comment_ID', array( 'comment_content' ) ),
		);
		return isset( $c['table'], $c['pk'], $c['id'], $c['column'], $c['new_hash'], $allowed[ $c['table'] ] )
			&& $c['pk'] === $allowed[ $c['table'] ][0]
			&& in_array( $c['column'], $allowed[ $c['table'] ][1], true )
			&& ctype_digit( (string) $c['id'] )
			&& preg_match( '/^[a-f0-9]{32}$/', (string) $c['new_hash'] );
	}

	/** First allowed column, for validating a saved table and primary key. */
	private static function first_column( $table ) {
		global $wpdb;
		return $table === $wpdb->posts ? 'post_content' : ( $table === $wpdb->comments ? 'comment_content' : ( $table === $wpdb->options ? 'option_value' : 'meta_value' ) );
	}

	/** Restore reference fields only while their current values still match the merge. */
	public static function restore( Backup $backup, $n, array $item ) {
		global $wpdb;
		if ( ! get_post( (int) $item['id'] ) ) {
			throw new \RuntimeException( __( 'Restore the backed-up attachment before restoring its old references.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		$rows = isset( $item['extra']['rows'] ) ? $item['extra']['rows'] : array();
		foreach ( $rows as $saved ) {
			$probe = array( 'table' => $saved['table'], 'pk' => $saved['pk'], 'id' => $saved['id'], 'column' => self::first_column( $saved['table'] ), 'new_hash' => $saved['hash'] );
			if ( ! self::safe_change( $probe ) ) {
				throw new \RuntimeException( __( 'Backup reference rows are invalid.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . $saved['table'] . ' WHERE ' . $saved['pk'] . ' = %s', $saved['id'] ), ARRAY_A ); // phpcs:ignore -- Validated table and primary key.
			if ( ! $row || md5( serialize( $row ) ) !== $saved['hash'] ) {
				throw new \RuntimeException( __( 'A referenced row changed since this merge; it was not overwritten.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
		}
		$changes = isset( $item['extra']['changes'] ) ? $item['extra']['changes'] : array();
		foreach ( $changes as $c ) {
			if ( ! self::safe_change( $c ) ) {
				throw new \RuntimeException( __( 'Backup reference fields are invalid.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
			$value = $wpdb->get_var( $wpdb->prepare( "SELECT {$c['column']} FROM {$c['table']} WHERE {$c['pk']} = %s", $c['id'] ) ); // phpcs:ignore -- Table and column identifiers come from fixed wpdb target lists or a validated backup manifest.
			if ( null === $value || md5( (string) $value ) !== $c['new_hash'] ) {
				throw new \RuntimeException( __( 'A reference changed since this merge; it was not overwritten.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
		}
		$backup->replay_sql( $n );
		wp_cache_flush();
		return sprintf( __( '%d redirected reference fields restored.', 'wp-cleanup' ), count( $changes ) );
	}
}
