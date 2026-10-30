<?php
/**
 * Bundled copy of a shared framework, as plugins ship under libs/ or vendor/.
 * The plugin never loads it in the tests; only the code index reads it.
 */

defined( 'ABSPATH' ) || exit;

// The Webcraftic Factory framework's signature name: a bundled copy keeps it in use.
function wpcu_fixture_factory_versions() {
	return get_option( 'factory_plugin_versions', array() );
}
