<?php
/**
 * Indhold: indlæg, sider, status, papirkurv, kategorier og tags.
 */

defined( 'ABSPATH' ) || exit;

/* -------------------------------------------------------------------------
 * Hjælpere
 * ---------------------------------------------------------------------- */

function keha_mcp_public_types() {
	$types = array_values( get_post_types( array( 'public' => true ), 'names' ) );
	return array_values( array_diff( $types, array( 'attachment' ) ) );
}

function keha_mcp_format_post( WP_Post $p, $full = false, $offset = 0, $length = 0 ) {
	$out = array(
		'id'       => $p->ID,
		'type'     => $p->post_type,
		'status'   => $p->post_status,
		'title'    => $p->post_title,
		'slug'     => $p->post_name,
		'date'     => $p->post_date,
		'modified' => $p->post_modified,
		'link'     => get_permalink( $p ),
		'parent'   => (int) $p->post_parent,
	);
	if ( ! $full ) {
		return $out;
	}

	$content = (string) $p->post_content;
	$total   = strlen( $content );
	if ( $offset > 0 || $length > 0 ) {
		$content = substr( $content, max( 0, (int) $offset ), $length > 0 ? (int) $length : null );
	}

	$out['content']        = $content;
	$out['content_length'] = $total;
	$out['excerpt']        = $p->post_excerpt;
	$out['menu_order']     = (int) $p->menu_order;
	$out['author']         = get_the_author_meta( 'display_name', $p->post_author );
	$out['template']       = (string) get_page_template_slug( $p );
	$out['preview_link']   = 'publish' === $p->post_status ? get_permalink( $p ) : get_preview_post_link( $p );

	foreach ( array( 'category' => 'categories', 'post_tag' => 'tags' ) as $tax => $key ) {
		if ( is_object_in_taxonomy( $p->post_type, $tax ) ) {
			$terms       = wp_get_post_terms( $p->ID, $tax );
			$out[ $key ] = is_wp_error( $terms ) ? array() : array_map( function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name );
			}, $terms );
		}
	}
	$thumb                  = (int) get_post_thumbnail_id( $p );
	$out['featured_media']  = $thumb ? array( 'id' => $thumb, 'url' => wp_get_attachment_url( $thumb ) ) : null;
	return $out;
}

/** Datoformat: "2026-11-03 09:00" eller "2026-11-03T09:00:00" (lokal tid). */
function keha_mcp_parse_date( $value ) {
	$value = str_replace( 'T', ' ', trim( (string) $value ) );
	if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $value ) ) {
		$value .= ':00';
	}
	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $value ) || false === strtotime( $value ) ) {
		return keha_mcp_error( 'bad_date', 'Ugyldig dato. Brug formatet ÅÅÅÅ-MM-DD TT:MM i sidens tidszone.' );
	}
	return $value;
}

/** Kategorier som id'er eller navne. Ukendte navne oprettes. */
function keha_mcp_resolve_categories( array $items ) {
	$ids = array();
	foreach ( $items as $item ) {
		if ( is_numeric( $item ) ) {
			$term = get_term( (int) $item, 'category' );
			if ( ! $term || is_wp_error( $term ) ) {
				return keha_mcp_error( 'bad_category', 'Kategori-id findes ikke: ' . (int) $item );
			}
			$ids[] = $term->term_id;
			continue;
		}
		$name = trim( (string) $item );
		if ( '' === $name ) {
			continue;
		}
		$term = get_term_by( 'name', $name, 'category' );
		if ( ! $term ) {
			$term = get_term_by( 'slug', sanitize_title( $name ), 'category' );
		}
		if ( $term ) {
			$ids[] = $term->term_id;
			continue;
		}
		if ( ! current_user_can( 'manage_categories' ) ) {
			return keha_mcp_error( 'forbidden', 'Du kan ikke oprette nye kategorier.' );
		}
		$new = wp_insert_term( $name, 'category' );
		if ( is_wp_error( $new ) ) {
			return $new;
		}
		$ids[] = (int) $new['term_id'];
	}
	return array_values( array_unique( $ids ) );
}

