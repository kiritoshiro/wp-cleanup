<?php
/**
 * Image cleanup integration tests. DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/images.php
 *
 * Needs an AVIF-capable GD or Imagick and WordPress 6.5+.
 *
 * @package WPCleanup
 */

use WPCleanup\Backup;
use WPCleanup\Media_Converter;
use WPCleanup\Media_Inventory;
use WPCleanup\Media_Files;
use WPCleanup\Media_Orphan_Files;
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
	WP_CLI::error( 'This server cannot write AVIF; the image tests need it.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';

// Reproduce the web-server path spelling on Windows ("C:\site/wp-content/uploads"), which WP-CLI
// hides by using forward slashes; a mismatch once stored absolute paths as _wp_attached_file.
if ( '\\' === DIRECTORY_SEPARATOR ) {
	add_filter(
		'upload_dir',
		static function ( $u ) {
			$abs = untrailingslashit( wp_normalize_path( ABSPATH ) );
			foreach ( array( 'basedir', 'path' ) as $k ) {
				$v = wp_normalize_path( $u[ $k ] );
				if ( 0 === strpos( $v, $abs ) ) {
					$u[ $k ] = str_replace( '/', '\\', $abs ) . substr( $v, strlen( $abs ) );
				}
			}
			return $u;
		}
	);
}

global $wpdb;
$GLOBALS['wpcu_f'] = 0;
$GLOBALS['wpcu_p'] = 0;
function wpcu_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpcu_p' : 'wpcu_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

$up      = wp_upload_dir();
$dir     = wp_normalize_path( $up['path'] );
$subdir  = ltrim( $up['subdir'], '/' );
$baseurl = $up['url']; // Includes the year/month folder.

/* ---------------------------------------------------------------------- */
/* Reset                                                                  */
/* ---------------------------------------------------------------------- */

foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpcu_test', 'fields' => 'ids' ) ) as $old ) {
	if ( 'attachment' === get_post_type( $old ) ) {
		wp_delete_attachment( $old, true );
	} else {
		wp_delete_post( $old, true );
	}
}
foreach ( glob( $dir . '/wpcut-*' ) as $f ) {
	unlink( $f );
}
foreach ( Backup::all() as $b ) {
	Backup::open( $b['id'] )->delete();
}
delete_option( 'wpcut_json' );
delete_option( 'wpcut_object' );
delete_option( Media_Policy::OPTION );
wp_cache_flush();

/* ---------------------------------------------------------------------- */
/* Fixtures                                                               */
/* ---------------------------------------------------------------------- */

function wpcu_make_image( $path, $w, $h, $type, $alpha = false ) {
	$im = imagecreatetruecolor( $w, $h );
	if ( $alpha ) {
		imagealphablending( $im, false );
		imagesavealpha( $im, true );
		imagefill( $im, 0, 0, imagecolorallocatealpha( $im, 0, 0, 0, 127 ) );
		imagefilledrectangle( $im, (int) ( $w / 4 ), (int) ( $h / 4 ), (int) ( $w * 3 / 4 ), (int) ( $h * 3 / 4 ), imagecolorallocatealpha( $im, 200, 40, 40, 0 ) );
	} else {
		for ( $x = 0; $x < $w; $x += 8 ) {
			imagefilledrectangle( $im, $x, 0, $x + 7, $h, imagecolorallocate( $im, intdiv( $x * 255, $w ), 120, 255 - intdiv( $x * 255, $w ) ) );
		}
	}
	switch ( $type ) {
		case 'jpg':
			imagejpeg( $im, $path, 90 );
			break;
		case 'png':
			imagepng( $im, $path );
			break;
		case 'avif':
			imageavif( $im, $path, 65 );
			break;
		case 'gif':
			imagegif( $im, $path );
			break;
	}
	imagedestroy( $im );
}

