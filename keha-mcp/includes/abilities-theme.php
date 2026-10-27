<?php
/**
 * Temafiler: list, læs, skriv, redigér, backups og gendannelse.
 *
 * Sikkerhedsnet ved skrivning:
 *  1. Kun filer inde i temamappen og med tilladte endelser.
 *  2. PHP syntakstjekkes, før filen gemmes.
 *  3. Backup af den gamle version (wp-content/uploads/keha-mcp-backups, ikke offentligt læsbar).
 *  4. Efter skrivning af PHP i det aktive tema kaldes forsiden. Giver den en fatal fejl,
 *     rulles ændringen automatisk tilbage.
 */

defined( 'ABSPATH' ) || exit;

function keha_mcp_theme_extensions() {
	return array( 'php', 'css', 'js', 'json', 'html', 'htm', 'txt', 'md', 'svg', 'xml', 'twig' );
}

/** @return array|WP_Error array( 'slug', 'root' ) */
function keha_mcp_theme_root( $slug ) {
	$slug  = '' !== (string) $slug ? sanitize_key( $slug ) : get_stylesheet();
	$theme = wp_get_theme( $slug );
	if ( ! $theme->exists() ) {
		return keha_mcp_error( 'bad_theme', 'Temaet findes ikke: ' . $slug );
	}
	$root = realpath( $theme->get_stylesheet_directory() );
	if ( ! $root ) {
		return keha_mcp_error( 'bad_theme', 'Temamappen kan ikke findes.' );
	}
	return array( 'slug' => $slug, 'root' => wp_normalize_path( $root ) );
}

/**
 * Validerer en relativ sti og returnerer den fulde sti inde i temamappen.
 *
 * @return string|WP_Error
 */
function keha_mcp_theme_path( $root, $rel, $must_exist = true ) {
	$rel = ltrim( wp_normalize_path( (string) $rel ), '/' );
	if ( '' === $rel || false !== strpos( $rel, "\0" ) || preg_match( '#(^|/)\.\.?(/|$)#', $rel ) ) {
		return keha_mcp_error( 'bad_path', 'Ugyldig sti. Brug en relativ sti inde i temaet, fx "functions.php" eller "assets/css/main.css".' );
	}
	foreach ( explode( '/', $rel ) as $part ) {
		if ( '' === $part || '.' === $part[0] ) {
			return keha_mcp_error( 'bad_path', 'Skjulte filer og mapper er ikke tilladt.' );
		}
	}
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	if ( ! in_array( $ext, keha_mcp_theme_extensions(), true ) ) {
		return keha_mcp_error( 'bad_ext', 'Filtypen er ikke tilladt. Tilladt: ' . implode( ', ', keha_mcp_theme_extensions() ) . '.' );
	}
	$full = $root . '/' . $rel;
	if ( file_exists( $full ) ) {
		$real = wp_normalize_path( (string) realpath( $full ) );
		if ( 0 !== strpos( $real, $root . '/' ) || is_link( $full ) ) {
			return keha_mcp_error( 'bad_path', 'Stien peger uden for temaet.' );
		}
		if ( ! is_file( $real ) ) {
			return keha_mcp_error( 'bad_path', 'Stien er ikke en fil.' );
		}
		return $real;
	}
	if ( $must_exist ) {
		return keha_mcp_error( 'not_found', 'Filen findes ikke: ' . $rel, 404 );
	}
	return $full;
}

function keha_mcp_can_write_files() {
	if ( defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ) {
		return keha_mcp_error( 'file_edit_disabled', 'Filredigering er slået fra med DISALLOW_FILE_EDIT i wp-config.php.', 403 );
	}
	if ( defined( 'DISALLOW_FILE_MODS' ) && DISALLOW_FILE_MODS ) {
		return keha_mcp_error( 'file_edit_disabled', 'Filændringer er slået fra med DISALLOW_FILE_MODS i wp-config.php.', 403 );
	}
	return true;
}

