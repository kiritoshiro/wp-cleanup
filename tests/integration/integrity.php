<?php
/**
 * Image data check, ALPS marker, file details, clean names, statistics and
 * content fallback. DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/integrity.php
 *
 * Set WPCU_ALPS_DIR to an Adventistai-ALPS checkout to also test against the theme's real
 * image code (app/UploadImages.php); default: ../Adventistai-ALPS next to this plugin's repo.
 *
 * @package WPCleanup
 */

use WPCleanup\Backup;
use WPCleanup\Media_Converter;
use WPCleanup\Media_Delivery;
use WPCleanup\Media_Files;
use WPCleanup\Media_Integrity;
use WPCleanup\Media_Policy;
use WPCleanup\Media_Report;

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
$GLOBALS['wpci_f'] = 0;
$GLOBALS['wpci_p'] = 0;
function wpci_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpci_p' : 'wpci_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

$up   = wp_upload_dir();
$dir  = wp_normalize_path( $up['path'] );
$sub  = ltrim( $up['subdir'], '/' );
$root = wp_normalize_path( $up['basedir'] );

function wpci_make( $path, $w, $h, $type ) {
	$im = imagecreatetruecolor( $w, $h );
	for ( $x = 0; $x < $w; $x += 8 ) {
		imagefilledrectangle( $im, $x, 0, $x + 7, $h, imagecolorallocate( $im, intdiv( $x * 255, $w ), 100, 200 ) );
	}
	'png' === $type ? imagepng( $im, $path ) : imagejpeg( $im, $path, 90 );
	imagedestroy( $im );
}

function wpci_attach( $name, $w, $h, $type = 'jpg' ) {
	$path = wp_upload_dir()['path'] . '/' . $name;
	wpci_make( $path, $w, $h, $type );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'png' === $type ? 'image/png' : 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wpci_test', 1 );
	return $id;
}

function wpci_files( $dir ) {
	$out = array();
	foreach ( glob( $dir . '/wpci-*' ) as $f ) {
		$out[ basename( $f ) ] = md5_file( $f );
	}
	ksort( $out );
	return $out;
}

function wpci_meta_snapshot( $id ) {
	global $wpdb;
	return array(
		'mime' => $wpdb->get_var( $wpdb->prepare( "SELECT post_mime_type FROM {$wpdb->posts} WHERE ID = %d", $id ) ),
		'meta' => $wpdb->get_results( $wpdb->prepare( "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_key, meta_id", $id ), ARRAY_A ),
	);
}

function wpci_codes( $id ) {
	return wp_list_pluck( Media_Integrity::check( $id )['issues'], 'code' );
}

$reset = static function () use ( $dir ) {
	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpci_test', 'fields' => 'ids' ) ) as $old ) {
		'attachment' === get_post_type( $old ) ? wp_delete_attachment( $old, true ) : wp_delete_post( $old, true );
	}
	foreach ( glob( $dir . '/wpci-*' ) as $f ) {
		unlink( $f );
	}
	foreach ( Backup::all() as $b ) {
		Backup::open( $b['id'] )->delete();
	}
	delete_option( Media_Policy::OPTION );
	wp_cache_flush();
};
$reset();

$policy = static function ( array $changes ) {
	Media_Policy::save( array_merge( Media_Policy::settings(), $changes ) );
};

/* ---------------------------------------------------------------------- */
/* 1. ALPS marker                                                         */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n1. ALPS marker" );
$policy( array( 'jpeg_fallback' => true, 'set_flag' => true ) );
$big   = wpci_attach( 'wpci-big.jpg', 2400, 1600 );
$small = wpci_attach( 'wpci-small.jpg', 500, 300 );
// Created before section 2 loads the ALPS hooks, so it has WordPress's classic sizes.
$d    = wpci_attach( 'wpci-detail.jpg', 2000, 1200 );
$post = wp_insert_post( array( 'post_title' => 'detail host', 'post_status' => 'publish', 'post_content' => '<img src="' . $up['url'] . '/wpci-detail-1024x614.jpg" class="wp-image-' . $d . ' size-large">' ) );
update_post_meta( $post, '_wpci_test', 1 );
$b1    = Backup::start();
$rb    = Media_Converter::convert( $big, $b1 );
$rs    = Media_Converter::convert( $small, $b1 );
wpci_ok( 'converted' === $rb['status'] && 'converted' === $rs['status'], 'both images converted' );
$mb = wp_get_attachment_metadata( $big );
$ms = wp_get_attachment_metadata( $small );
wpci_ok( isset( $mb['sizes']['alps-small'] ) && (bool) get_post_meta( $big, Media_Policy::ALPS_FLAG, true ), 'large image: alps-small exists and marker set' );
wpci_ok( empty( $ms['sizes'] ) && (bool) get_post_meta( $small, Media_Policy::ALPS_FLAG, true ), 'small image: single AVIF and marker set, as ALPS does for its own small uploads' );
wpci_ok( array() === wpci_codes( $big ) && array() === wpci_codes( $small ), 'freshly converted images have no data problems' );