function wpcu_attach( $name, $w, $h, $type, $alpha = false ) {
	$path = wp_upload_dir()['path'] . '/' . $name;
	wpcu_make_image( $path, $w, $h, $type, $alpha );
	$mime = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'avif' => 'image/avif' )[ $type ];
	$id   = wp_insert_attachment( array( 'post_mime_type' => $mime, 'post_title' => $name, 'post_status' => 'inherit' ), $path );
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $path ) );
	update_post_meta( $id, '_wpcu_test', 1 );
	return $id;
}

WP_CLI::log( 'Creating fixtures (encoding may take a moment)…' );
$big    = wpcu_attach( 'wpcut-big.jpg', 3000, 2000, 'jpg' );          // -scaled + all core sizes.
$png    = wpcu_attach( 'wpcut-alpha.png', 1200, 800, 'png', true );   // Transparency.
$small  = wpcu_attach( 'wpcut-small.jpg', 500, 300, 'jpg' );          // Below the small cap.
$gif    = wpcu_attach( 'wpcut-anim.gif', 800, 600, 'gif' );           // Must be skipped.
$twin_j = wpcu_attach( 'wpcut-twin.jpg', 1600, 1000, 'jpg' );         // Same base name as the next one.
$twin_p = wpcu_attach( 'wpcut-twin.png', 1000, 1600, 'png' );
$obj    = wpcu_attach( 'wpcut-object.jpg', 1000, 700, 'jpg' );        // Referenced inside a PHP object.
$fail   = wpcu_attach( 'wpcut-fail.jpg', 1400, 900, 'jpg' );          // Move failure is injected.
$avif_fail = wpcu_attach( 'wpcut-avif-fail.png', 900, 600, 'png' );
$avif_input = wpcu_attach( 'wpcut-input.avif', 1000, 700, 'avif' );
$server_avif = wpcu_attach( 'wpcut-server-1.avif', 900, 600, 'avif' );
wpcu_make_image( $dir . '/wpcut-server.jpg', 900, 600, 'jpg' ); // Server-only JPEG beside an AVIF attachment. // AVIF source needs a JPEG fallback. // AVIF decode failure is injected.

// Strays next to the big image: an old theme size and an optimizer sidecar.
copy( $dir . '/wpcut-big-300x200.jpg', $dir . '/wpcut-big-999x666.jpg' );
copy( $dir . '/wpcut-big-scaled.jpg', $dir . '/wpcut-big.jpg.webp' );

$u = static function ( $file ) use ( $baseurl ) {
	return $baseurl . '/' . $file;
};
$escaped   = static function ( $url ) {
	return str_replace( '/', '\\/', $url );
};
$content   = '<!-- wp:image {"id":' . $big . ',"sizeSlug":"large","url":"' . $escaped( $u( 'wpcut-big-1024x683.jpg' ) ) . '"} -->'
	. '<figure><img src="' . $u( 'wpcut-big-1024x683.jpg' ) . '" srcset="' . $u( 'wpcut-big-300x200.jpg' ) . ' 300w, ' . $u( 'wpcut-big-768x512.jpg' ) . ' 768w" class="wp-image-' . $big . '"></figure><!-- /wp:image -->'
	. '<p><a href="' . $u( 'wpcut-big.jpg' ) . '">original</a> <img src="' . wp_make_link_relative( $u( 'wpcut-alpha-150x150.png' ) ) . '"> <img src="' . $u( 'wpcut-big-999x666.jpg' ) . '"></p>'
	. '<p>Look-alike that must stay: wpcut-big-300x200.jpg.bak and not-wpcut-big.jpg</p>';
$post      = wp_insert_post( wp_slash( array( 'post_title' => 'Image host', 'post_content' => $content, 'post_status' => 'publish' ) ) ); // wp_slash keeps the JSON \/ escapes.
update_post_meta( $post, '_wpcu_test', 1 );
update_post_meta( $post, 'wpcut_gallery', array( 'items' => array( array( 'thumb' => $u( 'wpcut-twin-150x150.png' ) ) ), 'nested' => serialize( array( 'u' => $u( 'wpcut-big-150x150.jpg' ) ) ) ) );
update_option( 'wpcut_json', wp_json_encode( array( 'hero' => $u( 'wpcut-big-scaled.jpg' ) ) ) );
$o      = new stdClass();
$o->url = $u( 'wpcut-object-300x210.jpg' );
update_option( 'wpcut_object', $o );
wp_cache_flush();

