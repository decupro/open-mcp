<?php
/**
 * Site-level tools: info, settings, options, search, cache.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Site tools.
 */
class Site {

	/** Settings exposed via wp_get_settings / wp_update_settings. */
	const SETTINGS = array(
		'blogname'               => 'string',
		'blogdescription'        => 'string',
		'timezone_string'        => 'string',
		'gmt_offset'             => 'number',
		'date_format'            => 'string',
		'time_format'            => 'string',
		'start_of_week'          => 'integer',
		'WPLANG'                 => 'string',
		'posts_per_page'         => 'integer',
		'posts_per_rss'          => 'integer',
		'rss_use_excerpt'        => 'boolean',
		'show_on_front'          => 'string',
		'page_on_front'          => 'integer',
		'page_for_posts'         => 'integer',
		'blog_public'            => 'boolean',
		'default_category'       => 'integer',
		'default_post_format'    => 'string',
		'default_comment_status' => 'string',
		'default_ping_status'    => 'string',
		'comment_registration'   => 'boolean',
		'comment_moderation'     => 'boolean',
		'comments_notify'        => 'boolean',
		'moderation_notify'      => 'boolean',
		'thread_comments'        => 'boolean',
		'thread_comments_depth'  => 'integer',
		'page_comments'          => 'boolean',
		'comments_per_page'      => 'integer',
		'close_comments_for_old_posts' => 'boolean',
		'close_comments_days_old' => 'integer',
		'users_can_register'     => 'boolean',
		'default_role'           => 'string',
		'permalink_structure'    => 'string',
		'category_base'          => 'string',
		'tag_base'               => 'string',
		'site_icon'              => 'integer',
		'thumbnail_size_w'       => 'integer',
		'thumbnail_size_h'       => 'integer',
		'medium_size_w'          => 'integer',
		'medium_size_h'          => 'integer',
		'large_size_w'           => 'integer',
		'large_size_h'           => 'integer',
		'uploads_use_yearmonth_folders' => 'boolean',
	);

