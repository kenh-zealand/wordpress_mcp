<?php
/**
 * Medier: list, upload (URL eller base64), metadata og udvalgt billede.
 */

defined( 'ABSPATH' ) || exit;

function keha_mcp_format_media( WP_Post $a ) {
	$meta  = wp_get_attachment_metadata( $a->ID );
	$sizes = array();
	if ( wp_attachment_is_image( $a->ID ) ) {
		foreach ( array( 'thumbnail', 'medium', 'large', 'full' ) as $size ) {
			$src = wp_get_attachment_image_src( $a->ID, $size );
			if ( $src ) {
				$sizes[ $size ] = $src[0];
			}
		}
	}
	return array(
		'id'          => $a->ID,
		'title'       => $a->post_title,
		'url'         => wp_get_attachment_url( $a->ID ),
		'mime'        => $a->post_mime_type,
		'alt'         => (string) get_post_meta( $a->ID, '_wp_attachment_image_alt', true ),
		'caption'     => $a->post_excerpt,
		'description' => $a->post_content,
		'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
		'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
		'sizes'       => $sizes,
		'attached_to' => (int) $a->post_parent,
		'date'        => $a->post_date,
	);
}

/** Giver en fil uden filendelse den rigtige endelse ud fra indholdet. */
function keha_mcp_guess_filename( $tmp, $name ) {
	$name = sanitize_file_name( $name );
	if ( '' !== $name && pathinfo( $name, PATHINFO_EXTENSION ) ) {
		return $name;
	}
	$base = '' !== $name ? $name : 'upload-' . gmdate( 'Ymd-His' );
	$mime = function_exists( 'wp_get_image_mime' ) ? wp_get_image_mime( $tmp ) : false;
	$map  = array(
		'image/jpeg' => 'jpg',
		'image/png'  => 'png',
		'image/gif'  => 'gif',
		'image/webp' => 'webp',
		'image/avif' => 'avif',
	);
	if ( $mime && isset( $map[ $mime ] ) ) {
		return $base . '.' . $map[ $mime ];
	}
	$head = (string) file_get_contents( $tmp, false, null, 0, 512 ); // phpcs:ignore
	if ( false !== stripos( $head, '<svg' ) ) {
		return $base . '.svg';
	}
	if ( 0 === strpos( $head, '%PDF' ) ) {
		return $base . '.pdf';
	}
	return $base;
}

/** Fælles sideload fra en midlertidig fil. */
function keha_mcp_sideload( $tmp, $filename, array $in ) {
	require_once ABSPATH . 'wp-admin/includes/file.php';
	require_once ABSPATH . 'wp-admin/includes/media.php';
	require_once ABSPATH . 'wp-admin/includes/image.php';

	$post_id = isset( $in['attach_to'] ) ? (int) $in['attach_to'] : 0;
	if ( $post_id && ! current_user_can( 'edit_post', $post_id ) ) {
		wp_delete_file( $tmp );
		return keha_mcp_error( 'forbidden', 'Ingen adgang til at vedhæfte til det indlæg.', 403 );
	}

	$file = array(
		'name'     => keha_mcp_guess_filename( $tmp, $filename ),
		'tmp_name' => $tmp,
	);
	$post_data = array();
	if ( isset( $in['title'] ) ) {
		$post_data['post_title'] = sanitize_text_field( $in['title'] );
	}
	if ( isset( $in['caption'] ) ) {
		$post_data['post_excerpt'] = wp_kses_post( $in['caption'] );
	}
	if ( isset( $in['description'] ) ) {
		$post_data['post_content'] = wp_kses_post( $in['description'] );
	}

	$id = media_handle_sideload( $file, $post_id, null, $post_data );
	if ( is_wp_error( $id ) ) {
		wp_delete_file( $tmp );
		return keha_mcp_error( 'upload_failed', 'Upload mislykkedes: ' . $id->get_error_message() );
	}
	if ( isset( $in['alt'] ) ) {
		update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $in['alt'] ) );
	}
	if ( $post_id && ! empty( $in['set_featured'] ) && wp_attachment_is_image( $id ) ) {
		set_post_thumbnail( $post_id, $id );
	}
	return keha_mcp_format_media( get_post( $id ) );
}

