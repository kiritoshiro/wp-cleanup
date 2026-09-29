<?php
/**
 * Integration tests. DESTRUCTIVE: run only against a throwaway local site.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/run.php
 *
 * Requires the fixture plugins from tests/fixtures copied into wp-content/plugins,
 * with wpcu-fixture-active active and wpcu-fixture-inactive inactive (bin/test-setup.sh).
 *
 * @package WPCleanup
 */

use WPCleanup\Backup;
use WPCleanup\Cleaner;
use WPCleanup\Code_Index;
use WPCleanup\Scanner;
use WPCleanup\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}

$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( '1' !== getenv( 'WPCU_TESTS' ) || ! preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', (string) $host ) ) {
	WP_CLI::error( 'Refusing to run: set WPCU_TESTS=1 and use a local site (localhost, 127.0.0.1, *.test).' );
}
if ( ! is_plugin_active( 'wpcu-fixture-active/wpcu-fixture-active.php' ) || is_plugin_active( 'wpcu-fixture-inactive/wpcu-fixture-inactive.php' ) || ! file_exists( WP_PLUGIN_DIR . '/wpcu-fixture-inactive/wpcu-fixture-inactive.php' ) ) {
	WP_CLI::error( 'Fixture plugins missing or in the wrong state. Run bin/test-setup.sh.' );
}

global $wpdb;
$GLOBALS['wpcu_failures'] = 0;
$GLOBALS['wpcu_passes']   = 0;

function wpcu_assert( $condition, $message ) {
	if ( $condition ) {
		++$GLOBALS['wpcu_passes'];
		WP_CLI::log( '  ok   ' . $message );
	} else {
		++$GLOBALS['wpcu_failures'];
		WP_CLI::log( WP_CLI::colorize( '  %rFAIL%n ' ) . $message );
	}
}

function wpcu_items_by_key( array $items ) {
	$out = array();
	foreach ( $items as $item ) {
		$out[ $item['type'] . '|' . $item['id'] ] = $item;
	}
	return $out;
}

function wpcu_status( array $by_key, $key ) {
	return isset( $by_key[ $key ] ) ? $by_key[ $key ]['status'] : 'missing';
}

/* ---------------------------------------------------------------------- */
/* Reset + seed                                                           */
/* ---------------------------------------------------------------------- */

$p       = $wpdb->prefix;
$uploads = wp_upload_dir( null, false );
$tables  = array( 'wfconfig', 'fixact_log', 'zzold_table', 'staging_options', 'staging_posts', 'staging_users' );

foreach ( $tables as $t ) {
	$wpdb->query( "DROP TABLE IF EXISTS `{$p}{$t}`" );
}
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name IN ('wpseo_titles','zzleftover_config','fixinact_settings','fixact_settings','fixact_dyn_one','_transient_wpseo_cache','_transient_zzexp','_transient_timeout_zzexp','_transient_zzlive','_transient_timeout_zzlive')" );
$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_yoast_wpseo_focuskw','_fixact_meta') OR post_id = 999999" );
$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = '{$p}yoast_notifications'" );
foreach ( array( 'wpcf7_contact_form', 'fixact_item' ) as $pt ) {
	foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", $pt ) ) as $id ) {
		wp_delete_post( (int) $id, true );
	}
}
foreach ( array( 'wordfence_daily_cron', 'wordfence_custom_sched', 'fixact_cron', 'zzold_cron_hook' ) as $hook ) {
	wp_unschedule_hook( $hook );
}
foreach ( array( $uploads['basedir'] . '/wpforms', $uploads['basedir'] . '/zzold-cache', WP_CONTENT_DIR . '/wflogs' ) as $dir ) {
	if ( is_dir( $dir ) ) {
		array_map( 'unlink', glob( $dir . '/*' ) );
		rmdir( $dir );
	}
}
foreach ( Backup::all() as $b ) {
	Backup::open( $b['id'] )->delete();
}
@unlink( Storage::path( Code_Index::CACHE_FILE ) );
// wp_delete_post() leaves term links of unregistered post types behind; clear them from earlier runs.
$wpdb->query( "DELETE tr FROM {$wpdb->term_relationships} tr LEFT JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE p.ID IS NULL" );
wp_cache_flush(); // Raw SQL above bypassed the object cache.

WP_CLI::log( 'Seeding fixtures…' );