$files_before = wpci_files( $dir );
$policy( array( 'set_flag' => false ) );
$inv = \WPCleanup\Media_Inventory::attachment( $big );
wpci_ok( $inv['compliant'], 'switching the marker off does not make images non-compliant (no re-encoding)' );
wpci_ok( in_array( 'flag', wpci_codes( $big ), true ), 'marker left behind after switching it off is detected' );
$b2 = Backup::start();
$rr = Media_Integrity::repair( $big, $b2 );
wpci_ok( 'repaired' === $rr['status'] && ! get_post_meta( $big, Media_Policy::ALPS_FLAG, true ), 'repair removes the marker' );
wpci_ok( $files_before === wpci_files( $dir ), 'repair did not touch any image file' );

$policy( array( 'set_flag' => true, 'small_name' => 'tiny' ) );
wpci_ok( ! Media_Policy::alps_compatible() && ! Media_Policy::wants_flag(), 'a size name other than alps-small is not ALPS-compatible' );
wpci_ok( in_array( 'flag', wpci_codes( $small ), true ), 'marker on an image under a non-ALPS policy is detected' );
$policy( array( 'small_name' => 'alps-small' ) );
wpci_ok( in_array( 'flag', wpci_codes( $big ), true ), 'missing marker under an ALPS policy is detected' );
Media_Integrity::repair( $big, $b2 );
wpci_ok( (bool) get_post_meta( $big, Media_Policy::ALPS_FLAG, true ) && array() === wpci_codes( $big ), 'repair adds it back; no problems left' );

/* ---------------------------------------------------------------------- */
/* 2. ALPS theme code                                                     */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n2. Real ALPS theme image code" );
// Default: an Adventistai-ALPS checkout next to this plugin's real (symlink/junction-resolved) checkout.
$alps_dir = getenv( 'WPCU_ALPS_DIR' ) ? getenv( 'WPCU_ALPS_DIR' ) : dirname( (string) realpath( WPCU_DIR ) ) . '/Adventistai-ALPS';
if ( is_file( $alps_dir . '/app/UploadImages.php' ) && ! class_exists( 'App\\UploadImages' ) ) {
	require_once $alps_dir . '/app/UploadImages.php';
	\App\UploadImages::register();
	do_action( 'after_setup_theme' ); // Registers alps-small.
}
if ( class_exists( 'App\\UploadImages' ) ) {
	wpci_ok( Media_Policy::alps_theme()['active'], 'ALPS image policy detected' );
	// Regenerate the small marked image: ALPS limits generation to alps-small, so nothing is added.
	$file  = get_attached_file( $small );
	$regen = wp_generate_attachment_metadata( $small, $file );
	wpci_ok( empty( $regen['sizes'] ), 'with the marker, ALPS regeneration creates no old sizes for the small image' );
	delete_post_meta( $small, Media_Policy::ALPS_FLAG );
	$regen2 = wp_generate_attachment_metadata( $small, $file );
	wpci_ok( ! empty( $regen2['sizes'] ), 'without the marker, regeneration would bring back old sizes (' . implode( ', ', array_keys( (array) $regen2['sizes'] ) ) . ')' );
	foreach ( (array) $regen2['sizes'] as $sz ) {
		@unlink( dirname( $file ) . '/' . $sz['file'] );
	}
	update_post_meta( $small, Media_Policy::ALPS_FLAG, 1 );
	$src = wp_get_attachment_image_src( $big, 'horiz__16x9--s' );
	wpci_ok( $src && false !== strpos( $src[0], $mb['sizes']['alps-small']['file'] ), 'ALPS maps template size horiz__16x9--s to alps-small' );
} else {
	WP_CLI::log( '  skip ALPS theme code not found (set WPCU_ALPS_DIR)' );
}

