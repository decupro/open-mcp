<?php
/**
 * Shared helpers for tools.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Formatting / lookup helpers.
 */
class Util {

	/**
	 * Clamp paging args.
	 *
	 * @param array $args Tool args.
	 * @return array [per_page, page]
	 */
	public static function paging( array $args ) {
		$per_page = isset( $args['per_page'] ) ? (int) $args['per_page'] : 20;
		$page     = isset( $args['page'] ) ? (int) $args['page'] : 1;
		return array( max( 1, min( 100, $per_page ) ), max( 1, $page ) );
	}

	/**
	 * Load admin include files needed outside wp-admin.
	 *
	 * @param string ...$files File names inside wp-admin/includes.
	 */
	public static function admin_includes( ...$files ) {
		foreach ( $files as $file ) {
			require_once ABSPATH . 'wp-admin/includes/' . $file;
		}
	}

	/**
	 * Get a post or error.
	 *
	 * @param mixed $id Post ID.
	 * @return \WP_Post|\WP_Error
	 */
	public static function get_post( $id ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			return new \WP_Error( 'not_found', sprintf( 'Post %d not found.', (int) $id ) );
		}
		return $post;
	}

	/**
	 * Post as array.
	 *
	 * @param \WP_Post $post Post.
	 * @param bool     $full Include content, terms and meta.
	 * @return array
	 */
	public static function format_post( \WP_Post $post, $full = false ) {
		$data = array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'link'     => get_permalink( $post ),
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => (int) $post->post_author,
			'parent'   => (int) $post->post_parent,
		);

		if ( ! $full ) {
			$source          = '' !== $post->post_excerpt ? $post->post_excerpt : wp_strip_all_tags( strip_shortcodes( $post->post_content ) );
			$data['excerpt'] = wp_trim_words( $source, 30, '…' );
			return $data;
		}

		$data['content']        = $post->post_content;
		$data['excerpt']        = $post->post_excerpt;
		$data['menu_order']     = (int) $post->menu_order;
		$data['comment_status'] = $post->comment_status;
		$data['ping_status']    = $post->ping_status;
		$data['template']       = get_page_template_slug( $post );
		$data['featured_media'] = (int) get_post_thumbnail_id( $post );
		$data['edit_link']      = admin_url( 'post.php?post=' . $post->ID . '&action=edit' );
		$data['has_blocks']     = has_blocks( $post->post_content );
		if ( 'post' === $post->post_type ) {
			$data['sticky'] = is_sticky( $post->ID );
		}
		if ( '' !== $post->post_password ) {
			$data['password_protected'] = true;
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
			$assigned = wp_get_object_terms( $post->ID, $taxonomy );
			if ( is_wp_error( $assigned ) || ! $assigned ) {
				continue;
			}
			$terms[ $taxonomy ] = array_map(
				static function ( $t ) {
					return array( 'id' => $t->term_id, 'name' => $t->name, 'slug' => $t->slug );
				},
				$assigned
			);
		}
		$data['terms'] = $terms ? $terms : new \stdClass();
		$data['meta']  = self::post_meta( $post->ID, false );

		return $data;
	}

	/**
	 * Post meta as key => value (singles unwrapped).
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $protected Include protected (underscore) keys.
	 * @return array|\stdClass
	 */
	public static function post_meta( $post_id, $protected ) {
		$out = array();
		foreach ( (array) get_post_meta( $post_id ) as $key => $values ) {
			if ( ! $protected && is_protected_meta( $key, 'post' ) ) {
				continue;
			}
			$values      = array_map( 'maybe_unserialize', (array) $values );
			$out[ $key ] = 1 === count( $values ) ? $values[0] : $values;
		}
		return $out ? $out : new \stdClass();
	}

	/**
	 * Can the current user write this meta key?
	 *
	 * @param int    $post_id Post ID.
	 * @param string $key     Meta key.
	 * @return bool
	 */
	public static function can_edit_meta( $post_id, $key ) {
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return false;
		}
		if ( current_user_can( 'manage_options' ) ) {
			return true; // Admins may edit any meta, including SEO-plugin keys like _yoast_wpseo_title.
		}
		return current_user_can( 'edit_post_meta', $post_id, $key );
	}

	/**
	 * Term as array.
	 *
	 * @param \WP_Term $term Term.
	 * @return array
	 */
	public static function format_term( \WP_Term $term ) {
		return array(
			'id'          => $term->term_id,
			'taxonomy'    => $term->taxonomy,
			'name'        => $term->name,
			'slug'        => $term->slug,
			'description' => $term->description,
			'parent'      => (int) $term->parent,
			'count'       => (int) $term->count,
			'link'        => get_term_link( $term ),
		);
	}

	/**
	 * Resolve a list of IDs / names / slugs into term IDs, creating missing ones.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @param array  $terms    IDs or names.
	 * @return int[]|\WP_Error
	 */
	public static function resolve_term_ids( $taxonomy, array $terms ) {
		$tax = get_taxonomy( $taxonomy );
		if ( ! $tax ) {
			return new \WP_Error( 'invalid_taxonomy', "Unknown taxonomy '$taxonomy'." );
		}
		$ids = array();
		foreach ( $terms as $term ) {
			if ( is_int( $term ) || ctype_digit( (string) $term ) ) {
				$existing = get_term( (int) $term, $taxonomy );
				if ( ! $existing || is_wp_error( $existing ) ) {
					return new \WP_Error( 'invalid_term', "Term ID $term does not exist in '$taxonomy'." );
				}
				$ids[] = (int) $existing->term_id;
				continue;
			}
			$term  = (string) $term;
			$found = get_term_by( 'name', $term, $taxonomy );
			if ( ! $found ) {
				$found = get_term_by( 'slug', sanitize_title( $term ), $taxonomy );
			}
			if ( $found ) {
				$ids[] = (int) $found->term_id;
				continue;
			}
			if ( ! current_user_can( $tax->cap->edit_terms ) ) {
				return new \WP_Error( 'forbidden', "Term '$term' does not exist and you cannot create terms in '$taxonomy'." );
			}
			$created = wp_insert_term( $term, $taxonomy );
			if ( is_wp_error( $created ) ) {
				return $created;
			}
			$ids[] = (int) $created['term_id'];
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Attachment as array.
	 *
	 * @param \WP_Post $post Attachment.
	 * @return array
	 */
	public static function format_media( \WP_Post $post ) {
		$meta = wp_get_attachment_metadata( $post->ID );
		$data = array(
			'id'          => $post->ID,
			'title'       => $post->post_title,
			'url'         => wp_get_attachment_url( $post->ID ),
			'mime_type'   => $post->post_mime_type,
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'caption'     => $post->post_excerpt,
			'description' => $post->post_content,
			'date'        => $post->post_date,
			'attached_to' => (int) $post->post_parent,
		);
		if ( is_array( $meta ) && isset( $meta['width'] ) ) {
			$data['width']  = (int) $meta['width'];
			$data['height'] = (int) $meta['height'];
			$sizes          = array();
			foreach ( array_keys( isset( $meta['sizes'] ) ? $meta['sizes'] : array() ) as $size ) {
				$src = wp_get_attachment_image_src( $post->ID, $size );
				if ( $src ) {
					$sizes[ $size ] = $src[0];
				}
			}
			if ( $sizes ) {
				$data['sizes'] = $sizes;
			}
		}
		return $data;
	}

	/**
	 * User as array.
	 *
	 * @param \WP_User $user User.
	 * @param bool     $full Include email / caps.
	 * @return array
	 */
	public static function format_user( \WP_User $user, $full = false ) {
		$data = array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'roles'        => array_values( $user->roles ),
			'link'         => get_author_posts_url( $user->ID ),
		);
		if ( current_user_can( 'list_users' ) || get_current_user_id() === $user->ID ) {
			$data['email']      = $user->user_email;
			$data['registered'] = $user->user_registered;
		}
		if ( $full ) {
			$data['first_name'] = $user->first_name;
			$data['last_name']  = $user->last_name;
			$data['nickname']   = $user->nickname;
			$data['url']        = $user->user_url;
			$data['bio']        = $user->description;
			$data['post_count'] = (int) count_user_posts( $user->ID, 'post' );
		}
		return $data;
	}

	/**
	 * Comment as array.
	 *
	 * @param \WP_Comment $c Comment.
	 * @return array
	 */
	public static function format_comment( \WP_Comment $c ) {
		$status = wp_get_comment_status( $c );
		return array(
			'id'           => (int) $c->comment_ID,
			'post_id'      => (int) $c->comment_post_ID,
			'parent'       => (int) $c->comment_parent,
			'author_name'  => $c->comment_author,
			'author_email' => current_user_can( 'moderate_comments' ) ? $c->comment_author_email : null,
			'author_url'   => $c->comment_author_url,
			'user_id'      => (int) $c->user_id,
			'date'         => $c->comment_date,
			'content'      => $c->comment_content,
			'status'       => $status ? $status : $c->comment_approved,
			'type'         => $c->comment_type ? $c->comment_type : 'comment',
			'link'         => get_comment_link( $c ),
		);
	}

	/**
	 * Is this a sequential list array?
	 *
	 * @param mixed $value Value.
	 * @return bool
	 */
	public static function is_list( $value ) {
		if ( ! is_array( $value ) ) {
			return false;
		}
		return array() === $value || array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
