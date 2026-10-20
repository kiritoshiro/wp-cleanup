<?php
/**
 * Attachments that point at the same physical file. Removing, merging or converting
 * one of them must never take a file another attachment still names.
 * DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/shared-files.php
 *
 * Needs an AVIF-capable GD (the converter case).
 *
 * @package WPCleanup
 */

use WPCleanup\Backup;
use WPCleanup\Media_Converter;
use WPCleanup\Media_Merger;
use WPCleanup\Media_Policy;
use WPCleanup\Media_Remover;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( '1' !== getenv( 'WPCU_TESTS' ) || ! preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', (string) $host ) ) {
	WP_CLI::error( 'Refusing to run: set WPCU_TESTS=1 and use a local site.' );
}
if ( ! function_exists( 'imageavif' ) ) {
	WP_CLI::error( 'GD AVIF support is needed for the converter case.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';

$GLOBALS['wpcs_f'] = 0;
$GLOBALS['wpcs_p'] = 0;
function wpcs_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpcs_p' : 'wpcs_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

$dir      = wp_normalize_path( wp_upload_dir()['path'] );
$backups  = array();
$reset    = static function () use ( $dir, &$backups ) {
	foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpcs_test', 'fields' => 'ids' ) ) as $old ) {
		if ( 'attachment' === get_post_type( $old ) ) {
			wp_delete_attachment( $old, true );
		} else {
			wp_delete_post( $old, true );
		}
	}
	foreach ( glob( $dir . '/wpcs-*' ) as $f ) {
		unlink( $f );
	}
	foreach ( $backups as $id ) {
		$b = Backup::open( $id );
		if ( $b ) {
			$b->delete();
		}
	}
	$backups = array();
	delete_option( Media_Policy::OPTION );
	wp_cache_flush();
};
$reset();

/** A real upload with generated sizes. */
function wpcs_upload( $name, $w, $h ) {
	$path = wp_upload_dir()['path'] . '/' . $name;
	$im   = imagecreatetruecolor( $w, $h );
	for ( $x = 0; $x < $w; $x += 8 ) {
		imagefilledrectangle( $im, $x, 0, $x + 7, $h, imagecolorallocate( $im, intdiv( $x * 255, $w ), 90, 255 - intdiv( $x * 255, $w ) ) );
	}
	imagepng( $im, $path );
	imagedestroy( $im );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wpcs_test', 1 );
	return $id;
}

/** A second attachment row for the same file, as an import or a re-registered placeholder makes. */
function wpcs_twin( $of, $sizes = true ) {
	$id   = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'twin of ' . $of, 'post_status' => 'inherit' ), get_attached_file( $of, true ) );
	$meta = wp_get_attachment_metadata( $of, true );
	if ( ! $sizes ) {
		$meta['sizes'] = array();
	}
	wp_update_attachment_metadata( $id, $meta );
	update_post_meta( $id, '_wpcs_test', 1 );
	return $id;
}