/** Fælles felter for create/update. */
function keha_mcp_content_properties() {
	return array(
		'title'          => array( 'type' => 'string' ),
		'content'        => array( 'type' => 'string', 'description' => 'Rå HTML eller blokmarkup (<!-- wp:paragraph --> …).' ),
		'excerpt'        => array( 'type' => 'string' ),
		'status'         => array( 'type' => 'string', 'enum' => array( 'draft', 'pending', 'publish', 'future', 'private' ) ),
		'date'           => array( 'type' => 'string', 'description' => 'Udgivelsesdato i lokal tid, ÅÅÅÅ-MM-DD TT:MM. Med status=future planlægges indholdet.' ),
		'slug'           => array( 'type' => 'string' ),
		'parent'         => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Forælder-side (kun sider).' ),
		'menu_order'     => array( 'type' => 'integer' ),
		'template'       => array( 'type' => 'string', 'description' => 'Sideskabelon, fx "" for standard. Se site-info for mulige.' ),
		'categories'     => array( 'type' => 'array', 'items' => array( 'type' => array( 'integer', 'string' ) ), 'description' => 'Kategori-id\'er eller navne (erstatter de nuværende). Ukendte navne oprettes.' ),
		'tags'           => array( 'type' => 'array', 'items' => array( 'type' => 'string' ), 'description' => 'Tag-navne (erstatter de nuværende).' ),
		'featured_media' => array( 'type' => 'integer', 'minimum' => 0, 'description' => 'Medie-id til udvalgt billede, 0 fjerner det.' ),
	);
}

/**
 * Bygger postarr, tjekker publiceringsret, gemmer og sætter termer.
 *
 * @return array|WP_Error
 */
function keha_mcp_save_content( array $in, $post_type, $existing = null ) {
	$type_obj = get_post_type_object( $post_type );
	if ( ! $type_obj ) {
		return keha_mcp_error( 'bad_type', 'Ukendt indholdstype.' );
	}

	$arr = array();
	$map = array( 'title' => 'post_title', 'content' => 'post_content', 'excerpt' => 'post_excerpt' );
	foreach ( $map as $k => $field ) {
		if ( isset( $in[ $k ] ) ) {
			$arr[ $field ] = (string) $in[ $k ];
		}
	}
	if ( isset( $in['slug'] ) ) {
		$arr['post_name'] = sanitize_title( $in['slug'] );
	}
	if ( isset( $in['parent'] ) ) {
		$arr['post_parent'] = (int) $in['parent'];
	}
	if ( isset( $in['menu_order'] ) ) {
		$arr['menu_order'] = (int) $in['menu_order'];
	}
	if ( isset( $in['template'] ) ) {
		$arr['page_template'] = (string) $in['template'];
	}
	if ( isset( $in['date'] ) && '' !== $in['date'] ) {
		$date = keha_mcp_parse_date( $in['date'] );
		if ( is_wp_error( $date ) ) {
			return $date;
		}
		$arr['post_date']     = $date;
		$arr['post_date_gmt'] = get_gmt_from_date( $date );
		$arr['edit_date']     = true;
	}

	$status = isset( $in['status'] ) ? $in['status'] : ( $existing ? null : 'draft' );
	if ( null !== $status ) {
		if ( in_array( $status, array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $type_obj->cap->publish_posts ) ) {
			return keha_mcp_error( 'forbidden', 'Du har ikke ret til at publicere denne indholdstype.', 403 );
		}
		if ( 'future' === $status ) {
			$when = $arr['post_date_gmt'] ?? ( $existing ? $existing->post_date_gmt : '' );
			if ( ! $when || strtotime( $when . ' UTC' ) <= time() ) {
				return keha_mcp_error( 'bad_date', 'status=future kræver en dato i fremtiden.' );
			}
		}
		$arr['post_status'] = $status;
	}

	// wp_insert_post forventer "slashed" data – ellers forsvinder backslashes i blokmarkup.
	if ( $existing ) {
		$arr['ID'] = $existing->ID;
		$id        = wp_update_post( wp_slash( $arr ), true );
	} else {
		$arr['post_type']   = $post_type;
		$arr['post_author'] = get_current_user_id();
		if ( ! isset( $arr['post_title'] ) ) {
			$arr['post_title'] = '';
		}
		$id = wp_insert_post( wp_slash( $arr ), true );
	}
	if ( is_wp_error( $id ) ) {
		return $id;
	}

	if ( isset( $in['categories'] ) && is_object_in_taxonomy( $post_type, 'category' ) ) {
		$cats = keha_mcp_resolve_categories( (array) $in['categories'] );
		if ( is_wp_error( $cats ) ) {
			return $cats;
		}
		wp_set_post_categories( $id, $cats );
	}
	if ( isset( $in['tags'] ) && is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
		wp_set_post_tags( $id, array_map( 'strval', (array) $in['tags'] ), false );
	}
	if ( isset( $in['featured_media'] ) ) {
		if ( (int) $in['featured_media'] > 0 ) {
			if ( ! wp_attachment_is_image( (int) $in['featured_media'] ) ) {
				return keha_mcp_error( 'bad_media', 'featured_media er ikke et billede i mediebiblioteket.' );
			}
			set_post_thumbnail( $id, (int) $in['featured_media'] );
		} else {
			delete_post_thumbnail( $id );
		}
	}

	clean_post_cache( $id );
	return keha_mcp_format_post( get_post( $id ), false ) + array(
		'preview_link' => get_preview_post_link( $id ),
	);
}

