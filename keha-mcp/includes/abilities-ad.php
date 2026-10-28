<?php
/**
 * Filer i WordPress-rodens /ad/: versionskontrol, privat backup og atomisk gem.
 * Roden kan ikke vælges af klienten. PHP, konfiguration, skjulte filer og links
 * er udelukket. Der er ingen slette- eller shellfunktion.
 */
defined( 'ABSPATH' ) || exit;

function keha_mcp_ad_extensions() {
	return array( 'html', 'css', 'js', 'json', 'txt', 'md', 'svg', 'docx' );
}

function keha_mcp_ad_root() {
	$web = realpath( ABSPATH );
	if ( ! $web || is_link( $web . '/ad' ) || ! is_dir( $web . '/ad' ) ) {
		return keha_mcp_error( 'ad_root', 'Den eksisterende /ad/-mappe skal ligge direkte i WordPress-roden og må ikke være et symbolsk link.' );
	}
	$root = realpath( $web . '/ad' );
	if ( ! $root || dirname( $root ) !== $web ) {
		return keha_mcp_error( 'ad_root', '/ad/-mappen kan ikke valideres.' );
	}
	return $root;
}

/** Kun relative ASCII-stier, ingen links (heller ikke i overmapper). */
function keha_mcp_ad_path( $root, $path, $must_exist = true ) {
	if ( ! is_string( $path ) || strlen( $path ) > 240 || ! preg_match( '~\A[A-Za-z0-9][A-Za-z0-9._-]*(?:/[A-Za-z0-9][A-Za-z0-9._-]*)*\z~D', $path ) ) {
		return keha_mcp_error( 'ad_path', 'Brug en relativ filsti i /ad/ uden skjulte navne, backslashes eller specialtegn.' );
	}
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, keha_mcp_ad_extensions(), true ) || preg_match( '~\.(?:php\d*|phtml|pht|phar|shtml|cgi|pl|py|asp|aspx)(?:\.|/|$)~i', $path ) ) {
		return keha_mcp_error( 'ad_extension', 'Kun html, css, js, json, txt, md, svg og docx er tilladt.' );
	}
	$full = $root;
	$parts = explode( '/', $path );
	foreach ( $parts as $i => $part ) {
		$full .= '/' . $part;
		if ( is_link( $full ) ) {
			return keha_mcp_error( 'ad_path', 'Symbolske links er ikke tilladt.' );
		}
		if ( $i < count( $parts ) - 1 && ! is_dir( $full ) ) {
			return keha_mcp_error( 'ad_path', 'Overmappen skal allerede eksistere.' );
		}
	}
	if ( file_exists( $full ) ) {
		$real = realpath( $full );
		if ( ! is_file( $full ) || ! $real || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) ) {
			return keha_mcp_error( 'ad_path', 'Stien skal pege på en almindelig fil i /ad/.' );
		}
		// Et hardlink kan ellers vise indhold fra en anden mappe.
		$stat = lstat( $full );
		if ( ! $stat || $stat['nlink'] > 1 ) {
			return keha_mcp_error( 'ad_path', 'Filer med flere hardlinks er ikke tilladt.' );
		}
	} elseif ( $must_exist ) {
		return keha_mcp_error( 'ad_not_found', 'Filen findes ikke: ' . $path, 404 );
	}
	return $full;
}

function keha_mcp_ad_read_bytes( $full ) {
	$size = filesize( $full );
	if ( false === $size || $size > 2 * 1024 * 1024 ) {
		return keha_mcp_error( 'ad_size', 'Filer må højst være 2 MiB.' );
	}
	$data = file_get_contents( $full ); // phpcs:ignore
	return false === $data ? keha_mcp_error( 'ad_read', 'Filen kunne ikke læses.' ) : $data;
}