/* ---------------------------------------------------------------------- */
/* Snapshot helpers                                                       */
/* ---------------------------------------------------------------------- */

$files_snapshot = static function () use ( $dir ) {
	$out = array();
	foreach ( glob( $dir . '/wpcut-*' ) as $f ) {
		$out[ basename( $f ) ] = md5_file( $f );
	}
	ksort( $out );
	return $out;
};
$db_snapshot    = static function () use ( $wpdb, $post ) {
	return array(
		'post'  => $wpdb->get_var( $wpdb->prepare( "SELECT post_content FROM {$wpdb->posts} WHERE ID = %d", $post ) ),
		'meta'  => $wpdb->get_results( "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpcu_test') ORDER BY post_id, meta_key, meta_id", ARRAY_A ),
		'mimes' => $wpdb->get_results( "SELECT ID, post_mime_type FROM {$wpdb->posts} WHERE ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wpcu_test') ORDER BY ID", ARRAY_A ),
		'opts'  => $wpdb->get_results( "SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE 'wpcut\\_%' ORDER BY option_name", ARRAY_A ),
	);
};

/* ---------------------------------------------------------------------- */
/* 1. Inventory and report                                                */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n1. Inventory" );
$inv = Media_Inventory::attachment( $big );
wpcu_ok( isset( $inv['files']['wpcut-big-scaled.jpg'], $inv['files']['wpcut-big.jpg'], $inv['files']['wpcut-big-1024x683.jpg'] ), 'big: attached -scaled, original and sizes found' );
wpcu_ok( isset( $inv['files']['wpcut-big-999x666.jpg'] ) && 'stray' === $inv['files']['wpcut-big-999x666.jpg']['role'], 'big: stray theme size found' );
wpcu_ok( isset( $inv['files']['wpcut-big.jpg.webp'] ) && 'stray' === $inv['files']['wpcut-big.jpg.webp']['role'], 'big: optimizer sidecar found' );
$twin = Media_Inventory::attachment( $twin_j );
$leak = array_filter(
	array_keys( $twin['files'] ),
	static function ( $n ) {
		return (bool) preg_match( '/\.png$/', $n );
	}
);
wpcu_ok( ! $leak, 'twin.jpg does not claim twin.png files as strays: ' . implode( ',', $leak ) );
$report = Media_Report::build();
$ids    = wp_list_pluck( $report['items'], 'id' );
wpcu_ok( in_array( $big, $ids, true ) && in_array( $png, $ids, true ) && ! in_array( $gif, $ids, true ), 'report lists convertible images, not the GIF' );
$catalog = $report['file_catalog'];
$unregistered_paths = wp_list_pluck( $catalog['unregistered'], 'path' );
wpcu_ok( in_array( $subdir . '/wpcut-server.jpg', $unregistered_paths, true ), 'server-only JPEG beside an AVIF attachment appears in unregistered files' );
$catalog_big = array_values( array_filter( $catalog['library'], static function ( $item ) use ( $big ) { return $big === $item['id']; } ) );
wpcu_ok( 1 === count( $catalog_big ) && count( $catalog_big[0]['files'] ) === count( $inv['files'] ), 'each attachment appears once with its original and sizes grouped beneath it' );
$catalog_view = new ReflectionMethod( WPCleanup\Admin::class, 'render_file_catalog' );
$catalog_view->setAccessible( true );
ob_start();
$catalog_view->invoke( new WPCleanup\Admin(), $report );
$catalog_html = ob_get_clean();
wpcu_ok( false !== strpos( $catalog_html, 'Current image files on the server' ) && false !== strpos( $catalog_html, 'wpcut-server.jpg' ) && false !== strpos( $catalog_html, 'Original source' ) && false !== strpos( $catalog_html, 'name="paths[]"' ) && false !== strpos( $catalog_html, 'Move selected server files to backup' ), 'Images tab shows expandable attachment variants and unregistered server files' );