function keha_mcp_media_meta_props() {
	return array(
		'title'        => array( 'type' => 'string' ),
		'alt'          => array( 'type' => 'string', 'description' => 'Alternativ tekst (tilgængelighed). Bør altid udfyldes for billeder.' ),
		'caption'      => array( 'type' => 'string' ),
		'description'  => array( 'type' => 'string' ),
		'attach_to'    => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Indlæg/side som mediet tilknyttes.' ),
		'set_featured' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Sæt som udvalgt billede på attach_to.' ),
	);
}

function keha_mcp_register_media_abilities() {

	$can_upload = function () {
		return current_user_can( 'upload_files' );
	};

	/* --- list-media --- */
	keha_mcp_register( 'keha/list-media', array(
		'label'               => 'List medier',
		'description'         => 'Søger i mediebiblioteket. Returnerer id, URL, alt-tekst, størrelser m.m.',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'search'    => array( 'type' => 'string' ),
				'mime'      => array( 'type' => 'string', 'description' => 'Fx "image", "image/png" eller "application/pdf".' ),
				'attach_to' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Kun medier tilknyttet dette indlæg.' ),
				'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$args = array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => min( 100, max( 1, (int) ( $in['limit'] ?? 20 ) ) ),
				'paged'          => max( 1, (int) ( $in['page'] ?? 1 ) ),
				'orderby'        => 'date',
				'order'          => 'DESC',
			);
			if ( ! empty( $in['search'] ) ) {
				$args['s'] = (string) $in['search'];
			}
			if ( ! empty( $in['mime'] ) ) {
				$args['post_mime_type'] = (string) $in['mime'];
			}
			if ( isset( $in['attach_to'] ) ) {
				$args['post_parent'] = (int) $in['attach_to'];
			}
			$q = new WP_Query( $args );
			return array(
				'items' => array_map( 'keha_mcp_format_media', $q->posts ),
				'total' => (int) $q->found_posts,
			);
		},
		'permission_callback' => $can_upload,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- upload-media-from-url --- */
	$url_props = keha_mcp_media_meta_props() + array(
		'url'      => array( 'type' => 'string', 'description' => 'Offentlig http(s)-adresse til filen.' ),
		'filename' => array( 'type' => 'string', 'description' => 'Valgfrit filnavn, fx "forside-hero.jpg".' ),
	);
	keha_mcp_register( 'keha/upload-media-from-url', array(
		'label'               => 'Upload medie fra URL',
		'description'         => 'Henter en fil fra en offentlig URL og lægger den i mediebiblioteket. Kan tilknytte den til et indlæg og sætte den som udvalgt billede.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'url' ),
			'properties' => $url_props,
		),
		'execute_callback'    => function ( $input ) {
			$in  = keha_mcp_input( $input );
			$url = esc_url_raw( (string) ( $in['url'] ?? '' ), array( 'http', 'https' ) );
			if ( '' === $url ) {
				return keha_mcp_error( 'bad_url', 'Ugyldig URL.' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = download_url( $url, 60 ); // Bruger wp_safe_remote_get – interne adresser afvises.
			if ( is_wp_error( $tmp ) ) {
				return keha_mcp_error( 'download_failed', 'Kunne ikke hente filen: ' . $tmp->get_error_message() );
			}
			$name = ! empty( $in['filename'] ) ? (string) $in['filename'] : rawurldecode( basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			return keha_mcp_sideload( $tmp, $name, $in );
		},
		'permission_callback' => $can_upload,
	) );

	/* --- upload-media-base64 --- */
	$b64_props = keha_mcp_media_meta_props() + array(
		'filename' => array( 'type' => 'string', 'description' => 'Filnavn med endelse, fx "diagram.svg".' ),
		'data'     => array( 'type' => 'string', 'description' => 'Filens indhold som base64 (evt. med data:...;base64,-præfiks). Maks. 10 MB.' ),
	);
	keha_mcp_register( 'keha/upload-media-base64', array(
		'label'               => 'Upload medie (base64)',
		'description'         => 'Uploader en fil, du selv har lavet (fx en SVG-illustration eller et genereret billede), som base64.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'filename', 'data' ),
			'properties' => $b64_props,
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$data = (string) ( $in['data'] ?? '' );
			if ( preg_match( '/^data:[^;,]*;base64,/i', $data, $m ) ) {
				$data = substr( $data, strlen( $m[0] ) );
			}
			$data = preg_replace( '/\s+/', '', $data );
			if ( strlen( $data ) > 14 * 1024 * 1024 ) {
				return keha_mcp_error( 'too_large', 'Filen er for stor (maks. ca. 10 MB). Brug upload-media-from-url.' );
			}
			$bytes = base64_decode( $data, true );
			if ( false === $bytes || '' === $bytes ) {
				return keha_mcp_error( 'bad_base64', 'data er ikke gyldig base64.' );
			}
			require_once ABSPATH . 'wp-admin/includes/file.php';
			$tmp = wp_tempnam( (string) $in['filename'] );
			if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore
				return keha_mcp_error( 'tmp_failed', 'Kunne ikke skrive midlertidig fil på serveren.' );
			}
			return keha_mcp_sideload( $tmp, (string) $in['filename'], $in );
		},
		'permission_callback' => $can_upload,
	) );

	/* --- update-media --- */
	keha_mcp_register( 'keha/update-media', array(
		'label'               => 'Opdater medie',
		'description'         => 'Ændrer titel, alt-tekst, billedtekst eller beskrivelse på et eksisterende medie.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'title'       => array( 'type' => 'string' ),
				'alt'         => array( 'type' => 'string' ),
				'caption'     => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in = keha_mcp_input( $input );
			$a  = get_post( (int) ( $in['id'] ?? 0 ) );
			if ( ! $a || 'attachment' !== $a->post_type ) {
				return keha_mcp_error( 'not_found', 'Mediet findes ikke.', 404 );
			}
			if ( ! current_user_can( 'edit_post', $a->ID ) ) {
				return keha_mcp_error( 'forbidden', 'Ingen adgang til dette medie.', 403 );
			}
			$arr = array( 'ID' => $a->ID );
			if ( isset( $in['title'] ) ) {
				$arr['post_title'] = sanitize_text_field( $in['title'] );
			}
			if ( isset( $in['caption'] ) ) {
				$arr['post_excerpt'] = wp_kses_post( $in['caption'] );
			}
			if ( isset( $in['description'] ) ) {
				$arr['post_content'] = wp_kses_post( $in['description'] );
			}
			if ( count( $arr ) > 1 ) {
				$r = wp_update_post( wp_slash( $arr ), true );
				if ( is_wp_error( $r ) ) {
					return $r;
				}
			}
			if ( isset( $in['alt'] ) ) {
				update_post_meta( $a->ID, '_wp_attachment_image_alt', sanitize_text_field( $in['alt'] ) );
			}
			return keha_mcp_format_media( get_post( $a->ID ) );
		},
		'permission_callback' => $can_upload,
		'idempotent'          => true,
	) );

	/* --- set-featured-image --- */
	keha_mcp_register( 'keha/set-featured-image', array(
		'label'               => 'Sæt udvalgt billede',
		'description'         => 'Sætter (eller fjerner med media_id=0) det udvalgte billede på et indlæg eller en side.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'post_id', 'media_id' ),
			'properties' => array(
				'post_id'  => array( 'type' => 'integer', 'minimum' => 1 ),
				'media_id' => array( 'type' => 'integer', 'minimum' => 0 ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['post_id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$media = (int) ( $in['media_id'] ?? 0 );
			if ( 0 === $media ) {
				delete_post_thumbnail( $post->ID );
				return array( 'post_id' => $post->ID, 'featured_media' => null );
			}
			if ( ! wp_attachment_is_image( $media ) ) {
				return keha_mcp_error( 'bad_media', 'media_id er ikke et billede i mediebiblioteket.' );
			}
			set_post_thumbnail( $post->ID, $media );
			return array( 'post_id' => $post->ID, 'featured_media' => array( 'id' => $media, 'url' => wp_get_attachment_url( $media ) ) );
		},
		'permission_callback' => function () {
			return current_user_can( 'edit_posts' );
		},
		'idempotent'          => true,
	) );
}