/** Backups ligger uden for dokumentroden, ikke i offentlige uploads. */
function keha_mcp_ad_backup_dir() {
	$web = realpath( ABSPATH );
	if ( ! $web || dirname( $web ) === $web ) {
		return keha_mcp_error( 'ad_backup_dir', 'Kan ikke finde en privat backupmappe uden for WordPress-roden.' );
	}
	$dir = dirname( $web );
	foreach ( array( 'private-backups', 'keha-mcp-ad' ) as $part ) {
		$dir .= '/' . $part;
		if ( is_link( $dir ) || ( file_exists( $dir ) && ! is_dir( $dir ) ) ) {
			return keha_mcp_error( 'ad_backup_dir', 'Backupstien må ikke indeholde links eller filer.' );
		}
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700 ) ) {
			return keha_mcp_error( 'ad_backup_dir', 'Den private backupmappe kunne ikke oprettes. Kontrollér serverens skriverettigheder.' );
		}
	}
	$real = realpath( $dir );
	if ( ! $real || 0 === strpos( $real . DIRECTORY_SEPARATOR, $web . DIRECTORY_SEPARATOR ) ) {
		return keha_mcp_error( 'ad_backup_dir', 'Backups må ikke ligge i dokumentroden.' );
	}
	return $real;
}

/** Åbn med x, så eksisterende filer og links aldrig følges/overskrives. */
function keha_mcp_ad_create_private( $file, $data ) {
	$handle = fopen( $file, 'xb' ); // phpcs:ignore
	if ( ! $handle ) {
		return false;
	}
	$ok = chmod( $file, 0600 ) && fwrite( $handle, $data ) === strlen( $data ) && fflush( $handle );
	fclose( $handle );
	if ( ! $ok ) {
		unlink( $file );
	}
	return $ok;
}

function keha_mcp_ad_backup( $dir, $path, $data ) {
	$id = bin2hex( random_bytes( 16 ) );
	$record = array( 'version' => 1, 'path' => $path, 'sha256' => hash( 'sha256', $data ), 'saved_utc' => gmdate( 'c' ), 'content_base64' => base64_encode( $data ) );
	$json = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
	if ( false === $json || ! keha_mcp_ad_create_private( $dir . '/' . $id . '.json', $json ) ) {
		return keha_mcp_error( 'ad_backup_failed', 'Backup kunne ikke gemmes. Originalfilen er uændret.' );
	}
	return $id;
}

function keha_mcp_ad_load_backup( $dir, $id ) {
	if ( ! is_string( $id ) || ! preg_match( '/\A[a-f0-9]{32}\z/D', $id ) ) {
		return keha_mcp_error( 'ad_backup_id', 'Ugyldigt backup-id.' );
	}
	$file = $dir . '/' . $id . '.json';
	if ( is_link( $file ) || ! is_file( $file ) || filesize( $file ) > 3 * 1024 * 1024 ) {
		return keha_mcp_error( 'ad_backup_missing', 'Backuppen findes ikke eller er ugyldig.', 404 );
	}
	$record = json_decode( (string) file_get_contents( $file ), true ); // phpcs:ignore
	if ( ! is_array( $record ) || 1 !== ( $record['version'] ?? null ) || ! isset( $record['path'], $record['sha256'], $record['saved_utc'], $record['content_base64'] ) || ! is_string( $record['content_base64'] ) ) {
		return keha_mcp_error( 'ad_backup_invalid', 'Backuppens metadata er ugyldige.' );
	}
	$data = base64_decode( $record['content_base64'], true );
	if ( false === $data || strlen( $data ) > 2 * 1024 * 1024 || ! is_string( $record['sha256'] ) || ! hash_equals( $record['sha256'], hash( 'sha256', $data ) ) ) {
		return keha_mcp_error( 'ad_backup_invalid', 'Backuppens kontrolsum passer ikke.' );
	}
	$record['data'] = $data;
	return $record;
}

