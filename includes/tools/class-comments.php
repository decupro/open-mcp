<?php
/**
 * Comments.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * Comment tools.
 */
class Comments {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'wp_list_comments',
				'category'     => 'Comments',
				'description'  => 'List comments, e.g. the moderation queue (status=hold) or comments on one post.',
				'capability'   => 'moderate_comments',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'post_id' => Schema::int( 'Only comments on this post.' ),
							'status'  => Schema::enum( 'Status filter (default all).', array( 'all', 'approve', 'hold', 'spam', 'trash' ) ),
							'search'  => Schema::str( 'Search text.' ),
						),
						Schema::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_comments' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_create_comment',
				'category'     => 'Comments',
				'description'  => 'Post a comment or reply as the connected user.',
				'capability'   => 'read',
				'input_schema' => Schema::obj(
					array(
						'post_id' => Schema::int( 'Post ID.' ),
						'content' => Schema::str( 'Comment text.' ),
						'parent'  => Schema::int( 'Comment ID to reply to.' ),
					),
					array( 'post_id', 'content' )
				),
				'callback'     => array( __CLASS__, 'create_comment' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_moderate_comments',
				'category'     => 'Comments',
				'description'  => 'Approve, unapprove, mark as spam or trash one or more comments, or edit a comment\'s text.',
				'capability'   => 'moderate_comments',
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'ids'     => Schema::arr( 'Comment IDs.', array( 'type' => 'integer' ) ),
						'status'  => Schema::enum( 'New status.', array( 'approve', 'hold', 'spam', 'trash' ) ),
						'content' => Schema::str( 'New text (applies to every listed comment; normally pass one ID).' ),
					),
					array( 'ids' )
				),
				'callback'     => array( __CLASS__, 'moderate' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_comment',
				'category'     => 'Comments',
				'description'  => 'Permanently delete a comment.',
				'capability'   => 'moderate_comments',
				'destructive'  => true,
				'input_schema' => Schema::obj( array( 'id' => Schema::int( 'Comment ID.' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'delete_comment' ),
			)
		);
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_comments( array $args ) {
		list( $per_page, $page ) = Util::paging( $args );
		$query                   = array(
			'status'  => isset( $args['status'] ) ? $args['status'] : 'all',
			'number'  => $per_page,
			'offset'  => ( $page - 1 ) * $per_page,
			'orderby' => 'comment_date_gmt',
			'order'   => 'DESC',
		);
		if ( isset( $args['post_id'] ) ) {
			$query['post_id'] = (int) $args['post_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = (string) $args['search'];
		}
		$items = get_comments( $query );
		unset( $query['number'], $query['offset'] );
		$query['count'] = true;
		return array(
			'total' => (int) get_comments( $query ),
			'page'  => $page,
			'items' => array_map( array( Util::class, 'format_comment' ), $items ),
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function create_comment( array $args ) {
		$post = Util::get_post( $args['post_id'] );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! comments_open( $post ) && ! current_user_can( 'moderate_comments' ) ) {
			return new \WP_Error( 'comments_closed', 'Comments are closed on this post.' );
		}
		$user = wp_get_current_user();
		$id   = wp_insert_comment(
			wp_slash(
				array(
					'comment_post_ID'      => $post->ID,
					'comment_parent'       => isset( $args['parent'] ) ? (int) $args['parent'] : 0,
					'comment_content'      => wp_kses_post( (string) $args['content'] ),
					'user_id'              => $user->ID,
					'comment_author'       => $user->display_name,
					'comment_author_email' => $user->user_email,
					'comment_author_url'   => $user->user_url,
					'comment_approved'     => current_user_can( 'moderate_comments' ) ? 1 : 0,
				)
			)
		);
		if ( ! $id ) {
			return new \WP_Error( 'create_failed', 'Could not create the comment.' );
		}
		return Util::format_comment( get_comment( $id ) );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function moderate( array $args ) {
		$done = array();
		foreach ( (array) $args['ids'] as $id ) {
			$id = (int) $id;
			if ( ! get_comment( $id ) ) {
				return new \WP_Error( 'not_found', "Comment $id not found." );
			}
			if ( ! current_user_can( 'edit_comment', $id ) ) {
				return new \WP_Error( 'forbidden', "You cannot edit comment $id." );
			}
			if ( isset( $args['content'] ) ) {
				wp_update_comment(
					wp_slash(
						array(
							'comment_ID'      => $id,
							'comment_content' => wp_kses_post( (string) $args['content'] ),
						)
					)
				);
			}
			if ( ! empty( $args['status'] ) ) {
				wp_set_comment_status( $id, 'trash' === $args['status'] ? 'trash' : $args['status'] );
			}
			$done[] = Util::format_comment( get_comment( $id ) );
		}
		return array( 'comments' => $done );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_comment( array $args ) {
		$id = (int) $args['id'];
		if ( ! get_comment( $id ) ) {
			return new \WP_Error( 'not_found', 'Comment not found.' );
		}
		if ( ! current_user_can( 'edit_comment', $id ) ) {
			return new \WP_Error( 'forbidden', 'You cannot delete this comment.' );
		}
		wp_delete_comment( $id, true );
		return array( 'deleted' => $id );
	}
}