// Tricky bytes: serialized PHP, multibyte UTF-8, quotes, percent signs, backslashes, NUL.
$tricky = serialize( array( 'title' => "Ąžuolas „quoted“ 100% \\ back'slash", 'nul' => "a\0b", 'emoji' => '🧹' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => 'wpseo_titles', 'option_value' => $tricky, 'autoload' => 'yes' ) );
add_option( 'zzleftover_config', array( 'x' => 1 ), '', false );
add_option( 'fixinact_settings', 'inactive plugin data', '', false );
add_option( 'fixact_settings', 'active plugin data', '', false );
add_option( 'fixact_dyn_one', 'dynamic name', '', false );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_transient_wpseo_cache', 'option_value' => 'cached', 'autoload' => 'no' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_transient_zzexp', 'option_value' => 'expired value', 'autoload' => 'no' ) );
$wpdb->insert( $wpdb->options, array( 'option_name' => '_transient_timeout_zzexp', 'option_value' => (string) ( time() - 3600 ), 'autoload' => 'no' ) );
set_transient( 'zzlive', 'live value', HOUR_IN_SECONDS );

$wpdb->query( "CREATE TABLE `{$p}wfconfig` (name VARCHAR(100) NOT NULL PRIMARY KEY, val LONGBLOB, note TEXT) DEFAULT CHARSET=utf8mb4" );
$wpdb->query( $wpdb->prepare( "INSERT INTO `{$p}wfconfig` (name, val, note) VALUES (%s, %s, %s), (%s, NULL, %s)", 'bin', random_bytes( 64 ), 'Ąžuolas 🧹 100%', 'nullrow', "multi\nline" ) );
for ( $i = 0; $i < 1200; $i++ ) { // More than one backup batch.
	$wpdb->query( $wpdb->prepare( "INSERT INTO `{$p}wfconfig` (name, val, note) VALUES (%s, %s, %s)", 'row' . $i, str_repeat( 'x', 50 ), 'n' . $i ) );
}
$wpdb->query( "CREATE TABLE `{$p}fixact_log` (id INT PRIMARY KEY)" );
$wpdb->query( "CREATE TABLE `{$p}zzold_table` (id INT PRIMARY KEY)" );
foreach ( array( 'staging_options', 'staging_posts', 'staging_users' ) as $t ) {
	$wpdb->query( "CREATE TABLE `{$p}{$t}` (id INT PRIMARY KEY)" );
}

wp_schedule_event( time() + 600, 'daily', 'wordfence_daily_cron' );
wp_schedule_event( time() + 600, 'hourly', 'fixact_cron' );
wp_schedule_single_event( time() + 900, 'zzold_cron_hook', array( 'a' => 1, 'b' => 'two' ) );
// A recurrence only the (removed) owner registered: restore must not depend on it existing.
$wpcu_sched = static function ( $s ) {
	$s['wpcu_every_7'] = array( 'interval' => 420, 'display' => 'Every 7 minutes' );
	return $s;
};
add_filter( 'cron_schedules', $wpcu_sched );
wp_schedule_event( time() + 700, 'wpcu_every_7', 'wordfence_custom_sched', array( 42 ) );
remove_filter( 'cron_schedules', $wpcu_sched );

$post_id = wp_insert_post( array( 'post_title' => 'Host post', 'post_status' => 'publish' ) );
add_post_meta( $post_id, '_yoast_wpseo_focuskw', 'keyword' );
add_post_meta( $post_id, '_fixact_meta', 'kept' );
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => 999999, 'meta_key' => '_ghost', 'meta_value' => 'orphan' ) );
add_user_meta( 1, $p . 'yoast_notifications', 'x' );

$cf7_ids = array();
$cat     = wp_insert_term( 'CF7 test cat ' . wp_generate_password( 4, false ), 'category' );
for ( $i = 0; $i < 3; $i++ ) {
	$wpdb->insert( $wpdb->posts, array( 'post_type' => 'wpcf7_contact_form', 'post_title' => 'Form ' . $i, 'post_status' => 'publish', 'post_content' => '[text* name]', 'post_date' => current_time( 'mysql' ), 'post_date_gmt' => current_time( 'mysql', true ), 'post_excerpt' => '', 'to_ping' => '', 'pinged' => '', 'post_content_filtered' => '', 'guid' => '' ) );
	$cf7_ids[] = (int) $wpdb->insert_id;
	$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $wpdb->insert_id, 'meta_key' => '_form', 'meta_value' => 'form body ' . $i ) );
}
$wpdb->insert( $wpdb->term_relationships, array( 'object_id' => $cf7_ids[0], 'term_taxonomy_id' => $cat['term_taxonomy_id'] ) );
wp_update_term_count_now( array( $cat['term_taxonomy_id'] ), 'category' );
wp_insert_comment( array( 'comment_post_ID' => $cf7_ids[1], 'comment_content' => 'note on a form', 'comment_approved' => 1 ) );
wp_insert_post( array( 'post_type' => 'fixact_item', 'post_title' => 'Active CPT', 'post_status' => 'publish' ) );

