<?php
/**
 * Plugin Name:       WP Cleanup
 * Description:       Finds data left behind by removed plugins and themes (options, tables, cron events, meta, post types, transients, folders), explains who owns it, and removes it with a restorable backup. Also slims images to one JPEG fallback with optional AVIF alternatives.
 * Version:           0.5.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            kiritoshiro
 * Update URI:        https://github.com/kiritoshiro/wp-cleanup
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-cleanup
 *
 * @package WPCleanup
 */

defined( 'ABSPATH' ) || exit;

define( 'WPCU_VERSION', '0.5.0' );
define( 'WPCU_FILE', __FILE__ );
define( 'WPCU_DIR', plugin_dir_path( __FILE__ ) );
define( 'WPCU_URL', plugin_dir_url( __FILE__ ) );

spl_autoload_register(
	static function ( $class ) {
		$prefix = 'WPCleanup\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = WPCU_DIR . 'includes/class-' . strtolower( str_replace( '_', '-', $relative ) ) . '.php';
		if ( is_readable( $file ) ) {
			require $file;
		}
	}
);

add_action( 'plugins_loaded', array( 'WPCleanup\\Plugin', 'boot' ) );