/* -------------------------------------------------------------------------
 * Backups
 * ---------------------------------------------------------------------- */

function keha_mcp_backup_dir( $slug ) {
	$up   = wp_upload_dir( null, false );
	$base = wp_normalize_path( $up['basedir'] ) . '/keha-mcp-backups';
	if ( ! is_dir( $base ) ) {
		wp_mkdir_p( $base );
		file_put_contents( $base . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
		file_put_contents( $base . '/index.php', "<?php\n// Silence.\n" ); // phpcs:ignore
	}
	$dir = $base . '/' . sanitize_key( $slug );
	wp_mkdir_p( $dir );
	return $dir;
}

function keha_mcp_backup_prefix( $rel ) {
	return str_replace( '/', '__', $rel ) . '.';
}

/** @return string Backup-id (filnavn). */
function keha_mcp_make_backup( $slug, $rel, $full ) {
	$dir = keha_mcp_backup_dir( $slug );
	$id  = keha_mcp_backup_prefix( $rel ) . gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 4, false ) . '.bak';
	copy( $full, $dir . '/' . $id );

	// Behold de 20 nyeste pr. fil.
	$all = glob( $dir . '/' . keha_mcp_glob_escape( keha_mcp_backup_prefix( $rel ) ) . '*.bak' );
	if ( $all && count( $all ) > 20 ) {
		sort( $all );
		foreach ( array_slice( $all, 0, count( $all ) - 20 ) as $old ) {
			wp_delete_file( $old );
		}
	}
	return $id;
}

function keha_mcp_glob_escape( $s ) {
	return preg_replace( '/([\*\?\[\]])/', '[$1]', $s );
}

/* -------------------------------------------------------------------------
 * Skrivning med sikkerhedsnet
 * ---------------------------------------------------------------------- */

/** Fanger syntaksfejl i PHP uden at køre koden. */
function keha_mcp_php_syntax_error( $code ) {
	try {
		token_get_all( $code, TOKEN_PARSE );
	} catch ( ParseError $e ) {
		return sprintf( 'linje %d: %s', $e->getLine(), $e->getMessage() );
	}
	return null;
}

/**
 * Kalder forsiden og ser efter fatale fejl.
 *
 * @return array( 'state' => ok|broken|unknown, 'detail' => string )
 */
function keha_mcp_site_health() {
	$url = add_query_arg( 'keha_mcp_check', wp_generate_password( 8, false ), home_url( '/' ) );
	$res = wp_remote_get( $url, array(
		'timeout'     => 20,
		'redirection' => 3,
		'sslverify'   => apply_filters( 'https_local_ssl_verify', false ),
		'headers'     => array( 'Cache-Control' => 'no-cache' ),
	) );
	if ( is_wp_error( $res ) ) {
		return array( 'state' => 'unknown', 'detail' => 'Forsiden kunne ikke kaldes: ' . $res->get_error_message() );
	}
	$code = (int) wp_remote_retrieve_response_code( $res );
	$body = (string) wp_remote_retrieve_body( $res );
	$bad  = $code >= 500
		|| false !== stripos( $body, 'There has been a critical error' )
		|| false !== stripos( $body, 'Der er opstået en kritisk fejl' )
		|| false !== stripos( $body, 'Fatal error</b>' )
		|| false !== stripos( $body, 'Parse error</b>' );
	if ( $bad ) {
		$snippet = trim( wp_strip_all_tags( substr( $body, 0, 1500 ) ) );
		return array( 'state' => 'broken', 'detail' => sprintf( 'HTTP %d. %s', $code, mb_substr( $snippet, 0, 400 ) ) );
	}
	return array( 'state' => 'ok', 'detail' => 'HTTP ' . $code );
}

