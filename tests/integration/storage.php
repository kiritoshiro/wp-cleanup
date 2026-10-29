<?php
/**
 * Where backups live: a private folder outside the web root (mode 0700, owner
 * marker, web-root checks also for a chosen parent), the uploads fallback with
 * its nginx rule and fail-closed backups on nginx, owner markers in uploads,
 * moving an older folder out of uploads under per-set locks, SQL files that
 * PHP refuses to run, and quarantined script files renamed to inert names and
 * back. DESTRUCTIVE: throwaway local site only.
 *
 *   WPCU_TESTS=1 wp eval-file wp-content/plugins/wp-cleanup/tests/integration/storage.php
 *
 * @package WPCleanup
 */

use WPCleanup\Backup;
use WPCleanup\Cleaner;
use WPCleanup\Storage;

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$host = wp_parse_url( home_url(), PHP_URL_HOST );
if ( '1' !== getenv( 'WPCU_TESTS' ) || ! preg_match( '/^(localhost|127\.0\.0\.1|\[::1\]|.+\.(test|localhost))$/', (string) $host ) ) {
	WP_CLI::error( 'Refusing to run: set WPCU_TESTS=1 and use a local site.' );
}
if ( defined( 'WPCU_DATA_PARENT' ) ) {
	WP_CLI::error( 'Run without WPCU_DATA_PARENT; these tests choose the parent folder themselves.' );
}

global $wpdb;
$GLOBALS['wpst_f'] = 0;
$GLOBALS['wpst_p'] = 0;
function wpst_ok( $cond, $msg ) {
	$GLOBALS[ $cond ? 'wpst_p' : 'wpst_f' ]++;
	WP_CLI::log( ( $cond ? '  ok   ' : WP_CLI::colorize( '  %rFAIL%n ' ) ) . $msg );
}

/** Delete a test folder; only below the given base. */
function wpst_rm( $dir, $base ) {
	$dir  = wp_normalize_path( $dir );
	$base = untrailingslashit( wp_normalize_path( $base ) );
	if ( 0 !== strpos( $dir, $base . '/' ) ) {
		return;
	}
	if ( is_link( $dir ) || is_file( $dir ) ) {
		unlink( $dir );
		return;
	}
	if ( ! is_dir( $dir ) ) {
		return;
	}
	foreach ( scandir( $dir ) as $entry ) {
		if ( '.' !== $entry && '..' !== $entry ) {
			wpst_rm( $dir . '/' . $entry, $base );
		}
	}
	rmdir( $dir );
}

/** Relative path => md5 of every file in a tree. */
function wpst_hashes( $dir ) {
	$out = array();
	$it  = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS ) );
	foreach ( $it as $file ) {
		if ( $file->isFile() ) {
			$out[ substr( wp_normalize_path( $file->getPathname() ), strlen( wp_normalize_path( $dir ) ) + 1 ) ] = md5_file( $file->getPathname() );
		}
	}
	ksort( $out );
	return $out;
}

/** Pretend the web server is nginx (true) or Apache (false). */
function wpst_nginx( $on ) {
	$GLOBALS['is_apache'] = ! $on;
	$GLOBALS['is_IIS']    = false;
	$GLOBALS['is_iis7']   = false;
	$GLOBALS['is_nginx']  = $on;
}