function keha_mcp_ad_validate_data( $path, $data ) {
	if ( strlen( $data ) > 2 * 1024 * 1024 ) {
		return keha_mcp_error( 'ad_size', 'Filer må højst være 2 MiB.' );
	}
	$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
	if ( 'docx' === $ext ) {
		if ( 0 !== strpos( $data, "PK\x03\x04" ) ) {
			return keha_mcp_error( 'ad_docx', 'En DOCX-fil skal være en OOXML ZIP-fil.' );
		}
	} elseif ( false !== strpos( $data, "\0" ) || 1 !== preg_match( '//u', $data ) ) {
		return keha_mcp_error( 'ad_utf8', 'Tekstfiler skal indeholde gyldig UTF-8 uden NUL-tegn.' );
	}
	if ( 'json' === $ext ) {
		json_decode( $data );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return keha_mcp_error( 'ad_json', 'Ugyldig JSON: ' . json_last_error_msg() );
		}
	}
	return true;
}

/** $transform kører under lås og modtager originalindholdet. */
function keha_mcp_ad_update( $path, $expected, $transform ) {
	$ok = keha_mcp_can_write_files();
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( ! is_string( $expected ) || ! preg_match( '/\A(?:[a-f0-9]{64}|missing)\z/D', $expected ) ) {
		return keha_mcp_error( 'ad_expected', 'expected_sha256 skal være den senest læste SHA-256 eller missing for en ny fil.' );
	}
	$root = keha_mcp_ad_root();
	if ( is_wp_error( $root ) ) {
		return $root;
	}
	$full = keha_mcp_ad_path( $root, $path, false );
	if ( is_wp_error( $full ) ) {
		return $full;
	}
	$dir = keha_mcp_ad_backup_dir();
	if ( is_wp_error( $dir ) ) {
		return $dir;
	}
	$lockfile = $dir . '/write.lock';
	if ( is_link( $lockfile ) ) {
		return keha_mcp_error( 'ad_lock', 'Låsefilen må ikke være et link.' );
	}
	$lock = fopen( $lockfile, 'c+b' ); // phpcs:ignore
	if ( ! $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
		if ( $lock ) {
			fclose( $lock );
		}
		return keha_mcp_error( 'ad_lock', 'En anden ændring er i gang. Prøv igen.', 409 );
	}
	$temp = null;
	try {
		clearstatcache();
		$full = keha_mcp_ad_path( $root, $path, false );
		if ( is_wp_error( $full ) ) {
			return $full;
		}
		$exists = is_file( $full );
		$old = $exists ? keha_mcp_ad_read_bytes( $full ) : '';
		if ( is_wp_error( $old ) ) {
			return $old;
		}
		$hash = $exists ? hash( 'sha256', $old ) : 'missing';
		if ( ! hash_equals( $hash, $expected ) ) {
			return keha_mcp_error( 'ad_conflict', 'Filen er ændret siden sidste læsning. Læs den igen før gem.', 409 );
		}
		$data = call_user_func( $transform, $old );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$ok = keha_mcp_ad_validate_data( $path, $data );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$result = array( 'path' => $path, 'sha256' => hash( 'sha256', $data ), 'bytes' => strlen( $data ), 'created' => ! $exists, 'backup' => null );
		if ( $exists && $data === $old ) {
			$result['unchanged'] = true;
			return $result;
		}
		if ( $exists ) {
			$result['backup'] = keha_mcp_ad_backup( $dir, $path, $old );
			if ( is_wp_error( $result['backup'] ) ) {
				return $result['backup'];
			}
		}
		$temp = dirname( $full ) . '/.keha-mcp-ad-' . bin2hex( random_bytes( 16 ) );
		if ( ! keha_mcp_ad_create_private( $temp, $data ) || ! chmod( $temp, $exists ? fileperms( $full ) & 0777 : 0644 ) ) {
			return keha_mcp_error( 'ad_write', 'Den nye fil kunne ikke klargøres. Originalen er uændret.' );
		}
		// Opdag også ændringer fra FTP/editor, mens backuppen blev gemt.
		clearstatcache();
		$check = keha_mcp_ad_path( $root, $path, false );
		if ( is_wp_error( $check ) || ( is_file( $full ) ? hash_file( 'sha256', $full ) : 'missing' ) !== $hash ) {
			return keha_mcp_error( 'ad_conflict', 'Filen blev ændret under gem. Læs den igen.', 409 );
		}
		if ( ! rename( $temp, $full ) ) {
			return keha_mcp_error( 'ad_write', 'Filen kunne ikke udskiftes atomisk. Originalen er uændret.' );
		}
		$temp = null;
		clearstatcache();
		keha_mcp_flush_caches();
		return $result;
	} finally {
		if ( $temp && is_file( $temp ) ) {
			unlink( $temp );
		}
		flock( $lock, LOCK_UN );
		fclose( $lock );
	}
}

