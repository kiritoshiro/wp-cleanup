<?php
/**
 * Image usage, look-alike and updater tests. DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/usage.php
 *
 * The updater part downloads the latest release from GitHub; it is skipped when offline.
 * The local download check needs the site served on its own URL (php -S).
 *
 * @package WPCleanup
 */

use WPCleanup\Media_Report;
use WPCleanup\Media_Similarity;
use WPCleanup\Media_Usage;
use WPCleanup\Updater;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( '1' !== getenv( 'WPCU_TESTS' ) || ! preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', (string) $host ) ) {
	WP_CLI::error( 'Refusing to run: set WPCU_TESTS=1 and use a local site.' );
}
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

global $wpdb;
$GLOBALS['wpcu_f'] = 0;
$GLOBALS['wpcu_p'] = 0;
function wpcu_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpcu_p' : 'wpcu_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

$up  = wp_upload_dir();
$dir = wp_normalize_path( $up['path'] );

/* Reset ------------------------------------------------------------------ */

foreach ( get_posts( array( 'post_type' => 'any', 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => '_wpcu_utest', 'fields' => 'ids' ) ) as $old ) {
	'attachment' === get_post_type( $old ) ? wp_delete_attachment( $old, true ) : wp_delete_post( $old, true );
}
foreach ( glob( $dir . '/wpcuu-*' ) as $f ) {
	unlink( $f );
}
foreach ( get_terms( array( 'taxonomy' => 'category', 'hide_empty' => false, 'name__like' => 'wpcuu term' ) ) as $t ) {
	wp_delete_term( $t->term_id, 'category' );
}
delete_option( 'wpcut_logo_image' );
delete_option( 'wpcut_thumbnail_size_w' );
delete_site_transient( Updater::CACHE );
wp_cache_flush();

/* Fixtures --------------------------------------------------------------- */

// A drawing that differs per seed; the same seed at another size looks alike.
function wpcuu_draw( $path, $seed, $w, $h, $quality = 90 ) {
	$im = imagecreatetruecolor( $w, $h );
	for ( $x = 0; $x < $w; $x++ ) {
		$c = imagecolorallocate( $im, (int) ( 255 * $x / $w ), (int) ( ( $seed * 53 ) % 255 ), (int) ( 255 - 255 * $x / $w ) );
		imageline( $im, $x, 0, $x, $h, $c );
	}
	mt_srand( $seed );
	for ( $i = 0; $i < 6; $i++ ) {
		$x1 = mt_rand( 0, 90 ) / 100;
		$y1 = mt_rand( 0, 90 ) / 100;
		imagefilledrectangle( $im, (int) ( $x1 * $w ), (int) ( $y1 * $h ), (int) ( ( $x1 + 0.15 ) * $w ), (int) ( ( $y1 + 0.2 ) * $h ), imagecolorallocate( $im, mt_rand( 0, 255 ), mt_rand( 0, 255 ), mt_rand( 0, 255 ) ) );
	}
	imagejpeg( $im, $path, $quality );
	imagedestroy( $im );
}

function wpcuu_attach( $file, $parent = 0 ) {
	$id = wp_insert_attachment(
		array(
			'post_mime_type' => 'image/jpeg',
			'post_title'     => basename( $file ),
			'post_status'    => 'inherit',
		),
		$file,
		$parent
	);
	wp_update_attachment_metadata( $id, wp_generate_attachment_metadata( $id, $file ) );
	update_post_meta( $id, '_wpcu_utest', 1 );
	return $id;
}

function wpcuu_post( $args ) {
	$id = wp_insert_post( array_merge( array( 'post_status' => 'publish', 'post_title' => 'wpcuu post' ), $args ) );
	update_post_meta( $id, '_wpcu_utest', 1 );
	return $id;
}