/* ---------------------------------------------------------------------- */
/* 2. Convert                                                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n2. Convert" );
$before_files = $files_snapshot();
$before_db    = $db_snapshot();
$backup       = Backup::start();
$results      = array();
foreach ( array( $big, $png, $small, $gif, $twin_j, $twin_p, $avif_input ) as $id ) {
	$results[ $id ] = Media_Converter::convert( $id, $backup );
}
wp_cache_flush();
foreach ( array( $big, $png, $small, $twin_j, $twin_p, $avif_input ) as $id ) {
	wpcu_ok( 'converted' === $results[ $id ]['status'], "#$id converted: " . $results[ $id ]['message'] );
}
wpcu_ok( 'skipped' === $results[ $gif ]['status'], 'GIF skipped: ' . $results[ $gif ]['message'] );
wpcu_ok( 'image/jpeg' === get_post_mime_type( $avif_input ) && in_array( $subdir . '/wpcut-input.avif', $results[ $avif_input ]['backed_up'], true ), 'AVIF-only source gets one JPEG fallback and its old AVIF is backed up' );

$m = wp_get_attachment_metadata( $big );
$outputs = get_post_meta( $big, Media_Policy::OUTPUT_META, true );
wpcu_ok( 'image/jpeg' === get_post_mime_type( $big ) && '.jpg' === substr( get_attached_file( $big ), -4 ), 'big has one JPEG fallback attachment' );
$stored = get_post_meta( $big, '_wp_attached_file', true );
wpcu_ok( $subdir . '/wpcut-big-fallback.jpg' === $stored && is_file( get_attached_file( $big ) ), "stored path is uploads-relative and resolves (got $stored)" );
wpcu_ok( $stored === $m['file'] && $stored === $outputs['jpeg'], 'metadata and output policy name the one JPEG fallback' );
wpcu_ok( wp_get_attachment_url( $big ) === $u( 'wpcut-big-fallback.jpg' ), 'direct attachment URL uses compatible JPEG' );
wpcu_ok( 1920 === $m['width'] && 1280 === $m['height'], 'JPEG capped at 1920 (got ' . $m['width'] . 'x' . $m['height'] . ')' );
wpcu_ok( array() === $m['sizes'], 'no JPEG sub-sizes created' );
wpcu_ok( ! isset( $m['original_image'] ) && '1' === (string) get_post_meta( $big, Media_Policy::ALPS_FLAG, true ), 'original_image dropped, ALPS flag set' );
$left = array_values( array_filter( glob( $dir . '/wpcut-big*' ), 'is_file' ) );
wpcu_ok( 3 === count( $left ) && 1 === count( preg_grep( '/\.jpe?g$/', $left ) ), 'exactly one JPEG plus two AVIF files for big: ' . implode( ', ', array_map( 'basename', $left ) ) );
wpcu_ok( $subdir . '/wpcut-big.avif' === $outputs['avif_full'] && $subdir . '/wpcut-big-768x512.avif' === $outputs['avif_small'], 'full and small AVIF alternatives are recorded' );
wpcu_ok( in_array( $subdir . '/wpcut-big.jpg', $results[ $big ]['backed_up'], true ) && in_array( $subdir . '/wpcut-big-scaled.jpg', $results[ $big ]['backed_up'], true ), 'old original and scaled image were moved into the backup set' );
$reference_details = $results[ $big ]['reference_changes'];
wpcu_ok( $reference_details && count( array_filter( $reference_details, static function ( $change ) { return false !== strpos( $change['where'], 'post_content' ) && false !== strpos( $change['from'], 'wpcut-big-1024x683.jpg' ) && false !== strpos( $change['to'], '-fallback.jpg' ); } ) ) > 0, 'reference details show location, old path and JPEG path' );
$stored_backup = Backup::open( $backup->id );
wpcu_ok( ! empty( $stored_backup->manifest['items'][0]['extra']['reference_changes'] ) && ! empty( $stored_backup->manifest['items'][0]['extra']['moved'] ), 'reference changes and original file paths remain available in the backup manifest' );
$with_backups = Media_Files::catalog( Media_Files::all_ids() );
wpcu_ok( ! array_filter( wp_list_pluck( $with_backups['unregistered'], 'path' ), static function ( $path ) { return false !== strpos( $path, 'wp-cleanup-' ); } ), 'private backup images never appear as unregistered uploads files' );
$backup_view = new ReflectionMethod( WPCleanup\Admin::class, 'render_backups' );
$backup_view->setAccessible( true );
ob_start();
$backup_view->invoke( new WPCleanup\Admin() );
$backup_html = ob_get_clean();
wpcu_ok( false !== strpos( $backup_html, 'reference path changes' ) && false !== strpos( $backup_html, 'wpcut-big-1024x683.jpg' ) && false !== strpos( $backup_html, 'wpcut-big-fallback.jpg' ), 'Backups tab exposes expandable three-line reference details' );

$ms = wp_get_attachment_metadata( $small );
wpcu_ok( array() === $ms['sizes'] && 500 === $ms['width'], 'small image: one JPEG, no extra JPEG size' );
$small_outputs = get_post_meta( $small, Media_Policy::OUTPUT_META, true );
wpcu_ok( ! empty( $small_outputs['avif_full'] ) && empty( $small_outputs['avif_small'] ), 'small image has one AVIF alternative' );
$src = wp_get_attachment_image_src( $big, 'alps-small' );
wpcu_ok( $src && false !== strpos( $src[0], '-fallback.jpg' ), 'image URL remains JPEG for older devices' );
$picture = wp_get_attachment_image( $big, 'full' );
wpcu_ok( false !== strpos( $picture, '<picture><source type="image/avif"' ) && false !== strpos( $picture, 'wpcut-big.avif' ) && false !== strpos( $picture, 'wpcut-big-fallback.jpg' ), 'WordPress image markup offers AVIF and one JPEG fallback' );
$small_picture = wp_get_attachment_image( $big, 'alps-small' );
wpcu_ok( false !== strpos( $small_picture, 'sizes="(max-width: 768px) 100vw, 768px"' ), 'small image request selects the small AVIF width' );
wpcu_ok( is_file( $dir . '/wpcut-anim.gif' ) && 'image/gif' === get_post_mime_type( $gif ), 'GIF file untouched' );

// Transparency survived.
$pm  = wp_get_attachment_metadata( $png );
$png_outputs = get_post_meta( $png, Media_Policy::OUTPUT_META, true );
$img = imagecreatefromavif( $up['basedir'] . '/' . $png_outputs['avif_full'] );
$a   = ( imagecolorat( $img, 5, 5 ) >> 24 ) & 0x7F;
$c   = ( imagecolorat( $img, (int) ( $pm['width'] / 2 ), (int) ( $pm['height'] / 2 ) ) >> 24 ) & 0x7F;
wpcu_ok( $a > 100 && $c < 20, "PNG alpha kept (corner alpha $a, centre alpha $c)" );
$fallback_img = imagecreatefromjpeg( get_attached_file( $png ) );
$corner = imagecolorsforindex( $fallback_img, imagecolorat( $fallback_img, 5, 5 ) );
wpcu_ok( $corner['red'] > 230 && $corner['green'] > 230 && $corner['blue'] > 230, 'transparent PNG is flattened onto white in its JPEG fallback' );
imagedestroy( $fallback_img );

// References.
$html = get_post_field( 'post_content', $post, 'raw' );
$full = wp_basename( get_attached_file( $big ) );
$sm   = $full;
wpcu_ok( false === strpos( $html, 'wpcut-big-1024x683.jpg' ) && false !== strpos( $html, $escaped( $u( $full ) ) ) && false !== strpos( $html, '<img src="' . $u( $full ) . '"' ), 'large size (plain and JSON-escaped) → JPEG fallback' );
wpcu_ok( false !== strpos( $html, 'srcset="' . $u( $sm ) . ' 1920w"' ), 'srcset 300w/768w → one JPEG entry at its real width' );
wpcu_ok( false !== strpos( $html, 'href="' . $u( $full ) . '"' ), 'link to the original → JPEG fallback' );
wpcu_ok( false !== strpos( $html, '<img src="' . $u( $full ) . '"></p>' ) && false === strpos( $html, '999x666' ), 'stray 999x666 → JPEG fallback' );
wpcu_ok( false !== strpos( $html, wp_make_link_relative( $u( wp_basename( get_attached_file( $png ) ) ) ) ), 'relative URL rewritten' );
wpcu_ok( false !== strpos( $html, 'wpcut-big-300x200.jpg.bak and not-wpcut-big.jpg' ), 'look-alike text untouched' );
$g = get_post_meta( $post, 'wpcut_gallery', true );
wpcu_ok( is_array( $g ) && false === strpos( $g['items'][0]['thumb'], 'wpcut-twin-150x150.png' ) && false !== strpos( $g['items'][0]['thumb'], '.jpg' ) && false !== strpos( unserialize( $g['nested'] )['u'], '-fallback.jpg' ), 'serialized and nested-serialized meta rewritten and still valid' );
wpcu_ok( false !== strpos( get_option( 'wpcut_json' ), $escaped( $u( $full ) ) ), 'JSON option rewritten' );
$dead = array();
foreach ( array_keys( $before_files ) as $name ) {
	if ( ! is_file( $dir . '/' . $name ) && preg_match( '/\.(jpe?g|png|webp)$/', $name ) && false === strpos( $name, 'object' ) && false === strpos( $name, 'fail' ) ) {
		$hits = (int) $wpdb->get_var( $wpdb->prepare( "SELECT (SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_content LIKE %s) + (SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_value LIKE %s AND meta_key NOT IN ('_wp_attachment_metadata','_wp_attached_file')) + (SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s)", '%/' . $name . '"%', '%/' . $name . '"%', '%' . $name . '"%' ) );
		if ( $hits ) {
			$dead[] = $name;
		}
	}
}
wpcu_ok( ! $dead, 'no reference to a moved file remains: ' . implode( ', ', $dead ) );

/* AVIF decoding failure keeps a single usable JPEG and backs up the source. */
$reject_avif = static function ( $verified, $file, $mime ) {
	return 'image/avif' === $mime ? false : $verified;
};
add_filter( 'wp_cleanup_media_verify', $reject_avif, 10, 3 );
$avif_result = Media_Converter::convert( $avif_fail, $backup );
remove_filter( 'wp_cleanup_media_verify', $reject_avif, 10 );
$failed_outputs = get_post_meta( $avif_fail, Media_Policy::OUTPUT_META, true );
wpcu_ok( 'converted' === $avif_result['status'] && 'image/jpeg' === get_post_mime_type( $avif_fail ), 'AVIF decode failure still converts to JPEG: ' . $avif_result['message'] );
wpcu_ok( empty( $failed_outputs['avif_full'] ) && ! empty( $failed_outputs['avif_error'] ) && 1 === count( preg_grep( '/\.jpe?g$/', glob( $dir . '/wpcut-avif-fail*' ) ) ), 'AVIF failure leaves exactly one JPEG, without partial AVIF files' );
wpcu_ok( in_array( $subdir . '/wpcut-avif-fail.png', $avif_result['backed_up'], true ) && Media_Inventory::attachment( $avif_fail )['compliant'], 'old PNG moved to backup and JPEG-only output follows policy' );
wpcu_ok( false === strpos( wp_get_attachment_image( $avif_fail, 'full' ), '<picture>' ), 'JPEG-only output renders without an AVIF source' );

