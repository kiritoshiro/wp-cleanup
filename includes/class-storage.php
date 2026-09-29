<?php
/**
 * Private data directory for scan results, the code index and backups.
 *
 * The directory lives under uploads with an unguessable name, plus deny
 * rules for Apache/IIS, so backups are not web-readable on nginx either.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Storage {

	const OPTION_DIR = 'wpcu_data_dir';

	/**
	 * Absolute path of the data directory, created on demand.
	 *
	 * @return string
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public static function dir() {
		$uploads = wp_upload_dir( null, false );
		$name    = get_option( self::OPTION_DIR );
		if ( ! is_string( $name ) || ! preg_match( '/^wp-cleanup-[a-z0-9]{16}$/', $name ) ) {
			$name = 'wp-cleanup-' . strtolower( wp_generate_password( 16, false, false ) );
			update_option( self::OPTION_DIR, $name, false );
		}
		$dir = trailingslashit( $uploads['basedir'] ) . $name;
		self::ensure_protected_dir( $dir );
		return $dir;
	}

	/**
	 * Directory name (not path) of the data directory, if one was created.
	 *
	 * @return string|null
	 */
	public static function dir_name() {
		$name = get_option( self::OPTION_DIR );
		return is_string( $name ) && '' !== $name ? $name : null;
	}

	/**
	 * @param string $dir Absolute path.
	 * @throws \RuntimeException When the directory cannot be created.
	 */
	public static function ensure_protected_dir( $dir ) {
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			/* translators: %s: directory path */
			throw new \RuntimeException( sprintf( __( 'Could not create directory %s', 'wp-cleanup' ), $dir ) );
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
			throw new \RuntimeException( sprintf( __( 'Could not write %s', 'wp-cleanup' ), $name ) );
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
	 * Recursively delete a directory that is inside the data directory.
	 *
	 * @param string $dir Absolute path.
	 * @return bool
	 */
	public static function delete_tree( $dir ) {
		$base = wp_normalize_path( self::dir() );
		$dir  = wp_normalize_path( $dir );
		if ( 0 !== strpos( $dir, $base ) ) {
			return false; // Never delete outside our own directory.
		}
		return self::rmtree( $dir );
	}

	/**
	 * Delete the whole data directory (used by uninstall).
	 */
	public static function delete_all() {
		$name = self::dir_name();
		if ( ! $name ) {
			return;
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . $name;
		if ( preg_match( '/^wp-cleanup-[a-z0-9]{16}$/', $name ) && is_dir( $dir ) ) {
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