WP_CLI::log( 'Seeding images and uses…' );
$run = wp_generate_password( 6, false, false ); // Unique names: Windows may briefly lock a just-deleted file.
$p1 = wpcuu_post( array( 'post_title' => 'wpcuu featured host' ) );
wpcuu_draw( $dir . "/wpcuu-a-$run.jpg", 1, 800, 600 );
wpcuu_draw( $dir . "/wpcuu-b-$run.jpg", 1, 640, 480, 70 ); // Same picture, smaller and more compressed.
copy( $dir . "/wpcuu-a-$run.jpg", $dir . "/wpcuu-c-$run.jpg" ); // Identical file under another name.
$img = array( 'a' => wpcuu_attach( $dir . "/wpcuu-a-$run.jpg", $p1 ) );
$img['b'] = wpcuu_attach( $dir . "/wpcuu-b-$run.jpg" );
$img['c'] = wpcuu_attach( $dir . "/wpcuu-c-$run.jpg" );
foreach ( array( 'd' => 2, 'e' => 3, 'f' => 4, 'g' => 5, 'h' => 6 ) as $k => $seed ) {
	wpcuu_draw( $dir . "/wpcuu-$k-$run.jpg", $seed, 700, 500 );
	$img[ $k ] = wpcuu_attach( $dir . "/wpcuu-$k-$run.jpg" );
}

set_post_thumbnail( $p1, $img['a'] );
// Carbon Fields complex value holding an image id.
$wpdb->insert( $wpdb->postmeta, array( 'post_id' => $p1, 'meta_key' => '_hero|slide_image|0|0|value', 'meta_value' => (string) $img['f'] ) );

$b_small = wp_get_attachment_image_src( $img['b'], 'medium' );
$p2      = wpcuu_post(
	array(
		'post_title'   => 'wpcuu content host',
		'post_content' => '<p><img src="' . $b_small[0] . '" alt=""></p>' . "\n" . '<!-- wp:image {"id":' . $img['c'] . ',"sizeSlug":"large"} --><figure class="wp-block-image"></figure><!-- /wp:image -->',
	)
);
// A revision mentioning E must not count as a use.
wp_insert_post( array( 'post_type' => 'revision', 'post_parent' => $p2, 'post_status' => 'inherit', 'post_name' => $p2 . '-revision-v1', 'post_content' => wp_get_attachment_url( $img['e'] ) ) );

$p3 = wpcuu_post( array( 'post_title' => 'wpcuu gallery host', 'post_content' => '[gallery ids="' . $img['d'] . '"]' ) );
update_post_meta( $p3, '_wpcut_builder', wp_slash( wp_json_encode( array( 'img' => wp_get_attachment_url( $img['d'] ) ) ) ) );

// A trashed post's featured image does not count.
$trashed = wpcuu_post( array( 'post_title' => 'wpcuu trashed' ) );
set_post_thumbnail( $trashed, $img['e'] );
wp_trash_post( $trashed );

$term = wp_insert_term( 'wpcuu term ' . wp_generate_password( 4, false ), 'category' );
update_term_meta( $term['term_id'], 'thumbnail_id', $img['g'] );
update_option( 'wpcut_logo_image', $img['h'], false );
update_option( 'wpcut_thumbnail_size_w', $img['e'], false ); // A dimension key: never an image id.
wp_cache_flush();

/* 1. Usage --------------------------------------------------------------- */

