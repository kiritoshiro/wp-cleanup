<?php
/**
 * Finds images that look alike: the same photo uploaded several times, also
 * when resized, re-compressed or saved in another format.
 *
 * Each image gets a 64-bit difference hash ("dHash"): the picture is shrunk to
 * 9×8 grey pixels and every bit records whether a pixel is brighter than its
 * right neighbour. Two images count as look-alikes only when all of these agree:
 *   - their hashes differ in at most MAX_DISTANCE bits (same structure),
 *   - their average colours are close (flat graphics and gradients can share a
 *     structure while being different images),
 *   - their width/height ratios match (a crop is a different picture).
 * Groups form around one anchor image that every member must match, so a chain
 * of small differences never joins unrelated pictures.
 *
 * Results are cached in the plugin's data folder, keyed by file size and time,
 * so only new or changed images are read on the next check.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Similarity {

	const CACHE = 'media-hashes.json';

	/** Bumped when what is cached per image changes. */
	const FORMAT = 2;

	/** Bits two hashes may differ in and still count as the same picture. */
	const MAX_DISTANCE = 5;

	/** Largest difference per colour channel (0–255) of the average colours. */
	const MAX_COLOR = 24;

	/** Largest relative difference of the width/height ratios. */
	const MAX_RATIO = 0.03;

	/** @var int[] Bits set per byte value. */
	private static $pop = array();

	/**
	 * Analyse the given images, within a time budget.
	 *
	 * @param int[] $ids    Attachment ids.
	 * @param float $budget Seconds to spend on images not in the cache.
	 * @param bool  $prune  $ids is the whole library: forget cached images not in it.
	 * @return array{hashes:array<int,string>,colors:array<int,string>,ratios:array<int,float>,md5:array<int,string>,pending:int,failed:int}
	 */
	public static function hash_all( array $ids, $budget = 60.0, $prune = false ) {
		$cache   = Storage::read_json( self::CACHE );
		$cache   = is_array( $cache ) ? $cache : array();
		$fresh   = $prune ? array() : $cache;
		$hashes  = array();
		$colors  = array();
		$ratios  = array();
		$md5     = array();
		$pending = 0;
		$failed  = 0;
		$until   = microtime( true ) + (float) $budget;

		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$file = self::source_file( $id );
			$main = get_attached_file( $id, true );
			if ( ! $file || ! $main || ! is_file( $main ) ) {
				continue;
			}
			$sig = self::FORMAT . ':' . filesize( $main ) . ':' . filemtime( $main ) . ':' . wp_basename( $file );
			if ( isset( $cache[ $id ]['s'] ) && $cache[ $id ]['s'] === $sig ) {
				$fresh[ $id ] = $cache[ $id ];
			} elseif ( microtime( true ) < $until ) {
				$found        = self::analyze( $file );
				$meta         = wp_get_attachment_metadata( $id, true );
				$fresh[ $id ] = array(
					's' => $sig,
					'h' => $found ? $found['h'] : '',
					'c' => $found ? $found['c'] : '',
					'r' => ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ? round( $meta['width'] / $meta['height'], 4 ) : 0,
					'm' => (string) md5_file( $main ),
				);
			} else {
				++$pending;
				continue;
			}
			if ( '' === $fresh[ $id ]['h'] ) {
				++$failed;
				continue;
			}
			$hashes[ $id ] = $fresh[ $id ]['h'];
			$colors[ $id ] = $fresh[ $id ]['c'];
			$ratios[ $id ] = (float) $fresh[ $id ]['r'];
			$md5[ $id ]    = $fresh[ $id ]['m'];
		}
		Storage::write_json( self::CACHE, $fresh );
		return compact( 'hashes', 'colors', 'ratios', 'md5', 'pending', 'failed' );
	}

	/**
	 * Smallest stored copy that shows the whole picture (not a square crop).
	 *
	 * @param int $id Attachment id.
	 * @return string|null Absolute path.
	 */
	public static function source_file( $id ) {
		$main = get_attached_file( $id, true );
		if ( ! $main || ! is_file( $main ) ) {
			return null;
		}
		$meta = wp_get_attachment_metadata( $id, true );
		$w    = isset( $meta['width'] ) ? (int) $meta['width'] : 0;
		$h    = isset( $meta['height'] ) ? (int) $meta['height'] : 0;
		$best = null;
		$area = PHP_INT_MAX;
		foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $size ) {
			if ( empty( $size['file'] ) || empty( $size['width'] ) || empty( $size['height'] ) || $size['width'] < 64 || ! $w || ! $h ) {
				continue;
			}
			if ( abs( $size['width'] / $size['height'] - $w / $h ) > 0.03 ) {
				continue; // Cropped: another shape than the original.
			}
			$path = dirname( $main ) . '/' . wp_basename( $size['file'] );
			if ( $size['width'] * $size['height'] < $area && is_file( $path ) ) {
				$best = $path;
				$area = $size['width'] * $size['height'];
			}
		}
		return $best ? $best : $main;
	}

	/**
	 * 64-bit difference hash as 16 hex digits, or '' when the file can't be read.
	 *
	 * @param string $file Image file.
	 */
	public static function dhash( $file ) {
		$found = self::analyze( $file );
		return $found ? $found['h'] : '';
	}

	/**
	 * Difference hash and average colour ("rrggbb") of an image.
	 *
	 * @param string $file Image file.
	 * @return array{h:string,c:string}|null
	 */
	public static function analyze( $file ) {
		$rgb = self::pixels( $file );
		if ( ! $rgb ) {
			return null;
		}
		$hex = '';
		$sum = array( 0, 0, 0 );
		for ( $y = 0; $y < 8; $y++ ) {
			$byte = 0;
			for ( $x = 0; $x < 8; $x++ ) {
				$byte = ( $byte << 1 ) | ( self::luma( $rgb[ $y ][ $x ] ) > self::luma( $rgb[ $y ][ $x + 1 ] ) ? 1 : 0 );
			}
			$hex .= sprintf( '%02x', $byte );
			for ( $x = 0; $x < 9; $x++ ) {
				for ( $c = 0; $c < 3; $c++ ) {
					$sum[ $c ] += $rgb[ $y ][ $x ][ $c ];
				}
			}
		}
		return array(
			'h' => $hex,
			'c' => sprintf( '%02x%02x%02x', (int) round( $sum[0] / 72 ), (int) round( $sum[1] / 72 ), (int) round( $sum[2] / 72 ) ),
		);
	}

	/**
	 * @param int[] $p RGB.
	 */
	private static function luma( array $p ) {
		return 0.299 * $p[0] + 0.587 * $p[1] + 0.114 * $p[2];
	}

	/**
	 * 9×8 RGB pixels of an image via GD, or Imagick when GD can't read the format.
	 *
	 * @param string $file Image file.
	 * @return array<int,array<int,int[]>>|null
	 */
	private static function pixels( $file ) {
		if ( ! is_string( $file ) || '' === $file || ! is_readable( $file ) ) {
			return null;
		}
		if ( function_exists( 'imagecreatefromstring' ) ) {
			$data = @file_get_contents( $file ); // phpcs:ignore
			$src  = false !== $data ? @imagecreatefromstring( $data ) : false; // phpcs:ignore
			if ( $src ) {
				$dst = imagecreatetruecolor( 9, 8 );
				imagecopyresampled( $dst, $src, 0, 0, 0, 0, 9, 8, imagesx( $src ), imagesy( $src ) );
				$out = array();
				for ( $y = 0; $y < 8; $y++ ) {
					for ( $x = 0; $x < 9; $x++ ) {
						$c              = imagecolorat( $dst, $x, $y );
						$out[ $y ][ $x ] = array( ( $c >> 16 ) & 255, ( $c >> 8 ) & 255, $c & 255 );
					}
				}
				imagedestroy( $src );
				imagedestroy( $dst );
				return $out;
			}
		}
		if ( class_exists( '\Imagick' ) ) {
			try {
				$im = new \Imagick( $file );
				$im->setIteratorIndex( 0 );
				$im->resizeImage( 9, 8, \Imagick::FILTER_TRIANGLE, 1 );
				$out = array();
				for ( $y = 0; $y < 8; $y++ ) {
					for ( $x = 0; $x < 9; $x++ ) {
						$c              = $im->getImagePixelColor( $x, $y )->getColor();
						$out[ $y ][ $x ] = array( (int) $c['r'], (int) $c['g'], (int) $c['b'] );
					}
				}
				$im->clear();
				return $out;
			} catch ( \Exception $e ) {
				return null;
			}
		}
		return null;
	}

	/**
	 * Number of differing bits between two hashes.
	 *
	 * @param string $a Hex hash.
	 * @param string $b Hex hash.
	 */
	public static function distance( $a, $b ) {
		if ( ! self::$pop ) {
			for ( $i = 0; $i < 256; $i++ ) {
				self::$pop[ $i ] = substr_count( decbin( $i ), '1' );
			}
		}
		$d = 0;
		for ( $i = 0; $i < 16; $i += 2 ) {
			$d += self::$pop[ hexdec( substr( $a, $i, 2 ) ) ^ hexdec( substr( $b, $i, 2 ) ) ];
		}
		return $d;
	}

	/**
	 * Whether two analysed images show the same picture.
	 *
	 * @param string $hash_a  Hash.
	 * @param string $hash_b  Hash.
	 * @param string $color_a Average colour "rrggbb" ('' = unknown).
	 * @param string $color_b Average colour.
	 * @param float  $ratio_a Width/height (0 = unknown).
	 * @param float  $ratio_b Width/height.
	 */
	public static function alike( $hash_a, $hash_b, $color_a = '', $color_b = '', $ratio_a = 0.0, $ratio_b = 0.0 ) {
		if ( self::distance( $hash_a, $hash_b ) > self::MAX_DISTANCE ) {
			return false;
		}
		if ( 6 === strlen( $color_a ) && 6 === strlen( $color_b ) ) {
			for ( $i = 0; $i < 6; $i += 2 ) {
				if ( abs( hexdec( substr( $color_a, $i, 2 ) ) - hexdec( substr( $color_b, $i, 2 ) ) ) > self::MAX_COLOR ) {
					return false;
				}
			}
		}
		if ( $ratio_a > 0 && $ratio_b > 0 && abs( $ratio_a - $ratio_b ) / max( $ratio_a, $ratio_b ) > self::MAX_RATIO ) {
			return false;
		}
		return true;
	}

	/**
	 * Groups of look-alike images, largest first.
	 *
	 * Two hashes within MAX_DISTANCE bits share at least one of their eight
	 * bytes exactly (pigeonhole), so only images sharing a byte are compared.
	 * Each group is built around its oldest image, and every member must look
	 * like that anchor.
	 *
	 * @param array<int,string> $hashes id => hex hash.
	 * @param array<int,string> $colors id => average colour.
	 * @param array<int,float>  $ratios id => width/height.
	 * @return int[][] Groups of ids.
	 */
	public static function groups( array $hashes, array $colors = array(), array $ratios = array() ) {
		$buckets = array();
		foreach ( $hashes as $id => $hash ) {
			for ( $i = 0; $i < 8; $i++ ) {
				$buckets[ $i . ':' . substr( $hash, $i * 2, 2 ) ][] = $id;
			}
		}
		$near    = array();
		$checked = array();
		foreach ( $buckets as $members ) {
			$n = count( $members );
			for ( $i = 0; $i < $n; $i++ ) {
				for ( $j = $i + 1; $j < $n; $j++ ) {
					$a   = min( $members[ $i ], $members[ $j ] );
					$b   = max( $members[ $i ], $members[ $j ] );
					$key = $a . '-' . $b;
					if ( isset( $checked[ $key ] ) ) {
						continue;
					}
					$checked[ $key ] = true;
					if ( self::alike( $hashes[ $a ], $hashes[ $b ], isset( $colors[ $a ] ) ? $colors[ $a ] : '', isset( $colors[ $b ] ) ? $colors[ $b ] : '', isset( $ratios[ $a ] ) ? $ratios[ $a ] : 0.0, isset( $ratios[ $b ] ) ? $ratios[ $b ] : 0.0 ) ) {
						$near[ $a ][] = $b;
						$near[ $b ][] = $a;
					}
				}
			}
		}
		ksort( $near );
		$taken  = array();
		$groups = array();
		foreach ( $near as $anchor => $others ) {
			if ( isset( $taken[ $anchor ] ) ) {
				continue;
			}
			$group = array( $anchor );
			sort( $others );
			foreach ( $others as $other ) {
				if ( ! isset( $taken[ $other ] ) && $other !== $anchor ) {
					$group[]         = $other;
					$taken[ $other ] = true;
				}
			}
			if ( count( $group ) > 1 ) {
				$taken[ $anchor ] = true;
				$groups[]         = array_values( array_unique( $group ) );
			}
		}
		usort(
			$groups,
			static function ( $a, $b ) {
				return count( $b ) - count( $a ) ?: $a[0] - $b[0];
			}
		);
		return $groups;
	}
}