/** @return array|WP_Error */
function keha_mcp_theme_write( array $theme, $rel, $full, $content ) {
	$ok = keha_mcp_can_write_files();
	if ( is_wp_error( $ok ) ) {
		return $ok;
	}
	if ( strlen( $content ) > 1024 * 1024 ) {
		return keha_mcp_error( 'too_large', 'Filen må højst være 1 MB.' );
	}
	$ext = strtolower( pathinfo( $rel, PATHINFO_EXTENSION ) );
	if ( 'php' === $ext ) {
		$err = keha_mcp_php_syntax_error( $content );
		if ( $err ) {
			return keha_mcp_error( 'php_syntax', 'PHP-syntaksfejl – filen er IKKE gemt (' . $err . ').' );
		}
	}
	if ( 'json' === $ext && null === json_decode( $content ) && 'null' !== trim( $content ) ) {
		return keha_mcp_error( 'json_syntax', 'Ugyldig JSON – filen er IKKE gemt (' . json_last_error_msg() . ').' );
	}

	$existed = file_exists( $full );
	$backup  = $existed ? keha_mcp_make_backup( $theme['slug'], $rel, $full ) : null;

	if ( ! $existed ) {
		$dir = dirname( $full );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return keha_mcp_error( 'mkdir_failed', 'Kunne ikke oprette mappen.' );
		}
		$real_dir = wp_normalize_path( (string) realpath( $dir ) );
		if ( $real_dir !== $theme['root'] && 0 !== strpos( $real_dir . '/', $theme['root'] . '/' ) ) {
			return keha_mcp_error( 'bad_path', 'Stien peger uden for temaet.' );
		}
	}

	if ( false === file_put_contents( $full, $content, LOCK_EX ) ) { // phpcs:ignore
		return keha_mcp_error( 'write_failed', 'Kunne ikke skrive filen (filrettigheder på serveren?).' );
	}
	if ( function_exists( 'opcache_invalidate' ) ) {
		@opcache_invalidate( $full, true ); // phpcs:ignore
	}

	$result = array(
		'path'    => $rel,
		'theme'   => $theme['slug'],
		'bytes'   => strlen( $content ),
		'created' => ! $existed,
		'backup'  => $backup,
	);

	$active = in_array( $theme['slug'], array( get_stylesheet(), get_template() ), true );
	if ( 'php' === $ext && $active ) {
		$health           = keha_mcp_site_health();
		$result['health'] = $health;
		if ( 'broken' === $health['state'] ) {
			if ( $backup ) {
				copy( keha_mcp_backup_dir( $theme['slug'] ) . '/' . $backup, $full );
			} else {
				wp_delete_file( $full );
			}
			if ( function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $full, true ); // phpcs:ignore
			}
			return keha_mcp_error( 'rolled_back', 'Ændringen gav en fatal fejl på forsiden og er rullet tilbage. Detaljer: ' . $health['detail'], 500 );
		}
	}

	keha_mcp_flush_caches();
	return $result;
}

/* -------------------------------------------------------------------------
 * Registrering
 * ---------------------------------------------------------------------- */