WP_CLI::log( "\n1. Where images are used" );
$usage = Media_Usage::build();
$kinds = static function ( $id ) use ( $usage ) {
	$out = array();
	foreach ( isset( $usage['uses'][ $id ] ) ? $usage['uses'][ $id ] : array() as $u ) {
		$out[] = $u['k'] . ':' . $u['o'] . ( '' !== $u['f'] ? ':' . $u['f'] : '' );
	}
	sort( $out );
	return $out;
};
wpcu_ok( array( 'featured:' . $p1 ) === $kinds( $img['a'] ), 'A: featured image of its post (' . implode( ', ', $kinds( $img['a'] ) ) . ')' );
wpcu_ok( array( 'content:' . $p2 ) === $kinds( $img['b'] ), 'B: in content through the URL of one of its sizes' );
wpcu_ok( array( 'content:' . $p2 ) === $kinds( $img['c'] ), 'C: in content through an image block id' );
wpcu_ok( array( 'content:' . $p3, 'field:' . $p3 . ':_wpcut_builder' ) === $kinds( $img['d'] ), 'D: gallery shortcode and a JSON-escaped URL in a field (' . implode( ', ', $kinds( $img['d'] ) ) . ')' );
wpcu_ok( array() === $kinds( $img['e'] ), 'E: a revision, a trashed post and a dimension option do not count (' . implode( ', ', $kinds( $img['e'] ) ) . ')' );
wpcu_ok( array( 'field:' . $p1 . ':_hero|slide_image|0|0|value' ) === $kinds( $img['f'] ), 'F: Carbon Fields image field' );
wpcu_ok( array( 'term:' . $term['term_id'] . ':thumbnail_id' ) === $kinds( $img['g'] ), 'G: term field' );
wpcu_ok( array( 'setting:wpcut_logo_image:wpcut_logo_image' ) === $kinds( $img['h'] ), 'H: site setting' );
wpcu_ok( isset( $usage['parents'][ $img['a'] ] ) && $p1 === $usage['parents'][ $img['a'] ], 'A: "uploaded to" parent recorded separately' );
wpcu_ok( Media_Usage::is_id_key( '_hero|slide_image|1|0|value' ) && Media_Usage::is_id_key( 'thumbnail_id' ) && ! Media_Usage::is_id_key( 'thumbnail_size_w' ) && ! Media_Usage::is_id_key( 'image_quality' ) && ! Media_Usage::is_id_key( 'post_title' ), 'image-id key detection' );
wpcu_ok( 'photos/2024/05/photo' === Media_Usage::file_key( 'photos/2024/05/photo-300x200.jpg' ) && 'photo' === Media_Usage::file_key( 'photo-scaled.JPG' ), 'file keys ignore size suffixes and -scaled' );

/* 2. Look-alikes ------------------------------------------------------------ */

WP_CLI::log( "\n2. Look-alike images" );
$h = array();
foreach ( $img as $k => $id ) {
	$h[ $k ] = Media_Similarity::dhash( Media_Similarity::source_file( $id ) );
}
wpcu_ok( 16 === strlen( $h['a'] ), 'hash is 64 bits' );
wpcu_ok( 0 === Media_Similarity::distance( $h['a'], $h['c'] ), 'identical files: distance 0' );
wpcu_ok( Media_Similarity::distance( $h['a'], $h['b'] ) <= Media_Similarity::MAX_DISTANCE, 'resized, re-compressed copy looks alike (distance ' . Media_Similarity::distance( $h['a'], $h['b'] ) . ')' );
$far = min( Media_Similarity::distance( $h['a'], $h['d'] ), Media_Similarity::distance( $h['a'], $h['e'] ), Media_Similarity::distance( $h['d'], $h['e'] ) );
wpcu_ok( $far > Media_Similarity::MAX_DISTANCE, 'different pictures do not (closest distance ' . $far . ')' );
wpcu_ok( ! Media_Similarity::alike( '0000000000000000', '0000000000000000', '202020', 'e0e0e0' ), 'same structure but different colours: not alike (flat graphics)' );
wpcu_ok( ! Media_Similarity::alike( '0000000000000000', '0000000000000000', '808080', '808080', 1.5, 1.0 ), 'same picture data but another shape: not alike (a crop)' );
// Anchored groups: 1~2 and 2~3, but 1 and 3 differ in 8 bits, so 3 must not be pulled in through 2.
$chain = Media_Similarity::groups( array( 1 => '0000000000000000', 2 => '000000000000000f', 3 => '00000000000000ff' ) );
wpcu_ok( array( array( 1, 2 ) ) === $chain, 'no chaining: a group only holds images like its anchor (' . wp_json_encode( $chain ) . ')' );

