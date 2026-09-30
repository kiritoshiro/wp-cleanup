<?php
/**
 * Plugin Name: WPCU Fixture Inactive
 * Description: Test fixture for WP Cleanup integration tests. Installed but never activated.
 * Version: 1.0.0
 * Text Domain: wpcu-fixture-inactive
 */

defined( 'ABSPATH' ) || exit;

function wpcu_fixture_inactive_settings() {
	return get_option( 'fixinact_settings' );
}

// Only the bare prefix: an exact field name in active code must win over this.
function wpcu_fixture_inactive_legacy( $name ) {
	return get_option( 'fixcf_' . $name );
}