function keha_mcp_ad_list( $input ) {
	$root = keha_mcp_ad_root();
	if ( is_wp_error( $root ) ) {
		return $root;
	}
	$in = keha_mcp_input( $input );
	$offset = max( 0, (int) ( $in['offset'] ?? 0 ) );
	$files = array();
	$it = new RecursiveIteratorIterator( new RecursiveCallbackFilterIterator( new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ), function ( $file ) {
		return ! $file->isLink() && '.' !== $file->getFilename()[0];
	} ) );
	$seen = 0;
	foreach ( $it as $file ) {
		if ( ++$seen > 2000 ) {
			return keha_mcp_error( 'ad_listing_limit', '/ad/ indeholder for mange filer til denne oversigt.' );
		}
		$rel = str_replace( DIRECTORY_SEPARATOR, '/', substr( $file->getPathname(), strlen( $root ) + 1 ) );
		$full = keha_mcp_ad_path( $root, $rel );
		if ( is_wp_error( $full ) ) {
			continue;
		}
		$files[] = array( 'path' => $rel, 'bytes' => $file->getSize(), 'sha256' => $file->getSize() <= 2 * 1024 * 1024 ? hash_file( 'sha256', $full ) : null );
	}
	usort( $files, function ( $a, $b ) { return strcmp( $a['path'], $b['path'] ); } );
	$total = count( $files );
	return array( 'directory' => '/ad/', 'files' => array_slice( $files, $offset, 100 ), 'total' => $total, 'next_offset' => $offset + 100 < $total ? $offset + 100 : null );
}

function keha_mcp_ad_read( $input ) {
	$in = keha_mcp_input( $input );
	$root = keha_mcp_ad_root();
	if ( is_wp_error( $root ) ) { return $root; }
	$full = keha_mcp_ad_path( $root, $in['path'] ?? '' );
	if ( is_wp_error( $full ) ) { return $full; }
	$data = keha_mcp_ad_read_bytes( $full );
	if ( is_wp_error( $data ) ) { return $data; }
	$result = array( 'path' => $in['path'], 'bytes' => strlen( $data ), 'sha256' => hash( 'sha256', $data ) );
	if ( 'docx' === strtolower( pathinfo( $full, PATHINFO_EXTENSION ) ) ) {
		$result['encoding'] = 'base64';
		$result['content'] = base64_encode( $data );
		return $result;
	}
	if ( 1 !== preg_match( '//u', $data ) ) { return keha_mcp_error( 'ad_utf8', 'Filen indeholder ikke gyldig UTF-8.' ); }
	$lines = preg_split( '/\r\n|\n|\r/', $data );
	$start = max( 1, (int) ( $in['start_line'] ?? 1 ) );
	$end = min( count( $lines ), (int) ( $in['end_line'] ?? count( $lines ) ), $start + 1999 );
	$out = array();
	$bytes = 0;
	$last = $start - 1;
	for ( $i = $start; $i <= $end; $i++ ) {
		$line = $i . "\t" . $lines[ $i - 1 ];
		if ( $bytes + strlen( $line ) > 64 * 1024 ) {
			if ( ! $out ) { return keha_mcp_error( 'ad_line_size', 'En enkelt linje er større end læsegrænsen på 64 KiB.' ); }
			break;
		}
		$out[] = $line;
		$bytes += strlen( $line ) + 1;
		$last = $i;
	}
	return array_merge( $result, array( 'encoding' => 'utf-8', 'total_lines' => count( $lines ), 'start_line' => $start, 'end_line' => $last, 'next_start' => $last < count( $lines ) ? $last + 1 : null, 'content' => implode( "\n", $out ) ) );
}

