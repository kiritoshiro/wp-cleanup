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
	 * Longest signature prefix matching the name.
	 *
	 * @param string $name Normalized name.
	 * @return array{slug:string,name:string,prefix:string}|null
	 */
	public static function match( $name ) {
		$name = ltrim( strtolower( $name ), '_' );
		$best = null;
		foreach ( self::all() as $slug => $entry ) {
			foreach ( $entry['prefixes'] as $prefix ) {
				if ( '' !== $prefix && 0 === strpos( $name, $prefix ) && ( ! $best || strlen( $prefix ) > strlen( $best['prefix'] ) ) ) {
					$best = array( 'slug' => $slug, 'name' => $entry['name'], 'prefix' => $prefix );
				}
			}
		}
		return $best;
	}
}
