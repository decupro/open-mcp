<?php
/**
 * Plugin management.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin tools.
 */
class Plugins {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$plugin_arg = Schema::obj( array( 'plugin' => Schema::str( 'Plugin file ("akismet/akismet.php") or folder slug ("akismet").' ) ), array( 'plugin' ) );

		$r->register(
			array(
				'name'        => 'wp_list_plugins',
				'category'    => 'Plugins',
				'description' => 'List installed plugins with version, active state and available updates.',
				'capability'  => 'activate_plugins',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_plugins' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_search_plugin_directory',
				'category'     => 'Plugins',
				'description'  => 'Search the WordPress.org plugin directory.',
				'capability'   => 'install_plugins',
				'read_only'    => true,
				'idempotent'   => true,
				'open_world'   => true,
				'input_schema' => Schema::obj( array( 'search' => Schema::str( 'Search terms.' ) ), array( 'search' ) ),
				'callback'     => array( __CLASS__, 'search_directory' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_install_plugin',
				'category'     => 'Plugins',
				'description'  => 'Install a plugin from the WordPress.org directory by slug, optionally activating it.',
				'capability'   => 'install_plugins',
				'open_world'   => true,
				'input_schema' => Schema::obj(
					array(
						'slug'     => Schema::str( 'WordPress.org plugin slug, e.g. "contact-form-7".' ),
						'activate' => Schema::bool( 'Activate after install.' ),
					),
					array( 'slug' )
				),
				'callback'     => array( __CLASS__, 'install_plugin' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_activate_plugin',
				'category'     => 'Plugins',
				'description'  => 'Activate an installed plugin.',
				'capability'   => 'activate_plugins',
				'idempotent'   => true,
				'input_schema' => $plugin_arg,
				'callback'     => array( __CLASS__, 'activate_plugin' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_deactivate_plugin',
				'category'     => 'Plugins',
				'description'  => 'Deactivate a plugin.',
				'capability'   => 'activate_plugins',
				'idempotent'   => true,
				'input_schema' => $plugin_arg,
				'callback'     => array( __CLASS__, 'deactivate_plugin' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_plugin',
				'category'     => 'Plugins',
				'description'  => 'Update a plugin to the latest version from WordPress.org.',
				'capability'   => 'update_plugins',
				'open_world'   => true,
				'input_schema' => $plugin_arg,
				'callback'     => array( __CLASS__, 'update_plugin' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_plugin',
				'category'     => 'Plugins',
				'description'  => 'Delete an inactive plugin and its files.',
				'capability'   => 'delete_plugins',
				'destructive'  => true,
				'input_schema' => $plugin_arg,
				'callback'     => array( __CLASS__, 'delete_plugin' ),
			)
		);
	}

	/**
	 * Resolve slug/file to plugin file.
	 *
	 * @param string $plugin Input.
	 * @return string|\WP_Error
	 */
	private static function resolve( $plugin ) {
		Util::admin_includes( 'plugin.php' );
		$plugin  = trim( (string) $plugin );
		$plugins = get_plugins();
		if ( isset( $plugins[ $plugin ] ) ) {
			return $plugin;
		}
		foreach ( array_keys( $plugins ) as $file ) {
			if ( dirname( $file ) === $plugin || basename( $file, '.php' ) === $plugin ) {
				return $file;
			}
		}
		return new \WP_Error( 'not_found', "Plugin '$plugin' is not installed. Use wp_list_plugins." );
	}

	/**
	 * @param string $file Plugin file.
	 * @return bool
	 */
	private static function is_self( $file ) {
		return plugin_basename( OPEN_MCP_FILE ) === $file;
	}

	/** @return array */
	public static function list_plugins() {
		Util::admin_includes( 'plugin.php' );
		$updates = get_site_transient( 'update_plugins' );
		$out     = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'plugin'      => $file,
				'slug'        => dirname( $file ) !== '.' ? dirname( $file ) : basename( $file, '.php' ),
				'name'        => $data['Name'],
				'version'     => $data['Version'],
				'author'      => wp_strip_all_tags( $data['Author'] ),
				'description' => wp_strip_all_tags( $data['Description'] ),
				'active'      => is_plugin_active( $file ),
				'update'      => isset( $updates->response[ $file ]->new_version ) ? $updates->response[ $file ]->new_version : null,
			);
		}
		$mu = array_keys( get_mu_plugins() );
		return array(
			'plugins'    => $out,
			'mu_plugins' => $mu,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function search_directory( array $args ) {
		Util::admin_includes( 'plugin-install.php' );
		$res = plugins_api(
			'query_plugins',
			array(
				'search'   => (string) $args['search'],
				'per_page' => 15,
				'fields'   => array(
					'short_description' => true,
					'sections'          => false,
					'icons'             => false,
					'banners'           => false,
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( $res->plugins as $p ) {
			$p     = (array) $p;
			$out[] = array(
				'slug'            => $p['slug'],
				'name'            => wp_strip_all_tags( $p['name'] ),
				'version'         => $p['version'],
				'rating'          => $p['rating'],
				'active_installs' => $p['active_installs'],
				'description'     => isset( $p['short_description'] ) ? $p['short_description'] : '',
			);
		}
		return array( 'results' => $out );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function install_plugin( array $args ) {
		Util::admin_includes( 'file.php', 'misc.php', 'plugin.php', 'plugin-install.php', 'class-wp-upgrader.php' );
		$slug = sanitize_key( $args['slug'] );
		$api  = plugins_api(
			'plugin_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		$existing = self::resolve( $slug );
		if ( ! is_wp_error( $existing ) ) {
			return new \WP_Error( 'already_installed', "Plugin '$slug' is already installed as $existing." );
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$res      = $upgrader->install( $api->download_link );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( ! $res ) {
			return new \WP_Error( 'install_failed', 'Installation failed. The server may need FTP credentials (FS_METHOD).' );
		}
		$file = $upgrader->plugin_info();
		$out  = array(
			'installed' => $file,
			'version'   => $api->version,
			'active'    => false,
		);
		if ( ! empty( $args['activate'] ) && $file ) {
			$act = activate_plugin( $file );
			if ( is_wp_error( $act ) ) {
				$out['activation_error'] = $act->get_error_message();
			} else {
				$out['active'] = true;
			}
		}
		return $out;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function activate_plugin( array $args ) {
		$file = self::resolve( $args['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( ! current_user_can( 'activate_plugin', $file ) ) {
			return new \WP_Error( 'forbidden', 'You cannot activate this plugin.' );
		}
		$res = activate_plugin( $file );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function deactivate_plugin( array $args ) {
		$file = self::resolve( $args['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_self( $file ) ) {
			return new \WP_Error( 'forbidden', 'The MCP plugin cannot deactivate itself. Do this in wp-admin.' );
		}
		if ( ! current_user_can( 'deactivate_plugin', $file ) ) {
			return new \WP_Error( 'forbidden', 'You cannot deactivate this plugin.' );
		}
		deactivate_plugins( $file );
		return array(
			'plugin' => $file,
			'active' => is_plugin_active( $file ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_plugin( array $args ) {
		$file = self::resolve( $args['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		Util::admin_includes( 'file.php', 'misc.php', 'class-wp-upgrader.php', 'update.php' );
		wp_update_plugins();
		$was_active = is_plugin_active( $file );
		$skin       = new \WP_Ajax_Upgrader_Skin();
		$upgrader   = new \Plugin_Upgrader( $skin );
		$res        = $upgrader->upgrade( $file );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( $skin->get_errors()->has_errors() ) {
			return $skin->get_errors();
		}
		if ( false === $res ) {
			return new \WP_Error( 'update_failed', 'Update failed (it may already be up to date, or filesystem credentials are required).' );
		}
		if ( $was_active && ! is_plugin_active( $file ) ) {
			activate_plugin( $file );
		}
		$data = get_plugin_data( WP_PLUGIN_DIR . '/' . $file, false, false );
		return array(
			'plugin'  => $file,
			'version' => $data['Version'],
			'active'  => is_plugin_active( $file ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_plugin( array $args ) {
		$file = self::resolve( $args['plugin'] );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		if ( self::is_self( $file ) ) {
			return new \WP_Error( 'forbidden', 'The MCP plugin cannot delete itself.' );
		}
		if ( is_plugin_active( $file ) ) {
			return new \WP_Error( 'plugin_active', 'Deactivate the plugin before deleting it.' );
		}
		Util::admin_includes( 'file.php', 'misc.php' );
		$res = delete_plugins( array( $file ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new \WP_Error( 'delete_failed', 'Could not delete the plugin (filesystem credentials may be required).' );
		}
		return array( 'deleted' => $file );
	}
}