foreach ( array( $uploads['basedir'] . '/wpforms', $uploads['basedir'] . '/zzold-cache', WP_CONTENT_DIR . '/wflogs' ) as $dir ) {
	wp_mkdir_p( $dir );
	file_put_contents( $dir . '/data.txt', 'content of ' . basename( $dir ) );
}
wp_mkdir_p( $uploads['basedir'] . '/2026' );
wp_cache_flush();

/* ---------------------------------------------------------------------- */
/* Snapshot helper for restore round-trip                                 */
/* ---------------------------------------------------------------------- */

$snapshot = static function () use ( $wpdb, $p, $uploads, $cf7_ids ) {
	$cron = array();
	foreach ( (array) _get_cron_array() as $ts => $hooks ) {
		foreach ( array( 'wordfence_daily_cron', 'wordfence_custom_sched', 'zzold_cron_hook' ) as $hook ) {
			if ( isset( $hooks[ $hook ] ) ) {
				foreach ( $hooks[ $hook ] as $key => $event ) {
					$cron[] = $ts . ':' . $hook . ':' . $key . ':' . wp_json_encode( $event );
				}
			}
		}
	}
	sort( $cron );
	$ids = implode( ',', $cf7_ids );
	return array(
		'options'  => $wpdb->get_results( "SELECT option_id, option_name, HEX(option_value) v, autoload FROM {$wpdb->options} WHERE option_name IN ('wpseo_titles','_transient_wpseo_cache','_transient_zzexp','_transient_timeout_zzexp') ORDER BY option_name", ARRAY_A ),
		'wfconfig' => $wpdb->get_var( "SHOW TABLES LIKE '{$p}wfconfig'" ) ? $wpdb->get_results( "SELECT name, HEX(val) v, HEX(note) n FROM `{$p}wfconfig` ORDER BY name", ARRAY_A ) : null,
		'wfcreate' => $wpdb->get_var( "SHOW TABLES LIKE '{$p}wfconfig'" ) ? $wpdb->get_row( "SHOW CREATE TABLE `{$p}wfconfig`", ARRAY_N ) : null,
		'postmeta' => $wpdb->get_results( "SELECT meta_id, post_id, meta_key, HEX(meta_value) v FROM {$wpdb->postmeta} WHERE meta_key IN ('_yoast_wpseo_focuskw','_form') OR post_id = 999999 ORDER BY meta_id", ARRAY_A ),
		'usermeta' => $wpdb->get_results( "SELECT umeta_id, user_id, meta_key, meta_value FROM {$wpdb->usermeta} WHERE meta_key = '{$p}yoast_notifications'", ARRAY_A ),
		'posts'    => $wpdb->get_results( "SELECT * FROM {$wpdb->posts} WHERE ID IN ($ids) ORDER BY ID", ARRAY_A ),
		'rels'     => $wpdb->get_results( "SELECT * FROM {$wpdb->term_relationships} WHERE object_id IN ($ids)", ARRAY_A ),
		'comments' => $wpdb->get_results( "SELECT comment_ID, comment_post_ID, comment_content FROM {$wpdb->comments} WHERE comment_post_ID IN ($ids)", ARRAY_A ),
		'cron'     => $cron,
		'files'    => array(
			@file_get_contents( $uploads['basedir'] . '/wpforms/data.txt' ),
			@file_get_contents( WP_CONTENT_DIR . '/wflogs/data.txt' ),
		),
	);
};

/* ---------------------------------------------------------------------- */
/* 1. Classification                                                      */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n1. Classification" );
$scanner = new Scanner();
$result  = $scanner->scan();
$by      = wpcu_items_by_key( $result['items'] );
wpcu_assert( $result['index_rebuilt'], 'code index was built on first scan' );

