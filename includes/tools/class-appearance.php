<?php
/**
 * Themes, block templates, global styles, custom CSS.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Appearance tools.
 */
class Appearance {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'        => 'wp_list_themes',
				'category'    => 'Appearance',
				'description' => 'List installed themes, which one is active, and available updates.',
				'capability'  => 'switch_themes',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_themes' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_activate_theme',
				'category'     => 'Appearance',
				'description'  => 'Switch the active theme.',
				'capability'   => 'switch_themes',
				'input_schema' => Schema::obj( array( 'stylesheet' => Schema::str( 'Theme directory name (stylesheet) from wp_list_themes.' ) ), array( 'stylesheet' ) ),
				'callback'     => array( __CLASS__, 'activate_theme' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_install_theme',
				'category'     => 'Appearance',
				'description'  => 'Install a theme from the WordPress.org directory by slug (e.g. "twentytwentyfive"), optionally activating it.',
				'capability'   => 'install_themes',
				'open_world'   => true,
				'input_schema' => Schema::obj(
					array(
						'slug'     => Schema::str( 'WordPress.org theme slug.' ),
						'activate' => Schema::bool( 'Activate after install.' ),
					),
					array( 'slug' )
				),
				'callback'     => array( __CLASS__, 'install_theme' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_theme',
				'category'     => 'Appearance',
				'description'  => 'Delete an installed (inactive) theme.',
				'capability'   => 'delete_themes',
				'destructive'  => true,
				'input_schema' => Schema::obj( array( 'stylesheet' => Schema::str( 'Theme stylesheet.' ) ), array( 'stylesheet' ) ),
				'callback'     => array( __CLASS__, 'delete_theme' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_list_templates',
				'category'     => 'Appearance',
				'description'  => 'Block themes: list templates (index, single, page, archive…) or template parts (header, footer…).',
				'capability'   => 'edit_theme_options',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'type' => Schema::enum( 'Default wp_template.', array( 'wp_template', 'wp_template_part' ) ) ) ),
				'callback'     => array( __CLASS__, 'list_templates' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_template',
				'category'     => 'Appearance',
				'description'  => 'Block themes: get a template or template part with its block markup. ID format: "theme-slug//template-slug".',
				'capability'   => 'edit_theme_options',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'id'   => Schema::str( 'Template ID, e.g. "twentytwentyfive//single".' ),
						'type' => Schema::enum( 'Default wp_template.', array( 'wp_template', 'wp_template_part' ) ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'get_template' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_template',
				'category'     => 'Appearance',
				'description'  => 'Block themes: save new block markup for a template or template part (stored as a user customization; theme files are untouched). Use wp_reset_template to revert.',
				'capability'   => 'edit_theme_options',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'id'      => Schema::str( 'Template ID, e.g. "twentytwentyfive//footer".' ),
						'type'    => Schema::enum( 'Default wp_template.', array( 'wp_template', 'wp_template_part' ) ),
						'content' => Schema::str( 'Full block markup.' ),
					),
					array( 'id', 'content' )
				),
				'callback'     => array( __CLASS__, 'update_template' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_reset_template',
				'category'     => 'Appearance',
				'description'  => 'Block themes: remove user customizations of a template so the theme file is used again.',
				'capability'   => 'edit_theme_options',
				'destructive'  => true,
				'input_schema' => Schema::obj(
					array(
						'id'   => Schema::str( 'Template ID.' ),
						'type' => Schema::enum( 'Default wp_template.', array( 'wp_template', 'wp_template_part' ) ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'reset_template' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_global_styles',
				'category'     => 'Appearance',
				'description'  => 'Block themes: get site-wide styles (colors, typography, spacing, element and block styles). Returns the user customizations plus the merged effective palette and font sizes.',
				'capability'   => 'edit_theme_options',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'include_theme' => Schema::bool( 'Also return the full merged theme.json data (large).' ) ) ),
				'callback'     => array( __CLASS__, 'get_global_styles' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_global_styles',
				'category'     => 'Appearance',
				'description'  => 'Block themes: change site-wide styles using theme.json structure, e.g. {"styles":{"color":{"background":"#fff","text":"#111"},"elements":{"link":{"color":{"text":"#0a7"}}}}}. Merged into existing customizations unless replace=true.',
				'capability'   => 'edit_theme_options',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'styles'   => Schema::map( 'theme.json "styles" object.' ),
						'settings' => Schema::map( 'theme.json "settings" object (e.g. custom palette).' ),
						'replace'  => Schema::bool( 'Replace instead of merge.' ),
					)
				),
				'callback'     => array( __CLASS__, 'update_global_styles' ),
			)
		);