$report = Media_Report::build();
$ours   = array_flip( $img );
$mine   = array();
foreach ( $report['groups'] as $g ) {
	$in = array_values( array_intersect( $g['ids'], $img ) );
	if ( $in ) {
		sort( $in );
		$mine[] = array( 'ids' => $in, 'all' => count( $in ) === count( $g['ids'] ), 'identical' => $g['identical'] );
	}
}
$abc = array( $img['a'], $img['b'], $img['c'] );
sort( $abc );
wpcu_ok( 1 === count( $mine ) && $abc === $mine[0]['ids'] && $mine[0]['all'], 'report: A, B and C form one group, nothing else joins it' );
wpcu_ok( $mine && ! $mine[0]['identical'], 'report: the group is "look alike", not "identical files" (B differs)' );
$unused = array_values( array_intersect( $report['unused'], $img ) );
wpcu_ok( array( $img['e'] ) === $unused, 'report: only E is listed as not used (' . implode( ',', array_map( static function ( $i ) use ( $ours ) { return $ours[ $i ]; }, $unused ) ) . ')' );
wpcu_ok( isset( $report['info'][ $img['a'] ]['w'] ) && 800 === $report['info'][ $img['a'] ]['w'], 'report: image info for lists' );
wpcu_ok( 0 === $report['hash_left'], 'report: every image compared' );
$again = Media_Similarity::hash_all( array_values( $img ), 0.0 );
wpcu_ok( count( $img ) === count( $again['hashes'] ) && 0 === $again['pending'], 'hashes are reused from the cache even with no time budget' );

/* 3. Updater -------------------------------------------------------------- */

WP_CLI::log( "\n3. GitHub updater" );
$asset = array( 'name' => 'wp-cleanup-9.9.9.zip', 'state' => 'uploaded', 'browser_download_url' => 'https://github.com/kiritoshiro/wp-cleanup/releases/download/v9.9.9/wp-cleanup-9.9.9.zip', 'url' => 'https://api.github.com/repos/kiritoshiro/wp-cleanup/releases/assets/1', 'digest' => 'sha256:00' );
$json  = array( 'tag_name' => 'v9.9.9', 'draft' => false, 'prerelease' => false, 'html_url' => 'https://github.com/kiritoshiro/wp-cleanup/releases/tag/v9.9.9', 'body' => 'x', 'assets' => array( $asset ) );
$ok    = Updater::parse( $json );
wpcu_ok( $ok && '9.9.9' === $ok['version'] && $asset['browser_download_url'] === $ok['package'], 'release parsed' );
wpcu_ok( null === Updater::parse( array_merge( $json, array( 'draft' => true ) ) ) && null === Updater::parse( array_merge( $json, array( 'prerelease' => true ) ) ), 'drafts and pre-releases are ignored' );
wpcu_ok( null === Updater::parse( array_merge( $json, array( 'assets' => array( array_merge( $asset, array( 'name' => 'other.zip' ) ) ) ) ) ), 'a release without the expected ZIP is ignored' );
wpcu_ok( null === Updater::parse( array_merge( $json, array( 'assets' => array( array_merge( $asset, array( 'browser_download_url' => 'https://evil.example/wp-cleanup-9.9.9.zip' ) ) ) ) ) ), 'a package outside this repository is refused' );
$md = Updater::markdown( "## Changes\n- **Bold** `code` [link](https://example.test)\n<script>alert(1)</script>" );
wpcu_ok( false === strpos( $md, '<script>' ) && false !== strpos( $md, '<strong>Bold</strong>' ) && false !== strpos( $md, '<a href="https://example.test">' ), 'release notes: markdown rendered, HTML escaped' );