$expect = array(
	// Orphaned: known signature, not installed, unreferenced.
	'option|wpseo_titles'                   => 'orphaned',
	'transient|_transient_wpseo_cache'      => 'orphaned',
	'table|' . $p . 'wfconfig'              => 'orphaned',
	'cron|wordfence_daily_cron'             => 'orphaned',
	'cron|wordfence_custom_sched'           => 'orphaned',
	'meta|post:_yoast_wpseo_focuskw'        => 'orphaned',
	'meta|user:' . $p . 'yoast_notifications' => 'orphaned',
	'post_type|wpcf7_contact_form'          => 'orphaned',
	'file|uploads/wpforms'                  => 'orphaned',
	'file|content/wflogs'                   => 'orphaned',
	// Unknown.
	'option|zzleftover_config'              => 'unknown',
	'table|' . $p . 'zzold_table'           => 'unknown',
	'cron|zzold_cron_hook'                  => 'unknown',
	'file|uploads/zzold-cache'              => 'unknown',
	// Inactive plugin.
	'option|fixinact_settings'              => 'inactive',
	// In use by the active fixture.
	'option|fixact_settings'                => 'in_use',
	'option|fixact_dyn_one'                 => 'in_use',
	'table|' . $p . 'fixact_log'            => 'in_use',
	'cron|fixact_cron'                      => 'in_use',
	'meta|post:_fixact_meta'                => 'in_use',
	'post_type|fixact_item'                 => 'in_use',
	// Core / protected.
	'option|siteurl'                        => 'core',
	'option|cron'                           => 'core',
	'table|' . $p . 'options'               => 'core',
	'table|' . $p . 'staging_options'       => 'core',
	'table|' . $p . 'staging_posts'         => 'core',
	'meta|user:' . $p . 'capabilities'      => 'core',
	'meta|user:rich_editing'                => 'core',
	'post_type|post'                        => 'core',
	'file|uploads/2026'                     => 'core',
	'file|content/plugins'                  => 'missing', // Never listed at all.
	'file|content/uploads'                  => 'missing',
	// Structural.
	'orphan|postmeta'                       => 'safe',
	'orphan|expired_transients'             => 'safe',
	'meta|post:_ghost'                      => 'unknown', // Key of the seeded orphan row.
);
foreach ( $expect as $key => $status ) {
	wpcu_assert( wpcu_status( $by, $key ) === $status, sprintf( '%-45s is %-8s (got %s)', $key, $status, wpcu_status( $by, $key ) ) );
}
wpcu_assert( 'Yoast SEO' === $by['option|wpseo_titles']['owner'], 'wpseo_titles attributed to Yoast SEO' );
wpcu_assert( 'WPCU Fixture Inactive' === $by['option|fixinact_settings']['owner'], 'fixinact_settings attributed to the inactive fixture' );
wpcu_assert( ! empty( $by['option|wpseo_titles']['autoload'] ), 'autoload flag detected' );
wpcu_assert( 1 === $by['orphan|postmeta']['count'], 'exactly one orphaned postmeta row counted' );
$expired_def   = WPCleanup\Scanner::orphan_kinds()['expired_transients'];
$expired_names = $wpdb->get_col( "SELECT t.option_name FROM {$expired_def['table']} t WHERE {$expired_def['where']}" );
wpcu_assert( in_array( '_transient_zzexp', $expired_names, true ) && in_array( '_transient_timeout_zzexp', $expired_names, true ) && ! in_array( '_transient_zzlive', $expired_names, true ), 'expired transient value + timeout counted, live one not' );
wpcu_assert( count( $expired_names ) === $by['orphan|expired_transients']['count'], 'expired transient count matches its rows' );
$non_core_fresh = array_filter(
	$result['items'],
	static function ( $i ) {
		return in_array( $i['status'], array( 'orphaned', 'unknown', 'inactive', 'safe' ), true ) && false === strpos( $i['id'], 'zz' ) && false === strpos( $i['id'], 'fix' );
	}
);
$unexpected = array_diff( array_keys( wpcu_items_by_key( $non_core_fresh ) ), array_keys( $expect ) );
wpcu_assert( ! $unexpected, 'no unexpected cleanable items: ' . implode( ', ', $unexpected ) );

$again = ( new Scanner() )->scan( array( 'option' ) );
wpcu_assert( ! $again['index_rebuilt'], 'code index reused from cache on second scan' );