function keha_mcp_ad_write( $input ) {
	$in = keha_mcp_input( $input );
	$data = (string) ( $in['content'] ?? '' );
	$encoding = $in['encoding'] ?? 'utf-8';
	if ( ! in_array( $encoding, array( 'utf-8', 'base64' ), true ) ) { return keha_mcp_error( 'ad_encoding', 'encoding skal være utf-8 eller base64.' ); }
	if ( strlen( $data ) > 3 * 1024 * 1024 ) { return keha_mcp_error( 'ad_size', 'Input er for stort.' ); }
	if ( 'base64' === $encoding ) {
		$data = base64_decode( $data, true );
		if ( false === $data ) { return keha_mcp_error( 'ad_base64', 'Ugyldig base64.' ); }
	}
	return keha_mcp_ad_update( $in['path'] ?? '', $in['expected_sha256'] ?? '', function () use ( $data ) { return $data; } );
}

function keha_mcp_ad_edit( $input ) {
	$in = keha_mcp_input( $input );
	if ( 'docx' === strtolower( pathinfo( $in['path'] ?? '', PATHINFO_EXTENSION ) ) ) { return keha_mcp_error( 'ad_binary_edit', 'DOCX skal erstattes med write-ad-file og base64.' ); }
	$count = 0;
	$result = keha_mcp_ad_update( $in['path'] ?? '', $in['expected_sha256'] ?? '', function ( $old ) use ( $in, &$count ) {
		return keha_mcp_replace_once( $old, (string) ( $in['old'] ?? '' ), (string) ( $in['new'] ?? '' ), ! empty( $in['replace_all'] ), $count );
	} );
	if ( is_array( $result ) ) { $result['replaced'] = $count; }
	return $result;
}

function keha_mcp_ad_backups( $input ) {
	$in = keha_mcp_input( $input );
	$root = keha_mcp_ad_root();
	if ( is_wp_error( $root ) ) { return $root; }
	if ( isset( $in['path'] ) ) {
		$path = keha_mcp_ad_path( $root, $in['path'], false );
		if ( is_wp_error( $path ) ) { return $path; }
	}
	$dir = keha_mcp_ad_backup_dir();
	if ( is_wp_error( $dir ) ) { return $dir; }
	$ids = glob( $dir . '/*.json' );
	if ( count( $ids ?: array() ) > 5000 ) { return keha_mcp_error( 'ad_backup_limit', 'For mange backups til en samlet oversigt.' ); }
	$out = array();
	foreach ( $ids ?: array() as $file ) {
		$id = basename( $file, '.json' );
		$record = keha_mcp_ad_load_backup( $dir, $id );
		if ( is_wp_error( $record ) || ( isset( $in['path'] ) && $in['path'] !== $record['path'] ) ) { continue; }
		$out[] = array( 'backup' => $id, 'path' => $record['path'], 'sha256' => $record['sha256'], 'saved_utc' => $record['saved_utc'], 'bytes' => strlen( $record['data'] ) );
	}
	usort( $out, function ( $a, $b ) { return strcmp( $b['saved_utc'], $a['saved_utc'] ); } );
	$offset = max( 0, (int) ( $in['offset'] ?? 0 ) );
	return array( 'backups' => array_slice( $out, $offset, 100 ), 'total' => count( $out ), 'next_offset' => $offset + 100 < count( $out ) ? $offset + 100 : null );
}

function keha_mcp_ad_restore( $input ) {
	$in = keha_mcp_input( $input );
	$dir = keha_mcp_ad_backup_dir();
	if ( is_wp_error( $dir ) ) { return $dir; }
	$record = keha_mcp_ad_load_backup( $dir, $in['backup'] ?? '' );
	if ( is_wp_error( $record ) ) { return $record; }
	$result = keha_mcp_ad_update( $record['path'], $in['expected_sha256'] ?? '', function () use ( $record ) { return $record['data']; } );
	if ( is_array( $result ) ) { $result['restored_from'] = $in['backup']; }
	return $result;
}

