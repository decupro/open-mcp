<?php
/**
 * Categories, tags and custom taxonomies.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Taxonomy tools.
 */
class Taxonomies {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'        => 'wp_list_taxonomies',
				'category'    => 'Taxonomies',
				'description' => 'List taxonomies (category, post_tag, product_cat, custom ones) and which post types use them.',
				'capability'  => 'read',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_taxonomies' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_list_terms',
				'category'     => 'Taxonomies',
				'description'  => 'List terms of a taxonomy (e.g. all categories), including empty ones.',
				'capability'   => 'read',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'taxonomy' => Schema::str( 'Taxonomy name, e.g. "category" or "post_tag".' ),
							'search'   => Schema::str( 'Search in names.' ),
							'parent'   => Schema::int( 'Only direct children of this term ID (0 = top level).' ),
						),
						Schema::paging()
					),
					array( 'taxonomy' )
				),
				'callback'     => array( __CLASS__, 'list_terms' ),
			)
		);

		$term_fields = array(
			'name'        => Schema::str( 'Term name.' ),
			'slug'        => Schema::str( 'Slug.' ),
			'description' => Schema::str( 'Description.' ),
			'parent'      => Schema::int( 'Parent term ID (hierarchical taxonomies).' ),
		);

		$r->register(
			array(
				'name'         => 'wp_create_term',
				'category'     => 'Taxonomies',
				'description'  => 'Create a category, tag or custom taxonomy term.',
				'capability'   => 'read',
				'input_schema' => Schema::obj( array_merge( array( 'taxonomy' => Schema::str( 'Taxonomy.' ) ), $term_fields ), array( 'taxonomy', 'name' ) ),
				'callback'     => array( __CLASS__, 'create_term' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_term',
				'category'     => 'Taxonomies',
				'description'  => 'Rename or edit a term.',
				'capability'   => 'read',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'taxonomy' => Schema::str( 'Taxonomy.' ),
							'id'       => Schema::int( 'Term ID.' ),
						),
						$term_fields
					),
					array( 'taxonomy', 'id' )
				),
				'callback'     => array( __CLASS__, 'update_term' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_term',
				'category'     => 'Taxonomies',
				'description'  => 'Delete a term. Posts keep existing; they just lose the term.',
				'capability'   => 'read',
				'destructive'  => true,
				'input_schema' => Schema::obj(
					array(
						'taxonomy' => Schema::str( 'Taxonomy.' ),
						'id'       => Schema::int( 'Term ID.' ),
					),
					array( 'taxonomy', 'id' )
				),
				'callback'     => array( __CLASS__, 'delete_term' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_set_post_terms',
				'category'     => 'Taxonomies',
				'description'  => 'Assign terms to a post in one taxonomy (IDs or names; missing names are created). Replaces existing terms unless append=true.',
				'capability'   => 'edit_posts',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'post_id'  => Schema::int( 'Post ID.' ),
						'taxonomy' => Schema::str( 'Taxonomy.' ),
						'terms'    => Schema::id_or_name_list( 'Term IDs or names.' ),
						'append'   => Schema::bool( 'Add to existing terms instead of replacing.' ),
					),
					array( 'post_id', 'taxonomy', 'terms' )
				),
				'callback'     => array( __CLASS__, 'set_post_terms' ),
			)
		);
	}

	/** @return array */
	public static function list_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'post_types'   => array_values( (array) $tax->object_type ),
				'count'        => (int) wp_count_terms( array( 'taxonomy' => $tax->name, 'hide_empty' => false ) ),
			);
		}
		return array( 'taxonomies' => $out );
	}

	/**
	 * @param string $taxonomy Taxonomy.
	 * @param string $cap      Cap key in $tax->cap.
	 * @return \WP_Taxonomy|\WP_Error
	 */
	private static function tax( $taxonomy, $cap = null ) {
		$tax = get_taxonomy( (string) $taxonomy );
		if ( ! $tax ) {
			return new \WP_Error( 'invalid_taxonomy', "Unknown taxonomy '$taxonomy'." );
		}
		if ( $cap && ! current_user_can( $tax->cap->$cap ) ) {
			return new \WP_Error( 'forbidden', "You do not have permission ($cap) for '$taxonomy'." );
		}
		return $tax;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function list_terms( array $args ) {
		$tax = self::tax( $args['taxonomy'] );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		list( $per_page, $page ) = Util::paging( $args );
		$query                   = array(
			'taxonomy'   => $tax->name,
			'hide_empty' => false,
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = (string) $args['search'];
		}
		if ( isset( $args['parent'] ) ) {
			$query['parent'] = (int) $args['parent'];
		}
		$terms = get_terms( $query );
		if ( is_wp_error( $terms ) ) {
			return $terms;
		}
		unset( $query['number'], $query['offset'] );
		return array(
			'total' => (int) wp_count_terms( $query ),
			'page'  => $page,
			'items' => array_map( array( Util::class, 'format_term' ), $terms ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function create_term( array $args ) {
		$tax = self::tax( $args['taxonomy'], 'edit_terms' );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$res = wp_insert_term( (string) $args['name'], $tax->name, self::term_args( $args ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return Util::format_term( get_term( $res['term_id'], $tax->name ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_term( array $args ) {
		$tax = self::tax( $args['taxonomy'], 'edit_terms' );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$update = self::term_args( $args );
		if ( isset( $args['name'] ) ) {
			$update['name'] = (string) $args['name'];
		}
		$res = wp_update_term( (int) $args['id'], $tax->name, $update );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return Util::format_term( get_term( $res['term_id'], $tax->name ) );
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	private static function term_args( array $args ) {
		$out = array();
		foreach ( array( 'slug', 'description', 'parent' ) as $k ) {
			if ( isset( $args[ $k ] ) ) {
				$out[ $k ] = $args[ $k ];
			}
		}
		return $out;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_term( array $args ) {
		$tax = self::tax( $args['taxonomy'], 'delete_terms' );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$res = wp_delete_term( (int) $args['id'], $tax->name );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( ! $res ) {
			return new \WP_Error( 'delete_failed', 'Term not found or is the default term.' );
		}
		return array( 'deleted' => (int) $args['id'] );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function set_post_terms( array $args ) {
		$post = Util::get_post( $args['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this post.' );
		}
		$tax = self::tax( $args['taxonomy'], 'assign_terms' );
		if ( is_wp_error( $tax ) ) {
			return $tax;
		}
		$ids = Util::resolve_term_ids( $tax->name, (array) $args['terms'] );
		if ( is_wp_error( $ids ) ) {
			return $ids;
		}
		$res = wp_set_object_terms( $post->ID, $ids, $tax->name, ! empty( $args['append'] ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return array(
			'post_id' => $post->ID,
			'terms'   => array_map( array( Util::class, 'format_term' ), wp_get_object_terms( $post->ID, $tax->name ) ),
		);
	}
}
