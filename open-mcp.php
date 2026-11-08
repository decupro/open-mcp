<?php
/**
 * Plugin Name:       Open MCP
 * Plugin URI:        https://github.com/decupro/open-mcp
 * Description:       Free and open-source MCP (Model Context Protocol) server for WordPress. Connect Claude, ChatGPT, Cursor, VS Code or any MCP client and manage your whole site: content, media, users, comments, menus, plugins, themes, templates, styles, settings and every REST route.
 * Version:           1.0.0
 * Requires at least: 6.4
 * Requires PHP:      7.4
 * Author:            Open MCP Contributors
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       open-mcp
 *
 * @package OpenMCP
 */

defined( 'ABSPATH' ) || exit;

define( 'OPEN_MCP_VERSION', '1.0.0' );
define( 'OPEN_MCP_FILE', __FILE__ );
define( 'OPEN_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'OPEN_MCP_URL', plugin_dir_url( __FILE__ ) );

require_once OPEN_MCP_DIR . 'includes/class-plugin.php';
require_once OPEN_MCP_DIR . 'includes/class-schema.php';
require_once OPEN_MCP_DIR . 'includes/class-util.php';
require_once OPEN_MCP_DIR . 'includes/class-logger.php';
require_once OPEN_MCP_DIR . 'includes/class-auth.php';
require_once OPEN_MCP_DIR . 'includes/class-oauth.php';
require_once OPEN_MCP_DIR . 'includes/class-tool-registry.php';
require_once OPEN_MCP_DIR . 'includes/class-server.php';
require_once OPEN_MCP_DIR . 'includes/class-transport.php';
require_once OPEN_MCP_DIR . 'includes/class-admin.php';

foreach ( glob( OPEN_MCP_DIR . 'includes/tools/*.php' ) as $open_mcp_tool_file ) {
	require_once $open_mcp_tool_file;
}
unset( $open_mcp_tool_file );

add_action( 'plugins_loaded', array( '\\OpenMCP\\Plugin', 'instance' ) );

register_activation_hook( __FILE__, array( '\\OpenMCP\\Plugin', 'activate' ) );
