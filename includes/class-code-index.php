<?php
/**
 * Index of identifier-like string literals found in installed code.
 *
 * If an option name, table, hook or meta key (or its prefix) appears as a
 * string literal in an installed plugin or theme, that software may still use
 * it. This index is the main "still in use" signal and is cached on disk,
 * keyed by a fingerprint of the installed code.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Code_Index {

	const CACHE_FILE     = 'code-index.ser';
	const FORMAT         = 5; // Bump when indexing rules change.
	const MAX_FILE_BYTES = 8388608; // 8 MB; bigger PHP files are almost always generated data.
	const MAX_FILES      = 40000;   // Per source. A source that hits a limit is flagged as incompletely indexed.

	/** Directory names skipped while walking source trees. */
	const SKIP_DIRS = array( 'node_modules', '.git', '.svn', 'tests', 'languages' );

	/**
	 * Generic words that are never treated as an ownership prefix on their own.
	 */
	const STOPWORDS = array(
		'wp', 'wordpress', 'my', 'the', 'site', 'user', 'users', 'post', 'posts', 'page', 'pages', 'custom', 'plugin',
		'plugins', 'theme', 'themes', 'data', 'settings', 'setting', 'options', 'option', 'cache', 'config', 'general',
		'default', 'widget', 'widgets', 'admin', 'test', 'temp', 'tmp', 'version', 'update', 'updates', 'license',
		'active', 'enabled', 'disabled', 'last', 'first', 'new', 'old', 'meta', 'term', 'terms', 'comment', 'comments',
		'cron', 'event', 'events', 'log', 'logs', 'api', 'key', 'keys', 'token', 'status', 'type', 'name', 'value',
		'global', 'main', 'core', 'base', 'color', 'colors', 'font', 'fonts', 'image', 'images', 'media', 'menu', 'menus',
		'header', 'footer', 'sidebar', 'social', 'email', 'mail', 'form', 'forms', 'field', 'fields', 'block', 'blocks',
		'show', 'hide', 'enable', 'disable', 'is', 'has', 'get', 'set', 'add', 'delete', 'remove', 'dismissed', 'notice',
		'notices', 'install', 'installed', 'activation', 'activated', 'db', 'schema', 'transient', 'session', 'sessions',
		'queue', 'jobs', 'job', 'task', 'tasks', 'stats', 'report', 'reports', 'backup', 'backups', 'import', 'export',
		'shop', 'product', 'products', 'order', 'orders', 'cart', 'payment', 'google', 'facebook', 'twitter', 'youtube',
	);

	/** @var array<int,array> */
	private $sources = array();

	/** @var array<string,int[]> string => source ids */
	private $strings = array();

	/** @var array<string,int[]> declared class/interface/trait name => source ids */
	private $classes = array();

	/** @var array<string,array{ids:int[],raw:bool,exact:bool}> source token => owners; raw tokens match without a word boundary, exact ones only the whole name */
	private $tokens = array();

	/** @var int[]|null */
	private $core_ids = null;

	/** @var bool Whether the index was rebuilt during this request. */
	public $rebuilt = false;

	/**
	 * Load the cached index or build a fresh one.
	 *
	 * @param bool $fresh Force rebuilding.
	 * @return self
	 */
	public static function load( $fresh = false ) {
		$index   = new self();
		$sources = $index->discover_sources();
		$print   = md5( wp_json_encode( $sources ) . WPCU_VERSION . self::FORMAT . (int) apply_filters( 'wp_cleanup_index_max_files', self::MAX_FILES ) );
		$path    = Storage::path( self::CACHE_FILE );

		if ( ! $fresh && is_readable( $path ) ) {
			$cached = unserialize( (string) file_get_contents( $path ), array( 'allowed_classes' => false ) ); // phpcs:ignore
			if ( is_array( $cached ) && isset( $cached['print'] ) && $cached['print'] === $print ) {
				$index->sources = $cached['sources'];
				$index->strings = $cached['strings'];
				$index->classes = isset( $cached['classes'] ) ? $cached['classes'] : array();
				$index->build_tokens();
				return $index;
			}
		}

		$index->sources = $sources;
		$index->build_strings();
		$index->build_tokens();
		$index->rebuilt = true;
		file_put_contents( $path, serialize( array( 'print' => $print, 'sources' => $index->sources, 'strings' => $index->strings, 'classes' => $index->classes ) ), LOCK_EX ); // phpcs:ignore
		return $index;
	}

	/**
	 * Installed code bases: plugins, themes, mu-plugins, drop-ins.
	 *
	 * @return array<int,array> Each: kind, slug, name, path (dir or file), active, version, mtime, textdomain.
	 */
	private function discover_sources() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$sources = array();
		$self    = plugin_basename( WPCU_FILE );

		// WordPress core itself: exact references protect names missing from the static lists.
		foreach ( array( ABSPATH . WPINC, ABSPATH . 'wp-admin' ) as $core_dir ) {
			$sources[] = array(
				'kind'       => 'core',
				'slug'       => 'wordpress',
				'file'       => basename( $core_dir ),
				'name'       => 'WordPress',
				'path'       => $core_dir,
				'active'     => true,
				'version'    => get_bloginfo( 'version' ),
				'textdomain' => '',
				'mtime'      => 0,
			);
		}

		foreach ( get_plugins() as $file => $data ) {
			if ( $file === $self ) {
				continue; // Our own strings (signatures) would claim everything.
			}
			$is_dir    = false !== strpos( $file, '/' );
			$slug      = $is_dir ? dirname( $file ) : basename( $file, '.php' );
			$path      = WP_PLUGIN_DIR . '/' . ( $is_dir ? dirname( $file ) : $file );
			$sources[] = array(
				'kind'       => 'plugin',
				'slug'       => $slug,
				'file'       => $file,
				'name'       => $data['Name'],
				'path'       => $path,
				'active'     => is_plugin_active( $file ),
				'version'    => $data['Version'],
				'textdomain' => $data['TextDomain'],
				'mtime'      => (int) @filemtime( WP_PLUGIN_DIR . '/' . $file ), // phpcs:ignore
			);
		}

		$active_themes = array_unique( array( get_stylesheet(), get_template() ) );
		foreach ( wp_get_themes() as $slug => $theme ) {
			$sources[] = array(
				'kind'       => 'theme',
				'slug'       => $slug,
				'file'       => $slug,
				'name'       => $theme->get( 'Name' ),
				'path'       => $theme->get_stylesheet_directory(),
				'active'     => in_array( $slug, $active_themes, true ),
				'version'    => $theme->get( 'Version' ),
				'textdomain' => $theme->get( 'TextDomain' ),
				'mtime'      => (int) @filemtime( $theme->get_stylesheet_directory() . '/style.css' ), // phpcs:ignore
			);
		}

		if ( is_dir( WPMU_PLUGIN_DIR ) ) {
			$sources[] = array(
				'kind'       => 'mu-plugin',
				'slug'       => 'mu-plugins',
				'file'       => 'mu-plugins',
				'name'       => __( 'Must-use plugins', 'wp-cleanup' ),
				'path'       => WPMU_PLUGIN_DIR,
				'active'     => true,
				'version'    => '',
				'textdomain' => '',
				'mtime'      => self::dir_mtime( WPMU_PLUGIN_DIR ),
			);
		}

		foreach ( array_keys( _get_dropins() ) as $dropin ) {
			$path = WP_CONTENT_DIR . '/' . $dropin;
			if ( is_file( $path ) ) {
				$sources[] = array(
					'kind'       => 'dropin',
					'slug'       => basename( $dropin, '.php' ),
					'file'       => $dropin,
					'name'       => $dropin,
					'path'       => $path,
					'active'     => true,
					'version'    => '',
					'textdomain' => '',
					'mtime'      => (int) @filemtime( $path ), // phpcs:ignore
				);
			}
		}

		return $sources;
	}

	/**
	 * @param string $dir Directory.
	 * @return int Latest mtime of direct children.
	 */
	private static function dir_mtime( $dir ) {
		$max = (int) @filemtime( $dir ); // phpcs:ignore
		foreach ( (array) glob( trailingslashit( $dir ) . '*' ) as $file ) {
			$max = max( $max, (int) @filemtime( $file ) ); // phpcs:ignore
		}
		return $max;
	}

	private function build_strings() {
		$this->strings = array();
		$this->classes = array();
		/**
		 * Filter the maximum number of PHP files indexed per plugin/theme.
		 *
		 * @param int $max Files per source.
		 */
		$max = (int) apply_filters( 'wp_cleanup_index_max_files', self::MAX_FILES );
		foreach ( $this->sources as $id => $source ) {
			$budget = $max;
			$whole  = true;
			foreach ( $this->php_files( $source['path'], $budget ) as $file ) {
				$whole = $this->index_file( $file, $id ) && $whole;
			}
			// Fail safe: callers treat names as possibly in use while any source is incomplete.
			$this->sources[ $id ]['incomplete'] = ! $whole || $budget < 0;
		}
	}

	/**
	 * Sources that could not be fully indexed (file limit, oversized or unreadable files).
	 *
	 * @return array[]
	 */
	public function incomplete_sources() {
		return array_values(
			array_filter(
				$this->sources,
				static function ( $source ) {
					return ! empty( $source['incomplete'] );
				}
			)
		);
	}

	/**
	 * @param string $path    File or directory.
	 * @param int    $budget  Remaining file budget (by reference).
	 * @return \Generator<string>
	 */
	private function php_files( $path, &$budget ) {
		if ( is_file( $path ) ) {
			--$budget;
			yield $path;
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$stack = array( $path );
		while ( $stack ) { // The budget is checked per file so exhaustion always leaves it negative.
			$dir     = array_pop( $stack );
			$entries = @scandir( $dir ); // phpcs:ignore
			if ( ! $entries ) {
				continue;
			}
			foreach ( $entries as $entry ) {
				if ( '.' === $entry[0] ) {
					continue;
				}
				$full = $dir . '/' . $entry;
				if ( is_dir( $full ) ) {
					if ( ! is_link( $full ) && ! in_array( strtolower( $entry ), self::SKIP_DIRS, true ) ) {
						$stack[] = $full;
					}
				} elseif ( substr( $entry, -4 ) === '.php' || substr( $entry, -4 ) === '.inc' ) {
					if ( --$budget < 0 ) {
						return;
					}
					yield $full;
				}
			}
		}
	}

	/**
	 * @param string $file PHP file.
	 * @param int    $id   Source id.
	 * @return bool False when the file could not be indexed.
	 */
	private function index_file( $file, $id ) {
		$size = (int) @filesize( $file ); // phpcs:ignore
		if ( 0 === $size ) {
			return true;
		}
		if ( $size < 0 || $size > self::MAX_FILE_BYTES || ! is_readable( $file ) ) {
			return false;
		}
		$code = (string) file_get_contents( $file ); // phpcs:ignore
		// Single- or double-quoted identifier-like literals, 3..150 chars.
		preg_match_all( '/[\'"]([A-Za-z0-9_\-\.:]{3,150})[\'"]/', $code, $m );
		// Declared class/interface/trait names: names like 'schema-' . static::class end with one.
		if ( preg_match_all( '/\b(?:class|interface|trait)\s+([A-Za-z_][A-Za-z0-9_]{7,120})\b/', $code, $d ) ) {
			foreach ( array_unique( $d[1] ) as $class ) {
				$class = strtolower( $class );
				if ( ! isset( $this->classes[ $class ] ) || ! in_array( $id, $this->classes[ $class ], true ) ) {
					$this->classes[ $class ][] = $id;
				}
			}
		}
		foreach ( array_unique( $m[1] ) as $literal ) {
			$literal = strtolower( $literal );
			if ( ctype_digit( $literal ) ) {
				continue;
			}
			if ( ! isset( $this->strings[ $literal ] ) ) {
				$this->strings[ $literal ] = array( $id );
			} elseif ( ! in_array( $id, $this->strings[ $literal ], true ) ) {
				$this->strings[ $literal ][] = $id;
			}
		}
		return true;
	}

	/**
	 * Ownership tokens per source: slug, slug with underscores, text domain,
	 * and signature prefixes when the source is a known plugin/theme.
	 */
	private function build_tokens() {
		$this->tokens = array();
		$signatures   = Signatures::all();
		foreach ( $this->sources as $id => $source ) {
			if ( ! in_array( $source['kind'], array( 'plugin', 'theme' ), true ) ) {
				continue;
			}
			// Slug-derived tokens must end at a word boundary; curated signature prefixes match raw,
			// and curated exact names ("name$") only the whole name.
			$tokens = array(
				$source['slug']                        => 'word',
				str_replace( '-', '_', $source['slug'] ) => 'word',
				(string) $source['textdomain']         => 'word',
			);
			if ( isset( $signatures[ $source['slug'] ] ) ) {
				foreach ( $signatures[ $source['slug'] ]['prefixes'] as $prefix ) {
					if ( Signatures::is_exact( $prefix ) ) {
						$tokens[ substr( $prefix, 0, -1 ) ] = 'exact';
					} else {
						$tokens[ $prefix ] = 'raw';
					}
				}
			}
			foreach ( $tokens as $token => $mode ) {
				$token = strtolower( (string) $token );
				if ( strlen( $token ) < 3 && 'word' === $mode ) {
					continue;
				}
				if ( '' === $token || in_array( $token, self::STOPWORDS, true ) ) {
					continue;
				}
				if ( ! isset( $this->tokens[ $token ] ) ) {
					$this->tokens[ $token ] = array( 'ids' => array(), 'raw' => false, 'exact' => true );
				}
				$this->tokens[ $token ]['ids'][] = $id;
				$this->tokens[ $token ]['raw']   = $this->tokens[ $token ]['raw'] || 'raw' === $mode;
				// A token is exact-only when every source declared it that way.
				$this->tokens[ $token ]['exact'] = $this->tokens[ $token ]['exact'] && 'exact' === $mode;
			}
		}
	}

	/**
	 * @param int $id Source id.
	 * @return array|null
	 */
	public function source( $id ) {
		return isset( $this->sources[ $id ] ) ? $this->sources[ $id ] : null;
	}

	/**
	 * @return array<int,array>
	 */
	public function sources() {
		return $this->sources;
	}

	/**
	 * Sources whose code contains the name, or a prefix of it ending at a
	 * separator (e.g. 'wpseo_' for 'wpseo_titles').
	 *
	 * @param string $name Normalized name.
	 * @return array{ids:int[],match:string,exact:bool} Matching source ids, the literal that matched, and whether it was the whole name.
	 */
	public function references( $name ) {
		$name = strtolower( $name );
		if ( isset( $this->strings[ $name ] ) ) {
			return array( 'ids' => $this->strings[ $name ], 'match' => $name, 'exact' => true );
		}
		// Longest prefix first, so the most specific owner wins.
		$len = strlen( $name );
		for ( $i = $len - 1; $i >= 3; $i-- ) {
			if ( false === strpos( '_-.:', $name[ $i ] ) ) {
				continue;
			}
			$prefix = substr( $name, 0, $i + 1 ); // Keep separator: 'wpseo_'.
			$word   = substr( $name, 0, $i );
			if ( in_array( trim( $word, '_-.:' ), self::STOPWORDS, true ) ) {
				continue;
			}
			if ( isset( $this->strings[ $prefix ] ) ) {
				// Core uses many generic prefixes ('category_', 'user_'); only exact core hits count.
				$ids = array_values( array_diff( $this->strings[ $prefix ], $this->core_ids() ) );
				if ( $ids ) {
					return array( 'ids' => $ids, 'match' => $prefix, 'exact' => false );
				}
			}
		}
		// Tails after a separator that are declared class names, for names built as 'prefix-' . ClassName.
		for ( $i = 2; $i < $len - 8; $i++ ) {
			if ( false === strpos( '_-.:', $name[ $i ] ) ) {
				continue;
			}
			$tail = substr( $name, $i + 1 );
			if ( isset( $this->classes[ $tail ] ) ) {
				$ids = array_values( array_diff( $this->classes[ $tail ], $this->core_ids() ) );
				if ( $ids ) {
					return array( 'ids' => $ids, 'match' => $tail, 'exact' => false );
				}
			}
		}
		return array( 'ids' => array(), 'match' => '', 'exact' => false );
	}

	/**
	 * @return int[] Source ids of WordPress core.
	 */
	public function core_ids() {
		if ( null === $this->core_ids ) {
			$this->core_ids = array();
			foreach ( $this->sources as $id => $source ) {
				if ( 'core' === $source['kind'] ) {
					$this->core_ids[] = $id;
				}
			}
		}
		return $this->core_ids;
	}

	/**
	 * Whether WordPress core code contains this exact literal.
	 *
	 * @param string $name Name.
	 */
	public function core_references( $name ) {
		$name = strtolower( $name );
		return isset( $this->strings[ $name ] ) && array_intersect( $this->strings[ $name ], $this->core_ids() );
	}

	/**
	 * Installed sources whose ownership token is a prefix of the name.
	 *
	 * @param string $name Normalized name.
	 * @return array{ids:int[],match:string}
	 */
	public function token_owners( $name ) {
		$name = strtolower( $name );
		$best = array( 'ids' => array(), 'match' => '' );
		foreach ( $this->tokens as $token => $info ) {
			$token = (string) $token;
			if ( strlen( $token ) <= strlen( $best['match'] ) ) {
				continue;
			}
			if ( $info['exact'] ) {
				$hit = $name === $token;
			} else {
				$hit = $info['raw'] ? 0 === strpos( $name, $token ) : self::has_prefix( $name, $token );
			}
			if ( $hit ) {
				$best = array( 'ids' => array_values( array_unique( $info['ids'] ) ), 'match' => $token );
			}
		}
		return $best;
	}

	/**
	 * Installed plugins/themes whose slug appears inside the name, for names built
	 * around a slug such as update caches ("puc_external_updates_theme-{slug}",
	 * "external_updates-{slug}"). The slug must stand as its own word.
	 *
	 * @param string $name Normalized name.
	 * @return array{ids:int[],match:string}
	 */
	public function slug_owners( $name ) {
		$name = strtolower( $name );
		$best = array( 'ids' => array(), 'match' => '' );
		foreach ( $this->sources as $id => $source ) {
			if ( ! in_array( $source['kind'], array( 'plugin', 'theme' ), true ) ) {
				continue;
			}
			$slug = strtolower( (string) $source['slug'] );
			foreach ( array_unique( array( $slug, str_replace( '-', '_', $slug ) ) ) as $needle ) {
				// Short or generic slugs ("blocks", "forms") would match too much.
				if ( strlen( $needle ) < 6 || in_array( $needle, self::STOPWORDS, true ) || strlen( $needle ) < strlen( $best['match'] ) ) {
					continue;
				}
				$at = strpos( $name, $needle );
				while ( false !== $at ) {
					$end    = $at + strlen( $needle );
					$before = 0 === $at || false !== strpos( '_-.:|', $name[ $at - 1 ] );
					$after  = strlen( $name ) === $end || false !== strpos( '_-.:|', $name[ $end ] );
					if ( $before && $after ) {
						if ( strlen( $needle ) > strlen( $best['match'] ) ) {
							$best = array( 'ids' => array(), 'match' => $needle );
						}
						$best['ids'][] = $id;
						break;
					}
					$at = strpos( $name, $needle, $at + 1 );
				}
			}
		}
		$best['ids'] = array_values( array_unique( $best['ids'] ) );
		return $best;
	}

	/**
	 * Whether $name starts with $prefix at a word boundary.
	 *
	 * 'wpseo' matches 'wpseo', 'wpseo_titles', 'wpseo-x' but not 'wpseofoo'.
	 * Prefixes that already end with a separator match anything after them.
	 *
	 * @param string $name   Lower-case name.
	 * @param string $prefix Lower-case prefix.
	 */
	public static function has_prefix( $name, $prefix ) {
		if ( '' === $prefix || 0 !== strpos( $name, $prefix ) ) {
			return false;
		}
		if ( strlen( $name ) === strlen( $prefix ) ) {
			return true;
		}
		$last = substr( $prefix, -1 );
		if ( false !== strpos( '_-.:', $last ) ) {
			return true;
		}
		return false !== strpos( '_-.:', $name[ strlen( $prefix ) ] );
	}
}
