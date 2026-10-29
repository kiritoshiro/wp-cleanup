<?php
/**
 * Private data directory for scan results, the code index and backups.
 *
 * Preferred location: a folder with an unguessable name next to the WordPress
 * directory (the parent of ABSPATH), which is outside the web root on typical
 * hosts (/home/user/wp-cleanup-xxxx beside /home/user/public_html). Define
 * WPCU_DATA_PARENT in wp-config.php, or use the wp_cleanup_data_parent filter,
 * to pick another parent folder; the same web-root checks apply to it. Only
 * when no suitable parent is writable does the folder live under uploads, as
 * in versions up to 0.12.1, with deny rules that only Apache and IIS read. On
 * any other server new backups are then refused until the site owner confirms
 * a server-level deny rule (WPCU_UPLOADS_DENY_CONFIRMED); the admin page
 * shows the nginx rule.
 *
 * A data folder in uploads from an older version is moved over one backup set
 * at a time, each only while no request holds that set's lock, and read from
 * both places meanwhile. Every folder carries an owner marker, so a copied
 * database never uses or deletes another install's backups.
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
	const LOCK_PATTERN = '/^\.\d{8}-\d{6}-[a-z0-9]{8}\.lock$/';
	const OWNER_FILE   = 'owner.txt';
	const GUARDS       = array( 'index.php', '.htaccess', 'web.config' );
	const WEB_MARKERS  = array( 'wp-settings.php', 'wp-load.php', 'index.php', 'index.html', 'index.htm' );

	/** @var array{path:string,outside:bool,reason:string,mode_ok:bool}|null Location for this request. */
	private static $where = null;

	/** @var resource[] Lock handles opened in this request (closed before uninstall deletes the folders). */
	private static $locks = array();

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
	 * "blocked": the data folder can be served (not private, server ignores
	 * .htaccess/web.config, no confirmed server rule), so new backups are refused.
	 *
	 * @return array{path:string,outside:bool,reason:string,mode_ok:bool,legacy:?string,server_denies:bool,confirmed:bool,blocked:bool,nginx_rule:string}
	 */
	public static function status() {
		$path    = self::dir();
		$legacy  = self::legacy_dir();
		$public  = self::$where['outside'] ? $legacy : $path;
		$denies  = ! empty( $GLOBALS['is_apache'] ) || ! empty( $GLOBALS['is_IIS'] ) || ! empty( $GLOBALS['is_iis7'] );
		$confirm = self::deny_confirmed();
		return array(
			'path'          => $path,
			'outside'       => self::$where['outside'],
			'reason'        => self::$where['reason'],
			'mode_ok'       => self::$where['mode_ok'],
			'legacy'        => $legacy,
			'server_denies' => $denies,
			'confirmed'     => $confirm,
			'blocked'       => ! self::$where['outside'] && ! $denies && ! $confirm,
			'nginx_rule'    => $public ? self::nginx_rule( $public ) : '',
		);
	}

	/**
	 * Whether the site owner confirmed a server-level rule that denies the data folder in uploads.
	 *
	 * @return bool
	 */
	public static function deny_confirmed() {
		/**
		 * Confirm that the web server refuses every request into the WP Cleanup folder in uploads.
		 *
		 * @param bool $confirmed WPCU_UPLOADS_DENY_CONFIRMED.
		 */
		return (bool) apply_filters( 'wp_cleanup_uploads_deny_confirmed', defined( 'WPCU_UPLOADS_DENY_CONFIRMED' ) && WPCU_UPLOADS_DENY_CONFIRMED );
	}

	/**
	 * Refuse to start writing a new backup set where the web server could serve it.
	 *
	 * @throws \RuntimeException When blocked.
	 */
	public static function assert_safe_for_backups() {
		$status = self::status();
		if ( $status['blocked'] ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Plain-text exception; Admin::render_notice escapes HTML, CLI prints text.
			throw new \RuntimeException( sprintf( /* translators: 1: folder path, 2: nginx rule */ __( 'Refused: backups would be written to %1$s, which this web server may serve (it is not Apache or IIS, and no private folder outside the web root could be used). Define WPCU_DATA_PARENT as a writable folder outside the web root, or add "%2$s" to the server block and define WPCU_UPLOADS_DENY_CONFIRMED as true in wp-config.php. Existing backups can still be restored and deleted.', 'wp-cleanup' ), $status['path'], $status['nginx_rule'] ) );
		}
	}

	/**
	 * nginx rule that refuses every request into a data folder the web server can reach.
	 * "^~" makes it win over regex locations such as the PHP handler.
	 *
	 * @param string $dir Absolute path of a folder in uploads or the document root.
	 * @return string
	 */
	public static function nginx_rule( $dir ) {
		$uploads = wp_upload_dir( null, false );
		$dir     = untrailingslashit( wp_normalize_path( $dir ) );
		$doc     = self::document_root();
		if ( wp_normalize_path( dirname( $dir ) ) === untrailingslashit( wp_normalize_path( $uploads['basedir'] ) ) ) {
			$url = untrailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ) . '/' . basename( $dir );
		} elseif ( '' !== $doc && self::within( $dir, $doc ) ) {
			$url = substr( $dir, strlen( $doc ) );
		} else {
			return '';
		}
		return 'location ^~ ' . $url . '/ { deny all; }';
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
	 * Lock a backup set: shared while a request uses it, exclusive (never waiting)
	 * while it is moved. The lock file sits beside the set, so the set can be renamed
	 * while the lock is held.
	 *
	 * @param string $set       Backup set folder.
	 * @param bool   $exclusive Exclusive, non-blocking.
	 * @return resource|false|null Lock handle; false when another request holds it; null when no lock file can be made.
	 */
	public static function lock_set( $set, $exclusive ) {
		$handle = @fopen( self::lock_file( $set ), 'c' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- Lock file.
		if ( ! $handle ) {
			return null;
		}
		if ( ! flock( $handle, $exclusive ? LOCK_EX | LOCK_NB : LOCK_SH ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return false;
		}
		self::$locks[] = $handle;
		return $handle;
	}

	/**
	 * @param string $set Backup set folder.
	 * @return string
	 */
	public static function lock_file( $set ) {
		return dirname( $set ) . '/.' . basename( $set ) . '.lock';
	}

	/**
	 * @return array{path:string,outside:bool,reason:string,mode_ok:bool}
	 */
	private static function resolve() {
		$explicit = defined( 'WPCU_DATA_PARENT' ) && is_string( WPCU_DATA_PARENT ) && '' !== WPCU_DATA_PARENT;
		/**
		 * Parent folder for the private data folder. Return '' to keep the data in uploads.
		 *
		 * @param string $parent WPCU_DATA_PARENT when defined, else the folder containing the WordPress directory.
		 */
		$parent  = (string) apply_filters( 'wp_cleanup_data_parent', $explicit ? WPCU_DATA_PARENT : dirname( untrailingslashit( ABSPATH ) ) );
		$mode_ok = true;
		if ( '' === $parent ) {
			$reason = __( 'turned off by the wp_cleanup_data_parent filter', 'wp-cleanup' );
		} else {
			$path = self::outside_dir( $parent, $reason );
			if ( $path ) {
				$mode_ok = self::make_private( $path );
				if ( '' !== $reason ) {
					// A recorded folder that turns out to be web-served: keep reading it, but it is not private.
					return array(
						'path'    => $path,
						'outside' => false,
						'reason'  => $reason,
						'mode_ok' => $mode_ok,
					);
				}
				self::migrate_legacy( $path );
				return array(
					'path'    => $path,
					'outside' => true,
					'reason'  => '',
					'mode_ok' => $mode_ok,
				);
			}
		}
		return array(
			'path'    => self::uploads_dir( true ),
			'outside' => false,
			'reason'  => $reason,
			'mode_ok' => true,
		);
	}

	/**
	 * The private folder under $parent: the one recorded for this install, or a new one.
	 *
	 * @param string $parent Candidate parent folder.
	 * @param string $reason Set to why it cannot be used, or why a recorded folder is not private.
	 * @return string|null
	 */
	private static function outside_dir( $parent, &$reason ) {
		$reason = '';
		$real   = realpath( $parent );
		if ( ! $real || ! is_dir( $real ) ) {
			$reason = __( 'the parent folder does not exist', 'wp-cleanup' );
			return null;
		}
		$real   = untrailingslashit( wp_normalize_path( $real ) );
		$stored = get_option( self::OPTION_PATH );
		if ( is_string( $stored ) && preg_match( self::NAME_PATTERN, basename( $stored ) ) && wp_normalize_path( dirname( $stored ) ) === $real && self::owned( $stored ) ) {
			// Fully checked when it was made. The document root is known only in web
			// requests, so a folder made from WP-CLI is checked against it here too.
			$doc = self::document_root();
			if ( '' !== $doc && self::within( $real, $doc ) ) {
				$reason = __( 'the folder is inside the web server\'s document root', 'wp-cleanup' );
			}
			return $stored;
		}
		$reason = self::unsuitable( $real );
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
		if ( ! self::owned( $path ) && ! self::claim( $path ) ) {
			$reason = __( 'the folder could not be created', 'wp-cleanup' );
			return null;
		}
		update_option( self::OPTION_PATH, $path, false );
		return $path;
	}

	/**
	 * Restrict the private folder to its owner (0700) where the filesystem has modes.
	 *
	 * @param string $dir Folder.
	 * @return bool Whether the folder is 0700 now (always true without POSIX modes).
	 */
	private static function make_private( $dir ) {
		if ( '/' !== DIRECTORY_SEPARATOR ) {
			return true; // Windows: no POSIX modes; access is governed by ACLs.
		}
		clearstatcache( true, $dir );
		if ( 0700 !== ( fileperms( $dir ) & 0777 ) ) {
			@chmod( $dir, 0700 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions -- Checked below.
			clearstatcache( true, $dir );
		}
		return 0700 === ( fileperms( $dir ) & 0777 );
	}

	/**
	 * Why a parent folder must not hold the data folder ('' when it may). The same
	 * checks apply to WPCU_DATA_PARENT: it chooses the parent, not the boundary.
	 *
	 * @param string $real Canonical parent path.
	 * @return string
	 */
	private static function unsuitable( $real ) {
		$uploads = wp_upload_dir( null, false );
		if ( '' === $real || preg_match( '#^[a-z]:$#i', $real ) ) {
			return __( 'the parent folder is a filesystem root', 'wp-cleanup' );
		}
		// The parent and every folder above it: a web root has an index or WordPress file.
		for ( $dir = $real; '' !== $dir && ! preg_match( '#^[a-z]:$#i', $dir ); $dir = self::up( $dir ) ) {
			foreach ( self::WEB_MARKERS as $file ) {
				if ( @file_exists( $dir . '/' . $file ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors -- open_basedir.
					return __( 'the parent folder is inside what looks like a web root', 'wp-cleanup' );
				}
			}
		}
		$doc = self::document_root();
		if ( '' !== $doc && self::within( $real, $doc ) ) {
			return __( 'the parent folder is inside the web server\'s document root', 'wp-cleanup' );
		}
		foreach ( array( ABSPATH, WP_CONTENT_DIR, $uploads['basedir'] ) as $root ) {
			$root = $root ? realpath( $root ) : false;
			if ( $root && self::within( $real, untrailingslashit( wp_normalize_path( $root ) ) ) ) {
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
	 * @param string $dir Normalized path without a trailing slash.
	 * @return string Its parent, or '' at the top.
	 */
	private static function up( $dir ) {
		$parent = untrailingslashit( wp_normalize_path( dirname( $dir ) ) );
		return $parent === $dir ? '' : $parent;
	}

	/**
	 * Whether $path is $base or inside it (both normalized, no trailing slash).
	 *
	 * @param string $path Path.
	 * @param string $base Folder.
	 */
	private static function within( $path, $base ) {
		return $path === $base || 0 === strpos( $path . '/', $base . '/' );
	}

	/**
	 * Canonical document root of this web request ('' under WP-CLI).
	 *
	 * @return string
	 */
	private static function document_root() {
		if ( empty( $_SERVER['DOCUMENT_ROOT'] ) ) {
			return '';
		}
		$doc = realpath( wp_unslash( $_SERVER['DOCUMENT_ROOT'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server path, only compared.
		return $doc ? untrailingslashit( wp_normalize_path( $doc ) ) : '';
	}

	/**
	 * Whether a data folder was made (or claimed) by this WordPress install, so a
	 * copied database (staging) never uses or deletes the original's backups.
	 *
	 * @param string $dir Absolute path.
	 */
	private static function owned( $dir ) {
		$file = $dir . '/' . self::OWNER_FILE;
		return is_dir( $dir ) && ! is_link( $dir ) && is_readable( $file ) && trim( (string) file_get_contents( $file ) ) === self::owner_id(); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * Write this install's owner marker into a folder.
	 *
	 * @param string $dir Folder.
	 * @return bool
	 */
	private static function claim( $dir ) {
		return false !== file_put_contents( $dir . '/' . self::OWNER_FILE, self::owner_id() ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * @return string
	 */
	private static function owner_id() {
		$abs = realpath( ABSPATH );
		return untrailingslashit( wp_normalize_path( $abs ? $abs : ABSPATH ) );
	}

	/**
	 * The folder in uploads, as older versions used it. An existing folder without
	 * an owner marker (made by 0.12.1 or earlier) is claimed by this install when it
	 * is used; one marked by another install (a copied database sharing uploads) is
	 * never used: a new folder is made instead.
	 *
	 * @param bool $create Make a folder when none can be used.
	 * @return string|null
	 */
	private static function uploads_dir( $create ) {
		$uploads = wp_upload_dir( null, false );
		$name    = get_option( self::OPTION_DIR );
		if ( is_string( $name ) && preg_match( self::NAME_PATTERN, $name ) ) {
			$dir = trailingslashit( $uploads['basedir'] ) . $name;
			if ( ! file_exists( $dir ) ) {
				if ( ! $create ) {
					return null;
				}
				if ( wp_mkdir_p( $dir ) ) {
					self::claim( $dir );
				}
				return $dir; // ensure_protected_dir() reports a folder that could not be made.
			}
			if ( is_dir( $dir ) && ! is_link( $dir ) && ( self::owned( $dir ) || ( ! file_exists( $dir . '/' . self::OWNER_FILE ) && self::claim( $dir ) ) ) ) {
				return $dir;
			}
		}
		if ( ! $create ) {
			return null;
		}
		$name = 'wp-cleanup-' . strtolower( wp_generate_password( 16, false, false ) );
		$dir  = trailingslashit( $uploads['basedir'] ) . $name;
		if ( wp_mkdir_p( $dir ) ) {
			self::claim( $dir );
		}
		update_option( self::OPTION_DIR, $name, false );
		return $dir;
	}

	/**
	 * Move an older version's data from uploads into the private folder. A backup
	 * set moves only while this request holds its exclusive lock, so a backup,
	 * restore, delete or disarm running in another request is never cut off; such
	 * a set is read in place and moved by a later request. The folder in uploads
	 * is removed once only guards, its owner marker and lock files remain.
	 *
	 * @param string $to Private folder.
	 */
	private static function migrate_legacy( $to ) {
		$from = self::uploads_dir( false );
		if ( ! $from || ! is_dir( $from ) || is_link( $from ) ) {
			return;
		}
		if ( is_dir( $from . '/backups' ) ) {
			self::ensure_protected_dir( $to . '/backups' );
			foreach ( (array) glob( $from . '/backups/*', GLOB_ONLYDIR ) as $set ) {
				$id = basename( $set );
				if ( ! preg_match( self::SET_PATTERN, $id ) || is_link( $set ) || file_exists( $to . '/backups/' . $id ) ) {
					continue;
				}
				$lock = self::lock_set( $set, true );
				if ( ! $lock ) {
					continue; // In use (or unlockable): left for a later request.
				}
				$moved = @rename( $set, $to . '/backups/' . $id ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Retried on a later request.
				flock( $lock, LOCK_UN );
				fclose( $lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
				if ( $moved ) {
					@unlink( self::lock_file( $set ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Stale lock files are removed with the folder.
				}
			}
		}
		foreach ( (array) scandir( $from ) as $entry ) {
			$path = $from . '/' . $entry;
			if ( in_array( $entry, self::GUARDS, true ) || self::OWNER_FILE === $entry || ! is_file( $path ) || is_link( $path ) ) {
				continue;
			}
			$dest = $to . '/' . $entry;
			if ( ! file_exists( $dest ) || filemtime( $path ) > filemtime( $dest ) ) {
				@rename( $path, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Scan results and caches; retried later.
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- Older copy of a file that was already moved.
			}
		}
		if ( self::only_guards( $from, array( 'backups', self::OWNER_FILE ) ) && ( ! is_dir( $from . '/backups' ) || self::only_guards( $from . '/backups', array() ) ) ) {
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
			if ( '.' !== $entry && '..' !== $entry && ! in_array( $entry, array_merge( self::GUARDS, $allow ), true ) && ! preg_match( self::LOCK_PATTERN, $entry ) ) {
				return false;
			}
		}
		return true;
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
		$dir = untrailingslashit( wp_normalize_path( $dir ) );
		foreach ( array( self::dir(), self::legacy_dir() ) as $base ) {
			if ( $base && self::within( $dir, untrailingslashit( wp_normalize_path( $base ) ) ) ) {
				return self::rmtree( $dir );
			}
		}
		return false; // Never delete outside our own directories.
	}

	/**
	 * Delete this install's data directories (used by uninstall). A folder whose
	 * owner marker is missing or names another install is left alone.
	 */
	public static function delete_all() {
		foreach ( self::$locks as $handle ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions -- Windows cannot delete an open lock file.
			}
		}
		self::$locks = array();
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
		if ( preg_match( self::NAME_PATTERN, $name ) && self::owned( $dir ) ) {
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
