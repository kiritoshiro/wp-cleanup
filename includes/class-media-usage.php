<?php
/**
 * Where each image attachment is used: featured images, post content, custom
 * fields, term fields and site settings. Built in one pass over the site.
 *
 * Content references are matched by file (any size of the image, absolute,
 * relative or JSON-escaped URL), by the "wp-image-{id}" class, by image-type
 * block attributes and by [gallery ids=""]. Fields count when an image-like
 * key ("hero_image", "_hero|slide_image|0|0|value", "thumbnail_id", "gallery")
 * holds the attachment id, or when any value holds its URL.
 *
 * Revisions, auto-drafts, trashed posts, changesets and oEmbed caches don't
 * count as uses.
 *
 * Only what is stored in the database is visible. Images referenced from theme
 * files, CSS, other plugins' own tables or external sites are not.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Media_Usage {

	/** Key names that usually hold an image id. */
	const IMAGE_KEY = '(image|img|thumb|photo|logo|icon|banner|background|gallery|slide|hero|cover|picture|avatar|attachment|media)';

	/** Image-like keys that hold a dimension, count or flag, never an id (thumbnail_size_w, image_quality…). */
	const NOT_ID_KEY = '/(size|_w$|_h$|width|height|crop|quality|count|max|min|per_?page|columns|opacity|position|enabled|show|hide|lazy|limit|speed|delay|interval|autoplay|loop|number|version|time)/i';

	/** Uses recorded per image; the total is still counted beyond this. */
	const MAX_PER_IMAGE = 30;

	/** @var array<string,int[]> "dir/base" (lower case) => attachment ids */
	private $by_file = array();

	/** @var array<int,true> */
	private $ids = array();

	/** @var array<int,array> id => list of uses */
	private $uses = array();

	/** @var array<int,int> id => use count */
	private $counts = array();

	/** @var array<int,array<string,true>> dedupe keys */
	private $seen = array();

	/** @var string Uploads folder name as it appears in URLs, e.g. "uploads". */
	private $uploads;

	/**
	 * @return array{uses:array<int,array>,counts:array<int,int>,parents:array<int,int>}
	 */
	public static function build() {
		$usage = new self();
		return $usage->run();
	}

	private function run() {
		global $wpdb;
		$this->uploads = wp_basename( wp_upload_dir( null, false )['basedir'] );
		$parents       = array();

		$rows = $wpdb->get_results(
			"SELECT p.ID, p.post_parent, m.meta_value FROM {$wpdb->posts} p
			 JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file'
			 WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'"
		);
		foreach ( (array) $rows as $row ) {
			$id               = (int) $row->ID;
			$this->ids[ $id ] = true;
			if ( (int) $row->post_parent > 0 ) {
				$parents[ $id ] = (int) $row->post_parent;
			}
			$this->by_file[ self::file_key( $row->meta_value ) ][] = $id;
		}

		$this->featured();
		$this->content();
		$this->post_fields();
		$this->term_fields();
		$this->settings();

		return array(
			'uses'    => $this->uses,
			'counts'  => $this->counts,
			'parents' => $parents,
		);
	}

	/**
	 * "2024/05/photo" for "2024/05/photo-300x200.jpg", "photo-scaled.jpg", "photo-e1712345678901.jpg".
	 *
	 * @param string $path Path relative to uploads.
	 */
	public static function file_key( $path ) {
		$path = strtolower( ltrim( str_replace( '\\', '/', (string) $path ), '/' ) );
		$dir  = dirname( $path );
		$base = Media_Inventory::base_name( $path );
		$base = preg_replace( '/-\d+x\d+$/', '', $base );
		$base = preg_replace( '/-(?:scaled|rotated)$/', '', $base );
		return ( '.' === $dir || '' === $dir ? '' : $dir . '/' ) . $base;
	}

	/**
	 * @param int    $id     Attachment id.
	 * @param string $kind   featured|content|field|term|setting.
	 * @param mixed  $object Post id, term id or option name.
	 * @param string $field  Meta key or option name.
	 */
	private function add( $id, $kind, $object, $field = '' ) {
		$id = (int) $id;
		if ( ! isset( $this->ids[ $id ] ) ) {
			return;
		}
		$key = $kind . '|' . $object . '|' . $field;
		if ( isset( $this->seen[ $id ][ $key ] ) ) {
			return;
		}
		$this->seen[ $id ][ $key ] = true;
		$this->counts[ $id ]       = ( isset( $this->counts[ $id ] ) ? $this->counts[ $id ] : 0 ) + 1;
		if ( $this->counts[ $id ] <= self::MAX_PER_IMAGE ) {
			$this->uses[ $id ][] = array(
				'k' => $kind,
				'o' => $object,
				'f' => (string) $field,
			);
		}
	}

	/**
	 * Attachment ids whose files a text mentions (URLs, classes, block ids, galleries).
	 *
	 * @param string $text     Content, HTML or JSON.
	 * @param bool   $by_class Also read wp-image-N classes, block attributes and galleries.
	 * @return int[]
	 */
	private function ids_in_text( $text, $by_class = true ) {
		$text = str_replace( '\\/', '/', (string) $text );
		$ids  = array();
		if ( false !== stripos( $text, $this->uploads . '/' ) && preg_match_all( '~' . preg_quote( $this->uploads, '~' ) . '/([^\s"\'<>()?#,]+?\.(?:jpe?g|png|gif|webp|avif|bmp|tiff?))~i', $text, $m ) ) {
			foreach ( array_unique( $m[1] ) as $rel ) {
				$key = self::file_key( rawurldecode( $rel ) );
				if ( isset( $this->by_file[ $key ] ) ) {
					$ids = array_merge( $ids, $this->by_file[ $key ] );
				}
			}
		}
		if ( ! $by_class ) {
			return array_unique( $ids );
		}
		if ( preg_match_all( '/\bwp-image-(\d+)\b/', $text, $m ) ) {
			$ids = array_merge( $ids, array_map( 'intval', $m[1] ) );
		}
		// Block comments: core image-type blocks by "id"/"ids", any block by image-like attribute names.
		if ( false !== strpos( $text, '<!-- wp:' ) && preg_match_all( '~<!--\s+wp:([a-z0-9/-]+)\s+(\{.*?\})\s+/?-->~s', $text, $m, PREG_SET_ORDER ) ) {
			foreach ( $m as $block ) {
				$attrs = json_decode( $block[2], true );
				if ( ! is_array( $attrs ) ) {
					continue;
				}
				$core_media = in_array( preg_replace( '~^core/~', '', $block[1] ), array( 'image', 'cover', 'media-text', 'gallery', 'post-featured-image', 'site-logo' ), true );
				foreach ( $attrs as $name => $value ) {
					if ( ( $core_media && in_array( $name, array( 'id', 'ids', 'mediaId' ), true ) ) || self::is_id_key( (string) $name ) ) {
						$ids = array_merge( $ids, self::numbers( $value ) );
					}
				}
			}
		}
		if ( false !== strpos( $text, '[gallery' ) && preg_match_all( '/\[gallery[^\]]*?\b(?:ids|include)\s*=\s*["\']?([\d,\s]+)/', $text, $m ) ) {
			foreach ( $m[1] as $list ) {
				$ids = array_merge( $ids, array_map( 'intval', preg_split( '/[\s,]+/', $list, -1, PREG_SPLIT_NO_EMPTY ) ) );
			}
		}
		return array_unique( $ids );
	}

	/**
	 * Integers in a value: a number, "1,2,3", an array, or a serialized array.
	 *
	 * @param mixed $value Value.
	 * @return int[]
	 */
	private static function numbers( $value ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			$value = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore
		}
		if ( is_int( $value ) || ( is_string( $value ) && preg_match( '/^\d+$/', trim( $value ) ) ) ) {
			return array( (int) $value );
		}
		if ( is_string( $value ) && preg_match( '/^\d+(\s*,\s*\d+)+$/', trim( $value ) ) ) {
			return array_map( 'intval', preg_split( '/\s*,\s*/', trim( $value ) ) );
		}
		$out = array();
		if ( ! is_array( $value ) ) {
			return $out;
		}
		// Only plain id lists count; a settings array (width => 800) is not a list of images.
		if ( array_keys( $value ) !== range( 0, count( $value ) - 1 ) ) {
			return isset( $value['id'] ) && is_numeric( $value['id'] ) ? array( (int) $value['id'] ) : $out;
		}
		foreach ( $value as $item ) {
			if ( is_int( $item ) || ( is_string( $item ) && preg_match( '/^\d+$/', $item ) ) ) {
				$out[] = (int) $item;
			} elseif ( is_array( $item ) && isset( $item['id'] ) && is_numeric( $item['id'] ) ) {
				$out[] = (int) $item['id']; // Gallery rows such as array( 'id' => 12, 'url' => … ).
			}
		}
		return $out;
	}

	/**
	 * Whether a key name suggests it stores an image id.
	 *
	 * Carbon Fields keys are judged by their last named segment
	 * ("_hero|slide_image|1|0|value" by "slide_image").
	 *
	 * @param string $key Key.
	 */
	public static function is_id_key( $key ) {
		$key = (string) $key;
		if ( false !== strpos( $key, '|' ) ) {
			$named = array_filter(
				explode( '|', ltrim( $key, '_' ) ),
				static function ( $part ) {
					return '' !== $part && ! ctype_digit( $part ) && ! in_array( $part, array( 'value', '_empty' ), true );
				}
			);
			$key = (string) end( $named );
		}
		return '' !== $key && preg_match( '/' . self::IMAGE_KEY . '/i', $key ) && ! preg_match( self::NOT_ID_KEY, $key );
	}

	/**
	 * Walk a stored value: ids under image-like keys and URLs anywhere.
	 *
	 * @param mixed  $value Value (possibly serialized).
	 * @param string $key   Key of this value.
	 * @param int    $depth Recursion depth.
	 * @return int[]
	 */
	private function ids_in_value( $value, $key = '', $depth = 0 ) {
		if ( is_string( $value ) && is_serialized( $value ) ) {
			$value = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore
		}
		$ids = array();
		if ( self::is_id_key( $key ) ) {
			$ids = self::numbers( $value );
		}
		if ( is_string( $value ) ) {
			return array_merge( $ids, $this->ids_in_text( $value, false ) );
		}
		if ( is_array( $value ) && $depth < 8 ) {
			foreach ( $value as $k => $v ) {
				$ids = array_merge( $ids, $this->ids_in_value( $v, is_string( $k ) ? $k : $key, $depth + 1 ) );
			}
		}
		return $ids;
	}

	private function featured() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT m.post_id, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
			 WHERE m.meta_key = '_thumbnail_id'
			   AND p.post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache')
			   AND p.post_status NOT IN ('auto-draft','trash','inherit')"
		);
		foreach ( (array) $rows as $row ) {
			$this->add( (int) $row->meta_value, 'featured', (int) $row->post_id );
		}
	}

	private function content() {
		global $wpdb;
		$last = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_content, post_excerpt FROM {$wpdb->posts}
					 WHERE ID > %d
					   AND post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache')
					   AND post_status NOT IN ('auto-draft','trash','inherit')
					 ORDER BY ID LIMIT 300",
					$last
				)
			);
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->ID;
				foreach ( $this->ids_in_text( $row->post_content . "\n" . $row->post_excerpt ) as $id ) {
					$this->add( $id, 'content', $last );
				}
			}
		} while ( $rows );
	}

	private function post_fields() {
		global $wpdb;
		$like = $wpdb->esc_like( $this->uploads . '/' );
		$last = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT m.meta_id, m.post_id, m.meta_key, m.meta_value FROM {$wpdb->postmeta} m JOIN {$wpdb->posts} p ON p.ID = m.post_id
					 WHERE m.meta_id > %d
					   AND p.post_type NOT IN ('revision','attachment','customize_changeset','oembed_cache')
					   AND p.post_status NOT IN ('auto-draft','trash','inherit')
					   AND m.meta_key <> '_thumbnail_id' AND m.meta_key NOT LIKE '\\_oembed%%'
					   AND ( m.meta_value LIKE %s OR m.meta_value LIKE %s
					     OR ( m.meta_key REGEXP %s AND ( m.meta_value REGEXP '^[0-9][0-9, ]*$' OR m.meta_value LIKE 'a:%%' ) ) )
					 ORDER BY m.meta_id LIMIT 1000",
					$last,
					'%' . $like . '%',
					'%' . str_replace( '/', '\\\\/', $like ) . '%',
					self::IMAGE_KEY
				)
			);
			foreach ( (array) $rows as $row ) {
				$last = (int) $row->meta_id;
				foreach ( $this->ids_in_value( $row->meta_value, $row->meta_key ) as $id ) {
					$this->add( $id, 'field', (int) $row->post_id, $row->meta_key );
				}
			}
		} while ( $rows );
	}

	private function term_fields() {
		global $wpdb;
		$like = $wpdb->esc_like( $this->uploads . '/' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT term_id, meta_key, meta_value FROM {$wpdb->termmeta}
				 WHERE meta_value LIKE %s OR ( meta_key REGEXP %s AND ( meta_value REGEXP '^[0-9][0-9, ]*$' OR meta_value LIKE 'a:%%' ) )",
				'%' . $like . '%',
				self::IMAGE_KEY
			)
		);
		foreach ( (array) $rows as $row ) {
			foreach ( $this->ids_in_value( $row->meta_value, $row->meta_key ) as $id ) {
				$this->add( $id, 'term', (int) $row->term_id, $row->meta_key );
			}
		}
	}

	private function settings() {
		global $wpdb;
		$like = $wpdb->esc_like( $this->uploads . '/' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options}
				 WHERE option_name NOT LIKE 'wpcu\\_%%' AND option_name NOT LIKE '\\_transient%%' AND option_name NOT LIKE '\\_site\\_transient%%'
				   AND option_name NOT IN ('cron','rewrite_rules')
				   AND ( option_value LIKE %s OR option_value LIKE %s OR option_name IN ('site_icon','site_logo') OR option_name LIKE 'widget\\_%%'
				     OR option_name LIKE 'theme\\_mods\\_%%' OR ( option_name REGEXP %s AND option_value REGEXP '^[0-9][0-9, ]*$' ) )",
				'%' . $like . '%',
				'%' . str_replace( '/', '\\\\/', $like ) . '%',
				self::IMAGE_KEY
			)
		);
		foreach ( (array) $rows as $row ) {
			$name = (string) $row->option_name;
			$ids  = in_array( $name, array( 'site_icon', 'site_logo' ), true ) ? self::numbers( $row->option_value ) : $this->ids_in_value( $row->option_value, $name );
			foreach ( $ids as $id ) {
				$this->add( $id, 'setting', $name, $name );
			}
		}
	}
}
