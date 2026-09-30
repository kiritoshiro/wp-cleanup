<?php
/**
 * Collects candidate leftovers of every type and classifies them.
 *
 * Every item is an array:
 *   type, id (unique within type), label, status, owner, owner_slug,
 *   confidence, reason, group, count (rows/events/files), bytes, autoload.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Scanner {

	const RESULT_FILE   = 'last-scan.json';
	const DIR_FILE_CAP  = 20000;

	/** @var Classifier */
	private $classifier;

	/** @var Code_Index */
	private $index;

	/**
	 * @param bool $fresh_index Rebuild the code index.
	 */
	public function __construct( $fresh_index = false ) {
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore -- indexing large plugin sets takes a while.
		}
		wp_raise_memory_limit( 'admin' );
		$this->index      = Code_Index::load( $fresh_index );
		$this->classifier = new Classifier( $this->index );
	}

	/**
	 * Run a scan.
	 *
	 * @param string[]|null $types Types to scan (null = all).
	 * @return array{created:int,duration:float,index_rebuilt:bool,items:array}
	 */
	public function scan( $types = null ) {
		$start = microtime( true );
		$types = $types ? array_intersect( Plugin::TYPES, (array) $types ) : Plugin::TYPES;
		$items = array();
		foreach ( $types as $type ) {
			$method = 'scan_' . $type;
			foreach ( $this->$method() as $item ) {
				$items[] = $item;
			}
		}
		return array(
			'created'       => time(),
			'duration'      => round( microtime( true ) - $start, 2 ),
			'index_rebuilt' => $this->index->rebuilt,
			'sources'       => count( $this->index->sources() ),
			'incomplete'    => wp_list_pluck( $this->index->incomplete_sources(), 'name' ),
			'items'         => $items,
		);
	}

	/**
	 * Scan and persist the result for the admin screen.
	 *
	 * @return array
	 */
	public function scan_and_store() {
		$result = $this->scan();
		Storage::write_json( self::RESULT_FILE, $result );
		return $result;
	}

	/**
	 * @return array|null Last stored scan.
	 */
	public static function last() {
		return Storage::read_json( self::RESULT_FILE );
	}

	/**
	 * Drop items from the stored scan after they were deleted.
	 *
	 * @param array $keys "type|id" keys.
	 */
	public static function forget( array $keys ) {
		$last = self::last();
		if ( ! $last ) {
			return;
		}
		$drop          = array_flip( $keys );
		$last['items'] = array_values(
			array_filter(
				$last['items'],
				static function ( $item ) use ( $drop ) {
					return ! isset( $drop[ $item['type'] . '|' . $item['id'] ] );
				}
			)
		);
		Storage::write_json( self::RESULT_FILE, $last );
	}

	/**
	 * @param string $type  Item type.
	 * @param string $id    Unique id.
	 * @param string $label Display name.
	 * @param array  $class Classifier result.
	 * @param array  $extra count, bytes, autoload, meta_type ...
	 * @return array
	 */
	private function item( $type, $id, $label, array $class, array $extra = array() ) {
		return array_merge(
			array(
				'type'     => $type,
				'id'       => (string) $id,
				'label'    => (string) $label,
				'count'    => 1,
				'bytes'    => 0,
				'autoload' => null,
			),
			$class,
			$extra
		);
	}

	/**
	 * @param string $reason Why it's core.
	 * @return array Classifier-shaped result.
	 */
	private function core( $reason = '' ) {
		return array(
			'status'     => Plugin::STATUS_CORE,
			'owner'      => 'WordPress',
			'owner_slug' => 'wordpress',
			'confidence' => 'high',
			'reason'     => $reason ? $reason : __( 'Created and used by WordPress core.', 'wp-cleanup' ),
			'group'      => 'wordpress',
		);
	}

	/**
	 * @param string $reason Why it is safe.
	 * @return array Classifier-shaped result.
	 */
	private function safe( $reason ) {
		return array(
			'status'     => Plugin::STATUS_SAFE,
			'owner'      => '',
			'owner_slug' => '',
			'confidence' => 'high',
			'reason'     => $reason,
			'group'      => '',
		);
	}

	/* ------------------------------------------------------------------ */
	/* Options                                                             */
	/* ------------------------------------------------------------------ */

	private function scan_option() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options}
			 WHERE option_name NOT LIKE '\_transient\_%' AND option_name NOT LIKE '\_site\_transient\_%'",
			ARRAY_A
		);
		foreach ( (array) $rows as $row ) {
			$name  = $row['option_name'];
			$class = Protected_Names::is_core_option( $name ) ? $this->core() : $this->classifier->classify( Classifier::spellings( 'option', $name ) );
			yield $this->item(
				'option',
				$name,
				$name,
				$class,
				array(
					'bytes'    => (int) $row['bytes'],
					'autoload' => in_array( $row['autoload'], array( 'yes', 'on', 'auto-on', 'auto' ), true ),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Transients                                                          */
	/* ------------------------------------------------------------------ */

	private function scan_transient() {
		global $wpdb;
		$rows = $wpdb->get_results(
			"SELECT option_name, autoload, LENGTH(option_value) AS bytes FROM {$wpdb->options}
			 WHERE ( option_name LIKE '\_transient\_%' OR option_name LIKE '\_site\_transient\_%' )
			   AND option_name NOT LIKE '\_transient\_timeout\_%' AND option_name NOT LIKE '\_site\_transient\_timeout\_%'",
			ARRAY_A
		);
		$core_transients = array( 'doing_cron', 'update_core', 'update_plugins', 'update_themes', 'theme_roots', 'wp_theme_files_patterns', 'health-check-site-status-result', 'dirsize_cache', 'wp_core_block_css_files', 'wp_styles_for_blocks', 'available_translations', 'poptags', 'browser', 'php_check', 'wp_font_collections', 'is_multi_author', 'plugin_slugs', 'mailserver_last_checked', 'wp_remote_block_patterns', 'feed', 'feed_mod', 'dash', 'community-events', 'global_styles', 'wp_rest_api_cache', 'wp_block_patterns', 'wp_theme_json', 'wp_core_block', 'settings_errors', 'random_seed', 'wp_update_https', 'wp_sites_hash', 'popular_importers', 'wordpress_credits', 'scrape_key', 'rss', 'oembed' );

		foreach ( (array) $rows as $row ) {
			$name = $row['option_name'];
			$base = Classifier::normalize( 'transient', $name );
			$core = false;
			foreach ( $core_transients as $core_name ) {
				if ( $base === $core_name || 0 === strpos( $base, $core_name . '_' ) || 0 === strpos( $base, $core_name . '-' ) ) {
					$core = true;
					break;
				}
			}
			// Transients expire and are recreated on demand; unknown ones are still low-risk.
			$class = $core ? $this->core( __( 'WordPress core cache; recreated automatically.', 'wp-cleanup' ) ) : $this->classifier->classify( array( $base ) );
			if ( Plugin::STATUS_UNKNOWN === $class['status'] ) {
				$class['confidence'] = 'medium';
				$class['reason']     = __( 'Cached value with no known owner. Transients are caches, so deleting one is low-risk: its owner recreates it if still needed.', 'wp-cleanup' );
			}
			yield $this->item(
				'transient',
				$name,
				$base,
				$class,
				array(
					'bytes'    => (int) $row['bytes'],
					'autoload' => in_array( $row['autoload'], array( 'yes', 'on', 'auto-on', 'auto' ), true ),
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Tables                                                              */
	/* ------------------------------------------------------------------ */

	private function scan_table() {
		global $wpdb;
		$like   = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$tables = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS WHERE Name LIKE %s', $like ), ARRAY_A );
		$core   = array_map( 'strtolower', Protected_Names::core_tables() );
		$names  = array_map( 'strtolower', wp_list_pluck( (array) $tables, 'Name' ) );

		// Prefixes of other WordPress installs sharing this database (e.g. wp_ vs wp_staging_).
		$other_prefixes = array();
		foreach ( $names as $table ) {
			if ( substr( $table, -strlen( 'options' ) ) === 'options' && ! in_array( $table, $core, true ) ) {
				$maybe = substr( $table, 0, -strlen( 'options' ) );
				if ( in_array( $maybe . 'posts', $names, true ) && in_array( $maybe . 'users', $names, true ) ) {
					$other_prefixes[] = $maybe;
				}
			}
		}

		foreach ( (array) $tables as $row ) {
			$table = $row['Name'];
			$lower = strtolower( $table );
			$class = null;
			if ( in_array( $lower, $core, true ) ) {
				$class = $this->core();
			} else {
				foreach ( $other_prefixes as $prefix ) {
					if ( 0 === strpos( $lower, $prefix ) ) {
						/* translators: %s: table prefix */
						$class = $this->core( sprintf( __( 'Belongs to another WordPress install in this database (prefix %s).', 'wp-cleanup' ), $prefix ) );
						break;
					}
				}
			}
			$rows = (int) $row['Rows'];
			if ( ! $class ) {
				$class = $this->classifier->classify( Classifier::spellings( 'table', $table ) );
				// InnoDB's Rows is an estimate (often 0); count candidates exactly unless huge.
				if ( $rows < 1000000 ) {
					$rows = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM `' . Backup::ident( $table ) . '`' ); // phpcs:ignore
				}
			}
			yield $this->item(
				'table',
				$table,
				$table,
				$class,
				array(
					'count'  => $rows,
					'bytes'  => (int) $row['Data_length'] + (int) $row['Index_length'],
					'engine' => $row['Engine'],
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Cron                                                                */
	/* ------------------------------------------------------------------ */

	private function scan_cron() {
		$crons = _get_cron_array();
		$hooks = array();
		foreach ( (array) $crons as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				$hooks[ $hook ] = ( isset( $hooks[ $hook ] ) ? $hooks[ $hook ] : 0 ) + count( (array) $instances );
			}
		}
		foreach ( $hooks as $hook => $count ) {
			if ( Protected_Names::is_core_cron( $hook ) ) {
				$class = $this->core();
			} else {
				$runtime = has_action( $hook ) ? __( 'a callback is hooked to this event', 'wp-cleanup' ) : null;
				$class   = $this->classifier->classify( Classifier::spellings( 'cron', $hook ), $runtime );
			}
			yield $this->item( 'cron', $hook, $hook, $class, array( 'count' => $count ) );
		}
	}

	/* ------------------------------------------------------------------ */
	/* Meta keys                                                           */
	/* ------------------------------------------------------------------ */

	private function scan_meta() {
		global $wpdb;
		$tables = array(
			'post'    => $wpdb->postmeta,
			'user'    => $wpdb->usermeta,
			'term'    => $wpdb->termmeta,
			'comment' => $wpdb->commentmeta,
		);
		foreach ( $tables as $meta_type => $table ) {
			$rows = $wpdb->get_results( "SELECT meta_key, COUNT(*) AS n, SUM(LENGTH(meta_value)) AS bytes FROM {$table} GROUP BY meta_key", ARRAY_A ); // phpcs:ignore
			foreach ( (array) $rows as $row ) {
				$key = (string) $row['meta_key'];
				if ( '' === $key ) {
					continue;
				}
				$class = Protected_Names::is_core_meta( $meta_type, $key )
					? $this->core()
					: $this->classifier->classify( Classifier::spellings( 'meta', $key ) );
				yield $this->item(
					'meta',
					$meta_type . ':' . $key,
					$key,
					$class,
					array(
						'meta_type' => $meta_type,
						'count'     => (int) $row['n'],
						'bytes'     => (int) $row['bytes'],
					)
				);
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Post types                                                          */
	/* ------------------------------------------------------------------ */

	private function scan_post_type() {
		global $wpdb;
		$rows = $wpdb->get_results( "SELECT post_type, COUNT(*) AS n, SUM(LENGTH(post_content)) AS bytes FROM {$wpdb->posts} GROUP BY post_type", ARRAY_A );
		foreach ( (array) $rows as $row ) {
			$type = (string) $row['post_type'];
			if ( in_array( $type, Protected_Names::CORE_POST_TYPES, true ) ) {
				$class = $this->core();
			} else {
				$runtime = post_type_exists( $type ) ? __( 'post type is registered', 'wp-cleanup' ) : null;
				$class   = $this->classifier->classify( array( $type ), $runtime );
			}
			yield $this->item(
				'post_type',
				$type,
				$type,
				$class,
				array(
					'count' => (int) $row['n'],
					'bytes' => (int) $row['bytes'],
				)
			);
		}
	}

	/* ------------------------------------------------------------------ */
	/* Orphaned rows (safe, structural)                                    */
	/* ------------------------------------------------------------------ */

	/**
	 * SQL describing each orphan kind. Shared with the cleaner so the rows
	 * backed up and deleted are exactly the rows counted here.
	 *
	 * @return array<string,array{label:string,table:string,pk:string,where:string}>
	 */
	public static function orphan_kinds() {
		global $wpdb;
		$post_taxonomies = array();
		foreach ( get_taxonomies( array(), 'objects' ) as $tax ) {
			$types = (array) $tax->object_type;
			if ( $types && 'link_category' !== $tax->name && ! array_diff( $types, get_post_types() ) ) {
				$post_taxonomies[] = $tax->name;
			}
		}
		$tax_in = $post_taxonomies ? "'" . implode( "','", array_map( 'esc_sql', $post_taxonomies ) ) . "'" : "''";

		return array(
			'postmeta'           => array(
				'label' => __( 'Post meta whose post no longer exists', 'wp-cleanup' ),
				'table' => $wpdb->postmeta,
				'pk'    => 'meta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = t.post_id)",
			),
			'usermeta'           => array(
				'label' => __( 'User meta whose user no longer exists', 'wp-cleanup' ),
				'table' => $wpdb->usermeta,
				'pk'    => 'umeta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->users} u WHERE u.ID = t.user_id)",
			),
			'termmeta'           => array(
				'label' => __( 'Term meta whose term no longer exists', 'wp-cleanup' ),
				'table' => $wpdb->termmeta,
				'pk'    => 'meta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->terms} x WHERE x.term_id = t.term_id)",
			),
			'commentmeta'        => array(
				'label' => __( 'Comment meta whose comment no longer exists', 'wp-cleanup' ),
				'table' => $wpdb->commentmeta,
				'pk'    => 'meta_id',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->comments} c WHERE c.comment_ID = t.comment_id)",
			),
			'term_relationships' => array(
				'label' => __( 'Post-to-term links whose post no longer exists', 'wp-cleanup' ),
				'table' => $wpdb->term_relationships,
				'pk'    => '',
				'where' => "NOT EXISTS (SELECT 1 FROM {$wpdb->posts} p WHERE p.ID = t.object_id)
				            AND t.term_taxonomy_id IN (SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy IN ({$tax_in}))",
			),
			'expired_transients' => array(
				'label' => __( 'Expired transients', 'wp-cleanup' ),
				'table' => $wpdb->options,
				'pk'    => 'option_id',
				'where' => self::expired_transients_where(),
			),
		);
	}

	/**
	 * Expired transients: timeout rows in the past, plus their value rows.
	 */
	private static function expired_transients_where() {
		global $wpdb;
		$now = time();
		return "(
			( t.option_name LIKE '\\_transient\\_timeout\\_%' OR t.option_name LIKE '\\_site\\_transient\\_timeout\\_%' )
			AND CAST(t.option_value AS UNSIGNED) < {$now}
		) OR (
			t.option_name LIKE '\\_transient\\_%' AND t.option_name NOT LIKE '\\_transient\\_timeout\\_%'
			AND EXISTS (SELECT 1 FROM {$wpdb->options} o WHERE o.option_name = CONCAT('_transient_timeout_', SUBSTRING(t.option_name, 12)) AND CAST(o.option_value AS UNSIGNED) < {$now})
		) OR (
			t.option_name LIKE '\\_site\\_transient\\_%' AND t.option_name NOT LIKE '\\_site\\_transient\\_timeout\\_%'
			AND EXISTS (SELECT 1 FROM {$wpdb->options} o WHERE o.option_name = CONCAT('_site_transient_timeout_', SUBSTRING(t.option_name, 17)) AND CAST(o.option_value AS UNSIGNED) < {$now})
		)";
	}

	private function scan_orphan() {
		global $wpdb;
		foreach ( self::orphan_kinds() as $kind => $def ) {
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$def['table']} t WHERE {$def['where']}" ); // phpcs:ignore
			if ( $count > 0 ) {
				yield $this->item(
					'orphan',
					$kind,
					$def['label'],
					$this->safe(
						'expired_transients' === $kind
							? __( 'Cached values past their expiry time. WordPress would discard them on next read anyway.', 'wp-cleanup' )
							: __( 'These rows point to objects that no longer exist; nothing can read them.', 'wp-cleanup' )
					),
					array( 'count' => $count )
				);
			}
		}
	}

	/* ------------------------------------------------------------------ */
	/* Folders                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Candidate folders: top level of uploads and wp-content.
	 *
	 * @return array<string,string> id => absolute path. Ids are "uploads/x" or "content/x".
	 */
	public static function folder_roots() {
		$uploads = wp_upload_dir( null, false );
		return array(
			'uploads' => wp_normalize_path( $uploads['basedir'] ),
			'content' => wp_normalize_path( WP_CONTENT_DIR ),
		);
	}

	/**
	 * Resolve a folder item id to an absolute path, refusing anything unexpected.
	 *
	 * @param string $id Item id.
	 * @return string|null
	 */
	public static function folder_path( $id ) {
		$parts = explode( '/', (string) $id, 2 );
		$roots = self::folder_roots();
		if ( 2 !== count( $parts ) || ! isset( $roots[ $parts[0] ] ) || '' === $parts[1] || false !== strpbrk( $parts[1], '/\\' ) || '.' === $parts[1][0] ) {
			return null;
		}
		return $roots[ $parts[0] ] . '/' . $parts[1];
	}

	private function scan_file() {
		$roots   = self::folder_roots();
		$ours    = Storage::dir_name();
		$uploads = $roots['uploads'];
		$skip    = array_map(
			'wp_normalize_path',
			array_merge( array( WP_PLUGIN_DIR, WPMU_PLUGIN_DIR ), (array) ( $GLOBALS['wp_theme_directories'] ?? array() ) )
		);

		foreach ( $roots as $root_key => $root ) {
			$entries = @scandir( $root ); // phpcs:ignore
			foreach ( (array) $entries as $entry ) {
				if ( '.' === $entry[0] ) {
					continue;
				}
				$path = $root . '/' . $entry;
				if ( ! is_dir( $path ) || is_link( $path ) ) {
					continue;
				}
				if ( 'content' === $root_key && 0 === strpos( $uploads . '/', $path . '/' ) ) {
					continue; // The uploads dir itself (custom UPLOADS locations included).
				}
				if ( in_array( $path, $skip, true ) ) {
					continue;
				}

				$core_dirs = 'uploads' === $root_key ? Protected_Names::CORE_UPLOAD_DIRS : Protected_Names::CORE_CONTENT_DIRS;
				if ( 'uploads' === $root_key && preg_match( '/^\d{4}$/', $entry ) ) {
					$class = $this->core( __( 'Year-based media folder.', 'wp-cleanup' ) );
				} elseif ( in_array( $entry, $core_dirs, true ) ) {
					$class = $this->core();
				} elseif ( $ours && $entry === $ours ) {
					$class = array(
						'status'     => Plugin::STATUS_IN_USE,
						'owner'      => 'WP Cleanup',
						'owner_slug' => 'wp-cleanup',
						'confidence' => 'high',
						'reason'     => __( 'WP Cleanup data and backups.', 'wp-cleanup' ),
						'group'      => 'wp-cleanup',
					);
				} else {
					$class = $this->classifier->classify( array( $entry, str_replace( '-', '_', $entry ) ) );
				}

				list( $files, $bytes, $capped ) = self::dir_size( $path );
				yield $this->item(
					'file',
					$root_key . '/' . $entry,
					( 'uploads' === $root_key ? 'uploads/' : 'wp-content/' ) . $entry,
					$class,
					array(
						'count'  => $files,
						'bytes'  => $bytes,
						'capped' => $capped,
					)
				);
			}
		}
	}

	/**
	 * @param string $dir Directory.
	 * @return array{0:int,1:int,2:bool} files, bytes, capped.
	 */
	public static function dir_size( $dir ) {
		$files = 0;
		$bytes = 0;
		try {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( $file->isFile() ) {
					++$files;
					$bytes += $file->getSize();
					if ( $files >= self::DIR_FILE_CAP ) {
						return array( $files, $bytes, true );
					}
				}
			}
		} catch ( \Exception $e ) {
			return array( $files, $bytes, true );
		}
		return array( $files, $bytes, false );
	}
}