/* ---------------------------------------------------------------------- */
/* 2. Refusals                                                            */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n2. Refusals" );
$cleaner = new Cleaner();
$refuse  = array( 'option|siteurl', 'option|cron', 'table|' . $p . 'options', 'table|' . $p . 'staging_options', 'meta|user:' . $p . 'capabilities', 'option|fixact_settings', 'post_type|fixact_item', 'post_type|post', 'option|zzleftover_config', 'option|fixinact_settings', 'option|does_not_exist', 'file|uploads/../../wp-config.php', 'bogus|x' );
$report  = $cleaner->clean( $refuse );
wpcu_assert( ! $report['deleted'] && ! $report['failed'], 'nothing deleted for protected/unknown/inactive keys without opt-in' );
wpcu_assert( 12 === count( $report['refused'] ), 'every valid-typed key refused (' . count( $report['refused'] ) . ')' );
wpcu_assert( null === $report['backup'], 'no backup set created when nothing is deletable' );
wpcu_assert( 'active plugin data' === get_option( 'fixact_settings' ) && get_option( 'siteurl' ), 'protected options untouched' );

$dry = $cleaner->clean( array( 'option|wpseo_titles' ), array( 'dry_run' => true ) );
wpcu_assert( 1 === count( $dry['planned'] ) && false !== get_option( 'wpseo_titles' ), 'dry run plans but does not delete' );

/* ---------------------------------------------------------------------- */
/* 3. Clean orphaned + safe, then restore                                 */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n3. Clean + restore round-trip" );
$before  = $snapshot();
$targets = array();
foreach ( $by as $key => $item ) {
	if ( in_array( $item['status'], array( 'orphaned', 'safe' ), true ) ) {
		$targets[] = $key;
	}
}
// Poison the cached term count; deletion and restore must both recalculate it.
$term_count = static function () use ( $wpdb, $cat ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT count FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d", $cat['term_taxonomy_id'] ) );
};
$wpdb->update( $wpdb->term_taxonomy, array( 'count' => 99 ), array( 'term_taxonomy_id' => $cat['term_taxonomy_id'] ) );
$report           = $cleaner->clean( $targets );
wpcu_assert( ! $report['failed'], 'no failures: ' . wp_json_encode( $report['failed'] ) );
wpcu_assert( count( $report['deleted'] ) === count( $targets ), 'all ' . count( $targets ) . ' orphaned/safe items deleted' );
wpcu_assert( (bool) $report['backup'], 'backup set created: ' . $report['backup'] );

