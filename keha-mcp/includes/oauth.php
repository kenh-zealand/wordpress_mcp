<?php
/**
 * Minimal OAuth 2.1-autorisationsserver til KEHA MCP.
 *
 * - RFC 9728  Protected Resource Metadata   /.well-known/oauth-protected-resource
 * - RFC 8414  Authorization Server Metadata /.well-known/oauth-authorization-server
 * - RFC 7591  Dynamisk klientregistrering   /wp-json/keha-mcp/v1/oauth/register
 * - RFC 7636  PKCE (kun S256)
 * - Authorization code + refresh token (roteres ved brug)
 * - RFC 7009  Tilbagekaldelse               /wp-json/keha-mcp/v1/oauth/revoke
 *
 * Tokens gemmes kun som SHA-256-hash. Kun brugere med manage_options kan godkende.
 */

defined( 'ABSPATH' ) || exit;

final class Keha_MCP_OAuth {

	const DB_VERSION  = '1';
	const ACCESS_TTL  = 3600;          // 1 time.
	const REFRESH_TTL = 5184000;       // 60 dage.
	const CODE_TTL    = 600;           // 10 minutter.
	const SCOPE       = 'keha';
	const MAX_CLIENTS = 50;

	/** @var string|null null = ingen bearer, 'valid' eller 'invalid'. */
	private static $bearer_state = null;

	/* =====================================================================
	 * Opstart
	 * ================================================================== */

	public static function init() {
		add_action( 'plugins_loaded', array( __CLASS__, 'maybe_install' ) );
		add_action( 'init', array( __CLASS__, 'handle_front_requests' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
		add_action( 'keha_mcp_cleanup', array( __CLASS__, 'cleanup' ) );

		add_filter( 'determine_current_user', array( __CLASS__, 'determine_user' ), 25 );
		add_filter( 'application_password_is_api_request', array( __CLASS__, 'skip_app_passwords_on_oauth_routes' ) );
		add_filter( 'rest_authentication_errors', array( __CLASS__, 'authentication_errors' ), 99 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'neutralize_foreign_auth_errors' ), 100, 3 );
		add_filter( 'rest_post_dispatch', array( __CLASS__, 'add_www_authenticate' ), 10, 3 );
	}

	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta( "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			token_type varchar(10) NOT NULL,
			client_id varchar(64) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			family varchar(32) NOT NULL,
			scope varchar(100) NOT NULL DEFAULT '',
			expires_at bigint(20) unsigned NOT NULL,
			created_at bigint(20) unsigned NOT NULL,
			last_used bigint(20) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY family (family),
			KEY expires_at (expires_at)
		) {$charset};" );
		update_option( 'keha_mcp_db_version', self::DB_VERSION, false );

		if ( ! wp_next_scheduled( 'keha_mcp_cleanup' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'keha_mcp_cleanup' );
		}
	}

	/** Opgradering uden genaktivering (fx når zip'en uploades oven på den gamle). */
	public static function maybe_install() {
		if ( get_option( 'keha_mcp_db_version' ) !== self::DB_VERSION ) {
			self::install();
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( 'keha_mcp_cleanup' );
	}

	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE expires_at < %d', time() ) ); // phpcs:ignore
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'keha_mcp_tokens';
	}

	/* =====================================================================
	 * URL'er
	 * ================================================================== */

	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	public static function resource() {
		return untrailingslashit( rest_url( 'keha-mcp/mcp' ) );
	}

	public static function authorize_url() {
		return home_url( '/keha-mcp-oauth/authorize' );
	}

	public static function prm_url() {
		$path = (string) wp_parse_url( self::resource(), PHP_URL_PATH );
		return self::issuer() . '/.well-known/oauth-protected-resource' . $path;
	}

	public static function protected_resource_metadata() {
		return array(
			'resource'                 => self::resource(),
			'authorization_servers'    => array( self::issuer() ),
			'scopes_supported'         => array( self::SCOPE ),
			'bearer_methods_supported' => array( 'header' ),
			'resource_name'            => 'keha.dk MCP',
		);
	}

