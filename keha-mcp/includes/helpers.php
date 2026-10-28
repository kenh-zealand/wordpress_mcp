<?php
/**
 * Fælles hjælpefunktioner.
 */

defined( 'ABSPATH' ) || exit;

/**
 * Alle værktøjer, MCP-serveren eksponerer. Rækkefølgen er den, klienten ser.
 */
function keha_mcp_ability_names() {
	return array(
		// Overblik og indhold.
		'keha/site-info',
		'keha/list-content',
		'keha/get-content',
		'keha/create-content',
		'keha/update-content',
		'keha/replace-in-content',
		'keha/set-status',
		'keha/trash-content',
		'keha/restore-content',
		'keha/list-terms',
		'keha/create-term',
		// Medier.
		'keha/list-media',
		'keha/upload-media-from-url',
		'keha/upload-media-base64',
		'keha/update-media',
		'keha/set-featured-image',
		// Temafiler.
		'keha/list-theme-files',
		'keha/read-theme-file',
		'keha/write-theme-file',
		'keha/edit-theme-file',
		'keha/list-theme-backups',
		'keha/restore-theme-backup',
		// Afgrænset filadgang til Aktivitetsdesigner.
		'keha/list-ad-files',
		'keha/read-ad-file',
		'keha/write-ad-file',
		'keha/edit-ad-file',
		'keha/list-ad-backups',
		'keha/restore-ad-backup',
	);
}

/**
 * Registrerer en ability med fælles standardværdier.
 *
 * @param string $name Ability-navn, fx keha/list-content.
 * @param array  $args label, description, input_schema, execute_callback,
 *                     permission_callback, readonly, destructive, idempotent.
 */
function keha_mcp_register( $name, array $args ) {
	$annotations = array(
		'readonly'    => ! empty( $args['readonly'] ),
		'destructive' => ! empty( $args['destructive'] ),
		'idempotent'  => ! empty( $args['idempotent'] ),
	);
	unset( $args['readonly'], $args['destructive'], $args['idempotent'] );

	$args = array_merge(
		array(
			'category'      => 'keha',
			'output_schema' => array( 'type' => 'object' ),
			'meta'          => array( 'annotations' => $annotations ),
		),
		$args
	);

	wp_register_ability( $name, $args );
}

/**
 * Gør ability-input til et array uanset hvad adapteren sender.
 */
function keha_mcp_input( $input ) {
	if ( is_array( $input ) ) {
		return $input;
	}
	if ( is_object( $input ) ) {
		return json_decode( wp_json_encode( $input ), true );
	}
	return array();
}

/**
 * Fejl med dansk besked, som AI'en kan handle på.
 */
function keha_mcp_error( $code, $message, $status = 400 ) {
	return new WP_Error( 'keha_' . $code, $message, array( 'status' => $status ) );
}

/**
 * Tømmer kendte sidecaches (W3 Total Cache m.fl.) efter ændringer i temaet.
 */
function keha_mcp_flush_caches() {
	if ( function_exists( 'w3tc_flush_all' ) ) {
		w3tc_flush_all();
	}
	if ( function_exists( 'wp_cache_flush' ) ) {
		wp_cache_flush();
	}
}

/**
 * Antal forekomster + sikker erstatning (match-once, medmindre replace_all).
 *
 * @return string|WP_Error Ny tekst.
 */
function keha_mcp_replace_once( $haystack, $old, $new, $replace_all, &$count = 0 ) {
	if ( '' === $old ) {
		return keha_mcp_error( 'empty_old', 'old må ikke være tom.' );
	}
	$count = substr_count( $haystack, $old );
	if ( 0 === $count ) {
		return keha_mcp_error( 'no_match', 'Teksten i old blev ikke fundet. Hent indholdet igen og kopiér et uddrag præcist (inkl. mellemrum og citationstegn).' );
	}
	if ( $count > 1 && ! $replace_all ) {
		return keha_mcp_error( 'multiple_matches', sprintf( 'old forekommer %d gange. Tilføj mere omkringliggende tekst, så den er unik, eller sæt replace_all=true.', $count ) );
	}
	return str_replace( $old, $new, $haystack );
}
