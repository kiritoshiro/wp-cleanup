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
		case 'gif':
			imagegif( $im, $path );
			break;
	}
	imagedestroy( $im );
}

function wpcu_attach( $name, $w, $h, $type, $alpha = false ) {
	$path = wp_upload_dir()['path'] . '/' . $name;
	wpcu_make_image( $path, $w, $h, $type, $alpha );
	$mime = array( 'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif' )[ $type ];
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

/* ---------------------------------------------------------------------- */
/* 2. Convert                                                             */
/* ---------------------------------------------------------------------- */

WP_CLI::log( "\n2. Convert" );
$before_files = $files_snapshot();
$before_db    = $db_snapshot();
$backup       = Backup::start();
$results      = array();
foreach ( array( $big, $png, $small, $gif, $twin_j, $twin_p ) as $id ) {
	$results[ $id ] = Media_Converter::convert( $id, $backup );
}
wp_cache_flush();
foreach ( array( $big, $png, $small, $twin_j, $twin_p ) as $id ) {
	wpcu_ok( 'converted' === $results[ $id ]['status'], "#$id converted: " . $results[ $id ]['message'] );
}
wpcu_ok( 'skipped' === $results[ $gif ]['status'], 'GIF skipped: ' . $results[ $gif ]['message'] );

$m = wp_get_attachment_metadata( $big );
wpcu_ok( 'image/avif' === get_post_mime_type( $big ) && '.avif' === substr( get_attached_file( $big ), -5 ), 'big is an AVIF attachment now' );
$stored = get_post_meta( $big, '_wp_attached_file', true );
wpcu_ok( $subdir . '/wpcut-big.avif' === $stored && is_file( get_attached_file( $big ) ), "stored path is uploads-relative and resolves (got $stored)" );
wpcu_ok( $subdir . '/wpcut-big.avif' === wp_get_attachment_metadata( $big )['file'], 'metadata file is uploads-relative' );
wpcu_ok( wp_get_attachment_url( $big ) === $u( 'wpcut-big.avif' ), 'attachment URL is correct: ' . wp_get_attachment_url( $big ) );
wpcu_ok( 1920 === $m['width'] && 1280 === $m['height'], 'full capped at 1920 (got ' . $m['width'] . 'x' . $m['height'] . ')' );
wpcu_ok( array( 'alps-small' ) === array_keys( $m['sizes'] ) && 768 === $m['sizes']['alps-small']['width'], 'only alps-small (768 wide) remains' );
wpcu_ok( ! isset( $m['original_image'] ) && '1' === (string) get_post_meta( $big, Media_Policy::ALPS_FLAG, true ), 'original_image dropped, ALPS flag set' );
$left = array_values( array_filter( glob( $dir . '/wpcut-big*' ), 'is_file' ) );
wpcu_ok( 2 === count( $left ), 'exactly two files left for big: ' . implode( ', ', array_map( 'basename', $left ) ) );
$ms = wp_get_attachment_metadata( $small );
wpcu_ok( array() === $ms['sizes'] && 500 === $ms['width'], 'small image: single AVIF, no extra size' );
$src = wp_get_attachment_image_src( $big, 'alps-small' );
wpcu_ok( $src && false !== strpos( $src[0], '.avif' ) && 768 === $src[1], 'wp_get_attachment_image_src(alps-small) serves the small AVIF' );
wpcu_ok( is_file( $dir . '/wpcut-anim.gif' ) && 'image/gif' === get_post_mime_type( $gif ), 'GIF file untouched' );

// Transparency survived.
$pm  = wp_get_attachment_metadata( $png );
$img = imagecreatefromavif( get_attached_file( $png ) );
$a   = ( imagecolorat( $img, 5, 5 ) >> 24 ) & 0x7F;
$c   = ( imagecolorat( $img, (int) ( $pm['width'] / 2 ), (int) ( $pm['height'] / 2 ) ) >> 24 ) & 0x7F;
wpcu_ok( $a > 100 && $c < 20, "PNG alpha kept (corner alpha $a, centre alpha $c)" );

// References.
$html = get_post_field( 'post_content', $post, 'raw' );
$full = wp_basename( get_attached_file( $big ) );
$sm   = $m['sizes']['alps-small']['file'];
wpcu_ok( false === strpos( $html, 'wpcut-big-1024x683.jpg' ) && false !== strpos( $html, $escaped( $u( $full ) ) ) && false !== strpos( $html, '<img src="' . $u( $full ) . '"' ), 'large size (plain and JSON-escaped) → full AVIF' );
wpcu_ok( false !== strpos( $html, 'srcset="' . $u( $sm ) . ' 768w"' ), 'srcset 300w/768w → one small AVIF entry at its real 768w' );
wpcu_ok( false !== strpos( $html, 'href="' . $u( $full ) . '"' ), 'link to the original → full AVIF' );
wpcu_ok( false !== strpos( $html, '<img src="' . $u( $full ) . '"></p>' ) && false === strpos( $html, '999x666' ), 'stray 999x666 (wider than 768) → full AVIF' );
wpcu_ok( false !== strpos( $html, wp_make_link_relative( $u( wp_get_attachment_metadata( $png )['sizes']['alps-small']['file'] ) ) ), 'relative URL rewritten' );
wpcu_ok( false !== strpos( $html, 'wpcut-big-300x200.jpg.bak and not-wpcut-big.jpg' ), 'look-alike text untouched' );
$g = get_post_meta( $post, 'wpcut_gallery', true );
wpcu_ok( is_array( $g ) && false !== strpos( $g['items'][0]['thumb'], '.avif' ) && false !== strpos( unserialize( $g['nested'] )['u'], '.avif' ), 'serialized and nested-serialized meta rewritten and still valid' );
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
wpcu_ok( $snap_f === $files_snapshot(), 'rollback: every file back, new AVIFs removed' );
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
wpcu_ok( 5 === count( $res ) && ! $bad, 'restore of 5 converted images reported ok: ' . wp_json_encode( array_values( $bad ) ) );
wp_cache_flush();
wpcu_ok( $before_files === $files_snapshot(), 'restore: every original file back byte-identical, AVIFs gone from uploads' );
wpcu_ok( $before_db == $db_snapshot(), 'restore: attachments, content, meta and options identical' ); // phpcs:ignore
wpcu_ok( (bool) glob( $backup->dir . '/files/*/created/' . $up['subdir'] . '/wpcut-big*.avif' ), 'unused AVIFs parked in the backup set, not deleted' );

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
wpcu_ok( 'image/avif' === get_post_mime_type( $big ) && false !== strpos( get_post_field( 'post_content', $post, 'raw' ), 'edited later' ), 'nothing changed by the refused restore' );

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