	public static function authorization_server_metadata() {
		$meta = array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => rest_url( 'keha-mcp/v1/oauth/token' ),
			'revocation_endpoint'                   => rest_url( 'keha-mcp/v1/oauth/revoke' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array( self::SCOPE ),
			'authorization_response_iss_parameter_supported' => true,
		);
		if ( self::dcr_enabled() ) {
			$meta['registration_endpoint'] = rest_url( 'keha-mcp/v1/oauth/register' );
		}
		return $meta;
	}

	/* =====================================================================
	 * Indstillinger
	 * ================================================================== */

	public static function default_redirect_hosts() {
		return array( 'claude.ai', 'claude.com', 'chatgpt.com', 'localhost', '127.0.0.1', '[::1]' );
	}

	public static function redirect_hosts() {
		$hosts = get_option( 'keha_mcp_redirect_hosts', null );
		return is_array( $hosts ) ? $hosts : self::default_redirect_hosts();
	}

	public static function dcr_enabled() {
		return (bool) get_option( 'keha_mcp_dcr_enabled', true );
	}

	/* =====================================================================
	 * Klienter (gemmes i en option)
	 * ================================================================== */

	public static function clients() {
		$c = get_option( 'keha_mcp_clients', array() );
		return is_array( $c ) ? $c : array();
	}

	public static function get_client( $client_id ) {
		$clients = self::clients();
		return ( is_string( $client_id ) && isset( $clients[ $client_id ] ) ) ? $clients[ $client_id ] : null;
	}

	public static function delete_client( $client_id ) {
		global $wpdb;
		$clients = self::clients();
		unset( $clients[ $client_id ] );
		update_option( 'keha_mcp_clients', $clients, false );
		$wpdb->delete( self::table(), array( 'client_id' => $client_id ) );
	}

	private static function save_client( $client_id, array $client ) {
		$clients               = self::clients();
		$clients[ $client_id ] = $client;

		// Ryd op: hold antallet nede ved at fjerne de ældste klienter uden aktive tokens.
		if ( count( $clients ) > self::MAX_CLIENTS ) {
			global $wpdb;
			uasort( $clients, function ( $a, $b ) {
				return ( $a['created'] ?? 0 ) <=> ( $b['created'] ?? 0 );
			} );
			foreach ( array_keys( $clients ) as $id ) {
				if ( count( $clients ) <= self::MAX_CLIENTS ) {
					break;
				}
				if ( $id === $client_id ) {
					continue;
				}
				$active = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM ' . self::table() . ' WHERE client_id = %s AND expires_at > %d', $id, time() ) ); // phpcs:ignore
				if ( 0 === $active ) {
					unset( $clients[ $id ] );
				}
			}
		}
		update_option( 'keha_mcp_clients', $clients, false );
	}

	/** Gyldig redirect-URI ved registrering: https på tilladt vært, eller http på loopback. */
	private static function redirect_uri_allowed( $uri ) {
		if ( ! is_string( $uri ) || '' === $uri || strlen( $uri ) > 500 || false !== strpos( $uri, '#' ) ) {
			return false;
		}
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host  = strtolower( $parts['host'] );
		$hosts = array_map( 'strtolower', self::redirect_hosts() );
		if ( ! in_array( $host, $hosts, true ) ) {
			return false;
		}
		if ( 'https' === $parts['scheme'] ) {
			return true;
		}
		return 'http' === $parts['scheme'] && self::is_loopback( $host );
	}