	/** Options that must never be changed through wp_update_option. */
	const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'active_plugins', 'active_sitewide_plugins', 'template', 'stylesheet', 'cron', 'rewrite_rules', 'admin_email', 'new_admin_email', 'db_version', 'initial_db_version', 'auth_key', 'auth_salt', 'logged_in_key', 'logged_in_salt', 'nonce_key', 'nonce_salt', 'secure_auth_key', 'secure_auth_salt', 'recovery_keys', 'uninstall_plugins' );

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'        => 'wp_site_info',
				'category'    => 'Site',
				'description' => 'Overview of the WordPress site: name, URLs, versions, active theme, content counts, timezone, permalink structure, and the connected user. Call this first to orient yourself.',
				'capability'  => 'read',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'site_info' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_search',
				'category'     => 'Site',
				'description'  => 'Full-text search across posts, pages and other public post types. Returns matching items with type, status and link.',
				'capability'   => 'read',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'query'      => Schema::str( 'Search terms.' ),
							'post_types' => Schema::arr( 'Limit to these post types. Default: all searchable types.', array( 'type' => 'string' ) ),
						),
						Schema::paging()
					),
					array( 'query' )
				),
				'callback'     => array( __CLASS__, 'search' ),
			)
		);

		$settings_props = array();
		foreach ( self::SETTINGS as $key => $type ) {
			$settings_props[ $key ] = array( 'type' => $type );
		}

		$r->register(
			array(
				'name'        => 'wp_get_settings',
				'category'    => 'Site',
				'description' => 'Read core site settings (title, tagline, timezone, reading, discussion, permalinks, media sizes, front page).',
				'capability'  => 'manage_options',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'get_settings' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_settings',
				'category'     => 'Site',
				'description'  => 'Update core site settings. Only pass the settings you want to change. Use show_on_front="page" with page_on_front/page_for_posts to set a static front page.',
				'capability'   => 'manage_options',
				'idempotent'   => true,
				'input_schema' => Schema::obj( $settings_props ),
				'callback'     => array( __CLASS__, 'update_settings' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_option',
				'category'     => 'Site',
				'description'  => 'Read any raw WordPress option by name (including plugin options). Use wp_list_options to discover names.',
				'capability'   => 'manage_options',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'name' => Schema::str( 'Option name.' ) ), array( 'name' ) ),
				'callback'     => array( __CLASS__, 'get_option' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_list_options',
				'category'     => 'Site',
				'description'  => 'List option names, optionally filtered by a substring (e.g. "woocommerce", "yoast"). Returns names and value sizes only.',
				'capability'   => 'manage_options',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'search' => Schema::str( 'Substring to match in option names.' ),
						'limit'  => Schema::int( 'Max results (default 100, max 500).' ),
					)
				),
				'callback'     => array( __CLASS__, 'list_options' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_option',
				'category'     => 'Site',
				'description'  => 'Set any raw WordPress option (e.g. plugin settings). Value may be a string, number, boolean, array or object. Core-critical options (siteurl, home, active_plugins, template, salts…) are blocked. When merge=true and both the stored and new values are objects, keys are merged instead of replaced.',
				'capability'   => 'manage_options',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'name'  => Schema::str( 'Option name.' ),
						'value' => array( 'description' => 'New value (any JSON type).' ),
						'merge' => Schema::bool( 'Deep-merge into an existing array/object value. Default false.' ),
					),
					array( 'name', 'value' )
				),
				'callback'     => array( __CLASS__, 'update_option' ),
			)
		);

		$r->register(
			array(
				'name'        => 'wp_flush_cache',
				'category'    => 'Site',
				'description' => 'Flush the WordPress object cache and rewrite rules (use after changing permalinks or registering post types). Also triggers common page-cache plugins to purge.',
				'capability'  => 'manage_options',
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'flush_cache' ),
			)
		);
	}

	/** @return array */
	public static function site_info() {
		global $wp_version;
		$theme = wp_get_theme();
		$user  = wp_get_current_user();

		$counts = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			$c = wp_count_posts( $pt->name );
			if ( 'attachment' === $pt->name ) {
				$counts[ $pt->name ] = array_sum( (array) wp_count_attachments() );
				continue;
			}
			$counts[ $pt->name ] = array(
				'publish' => (int) ( isset( $c->publish ) ? $c->publish : 0 ),
				'draft'   => (int) ( isset( $c->draft ) ? $c->draft : 0 ),
				'pending' => (int) ( isset( $c->pending ) ? $c->pending : 0 ),
				'private' => (int) ( isset( $c->private ) ? $c->private : 0 ),
				'future'  => (int) ( isset( $c->future ) ? $c->future : 0 ),
			);
		}

		$info = array(
			'name'                => get_bloginfo( 'name' ),
			'tagline'             => get_bloginfo( 'description' ),
			'home_url'            => home_url(),
			'admin_url'           => admin_url(),
			'language'            => get_locale(),
			'timezone'            => wp_timezone_string(),
			'wordpress_version'   => $wp_version,
			'php_version'         => PHP_VERSION,
			'multisite'           => is_multisite(),
			'permalink_structure' => get_option( 'permalink_structure' ) ? get_option( 'permalink_structure' ) : 'plain',
			'show_on_front'       => get_option( 'show_on_front' ),
			'page_on_front'       => (int) get_option( 'page_on_front' ),
			'page_for_posts'      => (int) get_option( 'page_for_posts' ),
			'theme'               => array(
				'name'           => $theme->get( 'Name' ),
				'stylesheet'     => $theme->get_stylesheet(),
				'version'        => $theme->get( 'Version' ),
				'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
				'parent'         => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
			),
			'content_counts'      => $counts,
			'abilities_api'       => function_exists( 'wp_get_abilities' ),
			'current_user'        => array(
				'id'       => $user->ID,
				'username' => $user->user_login,
				'roles'    => array_values( $user->roles ),
			),
		);

		if ( current_user_can( 'activate_plugins' ) ) {
			$info['active_plugins'] = array_values( (array) get_option( 'active_plugins', array() ) );
		}
		return $info;
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function search( array $args ) {
		list( $per_page, $page ) = Util::paging( $args );
		$types                   = ! empty( $args['post_types'] ) ? array_map( 'sanitize_key', (array) $args['post_types'] ) : array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
		$q                       = new \WP_Query(
			array(
				's'              => (string) $args['query'],
				'post_type'      => $types,
				'post_status'    => current_user_can( 'edit_posts' ) ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : 'publish',
				'perm'           => 'readable',
				'posts_per_page' => $per_page,
				'paged'          => $page,
			)
		);
		return array(
			'total' => (int) $q->found_posts,
			'page'  => $page,
			'items' => array_map(
				static function ( $p ) {
					return Util::format_post( $p );
				},
				$q->posts
			),
		);
	}

	/** @return array */
	public static function get_settings() {
		$out = array();
		foreach ( self::SETTINGS as $key => $type ) {
			$value = get_option( $key );
			switch ( $type ) {
				case 'integer':
					$value = (int) $value;
					break;
				case 'boolean':
					$value = in_array( $value, array( '1', 1, true, 'open', 'yes' ), true );
					break;
				case 'number':
					$value = (float) $value;
					break;
			}
			if ( in_array( $key, array( 'default_comment_status', 'default_ping_status' ), true ) ) {
				$value = (string) get_option( $key );
			}
			$out[ $key ] = $value;
		}
		return $out;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_settings( array $args ) {
		$changed = array();
		foreach ( $args as $key => $value ) {
			if ( ! isset( self::SETTINGS[ $key ] ) ) {
				return new \WP_Error( 'invalid_setting', "Unknown setting '$key'. Use wp_update_option for other options." );
			}
			if ( 'boolean' === self::SETTINGS[ $key ] ) {
				$value = $value ? '1' : '0';
			}
			if ( 'default_role' === $key && ! get_role( $value ) ) {
				return new \WP_Error( 'invalid_role', "Role '$value' does not exist." );
			}
			if ( 'timezone_string' === $key && '' !== $value && ! in_array( $value, timezone_identifiers_list(), true ) ) {
				return new \WP_Error( 'invalid_timezone', "Unknown timezone '$value'." );
			}
			$value = sanitize_option( $key, $value );
			update_option( $key, $value );
			$changed[] = $key;
		}
		if ( array_intersect( $changed, array( 'permalink_structure', 'category_base', 'tag_base' ) ) ) {
			flush_rewrite_rules( false );
		}
		return array(
			'updated'  => $changed,
			'settings' => array_intersect_key( self::get_settings(), array_flip( $changed ) ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_option( array $args ) {
		$name = (string) $args['name'];
		if ( self::is_secret_option( $name ) ) {
			return new \WP_Error( 'forbidden', 'This option is not readable via MCP.' );
		}
		$missing = new \stdClass();
		$value   = get_option( $name, $missing );
		if ( $value === $missing ) {
			return new \WP_Error( 'not_found', "Option '$name' does not exist." );
		}
		return array(
			'name'  => $name,
			'value' => $value,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_options( array $args ) {
		global $wpdb;
		$limit  = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$search = isset( $args['search'] ) ? (string) $args['search'] : '';
		if ( '' !== $search ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS size, autoload FROM {$wpdb->options} WHERE option_name LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d", '%' . $wpdb->esc_like( $search ) . '%', $wpdb->esc_like( '_transient' ) . '%', $limit ) );
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT option_name, LENGTH(option_value) AS size, autoload FROM {$wpdb->options} WHERE option_name NOT LIKE %s AND option_name NOT LIKE %s ORDER BY option_name LIMIT %d", $wpdb->esc_like( '_transient' ) . '%', $wpdb->esc_like( '_site_transient' ) . '%', $limit ) );
		}
		$out = array();
		foreach ( (array) $rows as $row ) {
			if ( self::is_secret_option( $row->option_name ) ) {
				continue;
			}
			$out[] = array(
				'name'     => $row->option_name,
				'size'     => (int) $row->size,
				'autoload' => $row->autoload,
			);
		}
		return array( 'options' => $out );
	}

	/**
	 * Options that hold credentials of this plugin.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	private static function is_secret_option( $name ) {
		return 0 === strpos( $name, 'open_mcp_' );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_option( array $args ) {
		$name = (string) $args['name'];
		if ( self::is_secret_option( $name ) || in_array( $name, self::PROTECTED_OPTIONS, true ) || preg_match( '/_user_roles$/', $name ) ) {
			return new \WP_Error( 'forbidden', "The option '$name' is protected. Use the dedicated tools (plugins, themes, settings) instead." );
		}
		$value = $args['value'];
		if ( ! empty( $args['merge'] ) ) {
			$current = get_option( $name );
			if ( is_array( $current ) && is_array( $value ) ) {
				$value = array_replace_recursive( $current, $value );
			}
		}
		update_option( $name, $value );
		return array(
			'name'  => $name,
			'value' => get_option( $name ),
		);
	}

	/** @return array */
	public static function flush_cache() {
		wp_cache_flush();
		flush_rewrite_rules( false );
		// Common page-cache plugins.
		do_action( 'litespeed_purge_all' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}
		do_action( 'open_mcp_flush_cache' );
		return array( 'flushed' => true );
	}
}