function keha_mcp_register_theme_abilities() {

	$admin = function () {
		return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
	};
	$theme_prop = array( 'type' => 'string', 'description' => 'Temaets mappenavn. Standard: det aktive tema.' );

	/* --- list-theme-files --- */
	keha_mcp_register( 'keha/list-theme-files', array(
		'label'               => 'List temafiler',
		'description'         => 'Lister filer i temaet (relative stier og størrelser). pattern filtrerer, fx "*.php" eller "template-parts/*".',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'theme'   => $theme_prop,
				'pattern' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = keha_mcp_theme_root( $in['theme'] ?? '' );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$pattern = isset( $in['pattern'] ) ? (string) $in['pattern'] : '';
			$files   = array();
			$it      = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $theme['root'], FilesystemIterator::SKIP_DOTS ),
					function ( $f ) {
						$n = $f->getFilename();
						return '.' !== $n[0] && 'node_modules' !== $n && ! $f->isLink();
					}
				)
			);
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$rel = ltrim( substr( wp_normalize_path( $f->getPathname() ), strlen( $theme['root'] ) ), '/' );
				if ( '' !== $pattern && ! fnmatch( $pattern, $rel ) && ! fnmatch( $pattern, basename( $rel ) ) ) {
					continue;
				}
				$files[] = array( 'path' => $rel, 'size' => $f->getSize(), 'editable' => in_array( strtolower( $f->getExtension() ), keha_mcp_theme_extensions(), true ) );
				if ( count( $files ) >= 1000 ) {
					break;
				}
			}
			usort( $files, function ( $a, $b ) {
				return strcmp( $a['path'], $b['path'] );
			} );
			return array( 'theme' => $theme['slug'], 'active' => get_stylesheet(), 'files' => $files );
		},
		'permission_callback' => $admin,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- read-theme-file --- */
	keha_mcp_register( 'keha/read-theme-file', array(
		'label'               => 'Læs temafil',
		'description'         => 'Læser en temafil med linjenumre (linjenummer + tab + indhold). Store filer læses i bidder med start_line/end_line. Kopiér tekst uden linjenumre, når du bruger edit-theme-file.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'path' ),
			'properties' => array(
				'theme'        => $theme_prop,
				'path'         => array( 'type' => 'string' ),
				'start_line'   => array( 'type' => 'integer', 'minimum' => 1 ),
				'end_line'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'line_numbers' => array( 'type' => 'boolean', 'default' => true ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = keha_mcp_theme_root( $in['theme'] ?? '' );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$full = keha_mcp_theme_path( $theme['root'], $in['path'] ?? '' );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			$lines = preg_split( "/\r\n|\n|\r/", (string) file_get_contents( $full ) ); // phpcs:ignore
			$total = count( $lines );
			$start = max( 1, (int) ( $in['start_line'] ?? 1 ) );
			$end   = min( $total, (int) ( $in['end_line'] ?? $total ), $start + 1999 );
			$num   = ! isset( $in['line_numbers'] ) || $in['line_numbers'];
			$out   = array();
			for ( $i = $start; $i <= $end; $i++ ) {
				$out[] = $num ? $i . "\t" . $lines[ $i - 1 ] : $lines[ $i - 1 ];
			}
			return array(
				'path'        => ltrim( wp_normalize_path( (string) $in['path'] ), '/' ),
				'theme'       => $theme['slug'],
				'total_lines' => $total,
				'start_line'  => $start,
				'end_line'    => $end,
				'next_start'  => $end < $total ? $end + 1 : null,
				'content'     => implode( "\n", $out ),
			);
		},
		'permission_callback' => $admin,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- write-theme-file --- */
	keha_mcp_register( 'keha/write-theme-file', array(
		'label'               => 'Skriv temafil',
		'description'         => 'Opretter eller overskriver en hel temafil. Den gamle version gemmes som backup. PHP syntakstjekkes, og hvis forsiden derefter giver en fatal fejl, rulles ændringen automatisk tilbage. Til mindre ændringer: brug edit-theme-file.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'path', 'content' ),
			'properties' => array(
				'theme'   => $theme_prop,
				'path'    => array( 'type' => 'string' ),
				'content' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = keha_mcp_theme_root( $in['theme'] ?? '' );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$full = keha_mcp_theme_path( $theme['root'], $in['path'] ?? '', false );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			$rel = ltrim( wp_normalize_path( (string) $in['path'] ), '/' );
			return keha_mcp_theme_write( $theme, $rel, $full, (string) $in['content'] );
		},
		'permission_callback' => $admin,
		'destructive'         => true,
		'idempotent'          => true,
	) );

	/* --- edit-theme-file --- */
	keha_mcp_register( 'keha/edit-theme-file', array(
		'label'               => 'Redigér temafil',
		'description'         => 'Erstatter et præcist tekststykke i en temafil (uden linjenumre). old skal forekomme præcis én gang, medmindre replace_all=true. Samme sikkerhedsnet som write-theme-file.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'path', 'old', 'new' ),
			'properties' => array(
				'theme'       => $theme_prop,
				'path'        => array( 'type' => 'string' ),
				'old'         => array( 'type' => 'string', 'minLength' => 1 ),
				'new'         => array( 'type' => 'string' ),
				'replace_all' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = keha_mcp_theme_root( $in['theme'] ?? '' );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$full = keha_mcp_theme_path( $theme['root'], $in['path'] ?? '' );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			$count = 0;
			$new   = keha_mcp_replace_once( (string) file_get_contents( $full ), (string) $in['old'], (string) $in['new'], ! empty( $in['replace_all'] ), $count ); // phpcs:ignore
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$rel = ltrim( wp_normalize_path( (string) $in['path'] ), '/' );
			$r   = keha_mcp_theme_write( $theme, $rel, $full, $new );
			if ( is_array( $r ) ) {
				$r['replaced'] = $count;
			}
			return $r;
		},
		'permission_callback' => $admin,
		'destructive'         => true,
	) );

	/* --- list-theme-backups --- */
	keha_mcp_register( 'keha/list-theme-backups', array(
		'label'               => 'List backups af temafil',
		'description'         => 'Viser de gemte backups (nyeste først) for en temafil – eller for hele temaet, hvis path udelades.',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'theme' => $theme_prop,
				'path'  => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = keha_mcp_theme_root( $in['theme'] ?? '' );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$dir    = keha_mcp_backup_dir( $theme['slug'] );
			$prefix = ! empty( $in['path'] ) ? keha_mcp_glob_escape( keha_mcp_backup_prefix( ltrim( wp_normalize_path( (string) $in['path'] ), '/' ) ) ) : '';
			$files  = glob( $dir . '/' . $prefix . '*.bak' );
			$files  = $files ? $files : array();
			rsort( $files );
			$out = array();
			foreach ( array_slice( $files, 0, 100 ) as $f ) {
				$id    = basename( $f );
				$out[] = array(
					'backup' => $id,
					'path'   => str_replace( '__', '/', preg_replace( '/\.\d{8}-\d{6}-[A-Za-z0-9]{4}\.bak$/', '', $id ) ),
					'size'   => filesize( $f ),
					'saved'  => get_date_from_gmt( gmdate( 'Y-m-d H:i:s', filemtime( $f ) ) ),
				);
			}
			return array( 'theme' => $theme['slug'], 'backups' => $out );
		},
		'permission_callback' => $admin,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- restore-theme-backup --- */
	keha_mcp_register( 'keha/restore-theme-backup', array(
		'label'               => 'Gendan temafil fra backup',
		'description'         => 'Gendanner en temafil fra en backup (id fra list-theme-backups). Den nuværende version gemmes selv som backup først.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'backup' ),
			'properties' => array(
				'theme'  => $theme_prop,
				'backup' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = keha_mcp_theme_root( $in['theme'] ?? '' );
			if ( is_wp_error( $theme ) ) {
				return $theme;
			}
			$id = basename( (string) ( $in['backup'] ?? '' ) );
			if ( ! preg_match( '/^(.+)\.\d{8}-\d{6}-[A-Za-z0-9]{4}\.bak$/', $id, $m ) ) {
				return keha_mcp_error( 'bad_backup', 'Ugyldigt backup-id.' );
			}
			$src = keha_mcp_backup_dir( $theme['slug'] ) . '/' . $id;
			if ( ! is_file( $src ) ) {
				return keha_mcp_error( 'not_found', 'Backuppen findes ikke.', 404 );
			}
			$rel  = str_replace( '__', '/', $m[1] );
			$full = keha_mcp_theme_path( $theme['root'], $rel, false );
			if ( is_wp_error( $full ) ) {
				return $full;
			}
			$r = keha_mcp_theme_write( $theme, $rel, $full, (string) file_get_contents( $src ) ); // phpcs:ignore
			if ( is_array( $r ) ) {
				$r['restored_from'] = $id;
			}
			return $r;
		},
		'permission_callback' => $admin,
		'destructive'         => true,
	) );
}
