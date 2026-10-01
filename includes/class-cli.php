<?php
/**
 * WP-CLI: wp cleanup <scan|clean|backups|restore|delete-backup>
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

/**
 * Find and remove data left behind by removed plugins and themes.
 */
final class CLI {

	/**
	 * Scan for leftovers.
	 *
	 * ## OPTIONS
	 *
	 * [--type=<type>]
	 * : Comma-separated types: option, transient, table, cron, meta, post_type, orphan, file.
	 *
	 * [--status=<status>]
	 * : Comma-separated statuses to show, or "all". Default: orphaned,safe,unknown,inactive.
	 *
	 * [--owner=<slug>]
	 * : Only items attributed to this plugin/theme slug.
	 *
	 * [--fresh]
	 * : Re-read all plugin/theme code instead of using the cached index.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml, count, ids. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cleanup scan
	 *     wp cleanup scan --type=option,table --status=orphaned
	 *     wp cleanup scan --owner=wordpress-seo --format=ids
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Associative.
	 */
	public function scan( $args, $assoc ) {
		$items  = $this->filtered( $assoc );
		$format = \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' );
		if ( 'ids' === $format ) {
			\WP_CLI::line( implode( ' ', array_map( array( __CLASS__, 'key' ), $items ) ) );
			return;
		}
		$rows = array_map(
			static function ( $i ) {
				return array(
					'key'        => self::key( $i ),
					'status'     => $i['status'],
					'confidence' => $i['confidence'],
					'owner'      => $i['owner'] ? $i['owner'] : ( $i['group'] ? $i['group'] . '*' : '' ),
					'count'      => $i['count'],
					'size'       => $i['bytes'] ? size_format( $i['bytes'], 1 ) : '',
					'autoload'   => null === $i['autoload'] ? '' : ( $i['autoload'] ? 'yes' : 'no' ),
					'reason'     => $i['reason'],
				);
			},
			$items
		);
		\WP_CLI\Utils\format_items( $format, $rows, array( 'key', 'status', 'confidence', 'owner', 'count', 'size', 'autoload', 'reason' ) );
	}

