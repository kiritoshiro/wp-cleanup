<?php
/**
 * Restorable backup sets written before anything is deleted.
 *
 * Layout: <data dir>/backups/<id>/
 *   manifest.json     what was removed and how each item went
 *   item-<n>.sql      one full SQL statement per line (tables, rows)
 *   item-<n>.json     cron events
 *   files/<n>/        quarantined folders (moved, not copied)
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

	/** @var string */
	public $id;

	/** @var string */
	public $dir;

	/** @var array */
	public $manifest;

	/** @var array<string,array> Column info cache per table. */
	private static $columns = array();

	/**
	 * @param string $id  Backup id.
	 * @param string $dir Absolute directory.
	 */
	private function __construct( $id, $dir ) {
		$this->id  = $id;
		$this->dir = $dir;
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
	 * Start a new backup set.
	 *
	 * @return self
	 */
	public static function start() {
		$id  = gmdate( 'Ymd-His' ) . '-' . strtolower( wp_generate_password( 8, false, false ) );
		$dir = self::root() . '/' . $id;
		Storage::ensure_protected_dir( $dir );
		$user           = wp_get_current_user();
		$backup         = new self( $id, $dir );
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
		$dir  = self::root() . '/' . $id;
		$data = is_readable( $dir . '/manifest.json' ) ? json_decode( (string) file_get_contents( $dir . '/manifest.json' ), true ) : null; // phpcs:ignore
		if ( ! is_array( $data ) ) {
			return null;
		}
		$backup           = new self( $id, $dir );
		$backup->manifest = $data;
		return $backup;
	}

	/**
	 * @return array[] Manifests, newest first.
	 */
	public static function all() {
		$list = array();
		foreach ( (array) glob( self::root() . '/*', GLOB_ONLYDIR ) as $dir ) {
			$backup = self::open( basename( $dir ) );
			if ( $backup ) {
				$manifest          = $backup->manifest;
				$manifest['bytes'] = self::tree_bytes( $dir );
				$list[]            = $manifest;
			}
		}
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
		return $this->dir . '/item-' . (int) $n . '.sql';
	}

	/**
	 * @param int    $n    Section.
	 * @param string $line One SQL statement without newlines.
	 */
	public function write_sql( $n, $line ) {
		if ( false === file_put_contents( $this->sql_file( $n ), $line . "\n", FILE_APPEND | LOCK_EX ) ) { // phpcs:ignore
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

	/* ------------------------------------------------------------------ */
	/* Restore                                                             */
	/* ------------------------------------------------------------------ */

	/**
	 * Restore every deleted item of this set.
	 *
	 * @return array<int,array{label:string,ok:bool,message:string}>
	 */
	public function restore() {
		$report = array();
		$tt_ids = array();
		// Undo newest first: later items may have changed rows an earlier item also changed.
		foreach ( array_reverse( $this->manifest['items'], true ) as $n => $item ) {
			if ( 'deleted' !== $item['result'] || ! empty( $item['restored'] ) ) {
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
		$this->manifest['restored'] = time();
		$this->save();
		wp_cache_flush();
		if ( $tt_ids ) {
			Cleaner::recount_terms( array_map( 'intval', array_unique( $tt_ids ) ) );
		}
		return $report;
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
						throw new \RuntimeException( sprintf( __( 'Event %s is still missing after restore.', 'wp-cleanup' ), $event['hook'] ) );
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
				if ( ! @rename( $source, $target ) ) { // phpcs:ignore
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
					throw new \RuntimeException( __( 'Could not move the folder back.', 'wp-cleanup' ) );
				}
				return __( 'Folder moved back.', 'wp-cleanup' );

			case 'media':
				return Media_Converter::restore( $this, $n, $item );

			case 'media_delete':
				return Media_Remover::restore( $this, $n, $item );

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
		$file = $this->sql_file( $n );
		if ( ! is_readable( $file ) ) {
			return 0;
		}
		$handle = fopen( $file, 'rb' ); // phpcs:ignore
		$lines  = 0;
		$errors = 0;
		while ( false !== ( $line = fgets( $handle ) ) ) { // phpcs:ignore
			$line = rtrim( $line, "\r\n" );
			if ( '' === $line ) {
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
			throw new \RuntimeException( sprintf( __( '%1$d of %2$d statements failed: %3$s', 'wp-cleanup' ), $errors, $lines, $wpdb->last_error ) );
		}
		return $lines;
	}

	/**
	 * Permanently delete this backup set.
	 */
	public function delete() {
		return Storage::delete_tree( $this->dir );
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