/* ---------------------------------------------------------------------- */
/* 3. Data problems are detected and repaired                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n3. Data check and repair" );
$c    = wpci_attach( 'wpci-broken.jpg', 1600, 1000 );
Media_Converter::convert( $c, $b1 );
$good = wpci_meta_snapshot( $c );
$rel  = get_post_meta( $c, '_wp_attached_file', true );
$meta = wp_get_attachment_metadata( $c );
$out  = get_post_meta( $c, Media_Policy::OUTPUT_META, true );

// Recreate past mistakes directly in the database.
update_post_meta( $c, '_wp_attached_file', $root . '/' . $rel );               // 0.2.0 Windows absolute path.
$broken                    = $meta;
$broken['width']           = 999;                                              // Wrong dimensions.
$small_entry               = $broken['sizes']['alps-small'];
unset( $broken['sizes']['alps-small'] );                                       // Small AVIF exists but is not listed.
$broken['sizes']['medium'] = array( 'file' => 'wpci-broken-300x188.jpg', 'width' => 300, 'height' => 188, 'mime-type' => 'image/jpeg' ); // Listed file that does not exist.
update_post_meta( $c, '_wp_attachment_metadata', $broken );
$wpdb->update( $wpdb->posts, array( 'post_mime_type' => 'image/jpeg' ), array( 'ID' => $c ) ); // Wrong type.
$out_broken                   = $out;
$out_broken['avif_full_width'] = 123;                                         // Wrong recorded width.
update_post_meta( $c, Media_Policy::OUTPUT_META, $out_broken );
clean_post_cache( $c );
wp_cache_flush();
$bad   = wpci_meta_snapshot( $c );
$codes = wpci_codes( $c );
foreach ( array( 'path', 'mime', 'dims', 'size_missing', 'small_sync', 'output_dims' ) as $code ) {
	wpci_ok( in_array( $code, $codes, true ), "detects $code" );
}

$report = Media_Report::build( null, false );
wpci_ok( isset( $report['issues'][ $c ] ), 'a library check reports the problems automatically' );
\WPCleanup\Storage::write_json( Media_Report::FILE, $report );

$files_before = wpci_files( $dir );
$b3           = Backup::start();
$rep          = Media_Integrity::repair( $c, $b3 );
wp_cache_flush();
wpci_ok( 'repaired' === $rep['status'], 'repair succeeded: ' . count( $rep['fixed'] ) . ' fixes' );
wpci_ok( array() === wpci_codes( $c ), 'no problems left after repair' );
wpci_ok( $rel === get_post_meta( $c, '_wp_attached_file', true ) && 'image/avif' === get_post_mime_type( $c ), 'path relative again and type AVIF' );
$fixed = wp_get_attachment_metadata( $c );
wpci_ok( $meta['width'] === $fixed['width'] && isset( $fixed['sizes']['alps-small'] ) && ! isset( $fixed['sizes']['medium'] ), 'dimensions fixed, small AVIF listed again, missing size removed' );
wpci_ok( $files_before === wpci_files( $dir ), 'no image file changed' );
Media_Report::refresh_issues( array( $c ) );
$stored = Media_Report::last();
wpci_ok( empty( $stored['issues'][ $c ] ), 'stored report updated after repair' );

$res = Backup::open( $b3->id )->restore();
wp_cache_flush();
wpci_ok( 1 === count( $res ) && $res[0]['ok'] && $bad == wpci_meta_snapshot( $c ), 'repair restores to the exact previous data' ); // phpcs:ignore
$b4 = Backup::start();
Media_Integrity::repair( $c, $b4 );
update_post_meta( $c, '_wp_attachment_image_alt', 'edited later' );
update_post_meta( $c, Media_Policy::ALPS_FLAG, 0 );
$res = Backup::open( $b4->id )->restore();
wpci_ok( 1 === count( $res ) && ! $res[0]['ok'], 'repair restore refuses after a later change' );
update_post_meta( $c, Media_Policy::ALPS_FLAG, 1 );

$gone = wpci_attach( 'wpci-gone.jpg', 800, 600 );
unlink( get_attached_file( $gone ) );
$r = Media_Integrity::check( $gone );
wpci_ok( 1 === count( $r['issues'] ) && 'missing' === $r['issues'][0]['code'] && ! $r['issues'][0]['fixable'], 'a missing main file is reported as needing attention, not "repaired"' );
wpci_ok( 'clean' === Media_Integrity::repair( $gone, $b4 )['status'], 'repair changes nothing for it' );

/* ---------------------------------------------------------------------- */
/* 4. File details in results                                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n4. Sizes and dimensions of every file" );
$b5 = Backup::start();
$r  = Media_Converter::convert( $d, $b5 );
wpci_ok( 'converted' === $r['status'], 'converted' );
$all_info = true;
foreach ( $r['backed_up_info'] as $info ) {
	$all_info = $all_info && $info['bytes'] > 0 && $info['width'] > 0 && $info['height'] > 0;
}
wpci_ok( count( $r['backed_up_info'] ) === count( $r['backed_up'] ) && $all_info, 'every backed-up file reports size and dimensions (' . count( $r['backed_up_info'] ) . ' files)' );
$roles = wp_list_pluck( $r['created_info'], 'role' );
wpci_ok( 3 === count( $r['created_info'] ) && in_array( 'Small AVIF', $roles, true ), 'every new file reports role, size and dimensions: ' . implode( ', ', $roles ) );
$ch = $r['reference_changes'][0];
wpci_ok( 1024 === $ch['from_info']['width'] && 1920 === $ch['to_info']['width'] && $ch['to_info']['bytes'] > 0, 'rewritten reference shows old 1024 px file and new 1920 px file' );
$item = Backup::open( $b5->id )->manifest['items'][0];
wpci_ok( ! empty( $item['extra']['moved_info'] ) && ! empty( $item['extra']['created_info'] ), 'backup set keeps the file details for the Backups tab' );

/* ---------------------------------------------------------------------- */
/* 5. Clean names on re-conversion                                        */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n5. Re-conversion keeps clean names" );
$names_before = wp_list_pluck( $r['created_info'], 'path' );
$snap_files   = wpci_files( $dir );
$snap_meta    = wpci_meta_snapshot( $d );
$policy( array( 'jpeg_quality' => 70 ) ); // Forces re-encoding.
$b6 = Backup::start();
$r2 = Media_Converter::convert( $d, $b6 );
$names_after = wp_list_pluck( $r2['created_info'], 'path' );
sort( $names_before );
sort( $names_after );
wpci_ok( 'converted' === $r2['status'] && $names_before === $names_after, 'same clean file names, no -1 suffix: ' . implode( ', ', array_map( 'basename', $names_after ) ) );
wpci_ok( ! glob( $dir . '/*wpcu-new*' ), 'no temporary files left' );
wpci_ok( array() === wpci_codes( $d ), 'no data problems after re-conversion' );
$res = Backup::open( $b6->id )->restore();
wp_cache_flush();
wpci_ok( 1 === count( $res ) && $res[0]['ok'], 'restore works although old and new files share names: ' . $res[0]['message'] );
wpci_ok( $snap_files === wpci_files( $dir ) && $snap_meta == wpci_meta_snapshot( $d ), 'restore is byte-identical' ); // phpcs:ignore
$policy( array( 'jpeg_quality' => 82 ) );

