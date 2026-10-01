<?php
/**
 * Remove everything WP Cleanup stored, including its backup sets.
 *
 * @package WPCleanup
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

require_once __DIR__ . '/includes/class-storage.php';

WPCleanup\Storage::delete_all();
delete_option( WPCleanup\Storage::OPTION_DIR );
delete_option( 'wpcu_media_settings' );
delete_site_transient( 'wpcu_github_release' );
delete_option( 'wpcu_media_timing' );
delete_option( 'wpcu_media_gateway_limit' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_wpcu\_%' OR option_name LIKE '\_transient\_timeout\_wpcu\_%' OR option_name LIKE 'wpcu\_media\_lock\_%'" ); // phpcs:ignore
