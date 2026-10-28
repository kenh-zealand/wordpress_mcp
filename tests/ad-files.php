<?php
/** Standalone integration tests of the registered callbacks against real files. */
error_reporting( E_ALL );
set_error_handler( function ( $severity, $message, $file, $line ) {
	if ( error_reporting() & $severity ) { throw new ErrorException( $message, 0, $severity, $file, $line ); }
	return false;
} );
$fixture = __DIR__ . '/.tmp/' . bin2hex( random_bytes( 8 ) );
mkdir( $fixture . '/public_html/ad/assets', 0777, true );
define( 'ABSPATH', $fixture . '/public_html/' );
if ( in_array( '--disallow-edit', $argv, true ) ) { define( 'DISALLOW_FILE_EDIT', true ); }
if ( in_array( '--disallow-mods', $argv, true ) ) { define( 'DISALLOW_FILE_MODS', true ); }
class WP_Error {
	public $code; public $message; public $data;
	function __construct( $code, $message, $data = null ) { $this->code = $code; $this->message = $message; $this->data = $data; }
}
function is_wp_error( $x ) { return $x instanceof WP_Error; }
function wp_json_encode( $x, $flags = 0 ) { return json_encode( $x, $flags ); }
function current_user_can( $cap ) { return $GLOBALS['admin']; }
function is_multisite() { return $GLOBALS['multisite']; }
function is_super_admin() { return $GLOBALS['super']; }
function wp_cache_flush() { $GLOBALS['flushed']++; }
function wp_register_ability( $name, $args ) { $GLOBALS['abilities'][ $name ] = $args; }
$admin = true; $multisite = false; $super = false; $flushed = 0; $abilities = array(); $checks = 0;
require __DIR__ . '/../keha-mcp/includes/helpers.php';
require __DIR__ . '/../keha-mcp/includes/abilities-theme.php';
require __DIR__ . '/../keha-mcp/includes/abilities-ad.php';
keha_mcp_register_ad_abilities();
function check( $condition, $message ) {
	$GLOBALS['checks']++;
	if ( ! $condition ) { throw new RuntimeException( $message ); }
}
function ok( $r ) { check( ! is_wp_error( $r ), is_wp_error( $r ) ? $r->code . ': ' . $r->message : 'OK' ); return $r; }
function fails( $r, $code ) { check( is_wp_error( $r ) && $r->code === 'keha_' . $code, 'Expected ' . $code . ', got ' . ( is_wp_error( $r ) ? $r->code : json_encode( $r ) ) ); }
function call_tool( $name, $input = array() ) {
	$ability = $GLOBALS['abilities'][ 'keha/' . $name ];
	if ( ! call_user_func( $ability['permission_callback'] ) ) { return new WP_Error( 'keha_forbidden', 'Forbidden' ); }
	return call_user_func( $ability['execute_callback'], $input );
}
function remove_fixture( $path ) {
	// Only this test's randomly generated directory; never follow links.
	if ( is_link( $path ) || is_file( $path ) ) { unlink( $path ); return; }
	foreach ( new FilesystemIterator( $path, FilesystemIterator::SKIP_DOTS ) as $file ) { remove_fixture( $file->getPathname() ); }
	rmdir( $path );
}
try {
	$original = "const title='Prøve æøå';\n";
	file_put_contents( ABSPATH . 'ad/app.js', $original );
	if ( defined( 'DISALLOW_FILE_EDIT' ) || defined( 'DISALLOW_FILE_MODS' ) ) {
		fails( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => 'changed', 'expected_sha256' => hash( 'sha256', $original ) ) ), 'file_edit_disabled' );
		check( file_get_contents( ABSPATH . 'ad/app.js' ) === $original, 'Disabled writes changed original' );
		echo "PASS: WordPress file-edit/modification policy blocks AD writes\n";
	} else {
		check( count( $abilities ) === 6, 'Six abilities must register' );
		foreach ( $abilities as $name => $ability ) {
			check( in_array( $name, keha_mcp_ability_names(), true ), 'Ability missing from existing MCP endpoint' );
			check( false === $ability['input_schema']['additionalProperties'], 'Unexpected input parameters allowed' );
			check( true === $ability['meta']['show_in_rest'], 'Ability not discoverable in REST' );
		}
		$admin = false;
		foreach ( $abilities as $ability ) { check( false === call_user_func( $ability['permission_callback'] ), 'Non-admin has access' ); }
		$admin = true; $multisite = true;
		fails( call_tool( 'read-ad-file', array( 'path' => 'app.js' ) ), 'forbidden' );
		$super = true; ok( call_tool( 'read-ad-file', array( 'path' => 'app.js' ) ) );
		$multisite = false; $super = false;
		$read = ok( call_tool( 'read-ad-file', array( 'path' => 'app.js' ) ) );
		check( $read['sha256'] === hash( 'sha256', $original ) && false !== strpos( $read['content'], 'Prøve æøå' ), 'Read/hash/Unicode failed' );
		$write = ok( call_tool( 'edit-ad-file', array( 'path' => 'app.js', 'expected_sha256' => $read['sha256'], 'old' => 'Prøve æøå', 'new' => 'Rettet & klar' ) ) );
		check( 1 === $write['replaced'] && $write['backup'] && file_get_contents( ABSPATH . 'ad/app.js' ) === "const title='Rettet & klar';\n", 'Edit not applied' );
		check( 1 === $flushed, 'Cache flush missing' );
		$private = ok( keha_mcp_ad_backup_dir() );
		check( 0 !== strpos( $private, realpath( ABSPATH ) . DIRECTORY_SEPARATOR ), 'Backups stored in public root' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => 'stale', 'expected_sha256' => $read['sha256'] ) ), 'ad_conflict' );
		$restored = ok( call_tool( 'restore-ad-backup', array( 'backup' => $write['backup'], 'expected_sha256' => $write['sha256'] ) ) );
		check( $restored['backup'] && file_get_contents( ABSPATH . 'ad/app.js' ) === $original, 'Restore failed or failed to back up replaced version' );
		$backups = ok( call_tool( 'list-ad-backups', array( 'path' => 'app.js' ) ) );
		check( 2 === $backups['total'], 'Expected both versions in backup list' );
		check( ! isset( $backups['backups'][0]['content_base64'] ), 'Backup content unexpectedly listed' );
		$unchanged = ok( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => $original, 'expected_sha256' => $read['sha256'] ) ) );
		check( true === $unchanged['unchanged'] && null === $unchanged['backup'], 'No-op created backup' );
		$bad_backup = $private . '/' . $write['backup'] . '.json';
		$record = json_decode( file_get_contents( $bad_backup ), true ); $record['content_base64'] = base64_encode( 'tampered' );
		file_put_contents( $bad_backup, json_encode( $record ) );
		fails( call_tool( 'restore-ad-backup', array( 'backup' => $write['backup'], 'expected_sha256' => $read['sha256'] ) ), 'ad_backup_invalid' );
		check( file_get_contents( ABSPATH . 'ad/app.js' ) === $original, 'Corrupt backup changed file' );
		fails( call_tool( 'restore-ad-backup', array( 'backup' => '../app.js', 'expected_sha256' => $read['sha256'] ) ), 'ad_backup_id' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => 'x' ) ), 'ad_expected' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => 'x', 'expected_sha256' => 'missing' ) ), 'ad_conflict' );
		fails( call_tool( 'edit-ad-file', array( 'path' => 'app.js', 'old' => 'not present', 'new' => '', 'expected_sha256' => $read['sha256'] ) ), 'no_match' );
		fails( call_tool( 'edit-ad-file', array( 'path' => 'app.js', 'old' => '', 'new' => '', 'expected_sha256' => $read['sha256'] ) ), 'empty_old' );
		$multi = ok( call_tool( 'write-ad-file', array( 'path' => 'multi.txt', 'content' => 'one one', 'expected_sha256' => 'missing' ) ) );
		fails( call_tool( 'edit-ad-file', array( 'path' => 'multi.txt', 'old' => 'one', 'new' => 'two', 'expected_sha256' => $multi['sha256'] ) ), 'multiple_matches' );
		ok( call_tool( 'edit-ad-file', array( 'path' => 'multi.txt', 'old' => 'one', 'new' => 'two', 'replace_all' => true, 'expected_sha256' => $multi['sha256'] ) ) );
		check( 'two two' === file_get_contents( ABSPATH . 'ad/multi.txt' ), 'replace_all failed' );
		$binary = "PK\x03\x04\x00\xff binary fixture";
		ok( call_tool( 'write-ad-file', array( 'path' => 'skabelon.docx', 'encoding' => 'base64', 'content' => base64_encode( $binary ), 'expected_sha256' => 'missing' ) ) );
		$docx = ok( call_tool( 'read-ad-file', array( 'path' => 'skabelon.docx' ) ) );
		check( $binary === base64_decode( $docx['content'], true ), 'Binary round trip not byte exact' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'bad.docx', 'content' => 'not a docx', 'expected_sha256' => 'missing' ) ), 'ad_docx' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'bad.txt', 'content' => '%%%%', 'encoding' => 'base64', 'expected_sha256' => 'missing' ) ), 'ad_base64' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'bad.txt', 'content' => "bad\x00data", 'expected_sha256' => 'missing' ) ), 'ad_utf8' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'bad.txt', 'content' => "\xff", 'expected_sha256' => 'missing' ) ), 'ad_utf8' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'bad.json', 'content' => '{bad}', 'expected_sha256' => 'missing' ) ), 'ad_json' );
		ok( call_tool( 'write-ad-file', array( 'path' => 'assets/settings.json', 'content' => '{"name":"æøå"}', 'expected_sha256' => 'missing' ) ) );
		fails( call_tool( 'write-ad-file', array( 'path' => 'large.txt', 'content' => str_repeat( 'x', 2 * 1024 * 1024 + 1 ), 'expected_sha256' => 'missing' ) ), 'ad_size' );
		foreach ( array( '../wp-config.php', '/app.js', 'C:/app.js', 'assets/../app.js', 'assets//x.js', './app.js', '.htaccess', '.git/config.json', 'assets\\x.js', 'a%2fb.js', "a\0.js" ) as $path ) {
			fails( call_tool( 'write-ad-file', array( 'path' => $path, 'content' => 'x', 'expected_sha256' => 'missing' ) ), 'ad_path' );
		}
		foreach ( array( 'shell.php', 'shell.PHP.js', 'shell.phtml.html', 'file.phar.js', 'settings.ini' ) as $path ) {
			fails( call_tool( 'write-ad-file', array( 'path' => $path, 'content' => 'x', 'expected_sha256' => 'missing' ) ), 'ad_extension' );
		}
		fails( call_tool( 'write-ad-file', array( 'path' => 'absent/app.js', 'content' => 'x', 'expected_sha256' => 'missing' ) ), 'ad_path' );
		file_put_contents( ABSPATH . 'ad/.hidden.js', 'secret' );
		file_put_contents( ABSPATH . 'ad/blocked.php', 'secret' );
		file_put_contents( ABSPATH . 'ad/blocked.php.js', 'secret' );
		$list = ok( call_tool( 'list-ad-files' ) );
		$names = array_column( $list['files'], 'path' );
		check( ! in_array( '.hidden.js', $names, true ) && ! in_array( 'blocked.php', $names, true ) && ! in_array( 'blocked.php.js', $names, true ), 'Listing exposed blocked files' );
		check( in_array( 'assets/settings.json', $names, true ), 'Nested file missing' );
		if ( @link( ABSPATH . 'ad/app.js', ABSPATH . 'ad/hardlink.js' ) ) {
			fails( call_tool( 'read-ad-file', array( 'path' => 'hardlink.js' ) ), 'ad_path' );
			unlink( ABSPATH . 'ad/hardlink.js' ); clearstatcache();
		}
		if ( @symlink( $fixture, ABSPATH . 'ad/linked' ) ) {
			fails( call_tool( 'write-ad-file', array( 'path' => 'linked/outside.js', 'content' => 'escape', 'expected_sha256' => 'missing' ) ), 'ad_path' );
			unlink( ABSPATH . 'ad/linked' );
		} else { echo "NOTE: OS did not permit creating a symlink fixture\n"; }
		$lock = fopen( $private . '/write.lock', 'c+b' ); flock( $lock, LOCK_EX );
		fails( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => 'concurrent', 'expected_sha256' => $read['sha256'] ) ), 'ad_lock' );
		flock( $lock, LOCK_UN ); fclose( $lock );
		// Make the backup directory unavailable. No original data may be lost.
		rename( $private, $private . '-saved' ); file_put_contents( $private, 'occupied' );
		fails( call_tool( 'write-ad-file', array( 'path' => 'app.js', 'content' => 'must not save', 'expected_sha256' => $read['sha256'] ) ), 'ad_backup_dir' );
		check( file_get_contents( ABSPATH . 'ad/app.js' ) === $original, 'Backup failure changed original' );
		unlink( $private ); rename( $private . '-saved', $private );
		echo 'PASS: ' . $checks . " checks; permissions, real file edits, hashes/conflicts, backups/restore, corruption, DOCX bytes, path boundaries, locks and failure handling\n";
	}
} finally {
	remove_fixture( $fixture );
}