/* ---------------------------------------------------------------------- */
/* 6. JPEG fallback reaches images in post content                        */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n6. Post content gets the JPEG fallback" );
$html = apply_filters( 'the_content', get_post_field( 'post_content', $post, 'raw' ) );
$o    = get_post_meta( $d, Media_Policy::OUTPUT_META, true );
wpci_ok( false !== strpos( $html, '<picture><source type="image/avif"' ) && false !== strpos( $html, wp_basename( $o['jpeg'] ) ), 'content image wrapped in picture with AVIF source and JPEG img' );
$nested = Media_Delivery::content( '<picture><source srcset="x.avif"><img class="wp-image-' . $d . '" src="x.jpg"></picture>' );
wpci_ok( 1 === substr_count( $nested, '<picture' ), 'an image already inside a picture is left alone' );

/* ---------------------------------------------------------------------- */
/* 7. Server statistics                                                   */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n7. Statistics" );
wpci_make( $dir . '/wpci-loose.png', 640, 480, 'png' );
$cat = Media_Files::catalog( Media_Files::all_ids() );
$loose = null;
foreach ( $cat['unregistered'] as $f ) {
	if ( wp_basename( $f['path'] ) === 'wpci-loose.png' ) {
		$loose = $f;
	}
}
wpci_ok( $loose && 640 === $loose['width'] && 480 === $loose['height'], 'files without an attachment report their dimensions' );
$sum_files = array_sum( wp_list_pluck( $cat['formats'], 'files' ) );
$sum_bytes = array_sum( wp_list_pluck( $cat['formats'], 'bytes' ) );
wpci_ok( $sum_files === $cat['library_files'] + $cat['unregistered_count'] && $sum_bytes === $cat['library_bytes'] + $cat['unregistered_bytes'], "format totals add up ($sum_files files)" );
wpci_ok( isset( $cat['formats']['AVIF'], $cat['formats']['PNG'] ), 'formats include AVIF and PNG' );

/* ---------------------------------------------------------------------- */
/* Teardown                                                               */
/* ---------------------------------------------------------------------- */

$reset();
@unlink( $dir . '/wpci-loose.png' );

WP_CLI::log( '' );
if ( $GLOBALS['wpci_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpci_f'], $GLOBALS['wpci_p'] ) );
}
WP_CLI::success( sprintf( 'All %d data-check assertions passed.', $GLOBALS['wpci_p'] ) );