/** Every file an attachment's metadata names, as absolute paths. */
function wpcs_files( $id ) {
	$main  = wp_normalize_path( get_attached_file( $id, true ) );
	$meta  = wp_get_attachment_metadata( $id, true );
	$files = array( $main );
	foreach ( (array) ( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $size ) {
		$files[] = dirname( $main ) . '/' . $size['file'];
	}
	return array_values( array_unique( $files ) );
}

function wpcs_all_exist( array $files ) {
	return (bool) $files && count( array_filter( $files, 'is_file' ) ) === count( $files );
}

/** A post that uses the keeper as its featured image. */
function wpcs_use( $id ) {
	$post = wp_insert_post( array( 'post_title' => 'uses ' . $id, 'post_status' => 'publish' ) );
	update_post_meta( $post, '_wpcs_test', 1 );
	set_post_thumbnail( $post, $id );
	return $post;
}

/* ---------------------------------------------------------------------- */
WP_CLI::log( '1. Removing an identical duplicate that shares the keeper\'s file' );

$drop  = wpcs_upload( 'wpcs-shared.png', 900, 600 );
$keep  = wpcs_twin( $drop );
$files = wpcs_files( $keep );
wpcs_use( $keep );
wpcs_ok( count( $files ) > 1 && wpcs_all_exist( $files ), 'fixture: keeper names ' . count( $files ) . ' existing files shared with the copy' );

$backup    = Backup::start();
$backups[] = $backup->id;
$result    = Media_Remover::remove_duplicate( $drop, $keep, $backup );
wp_cache_flush();
wpcs_ok( wpcs_all_exist( $files ), 'every file the keeper names survives the duplicate removal (' . $result['status'] . ': ' . $result['message'] . ')' );
wpcs_ok( 'deleted' === $result['status'] && ! get_post( $drop ) && get_post( $keep ), 'the duplicate attachment row is removed and the keeper stays' );
$item = Backup::open( $backup->id )->manifest['items'][0];
wpcs_ok( isset( $item['extra']['kept_shared'] ) && count( $item['extra']['kept_shared'] ) === count( $files ) && empty( $item['extra']['moved'] ), 'manifest records the shared files as left in place and moves none of them' );
wpcs_ok( false !== strpos( (string) $item['message'], 'left in place' ), 'backup message says shared files were left in place: ' . $item['message'] );
$restored = Backup::open( $backup->id )->restore();
wpcs_ok( 1 === count( $restored ) && $restored[0]['ok'] && get_post( $drop ) && wpcs_all_exist( $files ), 'restore brings the duplicate row back without touching the shared files: ' . $restored[0]['message'] );

/* ---------------------------------------------------------------------- */
WP_CLI::log( '2. Keeper stores its file as an absolute path' );

$keep_rel = get_post_meta( $keep, '_wp_attached_file', true );
// The spelling an older upload_dir mismatch stored: the platform's own absolute path.
update_post_meta( $keep, '_wp_attached_file', wp_slash( str_replace( '/', DIRECTORY_SEPARATOR, wp_normalize_path( get_attached_file( $keep, true ) ) ) ) );
wpcs_ok( path_is_absolute( get_post_meta( $keep, '_wp_attached_file', true ) ) && is_file( get_attached_file( $keep, true ) ), 'fixture: the keeper resolves through an absolute path' );
$backup    = Backup::start();
$backups[] = $backup->id;
$result    = Media_Remover::remove( $drop, $backup, \WPCleanup\Media_Usage::build() );
wp_cache_flush();
wpcs_ok( 'deleted' === $result['status'] && wpcs_all_exist( $files ), 'an absolute _wp_attached_file still protects the shared files (' . $result['status'] . ': ' . $result['message'] . ')' );
Backup::open( $backup->id )->restore();
update_post_meta( $keep, '_wp_attached_file', $keep_rel );

/* ---------------------------------------------------------------------- */
WP_CLI::log( '3. Merging an identical copy into the keeper' );

$backup    = Backup::start();
$backups[] = $backup->id;
try {
	Media_Merger::merge( $drop, $keep, $backup, true );
	wp_cache_flush();
	wpcs_ok( ! get_post( $drop ) && wpcs_all_exist( $files ), 'merge removes the copy and keeps every shared file' );
} catch ( \Exception $e ) {
	wp_cache_flush();
	wpcs_ok( wpcs_all_exist( $files ), 'refused merge keeps every shared file: ' . $e->getMessage() );
}
foreach ( Backup::open( $backup->id )->restore() as $row ) {
	wpcs_ok( $row['ok'], 'merge restore: ' . $row['label'] . ': ' . $row['message'] );
}

/* ---------------------------------------------------------------------- */
WP_CLI::log( '4. Converting one of two attachments that share a file' );

wpcs_ok( get_post( $drop ) && wpcs_all_exist( $files ), 'fixture: both attachments and all files are back' );
$planned = Media_Converter::convert( $drop, null, true );
wpcs_ok( 'planned' !== $planned['status'], 'dry run does not plan converting a shared file: ' . $planned['message'] );
$backup    = Backup::start();
$backups[] = $backup->id;
try {
	$converted = Media_Converter::convert( $drop, $backup );
	$status    = $converted['status'] . ': ' . $converted['message'];
} catch ( \Exception $e ) {
	$converted = array( 'status' => 'exception' );
	$status    = $e->getMessage();
}
wp_cache_flush();
wpcs_ok( wpcs_all_exist( $files ), 'converting one attachment never moves a file the other still names (' . $status . ')' );
wpcs_ok( 'converted' !== $converted['status'] && 'image/png' === get_post_mime_type( $drop ), 'the shared image is left unconverted' );

/* ---------------------------------------------------------------------- */
WP_CLI::log( '5. Only the shared files stay; the copy\'s own sizes are backed up' );

$own   = wpcs_upload( 'wpcs-partly.png', 900, 600 );
$other = wpcs_twin( $own, false ); // Names the main file only.
wpcs_use( $other );
$own_files = wpcs_files( $own );
$main      = wp_normalize_path( get_attached_file( $other, true ) );
$backup    = Backup::start();
$backups[] = $backup->id;
$result    = Media_Remover::remove( $own, $backup, \WPCleanup\Media_Usage::build() );
wp_cache_flush();
$sizes_gone = array_filter( array_diff( $own_files, array( $main ) ), 'is_file' );
wpcs_ok( 'deleted' === $result['status'] && is_file( $main ) && ! $sizes_gone, 'the shared main file stays and the copy\'s own sizes move to the backup (' . $result['status'] . ': ' . $result['message'] . ')' );
$item = Backup::open( $backup->id )->manifest['items'][0];
wpcs_ok( isset( $item['extra']['kept_shared'], $item['extra']['moved'] ) && 1 === count( $item['extra']['kept_shared'] ) && count( $item['extra']['moved'] ) === count( $own_files ) - 1, 'manifest lists one kept file and the moved sizes' );
$restored = Backup::open( $backup->id )->restore();
wpcs_ok( $restored[0]['ok'] && get_post( $own ) && wpcs_all_exist( $own_files ), 'restore puts the sizes and the row back' );

/* ---------------------------------------------------------------------- */
WP_CLI::log( '6. Restoring only the missing files when a full restore is refused' );

// Replay the 2026-10 incident: a conversion that did not see the twin moved its files away.
$lost      = wpcs_upload( 'wpcs-lost.png', 900, 600 );
$twin      = wpcs_twin( $lost );
$twin_rel  = get_post_meta( $twin, '_wp_attached_file', true );
$twin_file = wpcs_files( $twin );
$page      = wp_insert_post( wp_slash( array( 'post_title' => 'shows the image', 'post_status' => 'publish', 'post_content' => '<img src="' . wp_get_attachment_url( $lost ) . '">' ) ) );
update_post_meta( $page, '_wpcs_test', 1 );
update_post_meta( $twin, '_wp_attached_file', 'elsewhere/hidden.png' );
$backup    = Backup::start();
$backups[] = $backup->id;
$converted = Media_Converter::convert( $lost, $backup );
update_post_meta( $twin, '_wp_attached_file', $twin_rel );
wp_update_post( array( 'ID' => $page, 'post_content' => get_post_field( 'post_content', $page, 'raw' ) . ' edited later' ) );
wp_cache_flush();
wpcs_ok( 'converted' === $converted['status'] && ! array_filter( $twin_file, 'is_file' ), 'fixture: the twin lost every file to the conversion' );
$refused = Backup::open( $backup->id )->restore();
wpcs_ok( ! $refused[0]['ok'] && false !== strpos( $refused[0]['message'], 'edited' ), 'a full restore is refused after the later edit: ' . $refused[0]['message'] );
$mime_before = get_post_mime_type( $lost );
$copied      = Backup::open( $backup->id )->restore_missing_files( 0 );
wp_cache_flush();
wpcs_ok( count( $copied ) === count( $twin_file ) && wpcs_all_exist( $twin_file ), 'restore missing files only brings back every file the twin names (' . count( $copied ) . ')' );
wpcs_ok( $mime_before === get_post_mime_type( $lost ) && 'image/avif' === $mime_before && false !== strpos( get_post_field( 'post_content', $page, 'raw' ), 'edited later' ), 'no database row changed' );
$item = Backup::open( $backup->id )->manifest['items'][0];
wpcs_ok( isset( $item['extra']['files_restored'] ) && count( $item['extra']['files_restored'] ) === count( $copied ) && empty( $item['restored'] ), 'manifest records the copied files and the item stays restorable' );
wpcs_ok( array() === Backup::open( $backup->id )->restore_missing_files( 0 ), 'a second run finds nothing missing' );
$orig_left = array_filter( $copied, static function ( $rel ) use ( $backup ) { return is_file( $backup->dir . '/files/0/orig/' . $rel ); } );
wpcs_ok( count( $orig_left ) === count( $copied ), 'the backup keeps its own copy of every file' );

$reset();
if ( $GLOBALS['wpcs_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpcs_f'], $GLOBALS['wpcs_p'] ) );
}
WP_CLI::success( sprintf( 'All %d shared-file assertions passed.', $GLOBALS['wpcs_p'] ) );
