<?php
/**
 * Plugin Name: WPCU Fixture Active
 * Description: Test fixture for WP Cleanup integration tests. Owns the "fixact" prefix.
 * Version: 1.0.0
 * Text Domain: wpcu-fixture-active
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'init',
	static function () {
		register_post_type( 'fixact_item', array( 'public' => false ) );
		get_option( 'fixact_settings' );
		get_option( 'fixact_dyn_' . 'one' ); // Prefix literal only: 'fixact_dyn_'.
	}
);

add_action( 'fixact_cron', '__return_true' );

function wpcu_fixture_active_table() {
	global $wpdb;
	return $wpdb->prefix . 'fixact_log';
}

function wpcu_fixture_active_meta( $post_id ) {
	return get_post_meta( $post_id, '_fixact_meta', true );
}
