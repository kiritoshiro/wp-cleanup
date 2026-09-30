<?php
/**
 * Known plugin/theme naming prefixes (see data/signatures.php).
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Signatures {

	/** @var array<string,array{name:string,prefixes:string[]}>|null */
	private static $all = null;

	/**
	 * @return array<string,array{name:string,prefixes:string[]}> Keyed by slug.
	 */
	public static function all() {
		if ( null === self::$all ) {
			$raw = require WPCU_DIR . 'data/signatures.php';
			/**
			 * Filter the known plugin/theme prefixes used to label leftovers.
			 *
			 * @param array $raw slug => array( name, prefixes[] ).
			 */
			$raw       = apply_filters( 'wp_cleanup_signatures', $raw );
			self::$all = array();
			foreach ( (array) $raw as $slug => $entry ) {
				if ( ! is_array( $entry ) || count( $entry ) < 2 ) {
					continue;
				}
				self::$all[ (string) $slug ] = array(
					'name'     => (string) $entry[0],
					'prefixes' => array_map( 'strtolower', array_map( 'strval', (array) $entry[1] ) ),
				);
			}
		}
		return self::$all;
	}

	/**
	 * Most specific signature matching the name: an exact name beats any prefix,
	 * then the longest prefix wins.
	 *
	 * @param string $name Normalized name.
	 * @return array{slug:string,name:string,prefix:string,exact:bool}|null
	 */
	public static function match( $name ) {
		$name  = ltrim( strtolower( $name ), '_' );
		$best  = null;
		$score = -1;
		foreach ( self::all() as $slug => $entry ) {
			foreach ( $entry['prefixes'] as $prefix ) {
				$exact = self::is_exact( $prefix );
				$text  = $exact ? substr( $prefix, 0, -1 ) : $prefix;
				if ( '' === $text || ( $exact ? $name !== $text : 0 !== strpos( $name, $text ) ) ) {
					continue;
				}
				$rank = strlen( $text ) + ( $exact ? 1000 : 0 );
				if ( $rank > $score ) {
					$score = $rank;
					$best  = array( 'slug' => $slug, 'name' => $entry['name'], 'prefix' => $text, 'exact' => $exact );
				}
			}
		}
		return $best;
	}

	/**
	 * Entries ending in "$" name one exact item instead of a prefix.
	 *
	 * @param string $prefix Signature entry.
	 */
	public static function is_exact( $prefix ) {
		return '' !== $prefix && '$' === substr( $prefix, -1 );
	}
}
