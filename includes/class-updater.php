<?php
/**
 * Updates from GitHub releases.
 *
 * WordPress asks plugins with an "Update URI" header on another host for their
 * own update data (update_plugins_{host}, WordPress 5.8+). This answers from the
 * latest published (not draft, not pre-release) GitHub release that has a
 * "wp-cleanup-X.Y.Z.zip" asset. Updates then appear on Dashboard → Updates and
 * the Plugins screen, and work with WordPress's auto-update toggle.
 *
 * Every download is checked against the SHA-256 digest GitHub publishes for the
 * asset before WordPress installs it.
 *
 * A token is only needed for a private repository, or if the site hits
 * GitHub's limit of 60 anonymous API requests per hour. Define it in
 * wp-config.php, never in the database:
 *
 *     define( 'WPCU_GITHUB_TOKEN', 'github_pat_…' );
 *
 * It is sent only to api.github.com, never along a download redirect.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Updater {

	const REPO      = 'kiritoshiro/wp-cleanup';
	const API       = 'https://api.github.com/repos/kiritoshiro/wp-cleanup/releases/latest';
	const CACHE     = 'wpcu_github_release';
	const INFO_SLUG = 'wp-cleanup-github'; // Not "wp-cleanup": wordpress.org has an unrelated plugin with that slug.

	public static function register() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'update_data' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'download' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
	}

	/**
	 * @return string Token from wp-config.php, or ''.
	 */
	private static function token() {
		$token = defined( 'WPCU_GITHUB_TOKEN' ) ? (string) WPCU_GITHUB_TOKEN : '';
		/**
		 * Filter the GitHub token used for update checks. Only needed for a private repository.
		 *
		 * @param string $token Token.
		 */
		return trim( (string) apply_filters( 'wp_cleanup_github_token', $token ) );
	}

	/**
	 * Latest release, cached for six hours (one hour after a failed check).
	 *
	 * @param bool $force Skip the cache.
	 * @return array{version:string,tag:string,url:string,package:string,api_asset:string,digest:string,notes:string,published:string}|null
	 */
	public static function release( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}
		$headers = array(
			'Accept'               => 'application/vnd.github+json',
			'X-GitHub-Api-Version' => '2022-11-28',
			'User-Agent'           => 'wp-cleanup/' . WPCU_VERSION,
		);
		$token   = self::token();
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$response = wp_remote_get( self::API, array( 'timeout' => 10, 'headers' => $headers ) );
		$data     = is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$release  = is_array( $data ) ? self::parse( $data ) : null;
		set_site_transient( self::CACHE, $release ? $release : array( 'version' => '' ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	/**
	 * @param array $data GitHub release JSON.
	 * @return array|null
	 */
	public static function parse( array $data ) {
		$version = ltrim( (string) ( isset( $data['tag_name'] ) ? $data['tag_name'] : '' ), 'vV' );
		if ( ! empty( $data['draft'] ) || ! empty( $data['prerelease'] ) || ! preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
			return null;
		}
		foreach ( (array) ( isset( $data['assets'] ) ? $data['assets'] : array() ) as $asset ) {
			if ( ! is_array( $asset ) || ( isset( $asset['name'] ) ? $asset['name'] : '' ) !== 'wp-cleanup-' . $version . '.zip' || ( isset( $asset['state'] ) && 'uploaded' !== $asset['state'] ) ) {
				continue;
			}
			$package = (string) ( isset( $asset['browser_download_url'] ) ? $asset['browser_download_url'] : '' );
			if ( 0 !== strpos( $package, 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
				return null;
			}
			return array(
				'version'   => $version,
				'tag'       => (string) $data['tag_name'],
				'url'       => (string) ( isset( $data['html_url'] ) ? $data['html_url'] : 'https://github.com/' . self::REPO . '/releases' ),
				'package'   => $package,
				'api_asset' => (string) ( isset( $asset['url'] ) ? $asset['url'] : '' ),
				'digest'    => (string) ( isset( $asset['digest'] ) ? $asset['digest'] : '' ),
				'notes'     => (string) ( isset( $data['body'] ) ? $data['body'] : '' ),
				'published' => (string) ( isset( $data['published_at'] ) ? $data['published_at'] : '' ),
			);
		}
		return null;
	}

	/**
	 * Update data for WordPress, for this plugin only.
	 *
	 * @param array|false $update      Data from another filter, or false.
	 * @param array       $plugin_data Plugin headers.
	 * @param string      $plugin_file Plugin basename.
	 * @return array|false
	 */
	public static function update_data( $update, $plugin_data, $plugin_file ) {
		if ( plugin_basename( WPCU_FILE ) !== $plugin_file ) {
			return $update;
		}
		// "Check again" on Dashboard → Updates bypasses the cache.
		$force   = isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only cache bypass.
		$release = self::release( $force );
		if ( ! $release ) {
			return $update;
		}
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => self::INFO_SLUG,
			'version'      => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * "View details" for the update.
	 *
	 * @param false|object|array $result Result.
	 * @param string             $action Action.
	 * @param object             $args   Arguments.
	 * @return false|object|array
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! is_object( $args ) || ! isset( $args->slug ) || self::INFO_SLUG !== $args->slug ) {
			return $result;
		}
		$release = self::release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP Cleanup',
			'slug'          => self::INFO_SLUG,
			'version'       => $release['version'],
			'author'        => 'kiritoshiro',
			'homepage'      => 'https://github.com/' . self::REPO,
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => self::markdown( $release['notes'] ),
			),
		);
	}

	/**
	 * Download our release package, verify its digest, and hand WordPress the file.
	 *
	 * @param bool|string|\WP_Error $reply   Earlier result.
	 * @param string                $package Package URL.
	 * @param \WP_Upgrader          $upgrader Upgrader.
	 * @return bool|string|\WP_Error
	 */
	public static function download( $reply, $package, $upgrader ) {
		$release = self::release();
		if ( false !== $reply || ! $release || $package !== $release['package'] ) {
			return $reply;
		}
		$url   = $release['package'];
		$token = self::token();
		if ( '' !== $token && 0 === strpos( $release['api_asset'], 'https://api.github.com/' ) ) {
			// Private repositories: ask the API for the asset, then follow its signed
			// redirect without the token, so the token never leaves api.github.com.
			$response = wp_remote_get(
				$release['api_asset'],
				array(
					'timeout'     => 15,
					'redirection' => 0,
					'headers'     => array(
						'Accept'        => 'application/octet-stream',
						'Authorization' => 'Bearer ' . $token,
						'User-Agent'    => 'wp-cleanup/' . WPCU_VERSION,
					),
				)
			);
			$location = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_header( $response, 'location' );
			if ( 0 !== strpos( $location, 'https://' ) ) {
				return new \WP_Error( 'wpcu_download', __( 'GitHub did not provide a download link for the update. Check WPCU_GITHUB_TOKEN.', 'wp-cleanup' ) );
			}
			$url = $location;
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $url, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( 0 === strpos( $release['digest'], 'sha256:' ) && ! hash_equals( substr( $release['digest'], 7 ), (string) hash_file( 'sha256', $file ) ) ) {
			wp_delete_file( $file );
			return new \WP_Error( 'wpcu_digest', __( 'The downloaded update does not match the checksum GitHub published for it, so it was not installed.', 'wp-cleanup' ) );
		}
		return $file;
	}

	/**
	 * Keep the installed folder name if a package's top folder differs.
	 *
	 * @param string|\WP_Error $source        Unpacked source folder.
	 * @param string           $remote_source Parent folder.
	 * @param \WP_Upgrader     $upgrader      Upgrader.
	 * @param array            $hook_extra    Context.
	 * @return string|\WP_Error
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;
		$ours = plugin_basename( WPCU_FILE );
		if ( is_wp_error( $source ) || empty( $hook_extra['plugin'] ) || $ours !== $hook_extra['plugin'] || ! $wp_filesystem ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . dirname( $ours ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $want ) || ! $wp_filesystem->exists( trailingslashit( $source ) . basename( $ours ) ) ) {
			return $source;
		}
		return $wp_filesystem->move( $source, $want ) ? $want : $source;
	}

	/**
	 * Release notes (Markdown) as simple, escaped HTML.
	 *
	 * @param string $text Markdown.
	 */
	public static function markdown( $text ) {
		$html = '';
		$list = false;
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = rtrim( $line );
			$item = preg_match( '/^\s*[-*]\s+(.*)$/', $line, $m );
			if ( $list && ! $item ) {
				$html .= '</ul>';
				$list  = false;
			}
			if ( $item ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . self::inline( $m[1] ) . '</li>';
				$list  = true;
			} elseif ( preg_match( '/^(#{1,4})\s+(.*)$/', $line, $m ) ) {
				$html .= '<h4>' . self::inline( $m[2] ) . '</h4>';
			} elseif ( '' !== trim( $line ) ) {
				$html .= '<p>' . self::inline( $line ) . '</p>';
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * @param string $text One line of Markdown.
	 */
	private static function inline( $text ) {
		$text = esc_html( $text );
		$text = preg_replace( '/`([^`]+)`/', '<code>$1</code>', $text );
		$text = preg_replace( '/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text );
		return preg_replace_callback(
			'/\[([^\]]+)\]\((https:\/\/[^\s)]+)\)/',
			static function ( $m ) {
				return '<a href="' . esc_url( html_entity_decode( $m[2] ) ) . '">' . $m[1] . '</a>';
			},
			$text
		);
	}
}