	/**
	 * Back up and delete leftovers.
	 *
	 * Pass item keys (from `wp cleanup scan --format=ids`) or select by filters.
	 * Everything is re-checked against a fresh scan; core and in-use items are always refused.
	 *
	 * ## OPTIONS
	 *
	 * [<key>...]
	 * : Item keys, e.g. option|wpseo_titles or table|wp_wfconfig.
	 *
	 * [--type=<type>]
	 * : Select by type (comma-separated).
	 *
	 * [--status=<status>]
	 * : Select by status. Default when selecting by filter: orphaned,safe.
	 *
	 * [--owner=<slug>]
	 * : Select items attributed to this plugin/theme slug.
	 *
	 * [--allow-unknown]
	 * : Allow items with an unknown owner.
	 *
	 * [--allow-inactive]
	 * : Allow items of installed-but-inactive plugins/themes.
	 *
	 * [--dry-run]
	 * : Show what would be deleted.
	 *
	 * [--skip-backup]
	 * : Do not write a backup set (folders are never deleted without one). Dangerous.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cleanup clean --status=orphaned --dry-run
	 *     wp cleanup clean --owner=wordfence --yes
	 *     wp cleanup clean "option|oldplugin_settings" --allow-unknown
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Associative.
	 */
	public function clean( $args, $assoc ) {
		if ( $args ) {
			$keys = $args;
		} else {
			if ( empty( $assoc['type'] ) && empty( $assoc['status'] ) && empty( $assoc['owner'] ) ) {
				\WP_CLI::error( 'Pass item keys, or select with --type, --status and/or --owner.' );
			}
			if ( empty( $assoc['status'] ) ) {
				$assoc['status'] = 'orphaned,safe';
			}
			$keys = array_map( array( __CLASS__, 'key' ), $this->filtered( $assoc ) );
		}
		if ( ! $keys ) {
			\WP_CLI::success( 'Nothing to clean.' );
			return;
		}

		$policy = array(
			'allow_unknown'  => (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'allow-unknown', false ),
			'allow_inactive' => (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'allow-inactive', false ),
		);
		$cleaner = new Cleaner();
		$plan    = $cleaner->clean( $keys, $policy + array( 'dry_run' => true ) );
		foreach ( $plan['refused'] as $row ) {
			\WP_CLI::warning( $row['label'] . ': ' . $row['message'] );
		}
		if ( ! $plan['planned'] ) {
			\WP_CLI::success( 'Nothing deletable.' );
			return;
		}
		foreach ( $plan['planned'] as $item ) {
			\WP_CLI::line( sprintf( '  %-10s %-9s %s', $item['type'], $item['status'], $item['label'] ) );
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false ) ) {
			\WP_CLI::success( sprintf( 'Dry run: %d item(s) would be deleted.', count( $plan['planned'] ) ) );
			return;
		}
		$skip_backup = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'skip-backup', false );
		\WP_CLI::confirm( sprintf( 'Delete %d item(s)%s?', count( $plan['planned'] ), $skip_backup ? ' WITHOUT a backup' : '' ), $assoc );

		$report = $cleaner->clean( array_map( array( __CLASS__, 'key' ), $plan['planned'] ), $policy + array( 'skip_backup' => $skip_backup ) );
		foreach ( $report['failed'] as $row ) {
			\WP_CLI::warning( 'Failed: ' . $row['label'] . ': ' . $row['message'] );
		}
		foreach ( $report['refused'] as $row ) {
			\WP_CLI::warning( 'Refused: ' . $row['label'] . ': ' . $row['message'] );
		}
		$message = sprintf( 'Deleted %d item(s).', count( $report['deleted'] ) );
		if ( $report['backup'] ) {
			$message .= ' Backup set: ' . $report['backup'] . ' (restore with: wp cleanup restore ' . $report['backup'] . ')';
		}
		if ( $report['failed'] ) {
			\WP_CLI::error( $message, false );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( $message );
	}

	/**
	 * List backup sets.
	 *
	 * [--format=<format>]
	 * : table, json, csv, yaml. Default: table.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Associative.
	 */
	public function backups( $args, $assoc ) {
		$rows = array();
		foreach ( Backup::all() as $b ) {
			$rows[] = array(
				'id'       => $b['id'],
				'created'  => gmdate( 'Y-m-d H:i:s', $b['created'] ),
				'user'     => $b['user'],
				'items'    => count( $b['items'] ),
				'size'     => size_format( $b['bytes'], 1 ),
				'restored' => $b['restored'] ? gmdate( 'Y-m-d H:i:s', $b['restored'] ) : '',
			);
		}
		\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ), $rows, array( 'id', 'created', 'user', 'items', 'size', 'restored' ) );
	}

	/**
	 * Restore a backup set.
	 *
	 * <id>
	 * : Backup set id.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Associative.
	 */
	public function restore( $args, $assoc ) {
		$backup = Backup::open( $args[0] );
		if ( ! $backup ) {
			\WP_CLI::error( 'Backup set not found.' );
		}
		\WP_CLI::confirm( 'Restore backup set ' . $backup->id . '?', $assoc );
		$failed = 0;
		foreach ( $backup->restore() as $row ) {
			if ( $row['ok'] ) {
				\WP_CLI::log( '✓ ' . $row['label'] . ': ' . $row['message'] );
			} else {
				++$failed;
				\WP_CLI::warning( $row['label'] . ': ' . $row['message'] );
			}
		}
		if ( $failed ) {
			\WP_CLI::error( sprintf( '%d item(s) could not be restored.', $failed ) );
		}
		\WP_CLI::success( 'Restored.' );
	}

	/**
	 * Permanently delete a backup set.
	 *
	 * <id>
	 * : Backup set id.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @subcommand delete-backup
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Associative.
	 */
	public function delete_backup( $args, $assoc ) {
		$backup = Backup::open( $args[0] );
		if ( ! $backup ) {
			\WP_CLI::error( 'Backup set not found.' );
		}
		\WP_CLI::confirm( 'Permanently delete backup set ' . $backup->id . '?', $assoc );
		if ( ! $backup->delete() ) {
			\WP_CLI::error( 'Could not delete the backup set.' );
		}
		\WP_CLI::success( 'Deleted.' );
	}

	/**
	 * Convert images to AVIF with an optional JPEG fallback and move old sizes into a backup set.
	 *
	 * ## OPTIONS
	 *
	 * <action>
	 * : status | convert | repair
	 * ---
	 * options:
	 *   - status
	 *   - convert
	 *   - repair
	 * ---
	 *
	 * [--ids=<ids>]
	 * : Comma-separated attachment ids (default: every eligible image).
	 *
	 * [--limit=<n>]
	 * : Convert at most this many.
	 *
	 * [--dry-run]
	 * : Report what would change.
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * [--format=<format>]
	 * : For status: table, json, csv. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cleanup images status
	 *     wp cleanup images convert --limit=20 --dry-run
	 *     wp cleanup images convert --ids=123,456 --yes
	 *     wp cleanup images repair --dry-run
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Associative.
	 */
	public function images( $args, $assoc ) {
		$s = Media_Policy::settings();
		if ( 'repair' === $args[0] ) {
			$ids    = ! empty( $assoc['ids'] ) ? array_filter( array_map( 'intval', explode( ',', $assoc['ids'] ) ) ) : Media_Files::all_ids();
			$issues = Media_Integrity::scan( $ids );
			if ( ! $issues ) {
				\WP_CLI::success( "Every image's saved data matches its files." );
				return;
			}
			$fixable = 0;
			foreach ( $issues as $id => $list ) {
				foreach ( $list as $issue ) {
					\WP_CLI::log( sprintf( '#%d %s %s', $id, $issue['fixable'] ? 'fix   ' : 'MANUAL', $issue['message'] ) );
					$fixable += $issue['fixable'] ? 1 : 0;
				}
			}
			if ( \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false ) || ! $fixable ) {
				\WP_CLI::success( sprintf( '%d problem(s) can be repaired automatically.', $fixable ) );
				return;
			}
			\WP_CLI::confirm( sprintf( 'Repair %d problem(s)? Only WordPress data changes; a backup set is written.', $fixable ), $assoc );
			$backup = Backup::start();
			$done   = 0;
			foreach ( array_keys( $issues ) as $id ) {
				$r = Media_Integrity::repair( $id, $backup );
				if ( 'repaired' === $r['status'] ) {
					++$done;
				} elseif ( 'failed' === $r['status'] ) {
					\WP_CLI::warning( '#' . $id . ': ' . $r['message'] );
				}
			}
			Media_Report::refresh_issues( array_keys( $issues ) );
			\WP_CLI::success( sprintf( 'Repaired %d image(s). Backup set: %s', $done, $backup->id ) );
			return;
		}
		if ( 'status' === $args[0] ) {
			$r = Media_Report::build_and_store();
			\WP_CLI::log( sprintf( 'Policy: %s, AVIF full <= %dpx + "%s" <= %dpx%s. AVIF support: %s.', $s['jpeg_fallback'] ? sprintf( 'one JPEG <= %dpx (quality %d)', $s['jpeg_max'], $s['jpeg_quality'] ) : 'AVIF only', $s['full_max'], $s['small_name'], $s['small_max'], $s['set_flag'] ? ', ALPS flag on' : '', $r['avif'] ? 'yes' : 'NO' ) );
			\WP_CLI::log( sprintf( '%d images: %d to convert (%d files, %s, incl. %d stray files %s), %d already compliant, %d skipped.', $r['total'], $r['eligible'], $r['files'], size_format( $r['bytes'], 1 ), $r['strays'], size_format( $r['stray_b'], 1 ), $r['compliant'], $r['skipped'] ) );
			if ( isset( $r['file_catalog'] ) ) {
				$c = $r['file_catalog'];
				\WP_CLI::log( sprintf( 'Image files on the server: %d (%s): %d in the Media Library (%s), %d without an attachment (%s).', $c['library_files'] + $c['unregistered_count'], size_format( $c['library_bytes'] + $c['unregistered_bytes'], 1 ), $c['library_files'], size_format( $c['library_bytes'], 1 ), $c['unregistered_count'], size_format( $c['unregistered_bytes'], 1 ) ) );
				foreach ( $c['formats'] as $format => $f ) {
					\WP_CLI::log( sprintf( '  %-5s %6d files  %s', $format, $f['files'], size_format( $f['bytes'], 1 ) ) );
				}
			}
			if ( ! empty( $r['issues'] ) ) {
				\WP_CLI::warning( sprintf( 'Image data problems in %d image(s); run "wp cleanup images repair --dry-run" to see them.', count( $r['issues'] ) ) );
			}
			foreach ( $r['reasons'] as $reason => $count ) {
				\WP_CLI::log( sprintf( '  skipped %d: %s', $count, $reason ) );
			}
			if ( isset( $r['unused'], $r['groups'] ) ) {
				\WP_CLI::log( sprintf( 'Not used anywhere: %d. Look-alike groups: %d (%d images)%s.', count( $r['unused'] ), count( $r['groups'] ), array_sum( array_map( 'count', wp_list_pluck( $r['groups'], 'ids' ) ) ), $r['hash_left'] ? sprintf( '; %d images not compared yet, run again to continue', $r['hash_left'] ) : '' ) );
				foreach ( $r['groups'] as $group ) {
					$files = array();
					foreach ( $group['ids'] as $id ) {
						$files[] = '#' . $id . ' ' . $r['info'][ $id ]['file'] . ( empty( $r['use_counts'][ $id ] ) ? ' (not used)' : ' (used ' . $r['use_counts'][ $id ] . 'x)' );
					}
					\WP_CLI::log( '  ' . ( $group['identical'] ? 'identical: ' : 'look alike: ' ) . implode( ' | ', $files ) );
				}
			}
			if ( $r['items'] ) {
				$rows = array_map(
					static function ( $i ) {
						return array(
							'id'     => $i['id'],
							'file'   => $i['file'],
							'size'   => $i['width'] . 'x' . $i['height'],
							'files'  => $i['files'],
							'strays' => $i['strays'],
							'bytes'  => size_format( $i['bytes'], 1 ),
						);
					},
					$r['items']
				);
				\WP_CLI\Utils\format_items( \WP_CLI\Utils\get_flag_value( $assoc, 'format', 'table' ), $rows, array( 'id', 'file', 'size', 'files', 'strays', 'bytes' ) );
			}
			return;
		}

		$ids = ! empty( $assoc['ids'] )
			? array_filter( array_map( 'intval', explode( ',', $assoc['ids'] ) ) )
			: wp_list_pluck( Media_Report::build( null, false )['items'], 'id' );
		if ( ! empty( $assoc['limit'] ) ) {
			$ids = array_slice( $ids, 0, max( 1, (int) $assoc['limit'] ) );
		}
		if ( ! $ids ) {
			\WP_CLI::success( 'Nothing to convert.' );
			return;
		}
		$dry = (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
		if ( ! $dry ) {
			if ( $s['jpeg_fallback'] && ! Media_Policy::jpeg_supported() ) {
				\WP_CLI::error( 'This server cannot write JPEG images.' );
			}
			if ( ! $s['jpeg_fallback'] && ! Media_Policy::avif_supported() ) {
				\WP_CLI::error( 'AVIF-only conversion requires server AVIF support.' );
			}
			\WP_CLI::confirm( sprintf( 'Convert %d image(s)? Old files are moved into a backup set.', count( $ids ) ), $assoc );
		}

		$backup = $dry ? null : Backup::start();
		$tally  = array( 'converted' => 0, 'failed' => 0, 'before' => 0, 'after' => 0 );
		foreach ( $ids as $id ) {
			if ( ! $dry ) {
				// WP-CLI has no gateway time limit, but an earlier web request may still hold or have abandoned this image.
				$check = Media_Guard::preflight( $id, true );
				if ( in_array( $check['state'], array( 'busy', 'interrupted' ), true ) ) {
					\WP_CLI::log( sprintf( '#%d %-9s %s', $id, $check['state'], $check['message'] ) );
					++$tally['failed'];
					continue;
				}
			}
			$r = Media_Converter::convert( $id, $backup, $dry );
			\WP_CLI::log( sprintf( '#%d %-9s %s', $id, $r['status'], $r['message'] ) );
			if ( 'converted' === $r['status'] ) {
				++$tally['converted'];
				$tally['before'] += $r['bytes_before'];
				$tally['after']  += $r['bytes_after'];
			} elseif ( 'failed' === $r['status'] ) {
				++$tally['failed'];
			}
		}
		if ( $dry ) {
			\WP_CLI::success( 'Dry run finished.' );
			return;
		}
		wp_cache_flush();
		Media_Report::forget( $ids );
		Media_Report::refresh_issues( $ids );
		$msg = sprintf(
			'Converted %d, failed %d. %s of old files moved into backup set %s; new image files use %s. Delete that set (wp cleanup delete-backup %s) once the site looks right to free the space.',
			$tally['converted'],
			$tally['failed'],
			size_format( $tally['before'], 1 ),
			$backup->id,
			size_format( $tally['after'], 1 ),
			$backup->id
		);
		if ( $tally['failed'] ) {
			\WP_CLI::warning( $msg );
			\WP_CLI::halt( 1 );
		}
		\WP_CLI::success( $msg );
	}

	/**
	 * @param array $item Item.
	 */
	private static function key( array $item ) {
		return $item['type'] . '|' . $item['id'];
	}

	/**
	 * @param array $assoc Flags.
	 * @return array Items.
	 */
	private function filtered( array $assoc ) {
		$types = ! empty( $assoc['type'] ) ? array_map( 'trim', explode( ',', $assoc['type'] ) ) : null;
		if ( $types && array_diff( $types, Plugin::TYPES ) ) {
			\WP_CLI::error( 'Unknown type. Use: ' . implode( ', ', Plugin::TYPES ) );
		}
		$status   = ! empty( $assoc['status'] ) ? $assoc['status'] : 'orphaned,safe,unknown,inactive';
		$statuses = 'all' === $status ? null : array_map( 'trim', explode( ',', $status ) );
		$owner    = ! empty( $assoc['owner'] ) ? strtolower( $assoc['owner'] ) : null;

		$scanner = new Scanner( (bool) \WP_CLI\Utils\get_flag_value( $assoc, 'fresh', false ) );
		$result  = $scanner->scan( $types );
		if ( ! empty( $result['incomplete'] ) ) {
			\WP_CLI::warning( 'Some code could not be fully indexed (' . implode( ', ', $result['incomplete'] ) . '); nothing is marked orphaned until it can.' );
		}
		if ( ! $types ) {
			Storage::write_json( Scanner::RESULT_FILE, $result );
		}
		return array_values(
			array_filter(
				$result['items'],
				static function ( $i ) use ( $statuses, $owner ) {
					return ( ! $statuses || in_array( $i['status'], $statuses, true ) )
						&& ( ! $owner || strtolower( $i['owner_slug'] ) === $owner );
				}
			)
		);
	}
}
