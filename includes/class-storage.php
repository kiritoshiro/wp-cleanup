<?php
/**
 * Private data directory for scan results, the code index and backups.
 *
 * Preferred location: a folder with an unguessable name next to the WordPress
 * directory (the parent of ABSPATH), which is outside the web root on typical
 * hosts (/home/user/wp-cleanup-xxxx beside /home/user/public_html). Define
 * WPCU_DATA_PARENT in wp-config.php, or use the wp_cleanup_data_parent filter,
 * to pick another parent folder. Only when no suitable parent is writable does
 * the folder live under uploads, as in versions up to 0.12.1, with deny rules
 * that only Apache and IIS read; the admin page then shows an nginx rule.
 *
 * A data folder in uploads from an older version is moved over one backup set
 * at a time (sets in use are left until idle) and read from both places meanwhile.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Storage {

	/** Folder name inside uploads (older versions, and the fallback). */
	const OPTION_DIR = 'wpcu_data_dir';

	/** Absolute path of the folder outside the web root. */
	const OPTION_PATH = 'wpcu_data_path';

	const NAME_PATTERN = '/^wp-cleanup-[a-z0-9]{16}$/';
	const SET_PATTERN  = '/^\d{8}-\d{6}-[a-z0-9]{8}$/'; // Same as Backup::ID_PATTERN; uninstall loads only this class.
	const OWNER_FILE   = 'owner.txt';
	const GUARDS       = array( 'index.php', '.htaccess', 'web.config' );
	const MIGRATE_IDLE = 3600; // Seconds a legacy backup set must be untouched before it is moved.

	/** @var array{path:string,outside:bool,reason:string}|null Location for this request. */
	private static $where = null;

	/**
	 * Absolute path of the data directory, created on demand.
	 *
	 * @return string
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public static function dir() {
		if ( null === self::$where ) {
			self::$where = self::resolve();
		}
		self::ensure_protected_dir( self::$where['path'] );
		return self::$where['path'];
	}

	/**
	 * Forget the location chosen for this request (tests, option changes).
	 */
	public static function reset() {
		self::$where = null;
	}

	/**
	 * Where the data lives and whether the web server is known to obey its deny rules.
	 *
	 * @return array{path:string,outside:bool,reason:string,legacy:?string,server_denies:bool,nginx_rule:string}
	 */
	public static function status() {
		$path   = self::dir();
		$legacy = self::legacy_dir();
		$public = self::$where['outside'] ? $legacy : $path;
		return array(
			'path'          => $path,
			'outside'       => self::$where['outside'],
			'reason'        => self::$where['reason'],
			'legacy'        => $legacy,
			'server_denies' => ! empty( $GLOBALS['is_apache'] ) || ! empty( $GLOBALS['is_IIS'] ) || ! empty( $GLOBALS['is_iis7'] ),
			'nginx_rule'    => $public ? self::nginx_rule( $public ) : '',
		);
	}

	/**
	 * nginx rule that refuses every request into a data folder under uploads.
	 * "^~" makes it win over regex locations such as the PHP handler.
	 *
	 * @param string $dir Absolute path of a folder directly in uploads.
	 * @return string
	 */
	public static function nginx_rule( $dir ) {
		$uploads = wp_upload_dir( null, false );
		$base    = (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH );
		return 'location ^~ ' . untrailingslashit( $base ) . '/' . basename( $dir ) . '/ { deny all; }';
	}

	/**
	 * Directory name (not path) of the data directory in uploads, if one exists or was created.
	 *
	 * @return string|null
	 */
	public static function dir_name() {
		$name = get_option( self::OPTION_DIR );
		return is_string( $name ) && '' !== $name ? $name : null;
	}

	/**
	 * Data folder in uploads left by an older version while it is being moved out, else null.
	 *
	 * @return string|null
	 */
	public static function legacy_dir() {
		self::dir();
		if ( ! self::$where['outside'] ) {
			return null;
		}
		$dir = self::uploads_dir( false );
		return $dir && is_dir( $dir ) ? $dir : null;
	}

	/**
	 * @return array{path:string,outside:bool,reason:string}
	 */
	private static function resolve() {
		$explicit = defined( 'WPCU_DATA_PARENT' ) && is_string( WPCU_DATA_PARENT ) && '' !== WPCU_DATA_PARENT;
		/**
		 * Parent folder for the private data folder. Return '' to keep the data in uploads.
		 *
		 * @param string $parent WPCU_DATA_PARENT when defined, else the folder containing the WordPress directory.
		 */
		$parent = (string) apply_filters( 'wp_cleanup_data_parent', $explicit ? WPCU_DATA_PARENT : dirname( untrailingslashit( ABSPATH ) ) );
		if ( '' === $parent ) {
			$reason = __( 'turned off by the wp_cleanup_data_parent filter', 'wp-cleanup' );
		} else {
			$path = self::outside_dir( $parent, $explicit, $reason );
			if ( $path ) {
				self::migrate_legacy( $path );
				return array(
					'path'    => $path,
					'outside' => true,
					'reason'  => '',
				);
			}
		}
		return array(
			'path'    => self::uploads_dir( true ),
			'outside' => false,
			'reason'  => $reason,
		);
	}

	/**
	 * The private folder under $parent: the one recorded for this install, or a new one.
	 *
	 * @param string $parent   Candidate parent folder.
	 * @param bool   $explicit Set in wp-config.php (skips the web-root guesses).
	 * @param string $reason   Set to why it cannot be used.
	 * @return string|null
	 */
	private static function outside_dir( $parent, $explicit, &$reason ) {
		$real = realpath( $parent );
		if ( ! $real || ! is_dir( $real ) ) {
			$reason = __( 'the parent folder does not exist', 'wp-cleanup' );
			return null;
		}
		$real   = untrailingslashit( wp_normalize_path( $real ) );
		$stored = get_option( self::OPTION_PATH );
		if ( is_string( $stored ) && preg_match( self::NAME_PATTERN, basename( $stored ) ) && wp_normalize_path( dirname( $stored ) ) === $real && self::owned( $stored ) ) {
			return $stored; // Checked when it was created; not re-guessed per request.
		}
		$reason = self::unsuitable( $real, $explicit );
		if ( '' !== $reason ) {
			return null;
		}
		// Unguessable but the same in every request, so two requests racing to create it agree.
		$path = $real . '/wp-cleanup-' . substr( hash_hmac( 'sha256', self::owner_id(), wp_salt( 'auth' ) ), 0, 16 );
		if ( is_dir( $path ) && ! self::owned( $path ) && file_exists( $path . '/' . self::OWNER_FILE ) ) {
			$reason = __( 'a folder with the same name belongs to another site', 'wp-cleanup' );
			return null;
		}
		if ( ! is_dir( $path ) && ! @mkdir( $path, 0700 ) && ! is_dir( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- Private folder; failure falls back to uploads.
			$reason = __( 'the folder could not be created', 'wp-cleanup' );
			return null;
		}
		if ( ! self::owned( $path ) && false === file_put_contents( $path . '/' . self::OWNER_FILE, self::owner_id() ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			$reason = __( 'the folder could not be created', 'wp-cleanup' );
			return null;
		}
		update_option( self::OPTION_PATH, $path, false );
		return $path;
	}

	/**
	 * Why a parent folder must not hold the data folder ('' when it may).
	 *
	 * @param string $real     Canonical parent path.
	 * @param bool   $explicit Chosen by the site owner.
	 * @return string
	 */
	private static function unsuitable( $real, $explicit ) {
		$uploads = wp_upload_dir( null, false );
		$inside  = array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] );
		if ( ! $explicit ) {
			if ( '' === $real || preg_match( '#^[a-z]:$#i', $real ) ) {
				return __( 'the parent folder is a filesystem root', 'wp-cleanup' );
			}
			foreach ( array( 'wp-settings.php', 'wp-load.php', 'index.php', 'index.html', 'index.htm' ) as $file ) {
				if ( @file_exists( $real . '/' . $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir.
					return __( 'the parent folder looks like a web root', 'wp-cleanup' );
				}
			}
			if ( ! empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
				$inside[] = wp_unslash( $_SERVER['DOCUMENT_ROOT'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server path, only compared.
			}
		}
		foreach ( $inside as $root ) {
			$root = $root ? realpath( $root ) : false;
			$root = $root ? untrailingslashit( wp_normalize_path( $root ) ) : '';
			if ( '' !== $root && ( $real === $root || 0 === strpos( $real . '/', $root . '/' ) ) ) {
				return __( 'the parent folder is inside the website', 'wp-cleanup' );
			}
		}
		if ( ! wp_is_writable( $real ) ) {
			return __( 'the parent folder is not writable', 'wp-cleanup' );
		}
		// Quarantine moves folders with rename(), which cannot cross filesystems.
		$here = @stat( $real ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir.
		foreach ( array( WP_CONTENT_DIR, $uploads['basedir'] ) as $dir ) {
			$there = @stat( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- May not exist yet.
			if ( $here && $there && $here['dev'] !== $there['dev'] ) {
				return __( 'the parent folder is on another filesystem than wp-content', 'wp-cleanup' );
			}
		}
		return '';
	}

	/**
	 * Whether a folder outside the web root was made by this WordPress install,
	 * so a copied database (staging) never uses or deletes the original's backups.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function owned( $dir ) {
		$file = $dir . '/' . self::OWNER_FILE;
		return is_dir( $dir ) && is_readable( $file ) && trim( (string) file_get_contents( $file ) ) === self::owner_id(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * @return string
	 */
	private static function owner_id() {
		$abs = realpath( ABSPATH );
		return untrailingslashit( wp_normalize_path( $abs ? $abs : ABSPATH ) );
	}

	/**
	 * The folder in uploads, as older versions used it.
	 *
	 * @param bool $create Pick a name when none is recorded.
	 * @return string|null
	 */
	private static function uploads_dir( $create ) {
		$uploads = wp_upload_dir( null, false );
		$name    = get_option( self::OPTION_DIR );
		if ( ! is_string( $name ) || ! preg_match( self::NAME_PATTERN, $name ) ) {
			if ( ! $create ) {
				return null;
			}
			$name = 'wp-cleanup-' . strtolower( wp_generate_password( 16, false, false ) );
			update_option( self::OPTION_DIR, $name, false );
		}
		return trailingslashit( $uploads['basedir'] ) . $name;
	}

	/**
	 * Move an older version's data from uploads into the private folder. Backup
	 * sets go one at a time and only once untouched for an hour, so an
	 * operation still writing into one is not cut off; until then they are
	 * read in place. The folder in uploads is removed once only guards remain.
	 *
	 * @param string $to Private folder.
	 */
	private static function migrate_legacy( $to ) {
		$from = self::uploads_dir( false );
		if ( ! $from || ! is_dir( $from ) || is_link( $from ) ) {
			return;
		}
		$idle = time() - self::MIGRATE_IDLE;
		if ( is_dir( $from . '/backups' ) ) {
			self::ensure_protected_dir( $to . '/backups' );
			foreach ( (array) glob( $from . '/backups/*', GLOB_ONLYDIR ) as $set ) {
				$id = basename( $set );
				if ( preg_match( self::SET_PATTERN, $id ) && ! is_link( $set ) && ! file_exists( $to . '/backups/' . $id ) && self::newest_mtime( $set ) < $idle ) {
					@rename( $set, $to . '/backups/' . $id ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Retried on a later request.
				}
			}
		}
		foreach ( (array) scandir( $from ) as $entry ) {
			$path = $from . '/' . $entry;
			if ( in_array( $entry, self::GUARDS, true ) || ! is_file( $path ) || is_link( $path ) ) {
				continue;
			}
			$dest = $to . '/' . $entry;
			if ( ! file_exists( $dest ) || filemtime( $path ) > filemtime( $dest ) ) {
				@rename( $path, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Scan results and caches; retried later.
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Older copy of a file that was already moved.
			}
		}
		if ( self::only_guards( $from, array( 'backups' ) ) && ( ! is_dir( $from . '/backups' ) || self::only_guards( $from . '/backups', array() ) ) ) {
			self::rmtree( $from );
			if ( ! file_exists( $from ) ) {
				delete_option( self::OPTION_DIR );
			}
		}
	}

	/**
	 * @param string   $dir   Folder.
	 * @param string[] $allow Other entry names allowed.
	 */
	private static function only_guards( $dir, array $allow ) {
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' !== $entry && '..' !== $entry && ! in_array( $entry, array_merge( self::GUARDS, $allow ), true ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Latest change to a backup set: its own entries and its quarantine folders.
	 *
	 * @param string $set Folder.
	 * @return int
	 */
	private static function newest_mtime( $set ) {
		$newest = (int) filemtime( $set );
		foreach ( array_merge( (array) glob( $set . '/*' ), (array) glob( $set . '/files/*' ) ) as $path ) {
			$newest = max( $newest, (int) @filemtime( $path ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- May vanish meanwhile.
		}
		return $newest;
	}

	/**
	 * @param string $dir Absolute path.
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public static function ensure_protected_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: directory path */
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( sprintf( /* translators: %s: directory path */ __( 'Could not create directory %s', 'wp-cleanup' ), $dir ) );
		}
		$guards = array(
			'index.php'  => "<?php\n// Silence is golden.\n",
			'.htaccess'  => "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n",
			'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n",
		);
		foreach ( $guards as $file => $contents ) {
			$path = trailingslashit( $dir ) . $file;
			if ( ! file_exists( $path ) ) {
				file_put_contents( $path, $contents ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
	}

	/**
	 * @param string $name File name inside the data directory.
	 * @return string
	 */
	public static function path( $name ) {
		return trailingslashit( self::dir() ) . $name;
	}

	/**
	 * @param string $name File name.
	 * @param mixed  $data JSON-encodable data.
	 */
	public static function write_json( $name, $data ) {
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE );
		if ( false === $json || false === file_put_contents( self::path( $name ), $json, LOCK_EX ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
			/* translators: %s: file name */
		// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( sprintf( /* translators: %s: file name */ __( 'Could not write %s', 'wp-cleanup' ), $name ) );
		}
	}

	/**
	 * @param string $name File name.
	 * @return mixed|null Decoded data, or null when missing/invalid.
	 */
	public static function read_json( $name ) {
		$path = self::path( $name );
		if ( ! is_readable( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Recursively delete a directory that is inside the data directory
	 * (or inside the older folder in uploads that is still being moved out).
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	public static function delete_tree( $dir ) {
		$dir = wp_normalize_path( $dir );
		foreach ( array( self::dir(), self::legacy_dir() ) as $base ) {
			if ( $base && 0 === strpos( $dir, wp_normalize_path( $base ) ) ) {
				return self::rmtree( $dir );
			}
		}
		return false; // Never delete outside our own directories.
	}

	/**
	 * Delete the data directories (used by uninstall).
	 */
	public static function delete_all() {
		$stored = get_option( self::OPTION_PATH );
		if ( is_string( $stored ) && preg_match( self::NAME_PATTERN, basename( $stored ) ) && self::owned( $stored ) ) {
			self::rmtree( $stored );
		}
		$name = self::dir_name();
		if ( ! $name ) {
			return;
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . $name;
		if ( preg_match( self::NAME_PATTERN, $name ) && is_dir( $dir ) ) {
			self::rmtree( $dir );
		}
	}

	/**
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	private static function rmtree( $dir ) {
		if ( is_link( $dir ) || is_file( $dir ) ) {
			return @unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
		if ( ! is_dir( $dir ) ) {
			return true;
		}
		$ok = true;
		foreach ( (array) scandir( $dir ) as $entry ) {
			if ( '.' === $entry || '..' === $entry ) {
				continue;
			}
			$ok = self::rmtree( $dir . '/' . $entry ) && $ok;
		}
		// Windows finishes deleting a file only once scanners/indexers release it; retry briefly.
		for ( $try = 0; $try < 5; $try++ ) {
			if ( @rmdir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors
				return $ok;
			}
			clearstatcache();
			usleep( 200000 );
		}
		return false;
	}
}