/* ---------------------------------------------------------------------- */
/* 3. Refusal and rollback                                                */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n3. Refusal and rollback" );
$snap_f = $files_snapshot();
$snap_d = $db_snapshot();
$r      = Media_Converter::convert( $obj, $backup );
wpcu_ok( 'failed' === $r['status'] && false !== strpos( $r['message'], 'PHP object' ), 'URL inside a PHP object → refused: ' . $r['message'] );
wp_cache_flush();
wpcu_ok( $snap_f === $files_snapshot() && $snap_d == $db_snapshot(), 'refused image: files and database unchanged' ); // phpcs:ignore

$fail_on = static function ( $ok, $rel ) {
	return false !== strpos( $rel, 'wpcut-fail-300x' ) ? false : $ok;
};
add_filter( 'wp_cleanup_media_move_file', $fail_on, 10, 2 );
$r = Media_Converter::convert( $fail, $backup );
remove_filter( 'wp_cleanup_media_move_file', $fail_on, 10 );
wp_cache_flush();
wpcu_ok( 'failed' === $r['status'], 'injected move failure → failed: ' . $r['message'] );
wpcu_ok( $snap_f === $files_snapshot(), 'rollback: every file back, new JPEG and AVIF files removed' );
wpcu_ok( $snap_d == $db_snapshot(), 'rollback: database identical' ); // phpcs:ignore