set_site_transient( Updater::CACHE, $ok, HOUR_IN_SECONDS );
wpcu_ok( false === Updater::update_data( false, array(), 'other/other.php' ), 'other plugins are left alone' );
$data = Updater::update_data( false, array(), plugin_basename( WPCU_FILE ) );
wpcu_ok( is_array( $data ) && '9.9.9' === $data['version'] && Updater::INFO_SLUG === $data['slug'] && $ok['package'] === $data['package'], 'update data for this plugin' );
$info = Updater::details( false, 'plugin_information', (object) array( 'slug' => Updater::INFO_SLUG ) );
wpcu_ok( is_object( $info ) && '9.9.9' === $info->version && false === Updater::details( false, 'plugin_information', (object) array( 'slug' => 'wp-cleanup' ) ), '"View details" only for our slug, not wordpress.org\'s unrelated "wp-cleanup"' );

// Digest check on a real download, served by the local site.
add_filter( 'http_request_host_is_external', '__return_true' );
add_filter(
	'http_allowed_safe_ports',
	static function ( $ports ) {
		return array_merge( (array) $ports, array( (int) wp_parse_url( home_url(), PHP_URL_PORT ) ) );
	}
);
$zip = $dir . "/wpcuu-pkg-$run.zip";
file_put_contents( $zip, 'fake package ' . wp_generate_password( 12, false ) );
$local = array_merge( $ok, array( 'package' => $up['url'] . "/wpcuu-pkg-$run.zip", 'digest' => 'sha256:' . hash_file( 'sha256', $zip ) ) );
set_site_transient( Updater::CACHE, $local, HOUR_IN_SECONDS );
$got = Updater::download( false, $local['package'], null );
if ( is_wp_error( $got ) && 'http_request_failed' === $got->get_error_code() ) {
	WP_CLI::log( '  skip local download check: the site is not being served (' . $got->get_error_message() . ')' );
} else {
	wpcu_ok( is_string( $got ) && hash_file( 'sha256', $got ) === substr( $local['digest'], 7 ), 'download with a matching digest is handed to WordPress' );
	if ( is_string( $got ) ) {
		wp_delete_file( $got );
	}
	set_site_transient( Updater::CACHE, array_merge( $local, array( 'digest' => 'sha256:' . str_repeat( '0', 64 ) ) ), HOUR_IN_SECONDS );
	$bad = Updater::download( false, $local['package'], null );
	wpcu_ok( is_wp_error( $bad ) && 'wpcu_digest' === $bad->get_error_code(), 'download with a wrong digest is refused' );
}
wpcu_ok( false === Updater::download( false, 'https://example.test/other.zip', null ), 'other packages are not intercepted' );
unlink( $zip );

// The real latest release on GitHub.
delete_site_transient( Updater::CACHE );
$live = Updater::release( true );
if ( ! $live ) {
	WP_CLI::log( '  skip GitHub check: no release data (offline or rate-limited)' );
} else {
	wpcu_ok( preg_match( '/^\d+\.\d+\.\d+$/', $live['version'] ) && 0 === strpos( $live['digest'], 'sha256:' ), 'GitHub: latest release ' . $live['tag'] . ' with a SHA-256 digest' );
	$file = Updater::download( false, $live['package'], null );
	wpcu_ok( is_string( $file ) && hash_file( 'sha256', $file ) === substr( $live['digest'], 7 ), 'GitHub: package downloaded and verified against its digest' );
	if ( is_string( $file ) ) {
		wp_delete_file( $file );
	}
}
delete_site_transient( Updater::CACHE );

// Leave the site as it was for the other suites.
foreach ( array_merge( array_values( $img ), array( $p1, $p2, $p3, $trashed ) ) as $id ) {
	'attachment' === get_post_type( $id ) ? wp_delete_attachment( $id, true ) : wp_delete_post( $id, true );
}
wp_delete_term( $term['term_id'], 'category' );
delete_option( 'wpcut_logo_image' );
delete_option( 'wpcut_thumbnail_size_w' );

if ( $GLOBALS['wpcu_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpcu_f'], $GLOBALS['wpcu_p'] ) );
}
WP_CLI::success( sprintf( 'All %d usage, look-alike and updater assertions passed.', $GLOBALS['wpcu_p'] ) );
