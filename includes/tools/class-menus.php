<?php
/**
 * Classic navigation menus. (Block-theme navigation lives in wp_navigation posts:
 * edit those with wp_list_posts / wp_update_post using post_type "wp_navigation".)
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;

defined( 'ABSPATH' ) || exit;

/**
 * Menu tools.
 */
class Menus {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'        => 'wp_list_menus',
				'category'    => 'Menus',
				'description' => 'List classic navigation menus, theme menu locations and which menu is assigned where. For block themes, navigation menus are "wp_navigation" posts (use wp_list_posts with post_type="wp_navigation").',
				'capability'  => 'edit_theme_options',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_menus' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_menu',
				'category'     => 'Menus',
				'description'  => 'Get a classic menu and all its items in order.',
				'capability'   => 'edit_theme_options',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'menu' => array( 'type' => array( 'integer', 'string' ), 'description' => 'Menu ID, slug or name.' ) ), array( 'menu' ) ),
				'callback'     => array( __CLASS__, 'get_menu' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_create_menu',
				'category'     => 'Menus',
				'description'  => 'Create a classic menu, optionally assigning it to a theme location.',
				'capability'   => 'edit_theme_options',
				'input_schema' => Schema::obj(
					array(
						'name'     => Schema::str( 'Menu name.' ),
						'location' => Schema::str( 'Theme location slug (see wp_list_menus).' ),
					),
					array( 'name' )
				),
				'callback'     => array( __CLASS__, 'create_menu' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_menu',
				'category'     => 'Menus',
				'description'  => 'Delete a classic menu and its items.',
				'capability'   => 'edit_theme_options',
				'destructive'  => true,
				'input_schema' => Schema::obj( array( 'menu_id' => Schema::int( 'Menu ID.' ) ), array( 'menu_id' ) ),
				'callback'     => array( __CLASS__, 'delete_menu' ),
			)
		);

		$item_fields = array(
			'title'     => Schema::str( 'Link text. Defaults to the linked object\'s title.' ),
			'type'      => Schema::enum( 'Item kind.', array( 'custom', 'post_type', 'taxonomy' ) ),
			'object'    => Schema::str( 'For post_type/taxonomy items: "page", "post", "category", etc.' ),
			'object_id' => Schema::int( 'ID of the linked post or term.' ),
			'url'       => Schema::str( 'URL for custom links.' ),
			'parent_id' => Schema::int( 'Parent menu item ID for sub-menus (0 = top level).' ),
			'position'  => Schema::int( 'Order within the menu (1-based).' ),
			'target'    => Schema::enum( 'Link target.', array( '', '_blank' ) ),
			'classes'   => Schema::str( 'CSS classes, space separated.' ),
		);

		$r->register(
			array(
				'name'         => 'wp_add_menu_item',
				'category'     => 'Menus',
				'description'  => 'Add a link to a classic menu: a page/post (type=post_type, object="page", object_id), a category (type=taxonomy) or a custom URL (type=custom, url).',
				'capability'   => 'edit_theme_options',
				'input_schema' => Schema::obj( array_merge( array( 'menu_id' => Schema::int( 'Menu ID.' ) ), $item_fields ), array( 'menu_id' ) ),
				'callback'     => array( __CLASS__, 'add_item' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_menu_item',
				'category'     => 'Menus',
				'description'  => 'Change a menu item (title, URL, parent, position…). Unspecified fields are kept.',
				'capability'   => 'edit_theme_options',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'menu_id' => Schema::int( 'Menu ID.' ),
							'item_id' => Schema::int( 'Menu item ID.' ),
						),
						$item_fields
					),
					array( 'menu_id', 'item_id' )
				),
				'callback'     => array( __CLASS__, 'update_item' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_menu_item',
				'category'     => 'Menus',
				'description'  => 'Remove an item from a menu.',
				'capability'   => 'edit_theme_options',
				'destructive'  => true,
				'input_schema' => Schema::obj( array( 'item_id' => Schema::int( 'Menu item ID.' ) ), array( 'item_id' ) ),
				'callback'     => array( __CLASS__, 'delete_item' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_assign_menu_location',
				'category'     => 'Menus',
				'description'  => 'Assign a menu to a theme location (menu_id=0 clears the location).',
				'capability'   => 'edit_theme_options',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'location' => Schema::str( 'Location slug.' ),
						'menu_id'  => Schema::int( 'Menu ID or 0.' ),
					),
					array( 'location', 'menu_id' )
				),
				'callback'     => array( __CLASS__, 'assign_location' ),
			)
		);
	}

	/** @return array */
	public static function list_menus() {
		$assigned  = get_nav_menu_locations();
		$locations = array();
		foreach ( get_registered_nav_menus() as $slug => $label ) {
			$locations[] = array(
				'location' => $slug,
				'label'    => $label,
				'menu_id'  => isset( $assigned[ $slug ] ) ? (int) $assigned[ $slug ] : 0,
			);
		}
		$menus = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$menus[] = array(
				'id'        => $menu->term_id,
				'name'      => $menu->name,
				'slug'      => $menu->slug,
				'count'     => (int) $menu->count,
				'locations' => array_keys( $assigned, $menu->term_id ), // phpcs:ignore WordPress.PHP.StrictInArray
			);
		}
		return array(
			'menus'          => $menus,
			'locations'      => $locations,
			'is_block_theme' => function_exists( 'wp_is_block_theme' ) && wp_is_block_theme(),
		);
	}

	/**
	 * @param \WP_Post $item Item.
	 * @return array
	 */
	private static function format_item( $item ) {
		return array(
			'id'        => (int) $item->ID,
			'title'     => $item->title,
			'url'       => $item->url,
			'type'      => $item->type,
			'object'    => $item->object,
			'object_id' => (int) $item->object_id,
			'parent_id' => (int) $item->menu_item_parent,
			'position'  => (int) $item->menu_order,
			'target'    => $item->target,
			'classes'   => trim( implode( ' ', (array) $item->classes ) ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_menu( array $args ) {
		$menu = wp_get_nav_menu_object( $args['menu'] );
		if ( ! $menu ) {
			return new \WP_Error( 'not_found', 'Menu not found.' );
		}
		$items = wp_get_nav_menu_items( $menu->term_id, array( 'update_post_term_cache' => false ) );
		return array(
			'id'    => $menu->term_id,
			'name'  => $menu->name,
			'items' => array_map( array( __CLASS__, 'format_item' ), $items ? $items : array() ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function create_menu( array $args ) {
		$id = wp_create_nav_menu( (string) $args['name'] );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! empty( $args['location'] ) ) {
			$res = self::assign_location(
				array(
					'location' => $args['location'],
					'menu_id'  => $id,
				)
			);
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		return self::get_menu( array( 'menu' => $id ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_menu( array $args ) {
		$res = wp_delete_nav_menu( (int) $args['menu_id'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new \WP_Error( 'not_found', 'Menu not found.' );
		}
		return array( 'deleted' => (int) $args['menu_id'] );
	}

	/**
	 * Build wp_update_nav_menu_item data from args (merged over $base).
	 *
	 * @param array $args Args.
	 * @param array $base Existing values.
	 * @return array
	 */
	private static function item_data( array $args, array $base = array() ) {
		$data = array_merge(
			array(
				'menu-item-status' => 'publish',
				'menu-item-type'   => 'custom',
			),
			$base
		);
		$map  = array(
			'title'     => 'menu-item-title',
			'type'      => 'menu-item-type',
			'object'    => 'menu-item-object',
			'object_id' => 'menu-item-object-id',
			'url'       => 'menu-item-url',
			'parent_id' => 'menu-item-parent-id',
			'position'  => 'menu-item-position',
			'target'    => 'menu-item-target',
			'classes'   => 'menu-item-classes',
		);
		foreach ( $map as $from => $to ) {
			if ( isset( $args[ $from ] ) ) {
				$data[ $to ] = $args[ $from ];
			}
		}
		if ( 'post_type' === $data['menu-item-type'] && empty( $data['menu-item-object'] ) && ! empty( $data['menu-item-object-id'] ) ) {
			$data['menu-item-object'] = get_post_type( (int) $data['menu-item-object-id'] );
		}
		if ( 'taxonomy' === $data['menu-item-type'] && empty( $data['menu-item-object'] ) && ! empty( $data['menu-item-object-id'] ) ) {
			$term = get_term( (int) $data['menu-item-object-id'] );
			if ( $term && ! is_wp_error( $term ) ) {
				$data['menu-item-object'] = $term->taxonomy;
			}
		}
		return $data;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function add_item( array $args ) {
		if ( ! wp_get_nav_menu_object( (int) $args['menu_id'] ) ) {
			return new \WP_Error( 'not_found', 'Menu not found.' );
		}
		if ( ! isset( $args['type'] ) ) {
			$args['type'] = isset( $args['object_id'] ) ? 'post_type' : 'custom';
		}
		if ( empty( $args['position'] ) ) {
			// Core assigns position 0 to the first item of an empty menu; always use an explicit 1-based position.
			$existing         = wp_get_nav_menu_items( (int) $args['menu_id'], array( 'post_status' => 'any' ) );
			$max              = $existing ? max( wp_list_pluck( $existing, 'menu_order' ) ) : 0;
			$args['position'] = (int) $max + 1;
		}
		$id = wp_update_nav_menu_item( (int) $args['menu_id'], 0, wp_slash( self::item_data( $args ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::format_item( wp_setup_nav_menu_item( get_post( $id ) ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_item( array $args ) {
		$post = get_post( (int) $args['item_id'] );
		if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Menu item not found.' );
		}
		$item = wp_setup_nav_menu_item( $post );
		$base = array(
			'menu-item-title'     => $item->title,
			'menu-item-type'      => $item->type,
			'menu-item-object'    => $item->object,
			'menu-item-object-id' => $item->object_id,
			'menu-item-url'       => $item->url,
			'menu-item-parent-id' => $item->menu_item_parent,
			'menu-item-position'  => max( 1, (int) $item->menu_order ),
			'menu-item-target'    => $item->target,
			'menu-item-classes'   => trim( implode( ' ', (array) $item->classes ) ),
			'menu-item-xfn'       => $item->xfn,
			'menu-item-attr-title' => $item->attr_title,
			'menu-item-description' => $item->description,
		);
		$id   = wp_update_nav_menu_item( (int) $args['menu_id'], $post->ID, wp_slash( self::item_data( $args, $base ) ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return self::format_item( wp_setup_nav_menu_item( get_post( $id ) ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_item( array $args ) {
		$post = get_post( (int) $args['item_id'] );
		if ( ! $post || 'nav_menu_item' !== $post->post_type ) {
			return new \WP_Error( 'not_found', 'Menu item not found.' );
		}
		wp_delete_post( $post->ID, true );
		return array( 'deleted' => $post->ID );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function assign_location( array $args ) {
		$registered = get_registered_nav_menus();
		$location   = (string) $args['location'];
		if ( ! isset( $registered[ $location ] ) ) {
			return new \WP_Error( 'invalid_location', "Unknown menu location '$location'. Available: " . implode( ', ', array_keys( $registered ) ) );
		}
		$locations              = get_nav_menu_locations();
		$locations[ $location ] = (int) $args['menu_id'];
		set_theme_mod( 'nav_menu_locations', $locations );
		return array(
			'location' => $location,
			'menu_id'  => (int) $args['menu_id'],
		);
	}
}