/* ---------------------------------------------------------------------- */
/* 4. Restore                                                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n4. Restore" );
$res = Backup::open( $backup->id )->restore();
$bad = array_filter(
	$res,
	static function ( $x ) {
		return ! $x['ok'];
	}
);
wpcu_ok( 7 === count( $res ) && ! $bad, 'restore of 7 converted images reported ok: ' . wp_json_encode( array_values( $bad ) ) );
wp_cache_flush();
wpcu_ok( $before_files === $files_snapshot(), 'restore: every original file back byte-identical, new files gone from uploads' );
wpcu_ok( $before_db == $db_snapshot(), 'restore: attachments, content, meta and options identical' ); // phpcs:ignore
wpcu_ok( (bool) glob( $backup->dir . '/files/*/created/' . $up['subdir'] . '/wpcut-big*.avif' ), 'unused AVIFs parked in the backup set, not deleted' );

/* Optional JPEG fallback: AVIF-only mode keeps no JPEG in uploads. */
$no_fallback = wpcu_attach( 'wpcut-avif-only.jpg', 1200, 800, 'jpg' );
$optional_files = $files_snapshot();
$optional_db = $db_snapshot();
Media_Policy::save( array( 'jpeg_fallback' => false ) );
$b_optional = Backup::start();
$only = Media_Converter::convert( $no_fallback, $b_optional );
$only_outputs = get_post_meta( $no_fallback, Media_Policy::OUTPUT_META, true );
wpcu_ok( 'converted' === $only['status'] && 'image/avif' === get_post_mime_type( $no_fallback ) && empty( $only_outputs['jpeg'] ), 'fallback-off policy produces an AVIF attachment with no JPEG' );
wpcu_ok( ! (bool) glob( $dir . '/wpcut-avif-only*.jpg' ) && Media_Inventory::attachment( $no_fallback )['compliant'], 'original JPEG is moved to backup and AVIF-only output is compliant' );
$only_restored = Backup::open( $b_optional->id )->restore();
wpcu_ok( 1 === count( $only_restored ) && $only_restored[0]['ok'] && $optional_files === $files_snapshot() && $optional_db == $db_snapshot(), 'AVIF-only conversion restores original file and database' ); // phpcs:ignore
$reject_avif = static function ( $verified, $file, $mime ) { return 'image/avif' === $mime ? false : $verified; };
add_filter( 'wp_cleanup_media_verify', $reject_avif, 10, 3 );
$failure_files = $files_snapshot();
$failure_db = $db_snapshot();
$only_failed = Media_Converter::convert( $avif_fail, Backup::start() );
remove_filter( 'wp_cleanup_media_verify', $reject_avif, 10 );
wpcu_ok( 'failed' === $only_failed['status'] && $failure_files === $files_snapshot() && $failure_db == $db_snapshot(), 'AVIF failure without fallback leaves original and database unchanged' ); // phpcs:ignore
delete_option( Media_Policy::OPTION );