		$r->register(
			array(
				'name'        => 'wp_get_custom_css',
				'category'    => 'Appearance',
				'description' => 'Get the site\'s Additional CSS for the active theme.',
				'capability'  => 'edit_css',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => static function () {
					return array( 'css' => wp_get_custom_css() );
				},
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_custom_css',
				'category'     => 'Appearance',
				'description'  => 'Replace the site\'s Additional CSS for the active theme.',
				'capability'   => 'edit_css',
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'css' => Schema::str( 'Full CSS.' ) ), array( 'css' ) ),
				'callback'     => array( __CLASS__, 'update_custom_css' ),
			)
		);
	}

	/** @return array */
	public static function list_themes() {
		$updates = get_site_transient( 'update_themes' );
		$active  = get_stylesheet();
		$out     = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$out[] = array(
				'stylesheet'     => $slug,
				'name'           => $theme->get( 'Name' ),
				'version'        => $theme->get( 'Version' ),
				'active'         => $slug === $active,
				'parent'         => $theme->parent() ? $theme->parent()->get_stylesheet() : null,
				'is_block_theme' => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
				'update'         => isset( $updates->response[ $slug ]['new_version'] ) ? $updates->response[ $slug ]['new_version'] : null,
			);
		}
		return array( 'themes' => $out );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function activate_theme( array $args ) {
		$theme = wp_get_theme( (string) $args['stylesheet'] );
		if ( ! $theme->exists() || ! $theme->is_allowed() ) {
			return new \WP_Error( 'not_found', 'Theme not found or not allowed.' );
		}
		if ( $theme->errors() ) {
			return $theme->errors();
		}
		switch_theme( $theme->get_stylesheet() );
		return array(
			'active' => get_stylesheet(),
			'name'   => wp_get_theme()->get( 'Name' ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function install_theme( array $args ) {
		Util::admin_includes( 'file.php', 'misc.php', 'theme.php', 'class-wp-upgrader.php' );
		$slug = sanitize_key( $args['slug'] );
		$api  = themes_api(
			'theme_information',
			array(
				'slug'   => $slug,
				'fields' => array( 'sections' => false ),
			)
		);
		if ( is_wp_error( $api ) ) {
			return $api;
		}
		if ( wp_get_theme( $slug )->exists() ) {
			return new \WP_Error( 'already_installed', "Theme '$slug' is already installed." );
		}
		$skin     = new \WP_Ajax_Upgrader_Skin();
		$upgrader = new \Theme_Upgrader( $skin );
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
		if ( ! empty( $args['activate'] ) ) {
			switch_theme( $slug );
		}
		return array(
			'installed' => $slug,
			'version'   => $api->version,
			'active'    => get_stylesheet() === $slug,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_theme( array $args ) {
		$slug = (string) $args['stylesheet'];
		if ( in_array( $slug, array( get_stylesheet(), get_template() ), true ) ) {
			return new \WP_Error( 'theme_active', 'You cannot delete the active theme (or its parent).' );
		}
		if ( ! wp_get_theme( $slug )->exists() ) {
			return new \WP_Error( 'not_found', 'Theme not found.' );
		}
		Util::admin_includes( 'file.php', 'theme.php' );
		$res = delete_theme( $slug );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new \WP_Error( 'delete_failed', 'Could not delete the theme (filesystem credentials may be required).' );
		}
		return array( 'deleted' => $slug );
	}

	/**
	 * @param array $args Args.
	 * @return string
	 */
	private static function template_type( array $args ) {
		return isset( $args['type'] ) && 'wp_template_part' === $args['type'] ? 'wp_template_part' : 'wp_template';
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_templates( array $args ) {
		$type = self::template_type( $args );
		$out  = array();
		foreach ( get_block_templates( array(), $type ) as $t ) {
			$row = array(
				'id'          => $t->id,
				'slug'        => $t->slug,
				'title'       => $t->title,
				'description' => $t->description,
				'source'      => $t->source,
				'customized'  => 'custom' === $t->source,
			);
			if ( 'wp_template_part' === $type ) {
				$row['area'] = $t->area;
			}
			$out[] = $row;
		}
		return array(
			'type'      => $type,
			'templates' => $out,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_template( array $args ) {
		$type = self::template_type( $args );
		$t    = get_block_template( (string) $args['id'], $type );
		if ( ! $t ) {
			return new \WP_Error( 'not_found', 'Template not found. Use wp_list_templates for valid IDs.' );
		}
		return array(
			'id'      => $t->id,
			'slug'    => $t->slug,
			'title'   => $t->title,
			'source'  => $t->source,
			'content' => $t->content,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_template( array $args ) {
		$base = 'wp_template_part' === self::template_type( $args ) ? 'template-parts' : 'templates';
		$res  = Advanced::rest( 'POST', '/wp/v2/' . $base . '/' . $args['id'], array(), array( 'content' => (string) $args['content'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'id'     => $res['data']['id'],
			'source' => $res['data']['source'],
			'saved'  => true,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function reset_template( array $args ) {
		$base = 'wp_template_part' === self::template_type( $args ) ? 'template-parts' : 'templates';
		$res  = Advanced::rest( 'DELETE', '/wp/v2/' . $base . '/' . $args['id'], array( 'force' => true ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'id'    => $args['id'],
			'reset' => true,
		);
	}

	/**
	 * @return int|\WP_Error
	 */
	private static function global_styles_id() {
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) || ! method_exists( '\WP_Theme_JSON_Resolver', 'get_user_global_styles_post_id' ) ) {
			return new \WP_Error( 'unsupported', 'Global styles are not supported on this WordPress version.' );
		}
		$id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		return $id ? (int) $id : new \WP_Error( 'unsupported', 'Could not locate the global styles record for the active theme.' );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_global_styles( array $args ) {
		$id = self::global_styles_id();
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$res = Advanced::rest( 'GET', '/wp/v2/global-styles/' . $id, array( 'context' => 'edit' ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$settings = wp_get_global_settings();
		$out      = array(
			'id'              => $id,
			'user_styles'     => ! empty( $res['data']['styles'] ) ? $res['data']['styles'] : new \stdClass(),
			'user_settings'   => ! empty( $res['data']['settings'] ) ? $res['data']['settings'] : new \stdClass(),
			'palette'         => isset( $settings['color']['palette'] ) ? $settings['color']['palette'] : array(),
			'font_sizes'      => isset( $settings['typography']['fontSizes'] ) ? $settings['typography']['fontSizes'] : array(),
			'font_families'   => isset( $settings['typography']['fontFamilies'] ) ? $settings['typography']['fontFamilies'] : array(),
			'effective_root'  => array_intersect_key( wp_get_global_styles(), array_flip( array( 'color', 'typography', 'spacing', 'elements' ) ) ),
		);
		if ( ! empty( $args['include_theme'] ) ) {
			$out['theme_settings'] = $settings;
			$out['theme_styles']   = wp_get_global_styles();
		}
		return $out;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_global_styles( array $args ) {
		$id = self::global_styles_id();
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$current = Advanced::rest( 'GET', '/wp/v2/global-styles/' . $id, array( 'context' => 'edit' ) );
		if ( is_wp_error( $current ) ) {
			return $current;
		}
		$body = array();
		foreach ( array( 'styles', 'settings' ) as $key ) {
			if ( ! isset( $args[ $key ] ) || ! is_array( $args[ $key ] ) ) {
				continue;
			}
			$existing     = isset( $current['data'][ $key ] ) && is_array( $current['data'][ $key ] ) ? $current['data'][ $key ] : array();
			$body[ $key ] = ! empty( $args['replace'] ) ? $args[ $key ] : array_replace_recursive( $existing, $args[ $key ] );
		}
		if ( ! $body ) {
			return new \WP_Error( 'nothing_to_update', 'Pass "styles" and/or "settings".' );
		}
		$res = Advanced::rest( 'POST', '/wp/v2/global-styles/' . $id, array(), $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'id'       => $id,
			'styles'   => $res['data']['styles'],
			'settings' => $res['data']['settings'],
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_custom_css( array $args ) {
		$res = wp_update_custom_css_post( (string) $args['css'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'saved'  => true,
			'length' => strlen( (string) $args['css'] ),
		);
	}
}
