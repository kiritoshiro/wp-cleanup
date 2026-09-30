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
		$usage = Media_Usage::build();
		/**
		 * Filter the seconds a check may spend hashing new images for the look-alike search.
		 * Hashes are cached, so a check that runs out of time continues on the next run.
		 *
		 * @param float $seconds Budget.
		 */
		$budget = (float) apply_filters( 'wp_cleanup_similarity_budget', max( 20.0, 200.0 - ( microtime( true ) - $start ) ) );
		$sim    = Media_Similarity::hash_all( $ids, $budget, true );
		$groups = Media_Similarity::groups( $sim['hashes'], $sim['colors'], $sim['ratios'] );

		$info = array();
		foreach ( $ids as $id ) {
			$info[ $id ] = self::info( $id );
		}
		$unused = array();
		foreach ( $ids as $id ) {
			if ( empty( $usage['counts'][ $id ] ) ) {
				$unused[] = $id;
			}
		}
		$out = array();
		$duplicate_unused = array();
		foreach ( $groups as $group ) {
			$keeper = $group[0];
			foreach ( $group as $candidate ) {
				if ( ! empty( $usage['counts'][ $candidate ] ) ) {
					$keeper = $candidate;
					break;
				}
			}
			foreach ( $group as $candidate ) {
				if ( $candidate !== $keeper && empty( $usage['counts'][ $candidate ] ) ) {
					$duplicate_unused[] = $candidate;
				}
			}
			$md5   = array_unique( array_intersect_key( $sim['md5'], array_flip( $group ) ) );
			$out[] = array(
				'ids'       => $group,
				'identical' => 1 === count( $md5 ),
			);
		}
		return array(
			'uses'       => $usage['uses'],
			'use_counts' => $usage['counts'],
			'parents'    => $usage['parents'],
			'unused'     => $unused,
			'duplicate_unused' => $duplicate_unused,
			'groups'     => $out,
			'info'       => $info,
			'hashed'     => count( $sim['hashes'] ),
			'hash_left'  => $sim['pending'],
			'hash_fail'  => $sim['failed'],
		);
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

	/** Force a new usage check after attachments have been removed. */
	public static function clear() {
		Storage::write_json( self::FILE, array() );
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