	private static function is_loopback( $host ) {
		return in_array( strtolower( $host ), array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
	}

	/** Sammenligner redirect-URI med de registrerede. Loopback ignorerer port (RFC 8252). */
	private static function match_redirect_uri( array $client, $uri ) {
		foreach ( (array) $client['redirect_uris'] as $registered ) {
			if ( hash_equals( $registered, $uri ) ) {
				return true;
			}
			$a = wp_parse_url( $registered );
			$b = wp_parse_url( $uri );
			if ( ! $a || ! $b || empty( $a['host'] ) || empty( $b['host'] ) ) {
				continue;
			}
			if ( self::is_loopback( $a['host'] ) && 'http' === ( $a['scheme'] ?? '' ) && ( $b['scheme'] ?? '' ) === 'http'
				&& strtolower( $a['host'] ) === strtolower( $b['host'] )
				&& ( $a['path'] ?? '' ) === ( $b['path'] ?? '' )
				&& ( $a['query'] ?? '' ) === ( $b['query'] ?? '' ) ) {
				return true;
			}
		}
		return false;
	}

	/* =====================================================================
	 * Forside-forespørgsler: .well-known og authorize-siden
	 * ================================================================== */

	public static function handle_front_requests() {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path      = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore
		$home_path = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $home_path && 0 === strpos( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			self::send_json( self::protected_resource_metadata() );
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $path, '/.well-known/openid-configuration' ) ) {
			self::send_json( self::authorization_server_metadata() );
		}
		if ( '/keha-mcp-oauth/authorize' === untrailingslashit( $path )
			|| ( isset( $_GET['keha_mcp_oauth'] ) && 'authorize' === $_GET['keha_mcp_oauth'] ) ) { // phpcs:ignore
			self::authorize();
		}
	}