/** Henter et indlæg og tjekker redigeringsret. */
function keha_mcp_editable_post( $id ) {
	$post = get_post( (int) $id );
	if ( ! $post || in_array( $post->post_type, array( 'revision', 'nav_menu_item' ), true ) ) {
		return keha_mcp_error( 'not_found', 'Indholdet findes ikke.', 404 );
	}
	if ( ! current_user_can( 'edit_post', $post->ID ) ) {
		return keha_mcp_error( 'forbidden', 'Ingen adgang til at redigere dette indhold.', 403 );
	}
	return $post;
}

/* -------------------------------------------------------------------------
 * Registrering
 * ---------------------------------------------------------------------- */

function keha_mcp_register_content_abilities() {

	$can_edit = function () {
		return current_user_can( 'edit_posts' );
	};

	/* --- site-info --- */
	keha_mcp_register( 'keha/site-info', array(
		'label'               => 'Webstedsoverblik',
		'description'         => 'Overblik over keha.dk: navn, tidszone, aktivt tema, sideskabeloner, antal indlæg/sider og tilgængelige indholdstyper. Godt første kald.',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'include_pages' => array( 'type' => 'boolean', 'default' => false, 'description' => 'Medtag liste over alle sider (id, titel, forælder).' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$theme = wp_get_theme();
			$count = function ( $type ) {
				$c = wp_count_posts( $type );
				return array(
					'publish' => (int) ( $c->publish ?? 0 ),
					'draft'   => (int) ( $c->draft ?? 0 ),
					'future'  => (int) ( $c->future ?? 0 ),
					'pending' => (int) ( $c->pending ?? 0 ),
					'private' => (int) ( $c->private ?? 0 ),
				);
			};
			$out = array(
				'name'           => get_bloginfo( 'name' ),
				'description'    => get_bloginfo( 'description' ),
				'url'            => home_url( '/' ),
				'wp_version'     => get_bloginfo( 'version' ),
				'timezone'       => wp_timezone_string(),
				'now_local'      => current_time( 'mysql' ),
				'language'       => get_locale(),
				'permalinks'     => (string) get_option( 'permalink_structure' ),
				'front_page'     => array(
					'show_on_front'  => get_option( 'show_on_front' ),
					'page_on_front'  => (int) get_option( 'page_on_front' ),
					'page_for_posts' => (int) get_option( 'page_for_posts' ),
				),
				'theme'          => array(
					'name'           => $theme->get( 'Name' ),
					'stylesheet'     => get_stylesheet(),
					'template'       => get_template(),
					'version'        => $theme->get( 'Version' ),
					'block_theme'    => function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false,
					'page_templates' => $theme->get_page_templates(),
				),
				'post_types'     => keha_mcp_public_types(),
				'counts'         => array( 'post' => $count( 'post' ), 'page' => $count( 'page' ) ),
				'current_user'   => wp_get_current_user()->display_name,
				'keha_mcp'       => KEHA_MCP_VERSION,
			);
			if ( ! empty( $in['include_pages'] ) ) {
				$out['pages'] = array_map( function ( $p ) {
					return array( 'id' => $p->ID, 'title' => $p->post_title, 'parent' => (int) $p->post_parent, 'status' => $p->post_status );
				}, get_pages( array( 'post_status' => 'publish,draft,private,pending,future', 'sort_column' => 'menu_order,post_title' ) ) );
			}
			return $out;
		},
		'permission_callback' => $can_edit,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- list-content --- */
	keha_mcp_register( 'keha/list-content', array(
		'label'               => 'List indhold',
		'description'         => 'Lister indlæg, sider eller andre indholdstyper med søgning, statusfilter, kategori/tag og sidetal. Returnerer ikke selve indholdet – brug get-content til det.',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'post_type' => array( 'type' => 'string', 'default' => 'post', 'description' => 'post, page, eller "any".' ),
				'status'    => array( 'type' => 'string', 'enum' => array( 'any', 'publish', 'draft', 'pending', 'future', 'private', 'trash' ), 'default' => 'any' ),
				'search'    => array( 'type' => 'string' ),
				'category'  => array( 'type' => 'string', 'description' => 'Kategori-slug eller -id.' ),
				'tag'       => array( 'type' => 'string', 'description' => 'Tag-slug.' ),
				'parent'    => array( 'type' => 'integer', 'minimum' => 0 ),
				'orderby'   => array( 'type' => 'string', 'enum' => array( 'date', 'modified', 'title', 'menu_order' ), 'default' => 'date' ),
				'order'     => array( 'type' => 'string', 'enum' => array( 'asc', 'desc' ), 'default' => 'desc' ),
				'limit'     => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 20 ),
				'page'      => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$type  = $in['post_type'] ?? 'post';
			$types = keha_mcp_public_types();
			if ( 'any' !== $type && ! in_array( $type, $types, true ) ) {
				return keha_mcp_error( 'bad_type', 'Ukendt indholdstype. Mulige: ' . implode( ', ', $types ) . ', any.' );
			}
			$status = $in['status'] ?? 'any';
			$args   = array(
				'post_type'      => 'any' === $type ? $types : $type,
				'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'pending', 'future', 'private' ) : $status,
				'posts_per_page' => min( 100, max( 1, (int) ( $in['limit'] ?? 20 ) ) ),
				'paged'          => max( 1, (int) ( $in['page'] ?? 1 ) ),
				'orderby'        => $in['orderby'] ?? 'date',
				'order'          => strtoupper( $in['order'] ?? 'desc' ),
				'no_found_rows'  => false,
			);
			if ( ! empty( $in['search'] ) ) {
				$args['s'] = (string) $in['search'];
			}
			if ( ! empty( $in['category'] ) ) {
				if ( is_numeric( $in['category'] ) ) {
					$args['cat'] = (int) $in['category'];
				} else {
					$args['category_name'] = sanitize_title( $in['category'] );
				}
			}
			if ( ! empty( $in['tag'] ) ) {
				$args['tag'] = sanitize_title( $in['tag'] );
			}
			if ( isset( $in['parent'] ) ) {
				$args['post_parent'] = (int) $in['parent'];
			}
			$q = new WP_Query( $args );
			return array(
				'items'       => array_map( function ( $p ) {
					return keha_mcp_format_post( $p );
				}, $q->posts ),
				'total'       => (int) $q->found_posts,
				'total_pages' => (int) $q->max_num_pages,
				'page'        => $args['paged'],
			);
		},
		'permission_callback' => $can_edit,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- get-content --- */
	keha_mcp_register( 'keha/get-content', array(
		'label'               => 'Hent indhold',
		'description'         => 'Henter ét indlæg eller én side med rå indhold (blokmarkup), uddrag, kategorier, tags, skabelon og udvalgt billede. Lange tekster kan hentes i bidder med content_offset/content_length.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array(
				'id'             => array( 'type' => 'integer', 'minimum' => 1 ),
				'content_offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0 ),
				'content_length' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => '0 = hele indholdet.' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			return keha_mcp_format_post( $post, true, (int) ( $in['content_offset'] ?? 0 ), (int) ( $in['content_length'] ?? 0 ) );
		},
		'permission_callback' => $can_edit,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- create-content --- */
	$create_props              = keha_mcp_content_properties();
	$create_props['post_type'] = array( 'type' => 'string', 'default' => 'post', 'description' => 'post eller page (eller anden offentlig type).' );
	keha_mcp_register( 'keha/create-content', array(
		'label'               => 'Opret indhold',
		'description'         => 'Opretter et indlæg eller en side. Standard er kladde; sæt status=publish for at udgive med det samme eller status=future + date for at planlægge. Returnerer id og preview-link.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'title' ),
			'properties' => $create_props,
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$type = $in['post_type'] ?? 'post';
			if ( ! in_array( $type, keha_mcp_public_types(), true ) ) {
				return keha_mcp_error( 'bad_type', 'Ukendt indholdstype.' );
			}
			$obj = get_post_type_object( $type );
			if ( ! current_user_can( $obj->cap->create_posts ) ) {
				return keha_mcp_error( 'forbidden', 'Du kan ikke oprette denne indholdstype.', 403 );
			}
			return keha_mcp_save_content( $in, $type );
		},
		'permission_callback' => $can_edit,
	) );

	/* --- update-content --- */
	$update_props       = keha_mcp_content_properties();
	$update_props['id'] = array( 'type' => 'integer', 'minimum' => 1 );
	keha_mcp_register( 'keha/update-content', array(
		'label'               => 'Opdater indhold',
		'description'         => 'Opdaterer felter på et eksisterende indlæg eller en side – også publicerede. Kun de felter, du sender, ændres. WordPress gemmer en revision, så ændringen kan fortrydes. Til små tekstrettelser i lange sider er replace-in-content billigere.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => $update_props,
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			return keha_mcp_save_content( $in, $post->post_type, $post );
		},
		'permission_callback' => $can_edit,
		'idempotent'          => true,
	) );

	/* --- replace-in-content --- */
	keha_mcp_register( 'keha/replace-in-content', array(
		'label'               => 'Find og erstat i indhold',
		'description'         => 'Erstatter et præcist tekststykke i titel, indhold eller uddrag uden at sende hele teksten frem og tilbage. old skal forekomme præcis én gang (eller sæt replace_all=true).',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'old', 'new' ),
			'properties' => array(
				'id'          => array( 'type' => 'integer', 'minimum' => 1 ),
				'field'       => array( 'type' => 'string', 'enum' => array( 'content', 'title', 'excerpt' ), 'default' => 'content' ),
				'old'         => array( 'type' => 'string', 'minLength' => 1 ),
				'new'         => array( 'type' => 'string' ),
				'replace_all' => array( 'type' => 'boolean', 'default' => false ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$field = $in['field'] ?? 'content';
			$col   = array( 'content' => 'post_content', 'title' => 'post_title', 'excerpt' => 'post_excerpt' )[ $field ] ?? 'post_content';
			$count = 0;
			$new   = keha_mcp_replace_once( (string) $post->$col, (string) $in['old'], (string) $in['new'], ! empty( $in['replace_all'] ), $count );
			if ( is_wp_error( $new ) ) {
				return $new;
			}
			$r = wp_update_post( wp_slash( array( 'ID' => $post->ID, $col => $new ) ), true );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			return array(
				'id'       => $post->ID,
				'field'    => $field,
				'replaced' => $count,
				'link'     => get_permalink( $post->ID ),
			);
		},
		'permission_callback' => $can_edit,
	) );

	/* --- set-status --- */
	keha_mcp_register( 'keha/set-status', array(
		'label'               => 'Skift status',
		'description'         => 'Publicerer, afpublicerer (til kladde), planlægger (future + date) eller gør privat.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id', 'status' ),
			'properties' => array(
				'id'     => array( 'type' => 'integer', 'minimum' => 1 ),
				'status' => array( 'type' => 'string', 'enum' => array( 'publish', 'draft', 'pending', 'future', 'private' ) ),
				'date'   => array( 'type' => 'string', 'description' => 'Påkrævet ved future. Lokal tid, ÅÅÅÅ-MM-DD TT:MM.' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			$r = keha_mcp_save_content( array_intersect_key( $in, array_flip( array( 'status', 'date' ) ) ), $post->post_type, $post );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$fresh = get_post( $post->ID );
			return array(
				'id'     => $post->ID,
				'status' => $fresh->post_status,
				'date'   => $fresh->post_date,
				'link'   => get_permalink( $fresh ),
			);
		},
		'permission_callback' => $can_edit,
		'idempotent'          => true,
	) );

	/* --- trash-content --- */
	keha_mcp_register( 'keha/trash-content', array(
		'label'               => 'Flyt til papirkurv',
		'description'         => 'Flytter et indlæg eller en side til papirkurven. Kan fortrydes med restore-content. Permanent sletning er ikke muligt via MCP.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( ! current_user_can( 'delete_post', $post->ID ) ) {
				return keha_mcp_error( 'forbidden', 'Ingen ret til at flytte dette til papirkurven.', 403 );
			}
			if ( ! wp_trash_post( $post->ID ) ) {
				return keha_mcp_error( 'trash_failed', 'Kunne ikke flytte til papirkurven.' );
			}
			return array( 'id' => $post->ID, 'status' => 'trash' );
		},
		'permission_callback' => $can_edit,
		'destructive'         => true,
	) );

	/* --- restore-content --- */
	keha_mcp_register( 'keha/restore-content', array(
		'label'               => 'Gendan fra papirkurv',
		'description'         => 'Gendanner indhold fra papirkurven til den status, det havde før.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'id' ),
			'properties' => array( 'id' => array( 'type' => 'integer', 'minimum' => 1 ) ),
		),
		'execute_callback'    => function ( $input ) {
			$in   = keha_mcp_input( $input );
			$post = keha_mcp_editable_post( $in['id'] ?? 0 );
			if ( is_wp_error( $post ) ) {
				return $post;
			}
			if ( 'trash' !== $post->post_status ) {
				return keha_mcp_error( 'not_trashed', 'Indholdet ligger ikke i papirkurven.' );
			}
			add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
			$r = wp_untrash_post( $post->ID );
			remove_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10 );
			if ( ! $r ) {
				return keha_mcp_error( 'restore_failed', 'Kunne ikke gendanne.' );
			}
			return array( 'id' => $post->ID, 'status' => get_post_status( $post->ID ) );
		},
		'permission_callback' => $can_edit,
		'idempotent'          => true,
	) );

	/* --- list-terms --- */
	keha_mcp_register( 'keha/list-terms', array(
		'label'               => 'List kategorier/tags',
		'description'         => 'Lister kategorier eller tags med id, navn, slug, forælder og antal indlæg.',
		'input_schema'        => array(
			'type'       => 'object',
			'properties' => array(
				'taxonomy' => array( 'type' => 'string', 'enum' => array( 'category', 'post_tag' ), 'default' => 'category' ),
				'search'   => array( 'type' => 'string' ),
				'limit'    => array( 'type' => 'integer', 'minimum' => 1, 'maximum' => 500, 'default' => 200 ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in    = keha_mcp_input( $input );
			$terms = get_terms( array(
				'taxonomy'   => $in['taxonomy'] ?? 'category',
				'hide_empty' => false,
				'search'     => $in['search'] ?? '',
				'number'     => (int) ( $in['limit'] ?? 200 ),
			) );
			if ( is_wp_error( $terms ) ) {
				return $terms;
			}
			return array( 'items' => array_map( function ( $t ) {
				return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'parent' => $t->parent, 'count' => $t->count, 'description' => $t->description );
			}, $terms ) );
		},
		'permission_callback' => $can_edit,
		'readonly'            => true,
		'idempotent'          => true,
	) );

	/* --- create-term --- */
	keha_mcp_register( 'keha/create-term', array(
		'label'               => 'Opret kategori/tag',
		'description'         => 'Opretter en ny kategori eller et nyt tag.',
		'input_schema'        => array(
			'type'       => 'object',
			'required'   => array( 'name' ),
			'properties' => array(
				'taxonomy'    => array( 'type' => 'string', 'enum' => array( 'category', 'post_tag' ), 'default' => 'category' ),
				'name'        => array( 'type' => 'string', 'minLength' => 1 ),
				'slug'        => array( 'type' => 'string' ),
				'parent'      => array( 'type' => 'integer', 'minimum' => 0 ),
				'description' => array( 'type' => 'string' ),
			),
		),
		'execute_callback'    => function ( $input ) {
			$in  = keha_mcp_input( $input );
			$tax = get_taxonomy( $in['taxonomy'] ?? 'category' );
			if ( ! $tax || ! current_user_can( $tax->cap->manage_terms ) ) {
				return keha_mcp_error( 'forbidden', 'Ingen ret til at oprette termer her.', 403 );
			}
			$r = wp_insert_term( (string) $in['name'], $tax->name, array_filter( array(
				'slug'        => isset( $in['slug'] ) ? sanitize_title( $in['slug'] ) : '',
				'parent'      => isset( $in['parent'] ) ? (int) $in['parent'] : 0,
				'description' => $in['description'] ?? '',
			) ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
			$t = get_term( $r['term_id'], $tax->name );
			return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug, 'taxonomy' => $tax->name );
		},
		'permission_callback' => $can_edit,
	) );
}
