<?php
/**
 * Conversion guard: per-image lock, check before converting, recovery after
 * a gateway timeout, time and memory estimates. DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/guard.php
 *
 * @package WPCleanup
 */

use WPCleanup\Backup;
use WPCleanup\Media_Converter;
use WPCleanup\Media_Guard;
use WPCleanup\Media_Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( '1' !== getenv( 'WPCU_TESTS' ) || ! preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', (string) $host ) ) {
	WP_CLI::error( 'Refusing to run: set WPCU_TESTS=1 and use a local site.' );
}
if ( ! Media_Policy::avif_supported() ) {
	WP_CLI::error( 'This server cannot write AVIF; these tests need it.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

global $wpdb;
$GLOBALS['wpcg_f'] = 0;
$GLOBALS['wpcg_p'] = 0;
function wpcg_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpcg_p' : 'wpcg_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

$dir = wp_normalize_path( wp_upload_dir()['path'] );

function wpcg_attach( $name, $w, $h ) {
	$path = wp_upload_dir()['path'] . '/' . $name;
	$im   = imagecreatetruecolor( $w, $h );
	for ( $x = 0; $x < $w; $x += 8 ) {
		imagefilledrectangle( $im, $x, 0, $x + 7, $h, imagecolorallocate( $im, intdiv( $x * 255, $w ), 90, 180 ) );
	}
	imagejpeg( $im, $path, 90 );
	imagedestroy( $im );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wpcg_test', 1 );
	return $id;
}

function wpcg_files( $dir ) {
	$out = array();
	foreach ( glob( $dir . '/wpcg-*' ) as $f ) {
		$out[ basename( $f ) ] = md5_file( $f );
	}
	ksort( $out );
	return $out;
}

$reset = static function () use ( $dir, $wpdb ) {
	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpcg_test', 'fields' => 'ids' ) ) as $old ) {
		wp_delete_attachment( $old, true );
	}
	foreach ( glob( $dir . '/wpcg-*' ) as $f ) {
		unlink( $f );
	}
	foreach ( Backup::all() as $b ) {
		Backup::open( $b['id'] )->delete();
	}
	delete_option( Media_Policy::OPTION );
	delete_option( Media_Guard::TIMING );
	delete_option( Media_Guard::GATEWAY );
	$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE 'wpcu\\_media\\_lock\\_%'" );
	wp_cache_flush();
};
$reset();

/* ---------------------------------------------------------------------- */
/* 1. Check before converting                                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( 'Check before converting' );
$a = wpcg_attach( 'wpcg-a.jpg', 1200, 800 );
$c = Media_Guard::preflight( $a );
wpcg_ok( 'ready' === $c['state'] && $c['estimate'] > 0 && ! $c['measured'] && 1200 === $c['width'] && 800 === $c['height'], "an unconverted image is ready, with an unmeasured estimate ({$c['estimate']} s of {$c['budget']} s)" );
wpcg_ok( Media_Guard::DEFAULT_BUDGET === $c['budget'], 'the default budget applies before any timeout was seen' );

/* ---------------------------------------------------------------------- */
/* 2. Lock: one conversion per image                                      */
/* ---------------------------------------------------------------------- */

WP_CLI::log( 'Lock' );
$backup = Backup::start();
wpcg_ok( Media_Guard::lock( $a, $backup->id ), 'the lock is taken' );
wpcg_ok( ! Media_Guard::lock( $a, $backup->id ), 'a second lock on the same image is refused' );
$before = wpcg_files( $dir );
$busy   = Media_Guard::preflight( $a );
wpcg_ok( 'busy' === $busy['state'] && $backup->id === $busy['backup'], 'the check reports busy and the running backup set' );
$r = Media_Converter::convert( $a, $backup );
wpcg_ok( 'busy' === $r['status'] && wpcg_files( $dir ) === $before, 'converting a locked image does nothing' );
Media_Guard::unlock( $a );
wpcg_ok( null === Media_Guard::lock_info( $a ), 'unlock removes the lock' );

/* ---------------------------------------------------------------------- */
/* 3. Abandoned locks                                                     */
/* ---------------------------------------------------------------------- */

WP_CLI::log( 'Abandoned locks' );
$stale = static function ( $id ) use ( $wpdb ) {
	$wpdb->replace( $wpdb->options, array( 'option_name' => Media_Guard::LOCK_PREFIX . $id, 'option_value' => wp_json_encode( array( 'since' => time() - 100000, 'backup' => 'old-set' ) ), 'autoload' => 'no' ) );
};
$stale( $a );
wpcg_ok( Media_Guard::lock_info( $a )['stale'], 'a lock older than the longest request is stale' );
$c = Media_Guard::preflight( $a );
wpcg_ok( 'ready' === $c['state'] && null === Media_Guard::lock_info( $a ), 'a stale lock on intact data is cleared and the image is ready' );

$stale( $a );
$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/png' ), array( 'ID' => $a ) );
clean_post_cache( $a );
$c = Media_Guard::preflight( $a );
wpcg_ok( 'interrupted' === $c['state'] && $c['issues'] && 'old-set' === $c['backup'] && null === Media_Guard::lock_info( $a ), 'a stale lock with mismatched data is reported as interrupted, with the issues' );
$after = Media_Guard::preflight( $a, false, true );
wpcg_ok( 'interrupted' === $after['state'], 'after a lost answer, mismatched data is reported even without a lock' );
$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/jpeg' ), array( 'ID' => $a ) );
clean_post_cache( $a );
$after = Media_Guard::preflight( $a, false, true );
wpcg_ok( 'ready' === $after['state'], 'after a lost answer, intact unconverted data is ready again (the request did not finish)' );

$stale( $a );
wpcg_ok( Media_Guard::lock( $a, $backup->id ), 'a stale lock can be taken over by a new conversion' );
Media_Guard::unlock( $a );

/* ---------------------------------------------------------------------- */
/* 4. Conversion releases the lock and records its time                   */
/* ---------------------------------------------------------------------- */

WP_CLI::log( 'Conversion' );
$r = Media_Converter::convert( $a, $backup );
wpcg_ok( 'converted' === $r['status'], 'the image converts: ' . $r['message'] );
wpcg_ok( null === Media_Guard::lock_info( $a ), 'the lock is released after converting' );
$t = Media_Guard::timings();
wpcg_ok( 1 === count( $t ) && $t[0][0] > 0 && $t[0][1] > 0, 'the conversion time is recorded (' . $t[0][0] . ' s for ' . $t[0][1] . ' MP)' );
wpcg_ok( 'done' === Media_Guard::preflight( $a )['state'], 'the check then reports done' );
wpcg_ok( 'done' === Media_Guard::preflight( $a, false, true )['state'], 'after a lost answer, a finished conversion is reported as done' );
wpcg_ok( Media_Guard::estimate( 2 )['measured'], 'estimates use measured timings once there are any' );

$fail = wpcg_attach( 'wpcg-fail.jpg', 900, 600 );
Media_Policy::save( array_merge( Media_Policy::settings(), array( 'jpeg_fallback' => false ) ) );
add_filter( 'wp_image_editors', '__return_empty_array' );
$r = Media_Converter::convert( $fail, $backup );
remove_filter( 'wp_image_editors', '__return_empty_array' );
wpcg_ok( 'failed' === $r['status'] && null === Media_Guard::lock_info( $fail ), 'a failed conversion also releases the lock' );
wpcg_ok( 1 === count( Media_Guard::timings() ), 'failed conversions are not recorded as timings' );
delete_option( Media_Policy::OPTION );

/* ---------------------------------------------------------------------- */
/* 5. Gateway limit and risky images                                      */
/* ---------------------------------------------------------------------- */

WP_CLI::log( 'Gateway limit' );
Media_Guard::gateway_failed( 4 );
wpcg_ok( 0 === (int) get_option( Media_Guard::GATEWAY, 0 ), 'an immediate gateway error is not taken as a time limit' );
Media_Guard::gateway_failed( 60 );
Media_Guard::gateway_failed( 90 );
wpcg_ok( 60 === (int) get_option( Media_Guard::GATEWAY ) && 42 === Media_Guard::budget(), 'the shortest observed wait is kept; the budget is 70% of it' );

$big = wpcg_attach( 'wpcg-big.jpg', 3000, 2000 );
update_option( Media_Guard::TIMING, array( array( 30.0, 1.0 ) ), false );
$files = wpcg_files( $dir );
$c     = Media_Guard::preflight( $big );
wpcg_ok( 'risky' === $c['state'] && $c['estimate'] > $c['budget'] && false !== strpos( $c['message'], 'wp cleanup images convert --ids=' . $big ), "a slow image is held back with a WP-CLI hint ({$c['estimate']} s > {$c['budget']} s)" );
wpcg_ok( wpcg_files( $dir ) === $files, 'the check changes no files' );
wpcg_ok( 'ready' === Media_Guard::preflight( $big, true )['state'], '"also try images that may time out" lets it through' );
add_filter( 'wpcu_media_request_budget', static function () { return 100000; } );
wpcg_ok( 'ready' === Media_Guard::preflight( $big )['state'], 'the budget can be raised with a filter' );
remove_all_filters( 'wpcu_media_request_budget' );
Media_Guard::record( 61, 2 );
wpcg_ok( 0 === (int) get_option( Media_Guard::GATEWAY, 0 ), 'a conversion that ran longer than the remembered limit clears it' );
delete_option( Media_Guard::TIMING );

WP_CLI::log( 'Memory' );
$gd_only = static function () { return array( 'WP_Image_Editor_GD' ); };
$tiny    = static function () { return '8M'; };
add_filter( 'wp_image_editors', $gd_only );
add_filter( 'image_memory_limit', $tiny );
$c = Media_Guard::preflight( $big );
wpcg_ok( 'risky' === $c['state'] && false !== strpos( $c['message'], 'PHP memory' ), 'an image too large for GD memory is held back' );
remove_filter( 'image_memory_limit', $tiny );
wpcg_ok( 'ready' === Media_Guard::preflight( $big )['state'], 'with enough memory it is ready' );
remove_filter( 'wp_image_editors', $gd_only );

/* ---------------------------------------------------------------------- */
/* Teardown                                                               */
/* ---------------------------------------------------------------------- */

$reset();

WP_CLI::log( '' );
if ( $GLOBALS['wpcg_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpcg_f'], $GLOBALS['wpcg_p'] ) );
}
WP_CLI::success( sprintf( 'All %d guard assertions passed.', $GLOBALS['wpcg_p'] ) );