wp_cache_flush();
wpcu_assert( false === get_option( 'wpseo_titles' ), 'option gone' );
wpcu_assert( ! $wpdb->get_var( "SHOW TABLES LIKE '{$p}wfconfig'" ), 'table dropped' );
wpcu_assert( ! wp_next_scheduled( 'wordfence_daily_cron' ), 'cron unscheduled' );
wpcu_assert( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_yoast_wpseo_focuskw' OR post_id = 999999" ), 'orphaned + orphan-row postmeta gone' );
wpcu_assert( ! $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'wpcf7_contact_form'" ), 'CF7 posts gone' );
wpcu_assert( ! $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $wpdb->comments . ' WHERE comment_post_ID IN (' . implode( ',', $cf7_ids ) . ')' ), 'their comments gone' );
wpcu_assert( 0 === $term_count(), 'term count recalculated after deletion' );
wpcu_assert( false === get_option( '_transient_zzexp' ) && false === get_option( '_transient_timeout_zzexp' ), 'expired transient pair gone' );
wpcu_assert( 'live value' === get_transient( 'zzlive' ), 'unexpired transient kept' );
wpcu_assert( ! is_dir( $uploads['basedir'] . '/wpforms' ) && ! is_dir( WP_CONTENT_DIR . '/wflogs' ), 'folders moved away' );
wpcu_assert( 'kept' === get_post_meta( $post_id, '_fixact_meta', true ), 'in-use meta kept' );
wpcu_assert( is_dir( $uploads['basedir'] . '/zzold-cache' ) && false !== get_option( 'zzleftover_config' ), 'unknown items kept' );

$backup = Backup::open( $report['backup'] );
$files  = glob( $backup->dir . '/*' );
wpcu_assert( in_array( $backup->dir . '/.htaccess', glob( $backup->dir . '/.htaccess' ), true ) && in_array( $backup->dir . '/index.php', $files, true ), 'backup dir has deny guards' );
$wpdb->update( $wpdb->term_taxonomy, array( 'count' => 99 ), array( 'term_taxonomy_id' => $cat['term_taxonomy_id'] ) );
$restore = $backup->restore();
$bad     = array_filter(
	$restore,
	static function ( $r ) {
		return ! $r['ok'];
	}
);
wpcu_assert( ! $bad, 'restore reported no failures: ' . wp_json_encode( array_values( $bad ) ) );
wp_cache_flush();
$after = $snapshot();
foreach ( $before as $part => $value ) {
	wpcu_assert( $value == $after[ $part ], "restored $part identical to original" ); // phpcs:ignore -- loose compare is intended for DB strings.
}
wpcu_assert( 0 === $term_count(), 'term count recalculated after restore' );
$again = $backup->restore();
wpcu_assert( ! $again, 'second restore is a no-op' );

/* ---------------------------------------------------------------------- */
/* 4. Opt-ins                                                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n4. Opt-in policies" );
$report = $cleaner->clean( array( 'option|zzleftover_config', 'file|uploads/zzold-cache' ), array( 'allow_unknown' => true ) );
wpcu_assert( 2 === count( $report['deleted'] ), 'unknown items deleted with allow_unknown' );
$report = $cleaner->clean( array( 'option|fixinact_settings' ), array( 'allow_unknown' => true ) );
wpcu_assert( ! $report['deleted'], 'inactive item still refused without allow_inactive' );
$report = $cleaner->clean( array( 'option|fixinact_settings' ), array( 'allow_inactive' => true ) );
wpcu_assert( 1 === count( $report['deleted'] ), 'inactive item deleted with allow_inactive' );

/* ---------------------------------------------------------------------- */
/* 5. Restore does not overwrite recreated data                           */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n5. Restore conflicts" );
$report = $cleaner->clean( array( 'table|' . $p . 'wfconfig' ) );
$wpdb->query( "CREATE TABLE `{$p}wfconfig` (x INT)" );
$res = Backup::open( $report['backup'] )->restore();
wpcu_assert( 1 === count( $res ) && ! $res[0]['ok'], 'restore refuses to overwrite a recreated table' );
wpcu_assert( 'x' === $wpdb->get_var( "SHOW COLUMNS FROM `{$p}wfconfig`" ), 'recreated table left untouched' );

/* ---------------------------------------------------------------------- */
/* 6. Incomplete index fails safe                                         */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n6. Incomplete code index" );
$one_file = static function () {
	return 1;
};
add_filter( 'wp_cleanup_index_max_files', $one_file );
$partial = ( new Scanner() )->scan( array( 'option' ) );
$pby     = wpcu_items_by_key( $partial['items'] );
wpcu_assert( $partial['index_rebuilt'] && ! empty( $partial['incomplete'] ), 'truncated sources are reported' );
wpcu_assert( 'unknown' === wpcu_status( $pby, 'option|wpseo_titles' ), 'orphaned downgraded to unknown while index is incomplete' );
wpcu_assert( 'core' === wpcu_status( $pby, 'option|siteurl' ), 'core protection unaffected' );
$report = $cleaner->clean( array( 'option|wpseo_titles' ) );
wpcu_assert( ! $report['deleted'], 'default policy deletes nothing while index is incomplete' );
remove_filter( 'wp_cleanup_index_max_files', $one_file );
$full = ( new Scanner() )->scan( array( 'option' ) );
wpcu_assert( empty( $full['incomplete'] ) && 'orphaned' === wpcu_status( wpcu_items_by_key( $full['items'] ), 'option|wpseo_titles' ), 'full index restores orphaned status' );

/* ---------------------------------------------------------------------- */
/* 7. Uninstall                                                           */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n7. Uninstall" );
$data_dir = Storage::dir();
define( 'WP_UNINSTALL_PLUGIN', 'wp-cleanup/wp-cleanup.php' );
include WPCU_DIR . 'uninstall.php';
wpcu_assert( ! is_dir( $data_dir ), 'data directory and backups removed' );
wpcu_assert( false === get_option( Storage::OPTION_DIR ), 'data dir option removed' );

WP_CLI::log( '' );
if ( $GLOBALS['wpcu_failures'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpcu_failures'], $GLOBALS['wpcu_passes'] ) );
}
WP_CLI::success( sprintf( 'All %d assertions passed.', $GLOBALS['wpcu_passes'] ) );
