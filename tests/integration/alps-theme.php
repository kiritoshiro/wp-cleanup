<?php
/**
 * Compatibility with images the ALPS theme converted on upload (3.29+): they are
 * recorded in the same output meta, keep their original for seven days and carry
 * the theme's marker. DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/alps-theme.php
 *
 * Builds the theme's result directly, so the ALPS theme does not need to be active.
 *
 * @package WPCleanup
 */

use WPCleanup\Media_Integrity;
use WPCleanup\Media_Inventory;
use WPCleanup\Media_Policy;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( '1' !== getenv( 'WPCU_TESTS' ) || ! preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', (string) $host ) ) {
	WP_CLI::error( 'Refusing to run: set WPCU_TESTS=1 and use a local site.' );
}
if ( ! function_exists( 'imageavif' ) ) {
	WP_CLI::error( 'GD AVIF support is needed to build the fixture.' );
}

$GLOBALS['wpca_f'] = 0;
$GLOBALS['wpca_p'] = 0;
function wpca_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpca_p' : 'wpca_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

$up  = wp_upload_dir();
$dir = wp_normalize_path( $up['path'] );
$sub = ltrim( $up['subdir'], '/' );

$reset = static function () use ( $dir ) {
	foreach ( get_posts( array( 'post_type' => 'attachment', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpca_test', 'fields' => 'ids' ) ) as $old ) {
		wp_delete_attachment( $old, true );
	}
	foreach ( glob( $dir . '/wpca-*' ) as $f ) {
		unlink( $f );
	}
	delete_option( Media_Policy::OPTION );
	wp_cache_flush();
};
$reset();

/** What the ALPS theme leaves after a 2400×1600 JPEG upload. */
$theme_upload = static function ( $name ) use ( $dir, $sub ) {
	$make = static function ( $w, $h ) {
		$im = imagecreatetruecolor( $w, $h );
		imagefill( $im, 0, 0, imagecolorallocate( $im, 40, 90, 200 ) );
		return $im;
	};
	$im = $make( 2400, 1600 );
	imagejpeg( $im, "$dir/$name.jpg", 90 );
	$display = $make( 1920, 1280 );
	imageavif( $display, "$dir/$name-display.avif", 60 );
	imagejpeg( $display, "$dir/$name-fallback.jpg", 82 );
	$small = $make( 768, 512 );
	imageavif( $small, "$dir/$name-display-768x512.avif", 60 );
	$id = wp_insert_attachment( array( 'post_mime_type' => 'image/avif', 'post_title' => $name, 'post_status' => 'inherit' ), "$dir/$name-display.avif" );
	update_post_meta( $id, '_wpca_test', 1 );
	wp_update_attachment_metadata(
		$id,
		array(
			'file'           => "$sub/$name-display.avif",
			'width'          => 1920,
			'height'         => 1280,
			'filesize'       => filesize( "$dir/$name-display.avif" ),
			'original_image' => "$name.jpg",
			'sizes'          => array(
				'alps-small' => array( 'file' => "$name-display-768x512.avif", 'width' => 768, 'height' => 512, 'mime-type' => 'image/avif', 'filesize' => filesize( "$dir/$name-display-768x512.avif" ) ),
			),
			'image_meta'     => array(),
		)
	);
	update_post_meta( $id, Media_Policy::ALPS_FLAG, 1 );
	update_post_meta( $id, '_alps_original_delete_after', time() + WEEK_IN_SECONDS );
	update_post_meta( $id, '_alps_retained_original', "$sub/$name.jpg" );
	update_post_meta(
		$id,
		Media_Policy::OUTPUT_META,
		array(
			'jpeg'              => "$sub/$name-fallback.jpg",
			'jpeg_width'        => 1920,
			'jpeg_height'       => 1280,
			'avif_full'         => "$sub/$name-display.avif",
			'avif_full_width'   => 1920,
			'avif_full_height'  => 1280,
			'avif_small'        => "$sub/$name-display-768x512.avif",
			'avif_small_width'  => 768,
			'avif_small_height' => 512,
			'avif_error'        => '',
			'policy'            => Media_Policy::settings(),
			'by'                => 'alps-theme',
		)
	);
	Media_Inventory::flush();
	return (int) $id;
};

WP_CLI::log( 'New upload converted by the theme' );
$id  = $theme_upload( 'wpca-photo' );
$inv = Media_Inventory::attachment( $id );
wpca_ok( $inv && $inv['compliant'], 'counts as already converted while the theme keeps the original for a week' );
wpca_ok( isset( $inv['files']['wpca-photo.jpg'] ) && 'original' === $inv['files']['wpca-photo.jpg']['role'], 'the retained original is listed as the original' );
wpca_ok( ! Media_Integrity::check( $id )['issues'], 'the data check finds no problem' );

delete_post_meta( $id, '_alps_original_delete_after' );
Media_Inventory::flush();
wpca_ok( ! Media_Inventory::attachment( $id )['compliant'], 'without the theme\'s expiry queue, a kept original still needs converting' );
update_post_meta( $id, '_alps_original_delete_after', time() + WEEK_IN_SECONDS );

WP_CLI::log( 'Marker' );
Media_Policy::save( array_merge( Media_Policy::settings(), array( 'set_flag' => false ) ) );
$codes = wp_list_pluck( Media_Integrity::check( $id )['issues'], 'code' );
wpca_ok( ! in_array( 'flag', $codes, true ), 'marking switched off here: the theme\'s own marker is not reported for removal' );
$outputs = get_post_meta( $id, Media_Policy::OUTPUT_META, true );
unset( $outputs['by'] );
update_post_meta( $id, Media_Policy::OUTPUT_META, $outputs );
$codes = wp_list_pluck( Media_Integrity::check( $id )['issues'], 'code' );
wpca_ok( in_array( 'flag', $codes, true ), 'images WP Cleanup converted itself still get their marker removed' );
delete_option( Media_Policy::OPTION );

WP_CLI::log( 'Deletion' );
$id2  = $theme_upload( 'wpca-gone' );
$jpeg = "$dir/wpca-gone-fallback.jpg";
wpca_ok( is_file( $jpeg ), 'fixture has its JPEG fallback' );
wp_delete_attachment( $id2, true );
wpca_ok( ! is_file( $jpeg ), 'deleting the attachment deletes its JPEG fallback' );

$id3   = $theme_upload( 'wpca-shared' );
$other = wp_insert_attachment( array( 'post_mime_type' => 'image/jpeg', 'post_title' => 'shared', 'post_status' => 'inherit' ), "$dir/wpca-shared-fallback.jpg" );
update_post_meta( $other, '_wpca_test', 1 );
wp_delete_attachment( $id3, true );
wpca_ok( is_file( "$dir/wpca-shared-fallback.jpg" ), 'a fallback file another attachment uses is kept' );

$reset();
WP_CLI::log( '' );
if ( $GLOBALS['wpca_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpca_f'], $GLOBALS['wpca_p'] ) );
}
WP_CLI::success( sprintf( 'All %d ALPS theme compatibility assertions passed.', $GLOBALS['wpca_p'] ) );