/** A backup set holding one deleted option, as the cleaner writes it. */
function wpst_option_set( $name, $value ) {
	global $wpdb;
	update_option( $name, $value, false );
	$backup = Backup::start();
	$n      = $backup->add_item( array( 'type' => 'option', 'id' => $name, 'label' => $name, 'status' => 'orphaned', 'owner' => '', 'count' => 1, 'bytes' => strlen( $value ) ) );
	$backup->write_rows( $n, $wpdb->options, (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->options} WHERE option_name = %s", $name ), ARRAY_A ) );
	delete_option( $name );
	$backup->set_result( $n, 'deleted', 'test' );
	return $backup;
}

$parent_of_wp = untrailingslashit( wp_normalize_path( realpath( dirname( untrailingslashit( ABSPATH ) ) ) ) );
$uploads      = wp_upload_dir( null, false );
$updir        = untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
$test_parent  = $parent_of_wp . '/wpcu-storage-test';
$opt_old      = 'wpcu_st_old';
$opt_new      = 'wpcu_st_new';
$opt_fresh    = 'wpcu_st_fresh';
$value        = "a:2:{s:1:\"k\";s:4:\"ž\x00'\";i:0;b:1;}";
$test_doc     = $parent_of_wp . '/wpcu-storage-doc';
$parent       = null;
$set_parent   = static function ( $standard ) use ( &$parent ) {
	return null === $parent ? $standard : $parent;
};
$server       = array( $GLOBALS['is_apache'] ?? false, $GLOBALS['is_IIS'] ?? false, $GLOBALS['is_iis7'] ?? false, $GLOBALS['is_nginx'] ?? false );
$doc_root     = $_SERVER['DOCUMENT_ROOT'] ?? null;

$reset = static function () use ( $test_parent, $test_doc, $parent_of_wp, $updir, $opt_old, $opt_new, $opt_fresh, $set_parent, $server, $doc_root ) {
	remove_filter( 'wp_cleanup_data_parent', $set_parent );
	remove_all_filters( 'wp_cleanup_uploads_deny_confirmed' );
	list( $GLOBALS['is_apache'], $GLOBALS['is_IIS'], $GLOBALS['is_iis7'], $GLOBALS['is_nginx'] ) = $server;
	if ( null === $doc_root ) {
		unset( $_SERVER['DOCUMENT_ROOT'] );
	} else {
		$_SERVER['DOCUMENT_ROOT'] = $doc_root;
	}
	Storage::reset();
	Storage::delete_all();
	delete_option( Storage::OPTION_DIR );
	delete_option( Storage::OPTION_PATH );
	foreach ( array( $opt_old, $opt_new, $opt_fresh ) as $opt ) {
		delete_option( $opt );
	}
	wpst_rm( $test_parent, $parent_of_wp );
	wpst_rm( $test_doc, $parent_of_wp );
	foreach ( array( $parent_of_wp, $updir ) as $base ) {
		foreach ( (array) glob( $base . '/wp-cleanup-*' ) as $dir ) {
			if ( ! is_readable( $dir . '/owner.txt' ) || 'not-this-site' === trim( file_get_contents( $dir . '/owner.txt' ) ) ) {
				wpst_rm( $dir, $base ); // Test leftovers: another site's, or unmarked.
			}
		}
	}
	foreach ( array( 'zzwpcu-st-new', 'zzwpcu-st-old', 'zzwpcu-st-inner' ) as $folder ) {
		wpst_rm( $updir . '/' . $folder, $updir );
	}
	Storage::reset();
};
$reset();

/* ---------------------------------------------------------------------- */
WP_CLI::log( '1. Private folder outside the web root' );
/* ---------------------------------------------------------------------- */

$dir = wp_normalize_path( Storage::dir() );
$st  = Storage::status();
wpst_ok( $st['outside'] && dirname( $dir ) === $parent_of_wp, 'data folder is next to the WordPress folder: ' . $dir );
wpst_ok( 0 !== strpos( $dir . '/', untrailingslashit( wp_normalize_path( ABSPATH ) ) . '/' ) && 0 !== strpos( $dir . '/', $updir . '/' ), 'not inside the WordPress folder or uploads' );
wpst_ok( preg_match( Storage::NAME_PATTERN, basename( $dir ) ) && get_option( Storage::OPTION_PATH ) === $dir, 'random name, recorded in the option' );
wpst_ok( is_file( $dir . '/owner.txt' ) && is_file( $dir . '/.htaccess' ) && is_file( $dir . '/index.php' ), 'owner marker and deny guards written' );
wpst_ok( false === get_option( Storage::OPTION_DIR ) && ! glob( $updir . '/wp-cleanup-*' ), 'nothing created in uploads' );
Storage::reset();
wpst_ok( wp_normalize_path( Storage::dir() ) === $dir, 'same folder on the next request' );
if ( '/' === DIRECTORY_SEPARATOR ) {
	wpst_ok( 0700 === ( fileperms( $dir ) & 0777 ), 'new folder is mode 0700' );
	chmod( $dir, 0755 );
	Storage::reset();
	Storage::dir();
	clearstatcache();
	wpst_ok( 0700 === ( fileperms( $dir ) & 0777 ) && Storage::status()['mode_ok'], 'a reused folder with mode 0755 is put back to 0700' );
} else {
	WP_CLI::log( '  skip mode 0700 checks: no POSIX modes on this system' );
}

$fresh = wpst_option_set( $opt_fresh, $value );
$sql   = $fresh->sql_file( 0 );
wpst_ok( '.sql.php' === substr( $sql, -8 ) && 0 === strpos( wp_normalize_path( $sql ), $dir . '/backups/' ), 'SQL written as item-0.sql.php in the private folder' );
$tokens = array_values( array_filter( token_get_all( file_get_contents( $sql ) ), static function ( $t ) {
	return ! is_array( $t ) || T_WHITESPACE !== $t[0];
} ) );
$names  = array_map( static function ( $t ) {
	return is_array( $t ) ? token_name( $t[0] ) : $t;
}, array_slice( $tokens, 0, 5 ) );
wpst_ok( array( 'T_OPEN_TAG', 'T_EXIT', ';', 'T_HALT_COMPILER', '(' ) === $names, 'served through PHP, the SQL file exits before any output: ' . implode( ' ', $names ) );
wpst_ok( Backup::SQL_GUARD === rtrim( strtok( file_get_contents( $sql ), "\n" ), "\r" ) && false === strpos( file_get_contents( $sql ), '<?php', 5 ), 'guard is the first line and the only PHP tag' );
$res = $fresh->restore();
wp_cache_flush();
wpst_ok( $res && $res[0]['ok'] && '1 statement(s) replayed.' === $res[0]['message'] && $value === get_option( $opt_fresh ), 'restore skips the guard line and is byte-identical' );
$fresh->delete();

/* ---------------------------------------------------------------------- */
WP_CLI::log( '2. A copied database never uses the original site\'s folder' );
/* ---------------------------------------------------------------------- */

// The original site's folder, as a copied database would record it.
$foreign = $parent_of_wp . '/wp-cleanup-0000000000000000';
wp_mkdir_p( $foreign );
file_put_contents( $foreign . '/owner.txt', 'not-this-site' );
file_put_contents( $foreign . '/sentinel.txt', 'keep' );
update_option( Storage::OPTION_PATH, $foreign, false );
Storage::reset();
wpst_ok( wp_normalize_path( Storage::dir() ) === $dir && get_option( Storage::OPTION_PATH ) === $dir, 'recorded folder owned by another site ignored; this site\'s own folder used' );
update_option( Storage::OPTION_PATH, $foreign, false );
Storage::delete_all();
wpst_ok( is_file( $foreign . '/sentinel.txt' ), 'uninstall does not delete a folder another site owns' );
wpst_rm( $foreign, $parent_of_wp );
delete_option( Storage::OPTION_PATH );
file_put_contents( $dir . '/owner.txt', 'not-this-site' );
Storage::reset();
$st = Storage::status();
wpst_ok( ! $st['outside'] && false !== strpos( $st['reason'], 'another site' ) && is_dir( $dir ), 'same-name folder of another site is never taken over: ' . $st['reason'] );
file_put_contents( $dir . '/owner.txt', untrailingslashit( wp_normalize_path( realpath( ABSPATH ) ) ) );
Storage::reset();
wpst_ok( wp_normalize_path( Storage::dir() ) === $dir, 'name is derived, so racing first requests agree on one folder' );

// A copied database whose uploads folder is shared with the original site.
add_filter( 'wp_cleanup_data_parent', $set_parent );
$parent  = '';
$theirs  = $updir . '/wp-cleanup-1111111111111111';
wp_mkdir_p( $theirs . '/backups' );
file_put_contents( $theirs . '/owner.txt', 'not-this-site' );
file_put_contents( $theirs . '/backups/sentinel.txt', 'keep' );
update_option( Storage::OPTION_DIR, basename( $theirs ), false );
Storage::reset();
$mine = wp_normalize_path( Storage::dir() );
wpst_ok( $mine !== $theirs && 0 === strpos( $mine, $updir . '/wp-cleanup-' ) && is_file( $mine . '/owner.txt' ), 'uploads folder of another site not used; a marked one of its own is made' );
update_option( Storage::OPTION_DIR, basename( $theirs ), false );
Storage::delete_all();
wpst_ok( is_file( $theirs . '/backups/sentinel.txt' ), 'uninstall does not delete another site\'s uploads folder' );
$scanned = ( new WPCleanup\Scanner() )->scan( array( 'file' ) );
$found   = array_values( array_filter( $scanned['items'], static function ( $i ) use ( $theirs ) {
	return 'uploads/' . basename( $theirs ) === $i['id'];
} ) );
wpst_ok( $found && 'in_use' === $found[0]['status'], 'scanner never offers another site\'s data folder for deletion' );
wpst_rm( $theirs, $updir );
wpst_rm( $mine, $updir );
// An unmarked folder from 0.12.1: kept by uninstall until this site has used (claimed) it.
$plain = $updir . '/wp-cleanup-2222222222222222';
wp_mkdir_p( $plain . '/backups' );
update_option( Storage::OPTION_DIR, basename( $plain ), false );
Storage::delete_all();
wpst_ok( is_dir( $plain ), 'uninstall leaves an unclaimed, unmarked folder alone' );
Storage::reset();
wpst_ok( wp_normalize_path( Storage::dir() ) === $plain && is_file( $plain . '/owner.txt' ), 'using it claims it with this site\'s marker' );
Storage::delete_all();
wpst_ok( ! file_exists( $plain ), 'uninstall then removes the claimed folder' );
delete_option( Storage::OPTION_DIR );
$parent = null;
remove_filter( 'wp_cleanup_data_parent', $set_parent );
update_option( Storage::OPTION_PATH, $dir, false );
Storage::reset();

// Deleting is bounded by the folder itself, not a name prefix.
wp_mkdir_p( $dir . 'x' );
wpst_ok( ! Storage::delete_tree( $dir . 'x' ) && is_dir( $dir . 'x' ), 'delete_tree refuses a sibling whose name starts with the data folder name' );
rmdir( $dir . 'x' );

/* ---------------------------------------------------------------------- */
WP_CLI::log( '3. Fallback to uploads and the nginx rule' );
/* ---------------------------------------------------------------------- */

add_filter( 'wp_cleanup_data_parent', $set_parent );
wp_mkdir_p( $test_parent . '/sub' );
file_put_contents( $test_parent . '/index.php', '<?php' );
wp_mkdir_p( $updir . '/zzwpcu-st-inner' );
wp_mkdir_p( $test_doc . '/private' );
$_SERVER['DOCUMENT_ROOT'] = $test_doc;
// The constant and the filter choose the parent; the web-root checks still apply.
$cases = array(
	'web root'           => array( $test_parent, 'looks like a web root' ),
	'below a web root'   => array( $test_parent . '/sub', 'looks like a web root' ),
	'WP folder'          => array( ABSPATH, 'looks like a web root' ),
	'in uploads'         => array( $updir . '/zzwpcu-st-inner', 'looks like a web root' ),
	'in document root'   => array( $test_doc . '/private', 'document root' ),
	'missing'            => array( $parent_of_wp . '/wpcu-no-such-folder', 'does not exist' ),
	'filtered'           => array( '', 'turned off' ),
);
foreach ( $cases as $label => $case ) {
	$parent = $case[0];
	Storage::reset();
	$st = Storage::status();
	wpst_ok( ! $st['outside'] && false !== strpos( $st['reason'], $case[1] ) && 0 === strpos( wp_normalize_path( $st['path'] ) . '/', $updir . '/wp-cleanup-' ), $label . ': falls back to uploads (' . $st['reason'] . ')' );
}
wpst_nginx( true );
$st   = Storage::status();
$want = 'location ^~ ' . untrailingslashit( (string) wp_parse_url( $uploads['baseurl'], PHP_URL_PATH ) ) . '/' . basename( $st['path'] ) . '/ { deny all; }';
wpst_ok( ! $st['server_denies'] && $want === $st['nginx_rule'], 'nginx: offers ' . $st['nginx_rule'] );
wpst_ok( $st['blocked'], 'nginx without a confirmed rule: new backups blocked' );
$refused = '';
try {
	Backup::start();
} catch ( RuntimeException $e ) {
	$refused = $e->getMessage();
}
wpst_ok( false !== strpos( $refused, 'Refused' ) && false !== strpos( $refused, $want ), 'Backup::start() refuses and names the rule' );
update_option( 'zzwpcust_blocked', 'x', false );
try {
	$report = ( new Cleaner() )->clean( array( 'option|zzwpcust_blocked' ), array( 'allow_unknown' => true, 'allow_inactive' => true ) );
} catch ( RuntimeException $e ) {
	$report = $e->getMessage();
}
wp_cache_flush();
wpst_ok( is_string( $report ) && 'x' === get_option( 'zzwpcust_blocked' ), 'cleanup refused before deleting anything: ' . ( is_string( $report ) ? 'exception' : wp_json_encode( $report ) ) );
delete_option( 'zzwpcust_blocked' );
add_filter( 'wp_cleanup_uploads_deny_confirmed', '__return_true' );
$st = Storage::status();
wpst_ok( $st['confirmed'] && ! $st['blocked'], 'confirmed server rule: not blocked' );
$kept = wpst_option_set( $opt_fresh, $value );
$kept_id = $kept->id;
unset( $kept );
remove_all_filters( 'wp_cleanup_uploads_deny_confirmed' );
Storage::reset();
wpst_ok( Storage::status()['blocked'], 'confirmation withdrawn: blocked again' );
$kept = Backup::open( $kept_id );
$res  = $kept ? $kept->restore() : array();
wp_cache_flush();
wpst_ok( $res && $res[0]['ok'] && $value === get_option( $opt_fresh ) && $kept->delete(), 'while blocked, an existing set still opens, restores and deletes' );
unset( $kept );
delete_option( $opt_fresh );
wpst_nginx( false );
wpst_ok( ! Storage::status()['blocked'] && Storage::status()['server_denies'], 'Apache: the folder\'s .htaccess counts; not blocked' );
list( $GLOBALS['is_apache'], $GLOBALS['is_IIS'], $GLOBALS['is_iis7'], $GLOBALS['is_nginx'] ) = $server;

// A recorded private folder that the web server turns out to serve (made from WP-CLI, where the document root is unknown).
$parent = null;
$_SERVER['DOCUMENT_ROOT'] = $parent_of_wp;
Storage::reset();
wpst_nginx( true );
$st = Storage::status();
wpst_ok( wp_normalize_path( $st['path'] ) === $dir && ! $st['outside'] && false !== strpos( $st['reason'], 'document root' ) && $st['blocked'], 'recorded folder inside the document root: still read, reported as not private, new backups blocked' );
wpst_ok( 'location ^~ /' . basename( $dir ) . '/ { deny all; }' === $st['nginx_rule'], 'with a rule for its URL: ' . $st['nginx_rule'] );
list( $GLOBALS['is_apache'], $GLOBALS['is_IIS'], $GLOBALS['is_iis7'], $GLOBALS['is_nginx'] ) = $server;
if ( null === $doc_root ) {
	unset( $_SERVER['DOCUMENT_ROOT'] );
} else {
	$_SERVER['DOCUMENT_ROOT'] = $doc_root;
}
Storage::reset();
wpst_ok( get_option( Storage::OPTION_PATH ) === $dir && Storage::status()['outside'], 'fallbacks keep the recorded private folder' );

/* ---------------------------------------------------------------------- */
WP_CLI::log( '4. Folders quarantined into a backup set: script files disarmed and restored' );
/* ---------------------------------------------------------------------- */

$parent = null; // Back to the private folder.
Storage::reset();
$files = array(
	'a.php'            => "<?php echo 'ran';\n",
	'sub/b.phtml'      => '<?= 1 ?>',
	'sub/deep/c.PHP5'  => '<?php',
	'.htaccess'        => "Require all granted\n",
	'.user.ini'        => "auto_prepend_file=a.php\n",
	'web.config'       => '<configuration/>',
	'img.png'          => "\x89PNG\r\n",
	'notes.php.txt'    => 'plain text',
	'readme.txt'       => 'hello',
);
foreach ( array( 'zzwpcu-st-new', 'zzwpcu-st-old' ) as $folder ) {
	foreach ( $files as $rel => $content ) {
		wp_mkdir_p( dirname( $updir . '/' . $folder . '/' . $rel ) );
		file_put_contents( $updir . '/' . $folder . '/' . $rel, $content );
	}
}
$before = wpst_hashes( $updir . '/zzwpcu-st-new' );
$report = ( new Cleaner() )->clean( array( 'file|uploads/zzwpcu-st-new', 'file|uploads/zzwpcu-st-old' ), array( 'allow_unknown' => true, 'allow_inactive' => true ) );
wpst_ok( 2 === count( $report['deleted'] ) && ! $report['failed'], 'both folders quarantined: ' . wp_json_encode( array_merge( $report['refused'], $report['failed'] ) ) );
$qset = Backup::open( (string) $report['backup'] );
$q    = array();
foreach ( $qset ? $qset->manifest['items'] : array() as $n => $item ) {
	$q[ basename( $item['id'] ) ] = $qset->dir . '/files/' . $n . '/' . basename( $item['id'] );
}
$runnable = array_filter( array_keys( isset( $q['zzwpcu-st-new'] ) ? wpst_hashes( $q['zzwpcu-st-new'] ) : array() ), static function ( $rel ) {
	return preg_match( Backup::RUNNABLE, basename( $rel ) );
} );
wpst_ok( isset( $q['zzwpcu-st-new'] ) && ! $runnable, 'no runnable or server-config name left in the quarantined copy' );
wpst_ok( isset( $q['zzwpcu-st-new'] ) && is_file( $q['zzwpcu-st-new'] . '/a.php' . Backup::DISARM_SUFFIX ) && is_file( $q['zzwpcu-st-new'] . '/.htaccess' . Backup::DISARM_SUFFIX ) && is_file( $q['zzwpcu-st-new'] . '/sub/deep/c.PHP5' . Backup::DISARM_SUFFIX ), 'a.php, .htaccess and sub/deep/c.PHP5 renamed with the suffix' );
wpst_ok( isset( $q['zzwpcu-st-new'] ) && is_file( $q['zzwpcu-st-new'] . '/img.png' ) && is_file( $q['zzwpcu-st-new'] . '/notes.php.txt' ), 'images and text files keep their names' );
// A folder quarantined by 0.12.1 or earlier was never disarmed: simulate one.
if ( isset( $q['zzwpcu-st-old'] ) ) {
	Backup::rearm_tree( $q['zzwpcu-st-old'] );
}
wpst_ok( isset( $q['zzwpcu-st-old'] ) && is_file( $q['zzwpcu-st-old'] . '/a.php' ), 'old-style quarantine (not disarmed) prepared' );
$res = $qset ? $qset->restore() : array();
wpst_ok( 2 === count( $res ) && $res[0]['ok'] && $res[1]['ok'], 'restore of both folders reported ok: ' . wp_json_encode( $res ) );
wpst_ok( is_dir( $updir . '/zzwpcu-st-new' ) && wpst_hashes( $updir . '/zzwpcu-st-new' ) === $before, 'disarmed folder restored byte-identical with original names' );
wpst_ok( is_dir( $updir . '/zzwpcu-st-old' ) && wpst_hashes( $updir . '/zzwpcu-st-old' ) === $before, 'old-style folder restored byte-identical' );
if ( $qset ) {
	$qset->delete();
}

/* ---------------------------------------------------------------------- */
WP_CLI::log( '5. Moving an older data folder out of uploads' );
/* ---------------------------------------------------------------------- */

$reset();
add_filter( 'wp_cleanup_data_parent', $set_parent );
add_filter( 'wp_cleanup_uploads_deny_confirmed', '__return_true' ); // WP-CLI cannot tell the server type.
$parent = ''; // Behave like 0.12.1: everything in uploads.
Storage::reset();
$legacy = wp_normalize_path( Storage::dir() );
wpst_ok( 0 === strpos( $legacy, $updir . '/wp-cleanup-' ), 'older layout in uploads prepared: ' . basename( $legacy ) );
$old = wpst_option_set( $opt_old, $value );
// Files written by 0.12.1: item-<n>.sql without the guard line.
$lines = file( $old->sql_file( 0 ), FILE_IGNORE_NEW_LINES );
array_shift( $lines );
file_put_contents( $old->dir . '/item-0.sql', implode( "\n", $lines ) . "\n" );
unlink( $old->dir . '/item-0.sql.php' );
wpst_ok( '.sql' === substr( $old->sql_file( 0 ), -4 ), 'an existing item-0.sql is read and appended as it is' );
$new = wpst_option_set( $opt_new, $value );
Storage::write_json( 'wpcu-st-cache.json', array( 'x' => 1 ) );
unlink( $legacy . '/owner.txt' ); // 0.12.1 wrote no marker.
$old_id = $old->id;
$new_id = $new->id;
unset( $old, $new ); // Release their locks.
remove_filter( 'wp_cleanup_data_parent', $set_parent );
remove_all_filters( 'wp_cleanup_uploads_deny_confirmed' );
// Another request is restoring the "new" set: it holds that set's lock.
$busy = Backup::open( $new_id );

Storage::reset();
$dir = wp_normalize_path( Storage::dir() );
wpst_ok( dirname( $dir ) === $parent_of_wp, 'private folder chosen after the update' );
wpst_ok( is_dir( $dir . '/backups/' . $old_id ) && ! is_dir( $legacy . '/backups/' . $old_id ), 'set nobody is using moved out of uploads at once' );
wpst_ok( is_dir( $legacy . '/backups/' . $new_id ) && ! is_dir( $dir . '/backups/' . $new_id ), 'set whose lock another request holds is left in place' );
wpst_ok( is_file( $legacy . '/owner.txt' ), 'the unmarked old folder was claimed with this site\'s marker' );
wpst_ok( is_file( $dir . '/wpcu-st-cache.json' ) && ! file_exists( $legacy . '/wpcu-st-cache.json' ) && array( 'x' => 1 ) === Storage::read_json( 'wpcu-st-cache.json' ), 'scan results and caches moved' );
$res = $busy ? $busy->restore() : array();
wp_cache_flush();
wpst_ok( $res && $res[0]['ok'] && $value === get_option( $opt_new ) && is_file( $busy->dir . '/manifest.json' ), 'the locked set restores in place, its state saved where it is' );
unset( $busy );
$ids = wp_list_pluck( Backup::all(), 'id' );
wpst_ok( in_array( $old_id, $ids, true ) && in_array( $new_id, $ids, true ) && 2 === count( $ids ), 'Backups list shows sets from both places' );
$st = Storage::status();
wpst_ok( $st['outside'] && wp_normalize_path( (string) $st['legacy'] ) === $legacy && false !== strpos( $st['nginx_rule'], basename( $legacy ) ), 'status reports the folder still in uploads, with its nginx rule' );
$scanned = ( new WPCleanup\Scanner() )->scan( array( 'file' ) );
$ours    = array_values( array_filter( $scanned['items'], static function ( $i ) use ( $legacy ) {
	return 'uploads/' . basename( $legacy ) === $i['id'];
} ) );
wpst_ok( $ours && 'in_use' === $ours[0]['status'], 'scanner still marks the folder in uploads as WP Cleanup data' );

$moved = Backup::open( $old_id );
$res   = $moved ? $moved->restore() : array();
wp_cache_flush();
wpst_ok( $res && $res[0]['ok'] && $value === get_option( $opt_old ), 'moved 0.12.1-format set restores byte-identical' );
unset( $moved );
$left = Backup::open( $new_id );
wpst_ok( $left && 0 === strpos( wp_normalize_path( $left->dir ), $legacy . '/' ), 'set still in uploads opens from there' );
unset( $left );

Storage::reset();
Storage::dir();
wpst_ok( is_dir( $dir . '/backups/' . $new_id ) && ! file_exists( $legacy ), 'once its lock is free the last set moves and the folder in uploads is removed' );
wpst_ok( false === get_option( Storage::OPTION_DIR ) && null === Storage::status()['legacy'] && '' === Storage::status()['nginx_rule'], 'option cleared; no warning left' );
$moved = Backup::open( $new_id );
wpst_ok( $moved && ! empty( $moved->manifest['restored'] ) && $moved->delete() && ! is_dir( $dir . '/backups/' . $new_id ) && ! file_exists( Storage::lock_file( $dir . '/backups/' . $new_id ) ), 'moved set keeps its restore state; deleting it removes its lock file too' );
unset( $moved );
Backup::open( $old_id )->delete();

/* ---------------------------------------------------------------------- */
/* Teardown                                                               */
/* ---------------------------------------------------------------------- */

$reset();
Storage::dir(); // Leave the site with a normal private folder.

WP_CLI::log( '' );
if ( $GLOBALS['wpst_f'] ) {
	WP_CLI::error( sprintf( '%d failed, %d passed.', $GLOBALS['wpst_f'], $GLOBALS['wpst_p'] ) );
}
WP_CLI::success( sprintf( 'All %d storage assertions passed.', $GLOBALS['wpst_p'] ) );
