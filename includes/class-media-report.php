<?php
/**
 * Library-wide image report: what would change and how much space it holds.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Report {

	const FILE = 'media-scan.json';

	/**
	 * @param int|null $limit   Stop after this many attachments (null = all).
	 * @param bool     $details Also find where images are used and which look alike.
	 * @return array Report.
	 */
	public static function build( $limit = null, $details = true ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore
		}
		$start  = microtime( true );
		$report = array(
			'created'   => time(),
			'duration'  => 0,
			'total'     => 0,
			'eligible'  => 0,
			'compliant' => 0,
			'skipped'   => 0,
			'reasons'   => array(),
			'files'     => 0,
			'bytes'     => 0,
			'strays'    => 0,
			'stray_b'   => 0,
			'items'     => array(),
			'settings'  => Media_Policy::settings(),
			'avif'      => Media_Policy::avif_supported(),
		);
		$offset = 0;
		$all    = array();
		do {
			$page             = Media_Inventory::image_ids( $offset, 200 );
			$report['total']  = $page['total'];
			$all              = array_merge( $all, $page['ids'] );
			foreach ( $page['ids'] as $id ) {
				if ( null !== $limit && count( $report['items'] ) + $report['compliant'] + $report['skipped'] >= $limit ) {
					break 2;
				}
				$reason = Media_Policy::skip_reason( $id );
				if ( $reason ) {
					++$report['skipped'];
					$report['reasons'][ $reason ] = ( isset( $report['reasons'][ $reason ] ) ? $report['reasons'][ $reason ] : 0 ) + 1;
					continue;
				}
				$inv = Media_Inventory::attachment( $id );
				if ( ! $inv ) {
					continue;
				}
				if ( $inv['compliant'] ) {
					++$report['compliant'];
					continue;
				}
				$strays = array_filter(
					$inv['files'],
					static function ( $f ) {
						return 'stray' === $f['role'];
					}
				);
				++$report['eligible'];
				$report['files']   += count( $inv['files'] );
				$report['bytes']   += $inv['bytes'];
				$report['strays']  += count( $strays );
				$report['stray_b'] += array_sum( wp_list_pluck( $strays, 'bytes' ) );
				$report['items'][]  = array(
					'id'     => $id,
					'file'   => ltrim( $inv['rel_dir'] . '/' . wp_basename( get_attached_file( $id, true ) ), '/' ),
					'title'  => get_the_title( $id ),
					'mime'   => get_post_mime_type( $id ),
					'files'  => count( $inv['files'] ),
					'strays' => count( $strays ),
					'bytes'  => $inv['bytes'],
					'width'  => (int) ( isset( $inv['meta']['width'] ) ? $inv['meta']['width'] : 0 ),
					'height' => (int) ( isset( $inv['meta']['height'] ) ? $inv['meta']['height'] : 0 ),
				);
			}
			$offset += 200;
			Media_Inventory::flush();
		} while ( $page['ids'] );

		if ( null === $limit && $details ) {
			$report = array_merge( $report, self::usage_and_likeness( $all, $start ) );
		}
		$report['duration'] = round( microtime( true ) - $start, 2 );
		return $report;
	}

	/**
	 * Where every image is used, which look alike, and which are not used at all.
	 *
	 * @param int[] $ids   All image attachment ids.
	 * @param float $start When the check started.
	 * @return array
	 */
	private static function usage_and_likeness( array $ids, $start ) {
		$usage = self::usage_data( $ids );
		return array_merge(
			$usage,
			self::similarity_data( $ids, $start, $usage['use_counts'], $usage['usage_checked_ids'] ),
			array( 'info' => self::info_all( $ids ) )
		);
	}

	/** Only scan WordPress references, without hashing images. */
	private static function usage_data( array $ids ) {
		$usage  = Media_Usage::build();
		$unused = array();
		foreach ( $ids as $id ) {
			if ( empty( $usage['counts'][ $id ] ) ) {
				$unused[] = $id;
			}
		}
		return array(
			'uses'              => $usage['uses'],
			'use_counts'        => $usage['counts'],
			'parents'           => $usage['parents'],
			'unused'            => $unused,
			'usage_checked_ids' => $ids,
			'usage_scanned'     => time(),
		);
	}

	/** Only compare the images, using cached hashes when the files are unchanged. */
	private static function similarity_data( array $ids, $start, array $counts, array $known_ids ) {
		/**
		 * Filter the seconds a check may spend hashing new images for the look-alike search.
		 * Hashes are cached, so a check that runs out of time continues on the next run.
		 *
		 * @param float $seconds Budget.
		 */
		$budget = (float) apply_filters( 'wp_cleanup_similarity_budget', max( 20.0, 200.0 - ( microtime( true ) - $start ) ) );
		$sim    = Media_Similarity::hash_all( $ids, $budget, true );
		$groups = Media_Similarity::groups( $sim['hashes'], $sim['colors'], $sim['ratios'] );
		$out    = array();
		foreach ( $groups as $group ) {
			$md5   = array_unique( array_intersect_key( $sim['md5'], array_flip( $group ) ) );
			$out[] = array(
				'ids'       => $group,
				'identical' => 1 === count( $md5 ),
			);
		}
		return array(
			'groups'             => $out,
			'duplicate_unused'  => self::duplicate_unused( $out, $counts, $known_ids ),
			'hashed'             => count( $sim['hashes'] ),
			'hash_left'          => $sim['pending'],
			'hash_fail'          => $sim['failed'],
			'similarity_scanned' => time(),
		);
	}

	/** Select unused copies while retaining a used copy, or the oldest copy. */
	private static function duplicate_unused( array $groups, array $counts, array $known_ids ) {
		$known = array_fill_keys( array_map( 'intval', $known_ids ), true );
		$out   = array();
		foreach ( $groups as $group ) {
			$keeper = $group['ids'][0];
			foreach ( $group['ids'] as $candidate ) {
				if ( isset( $known[ $candidate ] ) && ! empty( $counts[ $candidate ] ) ) {
					$keeper = $candidate;
					break;
				}
			}
			foreach ( $group['ids'] as $candidate ) {
				if ( $candidate !== $keeper && isset( $known[ $candidate ] ) && empty( $counts[ $candidate ] ) ) {
					$out[] = $candidate;
				}
			}
		}
		return $out;
	}

	/** Attachment summaries for a section-only refresh. */
	private static function info_all( array $ids ) {
		$info = array();
		foreach ( $ids as $id ) {
			$info[ $id ] = self::info( $id );
		}
		return $info;
	}

	/** List the current library without running conversion or usage analysis. */
	private static function all_image_ids() {
		$ids    = array();
		$offset = 0;
		do {
			$page    = Media_Inventory::image_ids( $offset, 200 );
			$ids     = array_merge( $ids, $page['ids'] );
			$offset += 200;
		} while ( $page['ids'] );
		return $ids;
	}

	/** Remove missing attachments from groups retained by a usage-only rescan. */
	private static function current_groups( array $groups, array $ids ) {
		$present = array_fill_keys( $ids, true );
		$out     = array();
		foreach ( $groups as $group ) {
			$group['ids'] = array_values( array_filter( $group['ids'], static function ( $id ) use ( $present ) { return isset( $present[ $id ] ); } ) );
			if ( count( $group['ids'] ) > 1 ) {
				$out[] = $group;
			}
		}
		return $out;
	}

	/** Rescan only where images are used and the unused list. */
	public static function refresh_usage() {
		$report = self::last();
		if ( ! $report ) {
			throw new \RuntimeException( __( 'Check the whole media library first.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Admin notices escape this message.
		}
		$ids    = self::all_image_ids();
		$report = array_merge( $report, self::usage_data( $ids ) );
		$report['info'] = self::info_all( $ids );
		$report['groups'] = self::current_groups( isset( $report['groups'] ) ? $report['groups'] : array(), $ids );
		$report['duplicate_unused'] = self::duplicate_unused( $report['groups'], $report['use_counts'], $ids );
		$report['inventory_stale'] = true;
		Storage::write_json( self::FILE, $report );
		return $report;
	}

	/** Rescan only look-alike groups; retain the last WordPress usage findings. */
	public static function refresh_similarity() {
		$report = self::last();
		if ( ! $report ) {
			throw new \RuntimeException( __( 'Check the whole media library first.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Admin notices escape this message.
		}
		$ids       = self::all_image_ids();
		$known_ids = isset( $report['usage_checked_ids'] ) ? $report['usage_checked_ids'] : array_map( 'intval', array_keys( isset( $report['info'] ) ? $report['info'] : array() ) );
		$report    = array_merge( $report, self::similarity_data( $ids, microtime( true ), isset( $report['use_counts'] ) ? $report['use_counts'] : array(), $known_ids ) );
		$report['info'] = self::info_all( $ids );
		$present = array_fill_keys( $ids, true );
		$report['unused'] = array_values( array_filter( isset( $report['unused'] ) ? $report['unused'] : array(), static function ( $id ) use ( $present ) { return isset( $present[ $id ] ); } ) );
		$report['inventory_stale'] = true;
		Storage::write_json( self::FILE, $report );
		return $report;
	}

	/**
	 * Small summary of an attachment for lists.
	 *
	 * @param int $id Attachment id.
	 * @return array{file:string,w:int,h:int,bytes:int,mime:string}
	 */
	private static function info( $id ) {
		$meta = wp_get_attachment_metadata( $id, true );
		$file = get_attached_file( $id, true );
		return array(
			'file'  => (string) get_post_meta( $id, '_wp_attached_file', true ),
			'w'     => isset( $meta['width'] ) ? (int) $meta['width'] : 0,
			'h'     => isset( $meta['height'] ) ? (int) $meta['height'] : 0,
			'bytes' => $file && is_file( $file ) ? (int) filesize( $file ) : 0,
			'mime'  => (string) get_post_mime_type( $id ),
		);
	}

	/**
	 * @return array Report, stored for the admin screen.
	 */
	public static function build_and_store() {
		$report = self::build();
		Storage::write_json( self::FILE, $report );
		return $report;
	}

	/**
	 * @return array|null
	 */
	public static function last() {
		return Storage::read_json( self::FILE );
	}

	/** Remove deleted attachments from every stored list without rehashing the library. */
	public static function forget_removed( array $ids ) {
		$report = self::last();
		if ( ! $report ) {
			return;
		}
		$drop = array_fill_keys( array_map( 'intval', $ids ), true );
		foreach ( array( 'items', 'unused' ) as $key ) {
			if ( ! isset( $report[ $key ] ) ) {
				continue;
			}
			$report[ $key ] = array_values( array_filter(
				$report[ $key ],
				static function ( $row ) use ( $key, $drop ) {
					$id = 'items' === $key ? (int) $row['id'] : (int) $row;
					return ! isset( $drop[ $id ] );
				}
			) );
		}
		foreach ( array( 'info', 'uses', 'use_counts', 'parents' ) as $key ) {
			if ( isset( $report[ $key ] ) ) {
				$report[ $key ] = array_diff_key( $report[ $key ], $drop );
			}
		}
		if ( isset( $report['usage_checked_ids'] ) ) {
			$report['usage_checked_ids'] = array_values( array_filter( $report['usage_checked_ids'], static function ( $id ) use ( $drop ) { return ! isset( $drop[ (int) $id ] ); } ) );
		}
		$groups = array();
		foreach ( isset( $report['groups'] ) ? $report['groups'] : array() as $group ) {
			$group['ids'] = array_values( array_filter( $group['ids'], static function ( $id ) use ( $drop ) { return ! isset( $drop[ (int) $id ] ); } ) );
			if ( count( $group['ids'] ) > 1 ) {
				$groups[] = $group;
			}
		}
		$report['groups'] = $groups;
		$known = isset( $report['usage_checked_ids'] ) ? $report['usage_checked_ids'] : array_map( 'intval', array_keys( isset( $report['info'] ) ? $report['info'] : array() ) );
		$report['duplicate_unused'] = self::duplicate_unused( $groups, isset( $report['use_counts'] ) ? $report['use_counts'] : array(), $known );
		$report['total'] = max( 0, (int) $report['total'] - count( $drop ) );
		$report['inventory_stale'] = true;
		Storage::write_json( self::FILE, $report );
	}

	/**
	 * Remove converted attachments from the stored report.
	 *
	 * @param int[] $ids Attachment ids.
	 */
	public static function forget( array $ids ) {
		$last = self::last();
		if ( ! $last ) {
			return;
		}
		$drop          = array_flip( array_map( 'intval', $ids ) );
		$last['items'] = array_values(
			array_filter(
				$last['items'],
				static function ( $i ) use ( $drop ) {
					return ! isset( $drop[ (int) $i['id'] ] );
				}
			)
		);
		Storage::write_json( self::FILE, $last );
	}
}
