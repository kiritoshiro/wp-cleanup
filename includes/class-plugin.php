<?php
/**
 * Bootstrap and shared service container.
 *
 * @package WPCleanup
 */

namespace WPCleanup;

defined( 'ABSPATH' ) || exit;

final class Plugin {

	/** Statuses an item can have, from most to least protected. */
	const STATUS_CORE     = 'core';
	const STATUS_IN_USE   = 'in_use';
	const STATUS_INACTIVE = 'inactive';
	const STATUS_UNKNOWN  = 'unknown';
	const STATUS_ORPHANED = 'orphaned';
	const STATUS_SAFE     = 'safe';

	/** Item types produced by the scanner. */
	const TYPES = array( 'option', 'transient', 'table', 'cron', 'meta', 'post_type', 'orphan', 'file' );

	public static function boot() {
		load_plugin_textdomain( 'wp-cleanup', false, dirname( plugin_basename( WPCU_FILE ) ) . '/languages' );

		if ( is_multisite() ) {
			// v1 only understands single-site table and option layouts.
			add_action( 'network_admin_notices', array( __CLASS__, 'multisite_notice' ) );
			add_action( 'admin_notices', array( __CLASS__, 'multisite_notice' ) );
			return;
		}

		if ( is_admin() ) {
			( new Admin() )->register();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'cleanup', CLI::class );
		}
	}

	public static function multisite_notice() {
		if ( ! current_user_can( 'manage_network_options' ) ) {
			return;
		}
		echo '<div class="notice notice-warning"><p>' . esc_html__( 'WP Cleanup does not support multisite yet and is inactive on this network.', 'wp-cleanup' ) . '</p></div>';
	}

	/**
	 * Human labels for statuses.
	 *
	 * @return array<string,string>
	 */
	public static function status_labels() {
		return array(
			self::STATUS_CORE     => __( 'WordPress core', 'wp-cleanup' ),
			self::STATUS_IN_USE   => __( 'In use', 'wp-cleanup' ),
			self::STATUS_INACTIVE => __( 'Inactive plugin/theme', 'wp-cleanup' ),
			self::STATUS_UNKNOWN  => __( 'Unknown owner', 'wp-cleanup' ),
			self::STATUS_ORPHANED => __( 'Orphaned', 'wp-cleanup' ),
			self::STATUS_SAFE     => __( 'Safe to clean', 'wp-cleanup' ),
		);
	}

	/**
	 * Human labels for item types.
	 *
	 * @return array<string,string>
	 */
	public static function type_labels() {
		return array(
			'option'    => __( 'Options', 'wp-cleanup' ),
			'transient' => __( 'Transients', 'wp-cleanup' ),
			'table'     => __( 'Tables', 'wp-cleanup' ),
			'cron'      => __( 'Cron events', 'wp-cleanup' ),
			'meta'      => __( 'Meta keys', 'wp-cleanup' ),
			'post_type' => __( 'Post types', 'wp-cleanup' ),
			'orphan'    => __( 'Orphaned rows', 'wp-cleanup' ),
			'file'      => __( 'Folders', 'wp-cleanup' ),
		);
	}

	/**
	 * Whether an item with this status may be deleted under the given policy.
	 *
	 * @param string $status Item status.
	 * @param array  $policy Keys: allow_unknown, allow_inactive (bool).
	 */
	public static function is_deletable( $status, array $policy = array() ) {
		switch ( $status ) {
			case self::STATUS_ORPHANED:
			case self::STATUS_SAFE:
				return true;
			case self::STATUS_UNKNOWN:
				return ! empty( $policy['allow_unknown'] );
			case self::STATUS_INACTIVE:
				return ! empty( $policy['allow_inactive'] );
			default:
				return false;
		}
	}
}