	private static function send_json( $data, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT );
		exit;
	}

	/* =====================================================================
	 * /authorize
	 * ================================================================== */

	private static function authorize() {
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$src     = $is_post ? $_POST : $_GET; // phpcs:ignore
		$p       = array();
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource', 'decision', '_wpnonce' ) as $k ) {
			$p[ $k ] = isset( $src[ $k ] ) && is_string( $src[ $k ] ) ? wp_unslash( $src[ $k ] ) : '';
		}

		// 1) Klient og redirect-URI valideres før vi nogensinde omdirigerer.
		$client = self::get_client( $p['client_id'] );
		if ( ! $client ) {
			self::error_page( 'Ukendt klient', 'Forbindelsen er ikke registreret på keha.dk (eller er blevet fjernet). Fjern connectoren i Claude og tilføj den igen.' );
		}
		if ( '' === $p['redirect_uri'] && 1 === count( (array) $client['redirect_uris'] ) ) {
			$p['redirect_uri'] = $client['redirect_uris'][0];
		}
		if ( ! self::match_redirect_uri( $client, $p['redirect_uri'] ) ) {
			self::error_page( 'Ugyldig redirect', 'Redirect-adressen matcher ikke den registrerede for denne klient.' );
		}

		// 2) Herefter sendes fejl tilbage til klienten.
		$back = function ( $error, $desc ) use ( $p ) {
			self::redirect_to_client( $p['redirect_uri'], array(
				'error'             => $error,
				'error_description' => $desc,
				'state'             => $p['state'],
				'iss'               => self::issuer(), // RFC 9207 – også ved fejl (krævet af ChatGPT).
			) );
		};

		if ( 'code' !== $p['response_type'] ) {
			$back( 'unsupported_response_type', 'Kun response_type=code understøttes.' );
		}
		if ( '' === $p['code_challenge'] || 'S256' !== $p['code_challenge_method'] ) {
			$back( 'invalid_request', 'PKCE med code_challenge_method=S256 er påkrævet.' );
		}
		if ( ! preg_match( '/^[A-Za-z0-9\-_]{43,128}$/', $p['code_challenge'] ) ) {
			$back( 'invalid_request', 'Ugyldig code_challenge.' );
		}
		if ( '' !== $p['resource'] && ! self::resource_ok( $p['resource'] ) ) {
			$back( 'invalid_target', 'Ukendt resource.' );
		}

		// 3) Log ind i WordPress hvis nødvendigt.
		if ( ! is_user_logged_in() ) {
			$args = array_filter( array(
				'client_id'             => $p['client_id'],
				'redirect_uri'          => $p['redirect_uri'],
				'response_type'         => $p['response_type'],
				'code_challenge'        => $p['code_challenge'],
				'code_challenge_method' => $p['code_challenge_method'],
				'state'                 => $p['state'],
				'scope'                 => $p['scope'],
				'resource'              => $p['resource'],
			), 'strlen' );
			$return = self::authorize_url() . '?' . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
			wp_safe_redirect( wp_login_url( $return ) );
			exit;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			$back( 'access_denied', 'Kun administratorer kan give MCP-adgang.' );
		}

		// 4) Svar på samtykke-formularen.
		if ( $is_post ) {
			if ( ! wp_verify_nonce( $p['_wpnonce'], 'keha_mcp_authorize_' . $p['client_id'] ) ) {
				self::error_page( 'Sessionen er udløbet', 'Gå tilbage til Claude og start forbindelsen igen.' );
			}
			if ( 'approve' !== $p['decision'] ) {
				$back( 'access_denied', 'Adgang blev afvist.' );
			}
			$code = 'kmcp_ac_' . bin2hex( random_bytes( 32 ) );
			set_transient( 'keha_mcp_code_' . hash( 'sha256', $code ), array(
				'client_id'      => $p['client_id'],
				'redirect_uri'   => $p['redirect_uri'],
				'code_challenge' => $p['code_challenge'],
				'user_id'        => get_current_user_id(),
				'scope'          => self::SCOPE,
				'created'        => time(),
			), self::CODE_TTL );
			self::redirect_to_client( $p['redirect_uri'], array(
				'code'  => $code,
				'state' => $p['state'],
				'iss'   => self::issuer(),
			) );
		}

		self::consent_page( $client, $p );
	}

	private static function resource_ok( $resource ) {
		$r = untrailingslashit( $resource );
		return in_array( $r, array( self::resource(), self::issuer() ), true );
	}

	private static function redirect_to_client( $uri, array $params ) {
		$params = array_filter( $params, 'strlen' );
		$sep    = ( false === strpos( $uri, '?' ) ) ? '?' : '&';
		header( 'Location: ' . $uri . $sep . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
		exit;
	}

	private static function page_head( $title ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?><!doctype html>
<html lang="da"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?php echo esc_html( $title ); ?> · keha.dk</title>
<style>
	:root{--bg:#f4f4f2;--card:#fff;--ink:#1d1d1b;--muted:#5f5f5a;--line:#e2e2dd;--accent:#1d4ed8;}
	@media (prefers-color-scheme:dark){:root{--bg:#141413;--card:#1f1f1d;--ink:#f1f1ee;--muted:#a5a59f;--line:#34342f;--accent:#7aa2ff;}}
	*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;place-items:center;min-height:100vh;padding:16px}
	.card{background:var(--card);border:1px solid var(--line);border-radius:14px;max-width:460px;width:100%;padding:28px}
	h1{font-size:1.3rem;margin:0 0 12px}p{margin:0 0 14px;color:var(--muted)}strong{color:var(--ink)}
	ul{margin:0 0 18px;padding-left:20px;color:var(--muted)}li{margin:2px 0}
	.meta{font-size:.85rem;border-top:1px solid var(--line);padding-top:12px;margin-top:4px}
	.row{display:flex;gap:10px;margin-top:20px}button{flex:1;font:inherit;padding:11px 14px;border-radius:9px;border:1px solid var(--line);background:transparent;color:var(--ink);cursor:pointer}
	button.primary{background:var(--accent);border-color:var(--accent);color:#fff;font-weight:600}
	code{font-size:.85em;word-break:break-all}
</style></head><body><main class="card">
		<?php
	}

	private static function consent_page( array $client, array $p ) {
		$user = wp_get_current_user();
		$host = (string) wp_parse_url( $p['redirect_uri'], PHP_URL_HOST );
		self::page_head( 'Giv adgang' );
		?>
		<h1>Giv <?php echo esc_html( $client['name'] ); ?> adgang til keha.dk?</h1>
		<p>Forbindelsen får adgang som <strong><?php echo esc_html( $user->display_name ); ?></strong> via MCP-værktøjerne og kan:</p>
		<ul>
			<li>læse, oprette, redigere og publicere indlæg og sider</li>
			<li>flytte indhold til papirkurven (ikke slette permanent)</li>
			<li>uploade og redigere medier</li>
			<li>læse og redigere filer i temaet</li>
		</ul>
		<p class="meta">Sendes tilbage til: <strong><?php echo esc_html( $host ); ?></strong><br>
		Du kan altid tilbagekalde adgangen under Indstillinger → KEHA MCP.</p>
		<form method="post" action="<?php echo esc_url( self::authorize_url() ); ?>">
			<?php
			foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ) as $k ) {
				printf( '<input type="hidden" name="%s" value="%s">', esc_attr( $k ), esc_attr( $p[ $k ] ) );
			}
			wp_nonce_field( 'keha_mcp_authorize_' . $p['client_id'] );
			?>
			<div class="row">
				<button type="submit" name="decision" value="deny">Afvis</button>
				<button type="submit" name="decision" value="approve" class="primary">Godkend</button>
			</div>
		</form>
		</main></body></html>
		<?php
		exit;
	}

	private static function error_page( $title, $message ) {
		self::page_head( $title );
		echo '<h1>' . esc_html( $title ) . '</h1><p>' . esc_html( $message ) . '</p></main></body></html>';
		exit;
	}

	/* =====================================================================
	 * REST: register, token, revoke
	 * ================================================================== */

	public static function register_routes() {
		register_rest_route( 'keha-mcp/v1', '/oauth/register', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_register' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'keha-mcp/v1', '/oauth/token', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_token' ),
			'permission_callback' => '__return_true',
		) );
		register_rest_route( 'keha-mcp/v1', '/oauth/revoke', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'rest_revoke' ),
			'permission_callback' => '__return_true',
		) );
	}

	private static function oauth_error( $error, $description, $status = 400 ) {
		$r = new WP_REST_Response( array( 'error' => $error, 'error_description' => $description ), $status );
		$r->header( 'Cache-Control', 'no-store' );
		$r->header( 'Pragma', 'no-cache' );
		return $r;
	}

	private static function params( WP_REST_Request $req ) {
		$json = $req->get_json_params();
		$body = $req->get_body_params();
		return array_merge( is_array( $body ) ? $body : array(), is_array( $json ) ? $json : array() );
	}

	public static function rest_register( WP_REST_Request $req ) {
		if ( ! self::dcr_enabled() ) {
			return self::oauth_error( 'access_denied', 'Dynamisk klientregistrering er slået fra på keha.dk.', 403 );
		}

		// Simpel hastighedsbegrænsning: højst 20 registreringer i timen.
		$count = (int) get_transient( 'keha_mcp_reg_count' );
		if ( $count >= 20 ) {
			return self::oauth_error( 'temporarily_unavailable', 'For mange registreringer. Prøv igen om lidt.', 429 );
		}
		set_transient( 'keha_mcp_reg_count', $count + 1, HOUR_IN_SECONDS );

		$b    = self::params( $req );
		$uris = isset( $b['redirect_uris'] ) && is_array( $b['redirect_uris'] ) ? array_values( $b['redirect_uris'] ) : array();
		if ( empty( $uris ) || count( $uris ) > 10 ) {
			return self::oauth_error( 'invalid_redirect_uri', 'redirect_uris mangler.' );
		}
		foreach ( $uris as $u ) {
			if ( ! self::redirect_uri_allowed( $u ) ) {
				return self::oauth_error( 'invalid_redirect_uri', 'Redirect-URI er ikke tilladt: ' . ( is_string( $u ) ? $u : '?' ) . '. Tilladte værter kan ændres under Indstillinger → KEHA MCP.' );
			}
		}

		$method = isset( $b['token_endpoint_auth_method'] ) ? (string) $b['token_endpoint_auth_method'] : 'client_secret_basic';
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			return self::oauth_error( 'invalid_client_metadata', 'token_endpoint_auth_method understøttes ikke.' );
		}
		$grants = isset( $b['grant_types'] ) && is_array( $b['grant_types'] ) ? $b['grant_types'] : array( 'authorization_code' );
		if ( array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) ) {
			return self::oauth_error( 'invalid_client_metadata', 'Kun authorization_code og refresh_token understøttes.' );
		}

		$name      = isset( $b['client_name'] ) ? sanitize_text_field( (string) $b['client_name'] ) : '';
		$name      = '' !== $name ? mb_substr( $name, 0, 100 ) : 'MCP-klient';
		$client_id = 'kmcpc_' . bin2hex( random_bytes( 12 ) );
		$secret    = 'none' === $method ? null : 'kmcps_' . bin2hex( random_bytes( 24 ) );
		$now       = time();

		self::save_client( $client_id, array(
			'name'          => $name,
			'redirect_uris' => $uris,
			'auth_method'   => $method,
			'secret_hash'   => $secret ? hash( 'sha256', $secret ) : '',
			'grant_types'   => array_values( array_unique( array_merge( $grants, array( 'authorization_code', 'refresh_token' ) ) ) ),
			'created'       => $now,
		) );

		$out = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => $now,
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
		);
		if ( $secret ) {
			$out['client_secret']            = $secret;
			$out['client_secret_expires_at'] = 0;
		}
		$r = new WP_REST_Response( $out, 201 );
		$r->header( 'Cache-Control', 'no-store' );
		return $r;
	}

	/** Klientgodkendelse ved token/revoke. @return array|WP_REST_Response Klient eller fejl. */
	private static function authenticate_client( array $p ) {
		$id     = '';
		$secret = null;
		$header = self::authorization_header();
		if ( preg_match( '/^Basic\s+(\S+)$/i', $header, $m ) ) {
			$decoded = base64_decode( $m[1], true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $id, $secret ) = explode( ':', $decoded, 2 );
				$id     = urldecode( $id );
				$secret = urldecode( $secret );
			}
		}
		if ( '' === $id && isset( $p['client_id'] ) ) {
			$id = (string) $p['client_id'];
		}
		if ( null === $secret && isset( $p['client_secret'] ) ) {
			$secret = (string) $p['client_secret'];
		}

		$client = self::get_client( $id );
		if ( ! $client ) {
			return self::oauth_error( 'invalid_client', 'Ukendt klient.', 401 );
		}
		if ( 'none' !== $client['auth_method'] ) {
			if ( null === $secret || '' === $client['secret_hash'] || ! hash_equals( $client['secret_hash'], hash( 'sha256', $secret ) ) ) {
				return self::oauth_error( 'invalid_client', 'Klientgodkendelse mislykkedes.', 401 );
			}
		}
		$client['id'] = $id;
		return $client;
	}

	public static function rest_token( WP_REST_Request $req ) {
		$p      = self::params( $req );
		$client = self::authenticate_client( $p );
		if ( $client instanceof WP_REST_Response ) {
			return $client;
		}
		$grant = isset( $p['grant_type'] ) ? (string) $p['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			$code = isset( $p['code'] ) ? (string) $p['code'] : '';
			$key  = 'keha_mcp_code_' . hash( 'sha256', $code );
			$data = '' !== $code ? get_transient( $key ) : false;
			delete_transient( $key ); // Engangskode.

			if ( ! is_array( $data ) || $data['client_id'] !== $client['id'] ) {
				return self::oauth_error( 'invalid_grant', 'Koden er ugyldig eller udløbet.' );
			}
			if ( ! isset( $p['redirect_uri'] ) || (string) $p['redirect_uri'] !== $data['redirect_uri'] ) {
				return self::oauth_error( 'invalid_grant', 'redirect_uri matcher ikke.' );
			}
			$verifier = isset( $p['code_verifier'] ) ? (string) $p['code_verifier'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9\-\._~]{43,128}$/', $verifier ) ) {
				return self::oauth_error( 'invalid_grant', 'code_verifier mangler eller er ugyldig.' );
			}
			$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
			if ( ! hash_equals( $data['code_challenge'], $challenge ) ) {
				return self::oauth_error( 'invalid_grant', 'PKCE-verifikation mislykkedes.' );
			}
			if ( ! user_can( (int) $data['user_id'], 'manage_options' ) ) {
				return self::oauth_error( 'invalid_grant', 'Brugeren har ikke længere adgang.' );
			}
			return self::token_response( self::issue_tokens( $client['id'], (int) $data['user_id'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			global $wpdb;
			$token = isset( $p['refresh_token'] ) ? (string) $p['refresh_token'] : '';
			$row   = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
				'SELECT * FROM ' . self::table() . " WHERE token_hash = %s AND token_type = 'refresh'",
				hash( 'sha256', $token )
			) );
			if ( ! $row || $row->client_id !== $client['id'] || (int) $row->expires_at < time() ) {
				return self::oauth_error( 'invalid_grant', 'Refresh-token er ugyldigt eller udløbet.' );
			}
			if ( ! user_can( (int) $row->user_id, 'manage_options' ) ) {
				$wpdb->delete( self::table(), array( 'family' => $row->family ) );
				return self::oauth_error( 'invalid_grant', 'Brugeren har ikke længere adgang.' );
			}
			// Rotation: det gamle refresh-token og familiens gamle access-tokens udgår.
			$wpdb->delete( self::table(), array( 'family' => $row->family ) );
			return self::token_response( self::issue_tokens( $client['id'], (int) $row->user_id, $row->family ) );
		}

		return self::oauth_error( 'unsupported_grant_type', 'grant_type understøttes ikke.' );
	}

	public static function rest_revoke( WP_REST_Request $req ) {
		global $wpdb;
		$p      = self::params( $req );
		$client = self::authenticate_client( $p );
		if ( $client instanceof WP_REST_Response ) {
			return $client;
		}
		$token = isset( $p['token'] ) ? (string) $p['token'] : '';
		if ( '' !== $token ) {
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT family, client_id FROM ' . self::table() . ' WHERE token_hash = %s', hash( 'sha256', $token ) ) ); // phpcs:ignore
			if ( $row && $row->client_id === $client['id'] ) {
				$wpdb->delete( self::table(), array( 'family' => $row->family ) );
			}
		}
		return new WP_REST_Response( null, 200 );
	}

	private static function issue_tokens( $client_id, $user_id, $family = null ) {
		global $wpdb;
		$family  = $family ? $family : bin2hex( random_bytes( 16 ) );
		$now     = time();
		$access  = 'kmcp_at_' . bin2hex( random_bytes( 32 ) );
		$refresh = 'kmcp_rt_' . bin2hex( random_bytes( 32 ) );

		foreach ( array( array( $access, 'access', self::ACCESS_TTL ), array( $refresh, 'refresh', self::REFRESH_TTL ) ) as $t ) {
			$wpdb->insert( self::table(), array(
				'token_hash' => hash( 'sha256', $t[0] ),
				'token_type' => $t[1],
				'client_id'  => $client_id,
				'user_id'    => $user_id,
				'family'     => $family,
				'scope'      => self::SCOPE,
				'expires_at' => $now + $t[2],
				'created_at' => $now,
				'last_used'  => 0,
			) );
		}
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => self::SCOPE,
		);
	}

	private static function token_response( array $tokens ) {
		$r = new WP_REST_Response( $tokens, 200 );
		$r->header( 'Cache-Control', 'no-store' );
		$r->header( 'Pragma', 'no-cache' );
		return $r;
	}

	/* =====================================================================
	 * Bearer-godkendelse af MCP-kald
	 * ================================================================== */

	private static function authorization_header() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) ); // phpcs:ignore
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( (string) wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ); // phpcs:ignore
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $k => $v ) {
				if ( 'authorization' === strtolower( $k ) ) {
					return trim( (string) $v );
				}
			}
		}
		return '';
	}

	private static function request_uri_contains( $needle ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore
		return false !== strpos( $uri, $needle );
	}

	private static function is_mcp_request() {
		return self::request_uri_contains( 'keha-mcp/mcp' );
	}

	public static function determine_user( $user_id ) {
		if ( $user_id || ! self::is_mcp_request() ) {
			return $user_id; // Fx Application Password fra desktopappen – virker fortsat.
		}
		if ( ! preg_match( '/^Bearer\s+(kmcp_at_[a-f0-9]{64})$/i', self::authorization_header(), $m ) ) {
			return $user_id;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( // phpcs:ignore
			'SELECT id, user_id, last_used FROM ' . self::table() . " WHERE token_hash = %s AND token_type = 'access' AND expires_at > %d",
			hash( 'sha256', $m[1] ),
			time()
		) );
		if ( ! $row ) {
			self::$bearer_state = 'invalid';
			return $user_id;
		}
		self::$bearer_state = 'valid';
		if ( time() - (int) $row->last_used > 300 ) {
			$wpdb->update( self::table(), array( 'last_used' => time() ), array( 'id' => $row->id ) );
		}
		return (int) $row->user_id;
	}

	/** Basic-auth ved /oauth/token er klient-id/hemmelighed, ikke et Application Password. */
	public static function skip_app_passwords_on_oauth_routes( $is_api_request ) {
		if ( self::request_uri_contains( 'keha-mcp/v1/oauth/' ) ) {
			return false;
		}
		return $is_api_request;
	}

	public static function authentication_errors( $result ) {
		if ( 'invalid' === self::$bearer_state && self::is_mcp_request() ) {
			return new WP_Error( 'invalid_token', 'Adgangstokenet er ugyldigt eller udløbet.', array( 'status' => 401 ) );
		}
		return $result;
	}

	/**
	 * Pluginet "JWT Authentication for WP REST API" afviser alle Bearer-tokens, det ikke selv
	 * har udstedt (og Basic-auth). På vores egne ruter ignoreres dets fejl.
	 */
	public static function neutralize_foreign_auth_errors( $result, $server, $request ) {
		if ( is_wp_error( $result ) && 0 === strpos( $request->get_route(), '/keha-mcp/' ) && 0 === strpos( (string) $result->get_error_code(), 'jwt_auth' ) ) {
			return null;
		}
		return $result;
	}

	/** RFC 9728: 401 på MCP-endpointet fortæller klienten, hvor den logger ind. */
	public static function add_www_authenticate( $response, $server, $request ) {
		if ( ! ( $response instanceof WP_HTTP_Response ) || 0 !== strpos( $request->get_route(), '/keha-mcp/mcp' ) ) {
			return $response;
		}
		$status = $response->get_status();
		if ( 403 === $status && ! is_user_logged_in() ) {
			$response->set_status( 401 );
			$status = 401;
		}
		if ( 401 === $status ) {
			$value = sprintf( 'Bearer resource_metadata="%s", scope="%s"', self::prm_url(), self::SCOPE );
			if ( 'invalid' === self::$bearer_state ) {
				$value .= ', error="invalid_token"';
			}
			$response->header( 'WWW-Authenticate', $value );
		}
		return $response;
	}

	/* =====================================================================
	 * Til admin-siden
	 * ================================================================== */

	public static function active_sessions() {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( // phpcs:ignore
			'SELECT family, client_id, user_id, MIN(created_at) AS created_at, MAX(last_used) AS last_used, MAX(expires_at) AS expires_at
			 FROM ' . self::table() . " GROUP BY family, client_id, user_id
			 HAVING SUM(token_type = 'refresh' AND expires_at > %d) > 0 ORDER BY created_at DESC",
			time()
		) );
	}

	public static function revoke_family( $family ) {
		global $wpdb;
		$wpdb->delete( self::table(), array( 'family' => $family ) );
	}
}