function keha_mcp_register_ad_abilities() {
	$admin = function () { return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() ); };
	$path = array( 'type' => 'string', 'description' => 'Relativ filsti i /ad/, fx refinement.js. Ingen absolutte stier eller skjulte filer.' );
	$expected = array( 'type' => 'string', 'pattern' => '^(?:[a-f0-9]{64}|missing)$', 'description' => 'SHA-256 fra seneste læsning. Brug missing kun ved oprettelse af en ny fil.' );
	$offset = array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 );
	$definitions = array(
		'list-ad-files' => array( 'List filer i /ad/', 'Lister tilladte filer og SHA-256 i /ad/. Højst 100 pr. side.', 'keha_mcp_ad_list', array( 'offset' => $offset ), array(), true ),
		'read-ad-file' => array( 'Læs fil i /ad/', 'Læs tekst med linjenumre og SHA-256. DOCX returneres som base64. Brug SHA-256 ved efterfølgende gem.', 'keha_mcp_ad_read', array( 'path' => $path, 'start_line' => array( 'type' => 'integer', 'minimum' => 1 ), 'end_line' => array( 'type' => 'integer', 'minimum' => 1 ) ), array( 'path' ), true ),
		'write-ad-file' => array( 'Skriv fil i /ad/', 'Opret eller erstat fil (højst 2 MiB). Kræver aktuel SHA-256, tager privat backup og gemmer atomisk. DOCX kan sendes som base64. PHP og konfiguration er udelukket.', 'keha_mcp_ad_write', array( 'path' => $path, 'expected_sha256' => $expected, 'content' => array( 'type' => 'string' ), 'encoding' => array( 'type' => 'string', 'enum' => array( 'utf-8', 'base64' ), 'default' => 'utf-8' ) ), array( 'path', 'expected_sha256', 'content' ), false ),
		'edit-ad-file' => array( 'Redigér fil i /ad/', 'Erstat præcis tekst i en tekstfil. old skal matche én gang, medmindre replace_all=true. Kræver aktuel SHA-256 og tager privat backup.', 'keha_mcp_ad_edit', array( 'path' => $path, 'expected_sha256' => $expected, 'old' => array( 'type' => 'string', 'minLength' => 1 ), 'new' => array( 'type' => 'string' ), 'replace_all' => array( 'type' => 'boolean', 'default' => false ) ), array( 'path', 'expected_sha256', 'old', 'new' ), false ),
		'list-ad-backups' => array( 'List backups af /ad/', 'Lister private backups med id, filsti og SHA-256. Valgfrit path-filter og offset; højst 100 pr. side.', 'keha_mcp_ad_backups', array( 'path' => $path, 'offset' => $offset ), array(), true ),
		'restore-ad-backup' => array( 'Gendan fil i /ad/', 'Gendan en backup til dens oprindelige filsti. Kræver den nuværende fils SHA-256 og gemmer først den nuværende version som backup.', 'keha_mcp_ad_restore', array( 'backup' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{32}$' ), 'expected_sha256' => $expected ), array( 'backup', 'expected_sha256' ), false ),
	);
	foreach ( $definitions as $name => $def ) {
		$schema = array( 'type' => 'object', 'properties' => $def[3], 'additionalProperties' => false );
		if ( $def[4] ) { $schema['required'] = $def[4]; }
		keha_mcp_register( 'keha/' . $name, array( 'label' => $def[0], 'description' => $def[1], 'input_schema' => $schema, 'execute_callback' => $def[2], 'permission_callback' => $admin, 'readonly' => $def[5], 'destructive' => ! $def[5], 'idempotent' => $def[5], 'meta' => array( 'show_in_rest' => true, 'annotations' => array( 'readonly' => $def[5], 'destructive' => ! $def[5], 'idempotent' => $def[5] ) ) ) );
	}
}