/* A server-only JPEG can be backed up and restored independently. */
$file_path = $subdir . '/wpcut-server.jpg';
$file_hash = md5_file( $dir . '/wpcut-server.jpg' );
$file_backup = Backup::start();
$file_result = Media_Orphan_Files::remove( $file_path, $file_backup, Media_Files::catalog( Media_Files::all_ids() ) );
wpcu_ok( 'deleted' === $file_result['status'] && ! is_file( $dir . '/wpcut-server.jpg' ), 'unregistered JPEG moved into a file-only backup' );
$file_restored = Backup::open( $file_backup->id )->restore();
wpcu_ok( 1 === count( $file_restored ) && $file_restored[0]['ok'] && $file_hash === md5_file( $dir . '/wpcut-server.jpg' ), 'server-only JPEG restores byte-identically' );
update_option( 'wpcut_server_ref', $u( 'wpcut-server.jpg' ) );
$file_refused = Media_Orphan_Files::remove( $file_path, Backup::start(), Media_Files::catalog( Media_Files::all_ids() ) );
wpcu_ok( 'refused' === $file_refused['status'] && is_file( $dir . '/wpcut-server.jpg' ), 'server-only file mentioned in WordPress data is refused' );
delete_option( 'wpcut_server_ref' );

/* ---------------------------------------------------------------------- */
/* 5. Restore refuses to clobber later edits                              */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n5. Restore conflicts" );
$b2 = Backup::start();
$r  = Media_Converter::convert( $big, $b2 );
wpcu_ok( 'converted' === $r['status'], 'converted again' );
wp_update_post( wp_slash( array( 'ID' => $post, 'post_content' => get_post_field( 'post_content', $post, 'raw' ) . '<p>edited later</p>' ) ) );
$res = Backup::open( $b2->id )->restore();
wpcu_ok( 1 === count( $res ) && ! $res[0]['ok'] && false !== strpos( $res[0]['message'], 'edited' ), 'restore refused after a later edit: ' . $res[0]['message'] );
wpcu_ok( 'image/jpeg' === get_post_mime_type( $big ) && false !== strpos( get_post_field( 'post_content', $post, 'raw' ), 'edited later' ), 'nothing changed by the refused restore' );

/* ---------------------------------------------------------------------- */
/* 6. Idempotence                                                         */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n6. Idempotence" );
$r = Media_Converter::convert( $big, $b2 );
wpcu_ok( 'compliant' === $r['status'], 'already converted image reports compliant: ' . $r['message'] );

/* ---------------------------------------------------------------------- */
/* Teardown: leave the site as we found it for other suites               */
/* ---------------------------------------------------------------------- */

foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpcu_test', 'fields' => 'ids' ) ) as $old ) {
	if ( 'attachment' === get_post_type( $old ) ) {
		wp_delete_attachment( $old, true );
	} else {
		wp_delete_post( $old, true );
	}
}
foreach ( glob( $dir . '/wpcut-*' ) as $f ) {
	unlink( $f );
}
foreach ( Backup::all() as $b ) {
	Backup::open( $b['id'] )->delete();
}
delete_option( 'wpcut_json' );
delete_option( 'wpcut_object' );
delete_option( Media_Policy::OPTION );

WP_CLI::log( '' );
if ( $GLOBALS['wpcu_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpcu_f'], $GLOBALS['wpcu_p'] ) );
}
WP_CLI::success( sprintf( 'All %d image assertions passed.', $GLOBALS['wpcu_p'] ) );
