<?php
/**
 * Restorable backup sets written before anything is deleted.
 *
 * Layout: <data dir>/backups/<id>/
 *   manifest.json     what was removed and how each item went
 *   item-<n>.sql.php  one full SQL statement per line (tables, rows), after a
 *                     first line that makes PHP stop, so the file is empty if
 *                     it is ever served through PHP (sets from 0.12.1 and
 *                     earlier have item-<n>.sql without that line; still read)
 *   item-<n>.json     cron events
 *   files/<n>/        quarantined folders (moved, not copied), with files a web
 *                     server could run or obey renamed with DISARM_SUFFIX
 *
 * Values are written as hex literals so any bytes (serialized PHP, binary,
 * odd charsets) round-trip exactly without SQL escaping pitfalls.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Backup {

	const ID_PATTERN   = '/^\d{8}-\d{6}-[a-z0-9]{8}$/';
	const INSERT_BYTES = 1048576; // Split multi-row INSERTs at ~1 MB to stay under max_allowed_packet.

	/** First line of a SQL file: PHP stops at once and never parses the rest. */
	const SQL_GUARD = '<?php exit; __halt_compiler(); ?>';

	/**
	 * Appended to quarantined files a web server could execute or read rules from.
	 * No dot, so Apache's multi-extension handlers no longer see ".php" either.
	 */
	const DISARM_SUFFIX = '-wpcu-disarmed';

	/** Names that run as code or configure the server (PHP, CGI, SSI, ASP/JSP, .htaccess, .user.ini, web.config). */
	const RUNNABLE = '/(?:\.(?:php\d*|phtml|pht|phar|phps|shtml?|cgi|pl|py|aspx?|jsp)|^\.htaccess|^\.user\.ini|^php\.ini|^web\.config)$/i';

	/** @var string */
	public $id;

	/** @var string */
	public $dir;

	/** @var array */
	public $manifest;

	/** @var array<string,array> Column info cache per table. */
	private static $columns = array();

	/** @var resource|false|null Shared lock on this set, held while this object lives (see Storage::lock_set()). */
	private $lock = null;

	/**
	 * @param string $id  Backup id.
	 * @param string $dir Absolute directory.
	 */
	private function __construct( $id, $dir ) {
		$this->id  = $id;
		$this->dir = $dir;
	}

	public function __destruct() {
		$this->unlock();
	}

	private function unlock() {
		if ( is_resource( $this->lock ) ) {
			flock( $this->lock, LOCK_UN );
			fclose( $this->lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$this->lock = null;
	}

	/**
	 * @return string Absolute path of the backups root.
	 */
	public static function root() {
		$root = Storage::path( 'backups' );
		Storage::ensure_protected_dir( $root );
		return $root;
	}

	/**
	 * Backups roots to read: the current one, then this site's older folders in
	 * uploads whose sets have not all been moved yet.
	 *
	 * @return string[]
	 */
	public static function roots() {
		$roots = array( self::root() );
		foreach ( Storage::legacy_dirs() as $legacy ) {
			if ( is_dir( $legacy . '/backups' ) ) {
				$roots[] = $legacy . '/backups';
			}
		}
		return $roots;
	}

	/**
	 * Refuse new data for this set where it would not be private (see
	 * Storage::assert_safe_for_backups()). Restore and delete never call this.
	 *
	 * @throws \RuntimeException When refused.
	 */
	public function assert_writable() {
		Storage::assert_safe_for_backups( $this->dir );
	}

	/**
	 * Start a new backup set.
	 *
	 * @return self
	 */
	public static function start() {
		Storage::assert_safe_for_backups();
		$id  = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
		$dir = self::root() . '/' . $id;
		Storage::ensure_protected_dir( $dir );
		$user           = wp_get_current_user();
		$backup         = new self( $id, $dir );
		$backup->lock   = Storage::lock_set( $dir, false );
		$backup->manifest = array(
			'id'             => $id,
			'created'        => time(),
			'user'           => $user && $user->exists() ? $user->user_login : ( defined( 'WP_CLI' ) && WP_CLI ? 'wp-cli' : '' ),
			'site'           => home_url(),
			'wp_version'     => get_bloginfo( 'version' ),
			'plugin_version' => WPCU_VERSION,
			'items'          => array(),
			'restored'       => null,
		);
		$backup->save();
		return $backup;
	}

	/**
	 * @param string $id Backup id.
	 * @return self|null
	 */
	public static function open( $id ) {
		if ( ! is_string( $id ) || ! preg_match( self::ID_PATTERN, $id ) ) {
			return null;
		}
		// Twice: a set moved out of uploads while we waited for its lock is found in its new place.
		for ( $try = 0; $try < 2; $try++ ) {
			foreach ( self::roots() as $root ) {
				$dir = $root . '/' . $id;
				if ( ! is_readable( $dir . '/manifest.json' ) ) {
					continue;
				}
				$lock = Storage::lock_set( $dir, false );
				clearstatcache();
				$data = is_readable( $dir . '/manifest.json' ) ? json_decode( (string) file_get_contents( $dir . '/manifest.json' ), true ) : null; // phpcs:ignore
				if ( is_array( $data ) ) {
					$backup           = new self( $id, $dir );
					$backup->manifest = $data;
					$backup->lock     = $lock;
					return $backup;
				}
				if ( is_resource( $lock ) ) {
					fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				}
			}
		}
		return null;
	}

	/**
	 * @return array[] Manifests, newest first.
	 */
	public static function all() {
		$list = array();
		foreach ( self::roots() as $root ) {
			foreach ( (array) Storage::subdirs( $root ) as $dir ) {
				$backup = isset( $list[ basename( $dir ) ] ) ? null : self::open( basename( $dir ) );
				if ( $backup ) {
					$manifest            = $backup->manifest;
					$manifest['bytes']   = self::tree_bytes( $backup->dir );
					$list[ $backup->id ] = $manifest;
				}
			}
		}
		$list = array_values( $list );
		usort(
			$list,
			static function ( $a, $b ) {
				return $b['created'] - $a['created'];
			}
		);
		return $list;
	}

	/**
	 * @param string $dir Directory.
	 */
	private static function tree_bytes( $dir ) {
		list( , $bytes ) = Scanner::dir_size( $dir );
		return $bytes;
	}

	public function save() {
		$json = wp_json_encode( $this->manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === file_put_contents( $this->dir . '/manifest.json', $json, LOCK_EX ) ) { // phpcs:ignore
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( __( 'Could not write the backup manifest.', 'wp-cleanup' ) );
		}
	}

	/**
	 * Register an item in the manifest; returns its section number.
	 *
	 * @param array $item Scanner item.
	 * @return int
	 */
	public function add_item( array $item ) {
		$this->assert_writable();
		$n                          = count( $this->manifest['items'] );
		$this->manifest['items'][ $n ] = array(
			'n'        => $n,
			'type'     => $item['type'],
			'id'       => $item['id'],
			'label'    => $item['label'],
			'status'   => $item['status'],
			'owner'    => $item['owner'],
			'count'    => $item['count'],
			'bytes'    => $item['bytes'],
			'result'   => 'pending',
			'message'  => '',
			'restored' => null,
		);
		$this->save();
		return $n;
	}

	/**
	 * @param int    $n       Section.
	 * @param string $result  deleted|failed|skipped.
	 * @param string $message Detail.
	 */
	public function set_result( $n, $result, $message = '' ) {
		$this->manifest['items'][ $n ]['result']  = $result;
		$this->manifest['items'][ $n ]['message'] = $message;
		$this->save();
	}

	/**
	 * Store extra restore data for a section (e.g. term ids to recount).
	 *
	 * @param int    $n     Section.
	 * @param string $key   Key.
	 * @param mixed  $value JSON-encodable value.
	 */
	public function set_extra( $n, $key, $value ) {
		$this->manifest['items'][ $n ]['extra'][ $key ] = $value;
		$this->save();
	}

	/**
	 * @param int $n Section.
	 * @return string SQL file path for a section.
	 */
	public function sql_file( $n ) {
		$legacy = $this->dir . '/item-' . (int) $n . '.sql';
		return is_file( $legacy ) ? $legacy : $legacy . '.php';
	}

	/**
	 * @param int    $n    Section.
	 * @param string $line One SQL statement without newlines.
	 */
	public function write_sql( $n, $line ) {
		$file = $this->sql_file( $n );
		$head = file_exists( $file ) ? '' : self::SQL_GUARD . "\n";
		if ( false === file_put_contents( $file, $head . $line . "\n", FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( __( 'Could not write the backup file. Is the disk full?', 'wp-cleanup' ) );
		}
	}

	/**
	 * Append INSERT statements for rows of a table.
	 *
	 * @param int    $n     Section.
	 * @param string $table Table name.
	 * @param array  $rows  ARRAY_A rows.
	 * @param string $verb  INSERT, or REPLACE for before-images of rows that are updated rather than deleted.
	 */
	public function write_rows( $n, $table, array $rows, $verb = 'INSERT' ) {
		if ( ! $rows ) {
			return;
		}
		$binary  = self::binary_columns( $table );
		$columns = array_keys( $rows[0] );
		$head    = ( 'REPLACE' === $verb ? 'REPLACE' : 'INSERT' ) . ' INTO `' . self::ident( $table ) . '` (`' . implode( '`,`', array_map( array( __CLASS__, 'ident' ), $columns ) ) . '`) VALUES ';
		$values  = array();
		$size    = 0;
		foreach ( $rows as $row ) {
			$parts = array();
			foreach ( $columns as $col ) {
				$parts[] = self::literal( $row[ $col ], isset( $binary[ $col ] ) );
			}
			$tuple    = '(' . implode( ',', $parts ) . ')';
			$values[] = $tuple;
			$size    += strlen( $tuple );
			if ( $size >= self::INSERT_BYTES ) {
				$this->write_sql( $n, $head . implode( ',', $values ) . ';' );
				$values = array();
				$size   = 0;
			}
		}
		if ( $values ) {
			$this->write_sql( $n, $head . implode( ',', $values ) . ';' );
		}
	}

	/**
	 * Dump a whole table: structure plus all rows.
	 *
	 * @param int    $n     Section.
	 * @param string $table Table name.
	 */
	public function write_table( $n, $table ) {
		global $wpdb;
		$create = $wpdb->get_row( 'SHOW CREATE TABLE `' . self::ident( $table ) . '`', ARRAY_N ); // phpcs:ignore
		if ( empty( $create[1] ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( __( 'Could not read the table structure.', 'wp-cleanup' ) );
		}
		$this->write_sql( $n, str_replace( array( "\r\n", "\n", "\r" ), ' ', $create[1] ) . ';' );

		$offset = 0;
		$batch  = 1000;
		do {
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM `' . self::ident( $table ) . '` LIMIT %d OFFSET %d', $batch, $offset ), ARRAY_A ); // phpcs:ignore
			$this->write_rows( $n, $table, (array) $rows );
			$offset += $batch;
		} while ( is_array( $rows ) && count( $rows ) === $batch );
	}

	/**
	 * @param int   $n      Section.
	 * @param array $events List of cron events.
	 */
	public function write_json( $n, array $events ) {
		$json = wp_json_encode( $events, JSON_UNESCAPED_SLASHES );
		if ( false === file_put_contents( $this->dir . '/item-' . (int) $n . '.json', $json, LOCK_EX ) ) { // phpcs:ignore
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( __( 'Could not write the backup file. Is the disk full?', 'wp-cleanup' ) );
		}
	}

	/**
	 * @param int $n Section.
	 * @return string Directory where a quarantined folder is moved.
	 */
	public function quarantine_dir( $n ) {
		$dir = $this->dir . '/files/' . (int) $n;
		wp_mkdir_p( $dir );
		return $dir;
	}

	/**
	 * Rename every file in a quarantined folder that a web server could run or
	 * read rules from, so a copy that is ever served is inert. Links are renamed,
	 * never followed.
	 *
	 * @param string $dir Quarantined folder.
	 * @return int Files that could not be renamed.
	 */
	public static function disarm_tree( $dir ) {
		$failed = 0;
		foreach ( self::tree_files( $dir ) as $path ) {
			if ( preg_match( self::RUNNABLE, basename( $path ) ) && ! @rename( $path, $path . self::DISARM_SUFFIX ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Counted.
				++$failed;
			}
		}
		return $failed;
	}

	/**
	 * Undo disarm_tree(). Folders quarantined by older versions have nothing to undo.
	 *
	 * @param string $dir Quarantined folder.
	 * @return int Files that could not be renamed back.
	 */
	public static function rearm_tree( $dir ) {
		$failed = 0;
		$length = strlen( self::DISARM_SUFFIX );
		foreach ( self::tree_files( $dir ) as $path ) {
			$original = substr( $path, 0, -$length );
			if ( substr( $path, -$length ) !== self::DISARM_SUFFIX || ! preg_match( self::RUNNABLE, basename( $original ) ) ) {
				continue;
			}
			if ( file_exists( $original ) || is_link( $original ) || ! @rename( $path, $original ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Counted.
				++$failed;
			}
		}
		return $failed;
	}

	/**
	 * Files and links in a folder tree, without descending into linked folders.
	 *
	 * @param string $dir Folder.
	 * @return string[]
	 */
	private static function tree_files( $dir ) {
		$files = array();
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return $files;
		}
		try {
			$tree = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $tree as $entry ) {
				if ( $entry->isLink() || ! $entry->isDir() ) {
					$files[] = $entry->getPathname();
				}
			}
		} catch ( \UnexpectedValueException $e ) {
			unset( $e ); // An unreadable subfolder; what was listed is still handled.
		}
		return $files;
	}

	/* ------------------------------------------------------------------ */
	/* Restore                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Restore every deleted item of this set.
	 *
	 * @return array<int,array{label:string,ok:bool,message:string}>
	 */
	public function restore( $only_item = null ) {
		if ( null !== $only_item && ( ! is_int( $only_item ) || ! isset( $this->manifest['items'][ $only_item ] ) ) ) {
			throw new \InvalidArgumentException( __( 'Backup item not found.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		$selected = null;
		if ( null !== $only_item ) {
			$selected = array( $only_item => true );
			$partner = isset( $this->manifest['items'][ $only_item ]['extra']['merge_partner'] ) ? (int) $this->manifest['items'][ $only_item ]['extra']['merge_partner'] : -1;
			if ( isset( $this->manifest['items'][ $partner ] ) ) {
				$selected[ $partner ] = true;
			}
		}
		$report = array();
		$tt_ids = array();
		// Undo newest first: later items may have changed rows an earlier item also changed.
		foreach ( array_reverse( $this->manifest['items'], true ) as $n => $item ) {
			if ( ( null !== $selected && ! isset( $selected[ $n ] ) ) || 'deleted' !== $item['result'] || ! empty( $item['restored'] ) ) {
				continue;
			}
			try {
				$message = $this->restore_item( (int) $n, $item );
				$ok      = true;
			} catch ( \Exception $e ) {
				$message = $e->getMessage();
				$ok      = false;
			}
			$this->manifest['items'][ $n ]['restored'] = $ok ? time() : null;
			if ( $ok && ! empty( $item['extra']['tt_ids'] ) ) {
				$tt_ids = array_merge( $tt_ids, $item['extra']['tt_ids'] );
			}
			$report[] = array(
				'label'   => $item['label'],
				'ok'      => $ok,
				'message' => $message,
			);
		}
		if ( ! $report ) {
			return $report;
		}
		$pending = array_filter( $this->manifest['items'], static function ( $item ) {
			return 'deleted' === $item['result'] && empty( $item['restored'] );
		} );
		$this->manifest['restored'] = $pending ? null : time();
		$this->save();
		wp_cache_flush();
		if ( $tt_ids ) {
			Cleaner::recount_terms( array_map( 'intval', array_unique( $tt_ids ) ) );
		}
		return $report;
	}

	/**
	 * Copy back only those backed-up image files that are missing now and that another
	 * attachment still names. No database row changes and the backup keeps its copy, so
	 * this works where a full restore is refused because a row was edited later.
	 *
	 * @param int $n Section of a converted or removed image.
	 * @return array{copied:string[],refused:string[],stranded:string[]} Paths copied back; shared paths whose folder is not a plain folder inside uploads; and paths where an emptied file may be left after a failed copy or a folder change (remove manually).
	 * @throws \RuntimeException When a file cannot be copied.
	 */
	public function restore_missing_files( $n ) {
		if ( ! is_int( $n ) || ! isset( $this->manifest['items'][ $n ] ) ) {
			throw new \InvalidArgumentException( __( 'Backup item not found.', 'wp-cleanup' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
		}
		$item = $this->manifest['items'][ $n ];
		if ( ! in_array( $item['type'], array( 'media', 'media_delete' ), true ) || 'deleted' !== $item['result'] || ! empty( $item['restored'] ) ) {
			return array( 'copied' => array(), 'refused' => array(), 'stranded' => array() );
		}
		$root = realpath( wp_upload_dir( null, false )['basedir'] );
		$from = realpath( $this->dir . '/files/' . $n . '/orig' );
		if ( ! $root || ! $from ) {
			return array( 'copied' => array(), 'refused' => array(), 'stranded' => array() );
		}
		$root    = untrailingslashit( wp_normalize_path( $root ) );
		$from    = untrailingslashit( wp_normalize_path( $from ) );
		$missing = array();
		foreach ( (array) ( isset( $item['extra']['moved'] ) ? $item['extra']['moved'] : array() ) as $rel ) {
			if ( ! self::safe_relative( $rel ) ) {
				continue; // Not a plain uploads-relative path; never followed.
			}
			$source = realpath( $from . '/' . $rel );
			$target = $root . '/' . $rel;
			if ( $source && 0 === strpos( wp_normalize_path( $source ), $from . '/' ) && is_file( $source ) && ! is_link( $target ) && ! file_exists( $target ) ) {
				$missing[ $rel ] = $source;
			}
		}
		$copied   = array();
		$refused  = array();
		$stranded = array();
		foreach ( $missing ? Media_Owners::shared( 0, array_keys( $missing ) ) : array() as $rel ) {
			$folder = self::safe_folder( $root, dirname( $rel ) );
			if ( null === $folder ) {
				$refused[] = $rel; // A link or something outside uploads: never written through.
				continue;
			}
			$target = $folder . '/' . wp_basename( $rel );
			/**
			 * Test seam: runs between checking the folder and creating the file.
			 *
			 * @param string $folder Checked canonical folder.
			 * @param string $rel    Uploads-relative path.
			 */
			do_action( 'wp_cleanup_restore_file_before_open', $folder, $rel );
			$out = @fopen( $target, 'xb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- Exclusive create: never overwrites, never follows a final link.
			if ( ! $out ) {
				if ( is_link( $target ) || file_exists( $target ) ) {
					continue; // Something appeared there meanwhile; leave it.
				}
				throw new \RuntimeException( sprintf( /* translators: %s: file path */ __( 'Could not copy %s back.', 'wp-cleanup' ), $rel ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Escaped in admin notices.
			}
			/*
			 * From here on the only file this code ever touches is the one behind $out.
			 *
			 * PHP has no openat()/dirfd, so someone with local write access to uploads can
			 * still replace a parent folder between any check and any later use of the
			 * pathname. The identity checks below (handle vs. path: same device and inode,
			 * unchanged realpath, before writing and again after copying) catch a swap at
			 * those points, but cannot exclude one at every instant. Therefore nothing is
			 * ever unlinked by pathname: cleanup empties our own file through its handle and
			 * reports it as stranded. The worst remaining effect of such a race is a
			 * misplaced or stranded (empty) file, never deletion of an existing one.
			 */
			if ( ! self::is_at( $out, $target ) ) {
				self::strand( $out );
				$stranded[] = $rel;
				continue;
			}
			/**
			 * Test seam: return false to simulate a failed copy.
			 *
			 * @param bool   $ok  Whether to copy.
			 * @param string $rel Uploads-relative path.
			 */
			$ok = apply_filters( 'wp_cleanup_restore_file_copy', true, $rel );
			$in = $ok ? @fopen( $missing[ $rel ], 'rb' ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- Backup copy.
			$ok = $in && false !== stream_copy_to_stream( $in, $out ) && fflush( $out );
			if ( $in ) {
				fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
			// A failed copy, or a path that no longer leads to our file, is never reported as restored.
			if ( ! $ok || ! self::is_at( $out, $target ) ) {
				self::strand( $out );
				$stranded[] = $rel;
				continue;
			}
			if ( ! fclose( $out ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
				$stranded[] = $rel;
				continue;
			}
			$copied[] = $rel;
		}
		if ( $copied ) {
			$before = isset( $item['extra']['files_restored'] ) ? (array) $item['extra']['files_restored'] : array();
			$this->set_extra( $n, 'files_restored', array_values( array_unique( array_merge( $before, $copied ) ) ) );
			Media_Inventory::flush();
		}
		if ( $stranded ) {
			$before = isset( $item['extra']['files_stranded'] ) ? (array) $item['extra']['files_stranded'] : array();
			$this->set_extra( $n, 'files_stranded', array_values( array_unique( array_merge( $before, $stranded ) ) ) );
		}
		return array( 'copied' => $copied, 'refused' => $refused, 'stranded' => $stranded );
	}

	/**
	 * Whether the open handle is the file this canonical path names right now.
	 *
	 * @param resource $handle Open file.
	 * @param string   $path   Canonical path it was created at.
	 */
	private static function is_at( $handle, $path ) {
		$made = fstat( $handle );
		clearstatcache( true, $path );
		$here = @stat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Checked below.
		$real = realpath( $path );
		return $made && $here && $made['dev'] === $here['dev'] && $made['ino'] === $here['ino'] && $real && wp_normalize_path( $real ) === $path;
	}

	/**
	 * Empty our own file through its handle and close it; never by pathname.
	 *
	 * @param resource $handle Open file this code created.
	 */
	private static function strand( $handle ) {
		ftruncate( $handle, 0 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * A plain uploads-relative path: forward slashes only, no drive, colon, NUL,
	 * leading slash, or empty, "." or ".." segment.
	 *
	 * @param mixed $rel Path from a manifest.
	 */
	private static function safe_relative( $rel ) {
		if ( ! is_string( $rel ) || '' === $rel || preg_match( '#[\\\\:\x00]#', $rel ) || '/' === $rel[0] ) {
			return false;
		}
		foreach ( explode( '/', $rel ) as $part ) {
			if ( '' === $part || '.' === $part || '..' === $part || rtrim( $part, '. ' ) !== $part ) {
				return false; // Includes Windows aliases ending in a dot or space.
			}
		}
		return true;
	}

	/**
	 * The folder under the uploads root, created one level at a time where missing,
	 * or null when any level is a link or the result resolves outside uploads.
	 *
	 * @param string $root    Canonical uploads root.
	 * @param string $sub_dir Relative folder ("." for the root).
	 * @return string|null
	 */
	private static function safe_folder( $root, $sub_dir ) {
		$folder = $root;
		foreach ( '.' === $sub_dir ? array() : explode( '/', $sub_dir ) as $part ) {
			$folder .= '/' . $part;
			if ( is_link( $folder ) ) {
				return null;
			}
			if ( ! file_exists( $folder ) && ! @mkdir( $folder, 0755 ) && ! is_dir( $folder ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- One level, checked below.
				return null;
			}
			if ( is_link( $folder ) || ! is_dir( $folder ) ) {
				return null;
			}
		}
		$real = realpath( $folder );
		$real = $real ? untrailingslashit( wp_normalize_path( $real ) ) : '';
		return ( $real === $root || 0 === strpos( $real . '/', $root . '/' ) ) ? $real : null;
	}

	/**
	 * @param int   $n    Section.
	 * @param array $item Manifest item.
	 * @return string Message.
	 * @throws \RuntimeException On failure.
	 */
	private function restore_item( $n, array $item ) {
		global $wpdb;
		switch ( $item['type'] ) {
			case 'cron':
				$events = json_decode( (string) @file_get_contents( $this->dir . '/item-' . $n . '.json' ), true ); // phpcs:ignore
				// Write events back verbatim. wp_schedule_event() would reject custom recurrences
				// (e.g. 'every_minute') whose plugin is gone, and would shift timestamps.
				$crons = _get_cron_array();
				$count = 0;
				foreach ( (array) $events as $event ) {
					$args = isset( $event['args'] ) ? (array) $event['args'] : array();
					$key  = md5( serialize( $args ) );
					$ts   = (int) $event['timestamp'];
					if ( isset( $crons[ $ts ][ $event['hook'] ][ $key ] ) ) {
						continue;
					}
					$entry = array(
						'schedule' => $event['schedule'] ? $event['schedule'] : false,
						'args'     => $args,
					);
					if ( $event['schedule'] && null !== $event['interval'] ) {
						$entry['interval'] = (int) $event['interval'];
					}
					$crons[ $ts ][ $event['hook'] ][ $key ] = $entry;
					++$count;
				}
				ksort( $crons );
				if ( $count && true !== _set_cron_array( $crons, true ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'Could not write the cron schedule.', 'wp-cleanup' ) );
				}
				foreach ( (array) $events as $event ) {
					if ( false === wp_next_scheduled( $event['hook'], isset( $event['args'] ) ? (array) $event['args'] : array() ) ) {
						/* translators: %s: hook */
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
						throw new \RuntimeException( sprintf( /* translators: %s: name of the scheduled event hook */ __( 'Event %s is still missing after restore.', 'wp-cleanup' ), $event['hook'] ) );
					}
				}
				/* translators: %d: number of events */
				return sprintf( __( '%d event(s) restored.', 'wp-cleanup' ), $count );

			case 'file':
				$source = $this->dir . '/files/' . $n . '/' . basename( (string) Scanner::folder_path( $item['id'] ) );
				$target = Scanner::folder_path( $item['id'] );
				if ( ! $target || ! is_dir( $source ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'Quarantined folder not found.', 'wp-cleanup' ) );
				}
				if ( file_exists( $target ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'A folder with that name exists again; not overwriting it.', 'wp-cleanup' ) );
				}
				if ( self::rearm_tree( $source ) ) {
					self::disarm_tree( $source );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'Could not rename the quarantined script files back; the folder was not moved.', 'wp-cleanup' ) );
				}
				if ( ! @rename( $source, $target ) ) { // phpcs:ignore
					self::disarm_tree( $source );
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'Could not move the folder back.', 'wp-cleanup' ) );
				}
				return __( 'Folder moved back.', 'wp-cleanup' );

			case 'media':
				return Media_Converter::restore( $this, $n, $item );

			case 'media_repair':
				return Media_Integrity::restore( $this, $n, $item );

			case 'media_delete':
				return Media_Remover::restore( $this, $n, $item );

			case 'media_merge_rewrite':
				return Media_Merger::restore( $this, $n, $item );

			case 'media_file':
				return Media_Orphan_Files::restore( $this, $n, $item );

			default:
				if ( 'table' === $item['type'] && $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $item['id'] ) ) ) ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'The table exists again; not overwriting it.', 'wp-cleanup' ) );
				}
				$lines = $this->replay_sql( $n );
				/* translators: %d: number of statements */
				return $lines ? sprintf( __( '%d statement(s) replayed.', 'wp-cleanup' ), $lines ) : __( 'Nothing to restore.', 'wp-cleanup' );
		}
	}

	/**
	 * Execute a section's SQL file.
	 *
	 * @param int $n Section.
	 * @return int Statements executed.
	 * @throws \RuntimeException When any statement fails.
	 */
	public function replay_sql( $n ) {
		global $wpdb;
		Media_Owners::reset(); // Raw SQL may bring attachment rows back.
		$file = $this->sql_file( $n );
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore
		$lines  = 0;
		$errors = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line || self::SQL_GUARD === $line ) {
				continue;
			}
			++$lines;
			if ( false === $wpdb->query( $line ) ) { // phpcs:ignore -- statements were generated by this class.
				++$errors;
			}
		}
		fclose( $handle ); // phpcs:ignore
		if ( $errors ) {
			/* translators: 1: failed statements, 2: all statements, 3: last DB error */
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text message; admin notices/lists use esc_html, AJAX uses textContent, WP-CLI prints text.
			throw new \RuntimeException( sprintf( /* translators: 1: failed statements, 2: total statements, 3: database error message */ __( '%1$d of %2$d statements failed: %3$s', 'wp-cleanup' ), $errors, $lines, $wpdb->last_error ) );
		}
		return $lines;
	}

	/**
	 * Permanently delete this backup set.
	 */
	public function delete() {
		$ok = Storage::delete_tree( $this->dir );
		$this->unlock();
		if ( $ok ) {
			@unlink( Storage::lock_file( $this->dir ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Lock file of a deleted set.
		}
		return $ok;
	}

	/* ------------------------------------------------------------------ */
	/* SQL helpers                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $name Identifier.
	 * @return string Backtick-safe identifier.
	 */
	public static function ident( $name ) {
		return str_replace( '`', '``', (string) $name );
	}

	/**
	 * Binary-safe SQL literal for a text column value.
	 *
	 * @param string|null $value Raw value.
	 * @return string
	 */
	public static function sql_literal( $value ) {
		return self::literal( $value, false );
	}

	/**
	 * @param string|null $value  Raw value.
	 * @param bool        $binary Column is binary.
	 * @return string SQL literal.
	 */
	private static function literal( $value, $binary ) {
		global $wpdb;
		if ( null === $value ) {
			return 'NULL';
		}
		$hex = "X'" . bin2hex( (string) $value ) . "'";
		if ( $binary ) {
			return $hex;
		}
		$charset = $wpdb->charset ? preg_replace( '/[^a-z0-9_]/i', '', $wpdb->charset ) : 'utf8mb4';
		return 'CONVERT(' . $hex . ' USING ' . $charset . ')';
	}

	/**
	 * @param string $table Table.
	 * @return array<string,bool> Binary column names.
	 */
	private static function binary_columns( $table ) {
		global $wpdb;
		if ( ! isset( self::$columns[ $table ] ) ) {
			self::$columns[ $table ] = array();
			foreach ( (array) $wpdb->get_results( 'SHOW COLUMNS FROM `' . self::ident( $table ) . '`', ARRAY_A ) as $col ) { // phpcs:ignore
				if ( preg_match( '/blob|binary|bit/i', $col['Type'] ) ) {
					self::$columns[ $table ][ $col['Field'] ] = true;
				}
			}
		}
		return self::$columns[ $table ];
	}
}
