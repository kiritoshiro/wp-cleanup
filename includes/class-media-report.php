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
	 * @param int|null $limit Stop after this many attachments (null = all).
	 * @return array Report.
	 */
	public static function build( $limit = null ) {
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
		do {
			$page             = Media_Inventory::image_ids( $offset, 200 );
			$report['total']  = $page['total'];
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
		$report['duration'] = round( microtime( true ) - $start, 2 );
		return $report;
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
