<?php
/**
 * Remove all plugin data.
 *
 * @package OpenMCP
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 'open_mcp_settings', 'open_mcp_api_keys', 'open_mcp_oauth_clients', 'open_mcp_oauth_tokens', 'open_mcp_log' ) as $open_mcp_option ) {
	delete_option( $open_mcp_option );
}

global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_open\_mcp\_%' OR option_name LIKE '\_transient\_timeout\_open\_mcp\_%'" );
