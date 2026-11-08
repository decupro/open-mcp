<?php
/**
 * Posts, pages and any custom post type; meta; revisions.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Content tools.
 */
class Content {

	/**
	 * Fields shared by create/update.
	 *
	 * @return array
	 */
	private static function post_fields() {
		return array(
			'title'          => Schema::str( 'Title.' ),
			'content'        => Schema::str( 'Full content as HTML or Gutenberg block markup (e.g. "<!-- wp:paragraph --><p>Hi</p><!-- /wp:paragraph -->"). Replaces the existing content.' ),
			'excerpt'        => Schema::str( 'Excerpt.' ),
			'status'         => Schema::enum( 'Post status.', array( 'draft', 'publish', 'pending', 'private', 'future' ) ),
			'slug'           => Schema::str( 'URL slug.' ),
			'date'           => Schema::str( 'Publish date in site timezone, "YYYY-MM-DD HH:MM:SS". With status=future schedules the post.' ),
			'author'         => Schema::int( 'Author user ID.' ),
			'parent'         => Schema::int( 'Parent post ID (hierarchical types such as pages).' ),
			'menu_order'     => Schema::int( 'Order among siblings.' ),
			'template'       => Schema::str( 'Page template slug (e.g. a block template slug). Empty string for default.' ),
			'comment_status' => Schema::enum( 'Comments open or closed.', array( 'open', 'closed' ) ),
			'ping_status'    => Schema::enum( 'Pings open or closed.', array( 'open', 'closed' ) ),
			'password'       => Schema::str( 'Password-protect the post. Empty string removes it.' ),
			'sticky'         => Schema::bool( 'Stick to the top of the blog (posts only).' ),
			'featured_media' => Schema::int( 'Attachment ID for the featured image. 0 removes it.' ),
			'categories'     => Schema::id_or_name_list( 'Categories (IDs or names; missing names are created). Replaces existing.' ),
			'tags'           => Schema::id_or_name_list( 'Tags (IDs or names; missing names are created). Replaces existing.' ),
			'terms'          => Schema::map( 'Other taxonomies: {"taxonomy_name": [IDs or names]}. Replaces terms in each taxonomy given.' ),
			'meta'           => Schema::map( 'Custom fields {"key": value}. A null value deletes the key.' ),
		);
	}

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'        => 'wp_list_post_types',
				'category'    => 'Content',
				'description' => 'List registered post types (post, page, products, custom types…) with their labels, supports and taxonomies.',
				'capability'  => 'read',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_post_types' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_list_posts',
				'category'     => 'Content',
				'description'  => 'List or filter posts of any post type (posts, pages, CPTs). Returns summaries without full content; use wp_get_post for content.',
				'capability'   => 'read',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'post_type' => Schema::str( 'Post type (default "post"). Use "any" for all.' ),
							'status'    => Schema::arr( 'Statuses to include. Default: publish, draft, pending, private, future.', array( 'type' => 'string' ) ),
							'search'    => Schema::str( 'Search text.' ),
							'author'    => Schema::int( 'Author user ID.' ),
							'parent'    => Schema::int( 'Parent post ID.' ),
							'include'   => Schema::arr( 'Only these IDs.', array( 'type' => 'integer' ) ),
							'taxonomy'  => Schema::str( 'Filter by taxonomy (e.g. "category").' ),
							'terms'     => Schema::id_or_name_list( 'Term IDs or slugs for the taxonomy filter.' ),
							'orderby'   => Schema::enum( 'Sort field.', array( 'date', 'modified', 'title', 'menu_order', 'ID', 'rand', 'comment_count' ) ),
							'order'     => Schema::enum( 'Sort direction.', array( 'ASC', 'DESC' ) ),
						),
						Schema::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_posts' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_post',
				'category'     => 'Content',
				'description'  => 'Get one post/page/CPT item with its full raw content (block markup), excerpt, terms, featured image, template and custom fields.',
				'capability'   => 'read',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'id'                => Schema::int( 'Post ID.' ),
						'include_protected' => Schema::bool( 'Also return protected meta keys (starting with "_").' ),
						'rendered'          => Schema::bool( 'Also return the rendered HTML (the_content filters applied).' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'get_post' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_create_post',
				'category'     => 'Content',
				'description'  => 'Create a post, page or custom post type item. Defaults to a draft post.',
				'capability'   => 'edit_posts',
				'input_schema' => Schema::obj( array_merge( array( 'post_type' => Schema::str( 'Post type (default "post").' ) ), self::post_fields() ) ),
				'callback'     => array( __CLASS__, 'create_post' ),
			)
		);

		$fields                 = self::post_fields();
		$fields['id']           = Schema::int( 'Post ID to update.' );
		$fields['replacements'] = Schema::arr(
			'Targeted edits applied to the current content instead of resending it all: [{"find": "old text", "replace": "new text"}]. Each "find" must occur in the content. Ignored if "content" is given.',
			Schema::obj(
				array(
					'find'    => Schema::str( 'Exact text to find.' ),
					'replace' => Schema::str( 'Replacement.' ),
					'all'     => Schema::bool( 'Replace every occurrence (default: first only).' ),
				),
				array( 'find', 'replace' )
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_post',
				'category'     => 'Content',
				'description'  => 'Update a post/page/CPT item. Only the fields you pass are changed. Use "replacements" for small edits to long content.',
				'capability'   => 'edit_posts',
				'input_schema' => Schema::obj( $fields, array( 'id' ) ),
				'callback'     => array( __CLASS__, 'update_post' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_post',
				'category'     => 'Content',
				'description'  => 'Move a post/page to the trash, or permanently delete it with force=true.',
				'capability'   => 'delete_posts',
				'destructive'  => true,
				'input_schema' => Schema::obj(
					array(
						'id'    => Schema::int( 'Post ID.' ),
						'force' => Schema::bool( 'Permanently delete instead of trashing.' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'delete_post' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_restore_post',
				'category'     => 'Content',
				'description'  => 'Restore a trashed post/page (back to draft).',
				'capability'   => 'edit_posts',
				'input_schema' => Schema::obj( array( 'id' => Schema::int( 'Post ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'restore_post' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_duplicate_post',
				'category'     => 'Content',
				'description'  => 'Duplicate a post/page (content, excerpt, terms, featured image and meta) as a new draft.',
				'capability'   => 'edit_posts',
				'input_schema' => Schema::obj(
					array(
						'id'    => Schema::int( 'Source post ID.' ),
						'title' => Schema::str( 'Title for the copy. Default: "<original> (copy)".' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'duplicate_post' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_post_meta',
				'category'     => 'Content',
				'description'  => 'Read custom fields of a post. Without "key" returns all fields (protected "_" keys only with include_protected=true).',
				'capability'   => 'edit_posts',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'id'                => Schema::int( 'Post ID.' ),
						'key'               => Schema::str( 'Single meta key.' ),
						'include_protected' => Schema::bool( 'Include keys starting with "_" (e.g. SEO plugin fields).' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'get_meta' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_post_meta',
				'category'     => 'Content',
				'description'  => 'Set or delete custom fields on a post: {"key": value}. null deletes. Admins may set protected keys such as _yoast_wpseo_title or rank_math_description.',
				'capability'   => 'edit_posts',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'id'   => Schema::int( 'Post ID.' ),
						'meta' => Schema::map( 'Key/value pairs.' ),
					),
					array( 'id', 'meta' )
				),
				'callback'     => array( __CLASS__, 'update_meta' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_list_revisions',
				'category'     => 'Content',
				'description'  => 'List revisions of a post/page (newest first).',
				'capability'   => 'edit_posts',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'id' => Schema::int( 'Post ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'list_revisions' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_restore_revision',
				'category'     => 'Content',
				'description'  => 'Restore a post to an earlier revision.',
				'capability'   => 'edit_posts',
				'input_schema' => Schema::obj( array( 'revision_id' => Schema::int( 'Revision ID from wp_list_revisions.' ) ), array( 'revision_id' ) ),
				'callback'     => array( __CLASS__, 'restore_revision' ),
			)
		);
	}

	/** @return array */
	public static function list_post_types() {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			$out[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'public'       => (bool) $pt->public,
				'hierarchical' => (bool) $pt->hierarchical,
				'supports'     => array_keys( get_all_post_type_supports( $pt->name ) ),
				'taxonomies'   => get_object_taxonomies( $pt->name ),
				'rest_base'    => $pt->show_in_rest ? ( $pt->rest_base ? $pt->rest_base : $pt->name ) : null,
			);
		}
		foreach ( array( 'wp_template', 'wp_template_part', 'wp_navigation', 'wp_block' ) as $special ) {
			$pt = get_post_type_object( $special );
			if ( $pt && ! wp_list_filter( $out, array( 'name' => $special ) ) ) {
				$out[] = array(
					'name'  => $pt->name,
					'label' => $pt->label,
				);
			}
		}
		return array( 'post_types' => $out );
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_posts( array $args ) {
		list( $per_page, $page ) = Util::paging( $args );
		$query                   = array(
			'post_type'           => isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post',
			'post_status'         => ! empty( $args['status'] ) ? array_map( 'sanitize_key', (array) $args['status'] ) : array( 'publish', 'draft', 'pending', 'private', 'future' ),
			'posts_per_page'      => $per_page,
			'paged'               => $page,
			'perm'                => 'readable',
			'ignore_sticky_posts' => true,
			'orderby'             => isset( $args['orderby'] ) ? $args['orderby'] : 'date',
			'order'               => isset( $args['order'] ) ? $args['order'] : 'DESC',
		);
		if ( 'any' === $query['post_type'] ) {
			$query['post_type'] = array_values( get_post_types( array( 'show_ui' => true ) ) );
		}
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = (string) $args['search'];
		}
		if ( isset( $args['author'] ) ) {
			$query['author'] = (int) $args['author'];
		}
		if ( isset( $args['parent'] ) ) {
			$query['post_parent'] = (int) $args['parent'];
		}
		if ( ! empty( $args['include'] ) ) {
			$query['post__in'] = array_map( 'intval', (array) $args['include'] );
		}
		if ( ! empty( $args['taxonomy'] ) && ! empty( $args['terms'] ) ) {
			$terms              = (array) $args['terms'];
			$numeric            = count( array_filter( $terms, 'is_numeric' ) ) === count( $terms );
			$query['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery
				array(
					'taxonomy' => sanitize_key( $args['taxonomy'] ),
					'field'    => $numeric ? 'term_id' : 'slug',
					'terms'    => $numeric ? array_map( 'intval', $terms ) : array_map( 'sanitize_title', $terms ),
				),
			);
		}
		$q = new \WP_Query( $query );
		return array(
			'total'       => (int) $q->found_posts,
			'total_pages' => (int) $q->max_num_pages,
			'page'        => $page,
			'items'       => array_map(
				static function ( $p ) {
					return Util::format_post( $p );
				},
				$q->posts
			),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_post( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'read_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot read this post.' );
		}
		$data = Util::format_post( $post, true );
		if ( ! empty( $args['include_protected'] ) && current_user_can( 'edit_post', $post->ID ) ) {
			$data['meta'] = Util::post_meta( $post->ID, true );
		}
		if ( ! empty( $args['rendered'] ) ) {
			$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			setup_postdata( $post );
			$data['rendered'] = apply_filters( 'the_content', $post->post_content ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
			wp_reset_postdata();
		}
		return $data;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function create_post( array $args ) {
		$type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		$pto  = get_post_type_object( $type );
		if ( ! $pto ) {
			return new \WP_Error( 'invalid_post_type', "Unknown post type '$type'." );
		}
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			return new \WP_Error( 'forbidden', "You cannot create items of type '$type'." );
		}
		$postarr = array(
			'post_type'   => $type,
			'post_status' => 'draft',
			'post_author' => get_current_user_id(),
		);
		$error   = self::apply_fields( $postarr, $args, $pto, null );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		$id = wp_insert_post( wp_slash( $postarr ), true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$after = self::after_save( $id, $args );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		return Util::format_post( get_post( $id ), true );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_post( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this post.' );
		}
		if ( ! isset( $args['content'] ) && ! empty( $args['replacements'] ) ) {
			$content = $post->post_content;
			foreach ( (array) $args['replacements'] as $i => $rep ) {
				$find = isset( $rep['find'] ) ? (string) $rep['find'] : '';
				if ( '' === $find || false === strpos( $content, $find ) ) {
					return new \WP_Error( 'replacement_not_found', sprintf( 'Replacement #%d: text not found in content. Fetch the post again and use an exact substring.', $i + 1 ) );
				}
				$replace = isset( $rep['replace'] ) ? (string) $rep['replace'] : '';
				if ( ! empty( $rep['all'] ) ) {
					$content = str_replace( $find, $replace, $content );
				} else {
					$pos     = strpos( $content, $find );
					$content = substr_replace( $content, $replace, $pos, strlen( $find ) );
				}
			}
			$args['content'] = $content;
		}
		$pto     = get_post_type_object( $post->post_type );
		$postarr = array( 'ID' => $post->ID );
		$error   = self::apply_fields( $postarr, $args, $pto, $post );
		if ( is_wp_error( $error ) ) {
			return $error;
		}
		if ( count( $postarr ) > 1 ) {
			$id = wp_update_post( wp_slash( $postarr ), true );
			if ( is_wp_error( $id ) ) {
				return $id;
			}
		}
		$after = self::after_save( $post->ID, $args );
		if ( is_wp_error( $after ) ) {
			return $after;
		}
		$data = Util::format_post( get_post( $post->ID ), true );
		unset( $data['content'] ); // Keep responses small; fetch with wp_get_post if needed.
		$data['content_length'] = strlen( get_post( $post->ID )->post_content );
		return $data;
	}

	/**
	 * Map tool args onto a wp_insert_post array, with permission checks.
	 *
	 * @param array         $postarr Target array.
	 * @param array         $args    Args.
	 * @param \WP_Post_Type $pto     Post type object.
	 * @param \WP_Post|null $post    Existing post.
	 * @return true|\WP_Error
	 */
	private static function apply_fields( array &$postarr, array $args, $pto, $post ) {
		$map = array(
			'title'          => 'post_title',
			'content'        => 'post_content',
			'excerpt'        => 'post_excerpt',
			'status'         => 'post_status',
			'slug'           => 'post_name',
			'date'           => 'post_date',
			'parent'         => 'post_parent',
			'menu_order'     => 'menu_order',
			'comment_status' => 'comment_status',
			'ping_status'    => 'ping_status',
			'password'       => 'post_password',
			'template'       => 'page_template',
		);
		foreach ( $map as $from => $to ) {
			if ( array_key_exists( $from, $args ) && null !== $args[ $from ] ) {
				$postarr[ $to ] = $args[ $from ];
			}
		}
		if ( isset( $postarr['post_status'] ) && in_array( $postarr['post_status'], array( 'publish', 'future', 'private' ), true ) && ! current_user_can( $pto->cap->publish_posts ) ) {
			return new \WP_Error( 'forbidden', 'You cannot publish this post type. Use status "pending" to submit for review.' );
		}
		if ( isset( $postarr['post_date'] ) ) {
			$postarr['post_date_gmt'] = get_gmt_from_date( $postarr['post_date'] );
			if ( $post ) {
				$postarr['edit_date'] = true;
			}
		}
		if ( isset( $args['author'] ) ) {
			$author = (int) $args['author'];
			if ( $author !== get_current_user_id() && ! current_user_can( $pto->cap->edit_others_posts ) ) {
				return new \WP_Error( 'forbidden', 'You cannot assign posts to other authors.' );
			}
			if ( ! get_userdata( $author ) ) {
				return new \WP_Error( 'invalid_author', "User $author does not exist." );
			}
			$postarr['post_author'] = $author;
		}
		return true;
	}

	/**
	 * Terms, meta, featured image, sticky.
	 *
	 * @param int   $id   Post ID.
	 * @param array $args Args.
	 * @return true|\WP_Error
	 */
	private static function after_save( $id, array $args ) {
		$taxes = array();
		if ( isset( $args['categories'] ) ) {
			$taxes['category'] = (array) $args['categories'];
		}
		if ( isset( $args['tags'] ) ) {
			$taxes['post_tag'] = (array) $args['tags'];
		}
		if ( isset( $args['terms'] ) && is_array( $args['terms'] ) ) {
			foreach ( $args['terms'] as $tax => $terms ) {
				$taxes[ $tax ] = (array) $terms;
			}
		}
		foreach ( $taxes as $tax => $terms ) {
			$tax_obj = get_taxonomy( $tax );
			if ( ! $tax_obj || ! is_object_in_taxonomy( get_post_type( $id ), $tax ) ) {
				return new \WP_Error( 'invalid_taxonomy', "Taxonomy '$tax' is not registered for this post type." );
			}
			if ( ! current_user_can( $tax_obj->cap->assign_terms ) ) {
				return new \WP_Error( 'forbidden', "You cannot assign terms in '$tax'." );
			}
			$ids = Util::resolve_term_ids( $tax, $terms );
			if ( is_wp_error( $ids ) ) {
				return $ids;
			}
			wp_set_object_terms( $id, $ids, $tax );
		}

		if ( isset( $args['featured_media'] ) ) {
			$media = (int) $args['featured_media'];
			if ( $media ) {
				if ( 'attachment' !== get_post_type( $media ) ) {
					return new \WP_Error( 'invalid_media', "Attachment $media not found." );
				}
				set_post_thumbnail( $id, $media );
			} else {
				delete_post_thumbnail( $id );
			}
		}

		if ( isset( $args['sticky'] ) && 'post' === get_post_type( $id ) ) {
			if ( $args['sticky'] ) {
				stick_post( $id );
			} else {
				unstick_post( $id );
			}
		}

		if ( isset( $args['meta'] ) && is_array( $args['meta'] ) ) {
			$result = self::write_meta( $id, $args['meta'] );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}
		return true;
	}

	/**
	 * @param int   $id   Post ID.
	 * @param array $meta Meta.
	 * @return array|\WP_Error
	 */
	private static function write_meta( $id, array $meta ) {
		$done = array();
		foreach ( $meta as $key => $value ) {
			$key = (string) $key;
			if ( ! Util::can_edit_meta( $id, $key ) ) {
				return new \WP_Error( 'forbidden', "You cannot edit the meta key '$key'." );
			}
			if ( null === $value ) {
				delete_post_meta( $id, $key );
			} else {
				update_post_meta( $id, wp_slash( $key ), is_string( $value ) ? wp_slash( $value ) : $value );
			}
			$done[] = $key;
		}
		return $done;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_post( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot delete this post.' );
		}
		$force = ! empty( $args['force'] ) || ! EMPTY_TRASH_DAYS;
		$res   = $force ? wp_delete_post( $post->ID, true ) : wp_trash_post( $post->ID );
		if ( ! $res ) {
			return new \WP_Error( 'delete_failed', 'Could not delete the post.' );
		}
		return array(
			'id'     => $post->ID,
			'result' => $force ? 'deleted' : 'trashed',
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function restore_post( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'delete_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot restore this post.' );
		}
		if ( ! wp_untrash_post( $post->ID ) ) {
			return new \WP_Error( 'restore_failed', 'Post is not in the trash or could not be restored.' );
		}
		return Util::format_post( get_post( $post->ID ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function duplicate_post( array $args ) {
		$src = Util::get_post( $args['id'] );
		if ( is_wp_error( $src ) ) {
			return $src;
		}
		$pto = get_post_type_object( $src->post_type );
		if ( ! current_user_can( 'read_post', $src->ID ) || ! current_user_can( $pto->cap->create_posts ) ) {
			return new \WP_Error( 'forbidden', 'You cannot duplicate this post.' );
		}
		$new_id = wp_insert_post(
			wp_slash(
				array(
					'post_type'      => $src->post_type,
					'post_status'    => 'draft',
					'post_title'     => isset( $args['title'] ) ? $args['title'] : $src->post_title . ' (copy)',
					'post_content'   => $src->post_content,
					'post_excerpt'   => $src->post_excerpt,
					'post_parent'    => $src->post_parent,
					'menu_order'     => $src->menu_order,
					'comment_status' => $src->comment_status,
					'ping_status'    => $src->ping_status,
					'post_author'    => get_current_user_id(),
				)
			),
			true
		);
		if ( is_wp_error( $new_id ) ) {
			return $new_id;
		}
		foreach ( get_object_taxonomies( $src->post_type ) as $tax ) {
			wp_set_object_terms( $new_id, wp_get_object_terms( $src->ID, $tax, array( 'fields' => 'ids' ) ), $tax );
		}
		foreach ( (array) get_post_meta( $src->ID ) as $key => $values ) {
			if ( in_array( $key, array( '_edit_lock', '_edit_last', '_wp_old_slug', '_wp_trash_meta_status', '_wp_trash_meta_time' ), true ) ) {
				continue;
			}
			foreach ( $values as $value ) {
				add_post_meta( $new_id, wp_slash( $key ), wp_slash( maybe_unserialize( $value ) ) );
			}
		}
		return Util::format_post( get_post( $new_id ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_meta( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot read this post\'s meta.' );
		}
		if ( ! empty( $args['key'] ) ) {
			$values = get_post_meta( $post->ID, (string) $args['key'] );
			return array(
				'key'   => $args['key'],
				'value' => 1 === count( $values ) ? $values[0] : ( $values ? $values : null ),
			);
		}
		return array( 'meta' => Util::post_meta( $post->ID, ! empty( $args['include_protected'] ) ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_meta( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! is_array( $args['meta'] ) ) {
			return new \WP_Error( 'invalid_meta', 'meta must be an object.' );
		}
		$done = self::write_meta( $post->ID, $args['meta'] );
		if ( is_wp_error( $done ) ) {
			return $done;
		}
		return array(
			'id'      => $post->ID,
			'updated' => $done,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function list_revisions( array $args ) {
		$post = Util::get_post( $args['id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return new \WP_Error( 'forbidden', 'You cannot view revisions of this post.' );
		}
		$out = array();
		foreach ( wp_get_post_revisions( $post->ID ) as $rev ) {
			$author = get_userdata( $rev->post_author );
			$out[]  = array(
				'revision_id' => $rev->ID,
				'date'        => $rev->post_modified,
				'author'      => $author ? $author->user_login : null,
				'title'       => $rev->post_title,
				'autosave'    => wp_is_post_autosave( $rev ) ? true : false,
				'length'      => strlen( $rev->post_content ),
			);
		}
		return array( 'revisions' => $out );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function restore_revision( array $args ) {
		$rev = wp_get_post_revision( (int) $args['revision_id'] );
		if ( ! $rev ) {
			return new \WP_Error( 'not_found', 'Revision not found.' );
		}
		if ( ! current_user_can( 'edit_post', $rev->post_parent ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this post.' );
		}
		$id = wp_restore_post_revision( $rev->ID );
		if ( ! $id || is_wp_error( $id ) ) {
			return new \WP_Error( 'restore_failed', 'Could not restore the revision.' );
		}
		return Util::format_post( get_post( $id ) );
	}
}
