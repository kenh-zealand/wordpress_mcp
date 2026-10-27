<?php
/**
 * Plugin Name: KEHA MCP
 * Description: Egen MCP-server til keha.dk med indbygget OAuth 2.1, så den kan bruges som custom connector i claude.ai. Værktøjer til indlæg og sider (inkl. publicering), kategorier/tags, medier og temafiler. Kræver pluginet "MCP Adapter".
 * Version:     0.2.1
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Author:      Kenneth
 * License:     GPL-2.0-or-later
 * Text Domain: keha-mcp
 *
 * MCP-endpoint: https://keha.dk/wp-json/keha-mcp/mcp
 */

defined( 'ABSPATH' ) || exit;

define( 'KEHA_MCP_VERSION', '0.2.1' );
define( 'KEHA_MCP_FILE', __FILE__ );
define( 'KEHA_MCP_DIR', plugin_dir_path( __FILE__ ) );

require_once KEHA_MCP_DIR . 'includes/helpers.php';
require_once KEHA_MCP_DIR . 'includes/oauth.php';
require_once KEHA_MCP_DIR . 'includes/abilities-content.php';
require_once KEHA_MCP_DIR . 'includes/abilities-media.php';
require_once KEHA_MCP_DIR . 'includes/abilities-theme.php';

if ( is_admin() ) {
	require_once KEHA_MCP_DIR . 'includes/admin.php';
}

/* -------------------------------------------------------------------------
 * OAuth 2.1 (så claude.ai kan logge ind)
 * ---------------------------------------------------------------------- */
Keha_MCP_OAuth::init();
register_activation_hook( __FILE__, array( 'Keha_MCP_OAuth', 'install' ) );
register_deactivation_hook( __FILE__, array( 'Keha_MCP_OAuth', 'deactivate' ) );

/* -------------------------------------------------------------------------
 * Abilities (værktøjerne)
 * ---------------------------------------------------------------------- */
add_action( 'wp_abilities_api_categories_init', function () {
	wp_register_ability_category( 'keha', array(
		'label'       => 'keha.dk',
		'description' => 'Indholds-, medie- og temaværktøjer til keha.dk.',
	) );
} );

add_action( 'wp_abilities_api_init', function () {
	keha_mcp_register_content_abilities();
	keha_mcp_register_media_abilities();
	keha_mcp_register_theme_abilities();
} );

/* -------------------------------------------------------------------------
 * Egen MCP-server, der kun eksponerer keha/*-værktøjerne
 * URL: /wp-json/keha-mcp/mcp
 * ---------------------------------------------------------------------- */
add_action( 'mcp_adapter_init', function ( $adapter ) {
	$adapter->create_server(
		'keha-mcp',                                   // Server-id
		'keha-mcp',                                   // REST-namespace
		'mcp',                                        // REST-route
		'keha.dk MCP',                                // Navn
		'Indhold, medier og temafiler på keha.dk',    // Beskrivelse
		'v' . KEHA_MCP_VERSION,                       // Version
		array( \WP\MCP\Transport\HttpTransport::class ),
		\WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler::class,
		\WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler::class,
		keha_mcp_ability_names(),                     // Værktøjer
		array(),                                      // Resources
		array()                                       // Prompts
	);
} );

/* -------------------------------------------------------------------------
 * Advarsel hvis MCP Adapter mangler
 * ---------------------------------------------------------------------- */
add_action( 'admin_notices', function () {
	if ( ! current_user_can( 'activate_plugins' ) || did_action( 'mcp_adapter_init' ) || class_exists( '\WP\MCP\Core\McpAdapter' ) ) {
		return;
	}
	echo '<div class="notice notice-warning"><p><strong>KEHA MCP:</strong> Pluginet "MCP Adapter" ser ikke ud til at være aktivt. MCP-serveren virker først, når det er aktiveret.</p></div>';
} );
