<?php
/**
 * Which attachments name which uploads file, from every place WordPress and this
 * plugin record one. Files named by another attachment are never moved or deleted.
 *
 * The index is built from postmeta in a few bulk queries and reused for the rest of
 * the request. Attachments whose meta changes through the WordPress API are re-read
 * one by one; raw SQL writes (backup replays, reference rewrites) reset it.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Owners {
	const KEYS  = array( '_wp_attached_file', '_wp_attachment_metadata', '_wp_attachment_backup_sizes', Media_Policy::OUTPUT_META );
	const CHUNK = 500;

	/** @var array<string,array<int,true>>|null Normalised path key => owner ids. */
	private static $index = null;

	/** @var array<int,string[]> Owner id => its path keys. */
	private static $by_post = array();

	/** @var array<int,true> Attachments to re-read before the next lookup. */
	private static $dirty = array();

	/** @var bool */
	private static $hooked = false;

	/**
	 * The subset of $paths that an attachment other than $self_id names.
	 *
	 * @param int      $self_id Attachment the files would be taken from (0: any attachment counts).
	 * @param string[] $paths   Uploads-relative paths.
	 * @return string[] Shared paths, as given.
	 * @throws \RuntimeException When the index cannot be read.
	 */
	public static function shared( $self_id, array $paths ) {
		$index  = self::index();
		$root   = self::root();
		$shared = array();
		foreach ( $paths as $path ) {
			$key = self::key( (string) $path, $root );
			if ( null === $key || empty( $index[ $key ] ) ) {
				continue;
			}
			$owners = $index[ $key ];
			unset( $owners[ (int) $self_id ] );
			if ( $owners ) {
				$shared[] = $path;
			}
		}
		return $shared;
	}

	/** Forget everything; the next lookup rebuilds from the database. */
	public static function reset() {
		self::$index   = null;
		self::$by_post = array();
		self::$dirty   = array();
	}

	/**
	 * Re-read this attachment before the next lookup.
	 *
	 * @param int $id Post id.
	 */
	public static function touch( $id ) {
		if ( null !== self::$index ) {
			self::$dirty[ (int) $id ] = true;
		}
	}

	/** @internal Meta hook. */
	public static function on_meta( $meta_ids, $object_id, $meta_key ) {
		if ( in_array( $meta_key, self::KEYS, true ) ) {
			self::touch( $object_id );
		}
	}

	/**
	 * Normalised, lower-case, uploads-relative key for a stored path, or null when
	 * it does not name a file inside uploads. Absolute paths count when they point
	 * into uploads, with either separator; "./", "//" and inner ".." are resolved,
	 * and trailing dots and spaces are dropped from each part as Windows does.
	 * Case and trailing-dot folding are deliberate on every system: a false "shared"
	 * only leaves a file in place, while a missed alias could move a used file.
	 *
	 * @param string $path Stored path.
	 * @param string $root Normalised uploads base directory.
	 * @return string|null
	 */
	public static function key( $path, $root = null ) {
		$root = null === $root ? self::root() : $root;
		$path = wp_normalize_path( trim( (string) $path ) );
		if ( '' === $path || false !== strpos( $path, "\0" ) ) {
			return null;
		}
		if ( '/' === $path[0] || preg_match( '#^[A-Za-z]:/#', $path ) ) {
			$prefix = strtolower( $root ) . '/';
			if ( 0 !== strpos( strtolower( $path ), $prefix ) ) {
				return null;
			}
			$path = substr( $path, strlen( $prefix ) );
		} elseif ( false !== strpos( $path, ':' ) ) {
			return null;
		}
		$parts = array();
		foreach ( explode( '/', $path ) as $part ) {
			if ( '' === $part || '.' === $part ) {
				continue;
			}
			if ( '..' === $part ) {
				if ( ! $parts ) {
					return null;
				}
				array_pop( $parts );
				continue;
			}
			// Windows drops trailing dots and spaces, so "x.jpg." and "x.jpg " open x.jpg.
			// Folding them everywhere (like case) can only over-report sharing, never miss it.
			$part = rtrim( $part, '. ' );
			if ( '' === $part ) {
				return null;
			}
			$parts[] = $part;
		}
		return $parts ? strtolower( implode( '/', $parts ) ) : null;
	}

	/** @return array<string,array<int,true>> */
	private static function index() {
		global $wpdb;
		if ( ! self::$hooked ) {
			self::$hooked = true;
			foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta' ) as $hook ) {
				add_action( $hook, array( __CLASS__, 'on_meta' ), 10, 3 );
			}
			add_action( 'add_attachment', array( __CLASS__, 'touch' ) );
			add_action( 'delete_attachment', array( __CLASS__, 'touch' ) );
		}
		if ( null === self::$index ) {
			$ids = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' ORDER BY ID" );
			self::check_db();
			self::$index   = array();
			self::$by_post = array();
			self::$dirty   = array();
			foreach ( array_chunk( array_map( 'intval', (array) $ids ), self::CHUNK ) as $chunk ) {
				self::load( $chunk );
			}
		}
		if ( self::$dirty ) {
			$dirty       = array_keys( self::$dirty );
			self::$dirty = array();
			foreach ( $dirty as $id ) {
				self::drop( $id );
			}
			$live = $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID IN (" . implode( ',', array_map( 'intval', $dirty ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Integer ids only.
			self::check_db();
			if ( $live ) {
				self::load( array_map( 'intval', $live ) );
			}
		}
		return self::$index;
	}

	/**
	 * Index these attachments' paths.
	 *
	 * @param int[] $ids Attachment ids.
	 */
	private static function load( array $ids ) {
		global $wpdb;
		$keys = "'" . implode( "','", array_map( 'esc_sql', self::KEYS ) ) . "'";
		$rows = $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE meta_key IN ({$keys}) AND post_id IN (" . implode( ',', array_map( 'intval', $ids ) ) . ')' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Fixed keys and integer ids.
		self::check_db();
		$meta = array();
		foreach ( (array) $rows as $row ) {
			$meta[ (int) $row->post_id ][ $row->meta_key ] = $row->meta_value;
		}
		$root = self::root();
		foreach ( $meta as $id => $values ) {
			foreach ( self::paths_of( $values, $root ) as $key ) {
				self::$index[ $key ][ $id ] = true;
				self::$by_post[ $id ][]     = $key;
			}
		}
	}

	/**
	 * Every path key one attachment names.
	 *
	 * Sizes and other names are resolved against both the `_wp_attached_file` folder
	 * and the metadata `file` folder (WordPress uses each in different places); a name
	 * carrying a folder also counts relative to uploads.
	 *
	 * @param array<string,string> $values Raw meta values by key.
	 * @param string               $root   Uploads base directory.
	 * @return string[]
	 */
	private static function paths_of( array $values, $root ) {
		$data    = self::value( isset( $values['_wp_attachment_metadata'] ) ? $values['_wp_attachment_metadata'] : '' );
		$backups = self::value( isset( $values['_wp_attachment_backup_sizes'] ) ? $values['_wp_attachment_backup_sizes'] : '' );
		$outputs = self::value( isset( $values[ Media_Policy::OUTPUT_META ] ) ? $values[ Media_Policy::OUTPUT_META ] : '' );
		$keys    = array();
		$folders = array();
		foreach ( array( isset( $values['_wp_attached_file'] ) ? $values['_wp_attached_file'] : '', is_array( $data ) && isset( $data['file'] ) && is_scalar( $data['file'] ) ? $data['file'] : '' ) as $main ) {
			$key = self::key( (string) $main, $root );
			if ( null !== $key ) {
				$keys[ $key ] = true;
				$folder       = dirname( $key );
				$folders[ '.' === $folder ? '' : $folder ] = true;
			}
		}
		$names = array();
		if ( is_array( $data ) ) {
			$names[] = isset( $data['original_image'] ) ? $data['original_image'] : '';
			$names[] = isset( $data['thumb'] ) ? $data['thumb'] : '';
			foreach ( ( isset( $data['sizes'] ) && is_array( $data['sizes'] ) ) ? $data['sizes'] : array() as $size ) {
				$names[] = is_array( $size ) && isset( $size['file'] ) ? $size['file'] : '';
			}
		}
		foreach ( is_array( $backups ) ? $backups : array() as $size ) {
			$names[] = is_array( $size ) && isset( $size['file'] ) ? $size['file'] : '';
		}
		if ( is_array( $outputs ) ) {
			foreach ( array( 'jpeg', 'avif_full', 'avif_small' ) as $variant ) {
				$names[] = isset( $outputs[ $variant ] ) ? $outputs[ $variant ] : '';
			}
		}
		foreach ( $names as $name ) {
			if ( ! is_scalar( $name ) || '' === (string) $name ) {
				continue;
			}
			$name       = wp_normalize_path( (string) $name );
			$candidates = false !== strpos( $name, '/' ) ? array( $name ) : array(); // Absolute, or a folder relative to uploads.
			foreach ( array_keys( $folders ) as $folder ) {
				$candidates[] = ( '' === $folder ? '' : $folder . '/' ) . $name;
			}
			foreach ( $candidates as $candidate ) {
				$key = self::key( $candidate, $root );
				if ( null !== $key ) {
					$keys[ $key ] = true;
				}
			}
		}
		return array_keys( $keys );
	}

	/** Unserialise without objects. */
	private static function value( $raw ) {
		if ( ! is_string( $raw ) || ! is_serialized( $raw ) ) {
			return $raw;
		}
		return @unserialize( trim( $raw ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.PHP.DiscouragedPHPFunctions -- Objects are refused.
	}

	/** @param int $id Attachment id. */
	private static function drop( $id ) {
		foreach ( isset( self::$by_post[ $id ] ) ? self::$by_post[ $id ] : array() as $key ) {
			unset( self::$index[ $key ][ $id ] );
			if ( empty( self::$index[ $key ] ) ) {
				unset( self::$index[ $key ] );
			}
		}
		unset( self::$by_post[ $id ] );
	}

	private static function root() {
		return untrailingslashit( wp_normalize_path( wp_upload_dir( null, false )['basedir'] ) );
	}

	private static function check_db() {
		global $wpdb;
		if ( $wpdb->last_error ) {
			self::$index = null;
			throw new \RuntimeException( __( 'Could not check whether image files are shared.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; admin notices escape output.
		}
	}
}
