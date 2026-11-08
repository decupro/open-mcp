<?php
/**
 * Users and roles.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Util;

defined( 'ABSPATH' ) || exit;

/**
 * User tools.
 */
class Users {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'wp_list_users',
				'category'     => 'Users',
				'description'  => 'List users, optionally by role or search.',
				'capability'   => 'list_users',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'role'   => Schema::str( 'Role slug, e.g. "editor".' ),
							'search' => Schema::str( 'Search login, email, URL or display name.' ),
						),
						Schema::paging()
					)
				),
				'callback'     => array( __CLASS__, 'list_users' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_get_user',
				'category'     => 'Users',
				'description'  => 'Get a user\'s profile. Use id="me" for the connected account.',
				'capability'   => 'read',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj( array( 'id' => array( 'type' => array( 'integer', 'string' ), 'description' => 'User ID, username, email, or "me".' ) ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'get_user' ),
			)
		);

		$profile = array(
			'email'        => Schema::str( 'Email address.' ),
			'first_name'   => Schema::str( 'First name.' ),
			'last_name'    => Schema::str( 'Last name.' ),
			'display_name' => Schema::str( 'Public display name.' ),
			'nickname'     => Schema::str( 'Nickname.' ),
			'url'          => Schema::str( 'Website.' ),
			'bio'          => Schema::str( 'Biographical info.' ),
			'role'         => Schema::str( 'Role slug (see wp_list_roles).' ),
			'password'     => Schema::str( 'New password.' ),
		);

		$r->register(
			array(
				'name'         => 'wp_create_user',
				'category'     => 'Users',
				'description'  => 'Create a user. If no password is given a strong one is generated and the user is emailed a link to set it (send_notification defaults to true).',
				'capability'   => 'create_users',
				'input_schema' => Schema::obj(
					array_merge(
						array(
							'username'          => Schema::str( 'Login name (cannot be changed later).' ),
							'send_notification' => Schema::bool( 'Email the new user their login details. Default true.' ),
						),
						$profile
					),
					array( 'username', 'email' )
				),
				'callback'     => array( __CLASS__, 'create_user' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_update_user',
				'category'     => 'Users',
				'description'  => 'Update a user\'s profile, role or password.',
				'capability'   => 'read',
				'idempotent'   => true,
				'input_schema' => Schema::obj( array_merge( array( 'id' => Schema::int( 'User ID.' ) ), $profile ), array( 'id' ) ),
				'callback'     => array( __CLASS__, 'update_user' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_delete_user',
				'category'     => 'Users',
				'description'  => 'Delete a user. Their content is reassigned to "reassign" if given, otherwise deleted.',
				'capability'   => 'delete_users',
				'destructive'  => true,
				'input_schema' => Schema::obj(
					array(
						'id'       => Schema::int( 'User ID.' ),
						'reassign' => Schema::int( 'User ID that receives the deleted user\'s posts. Strongly recommended.' ),
					),
					array( 'id' )
				),
				'callback'     => array( __CLASS__, 'delete_user' ),
			)
		);

		$r->register(
			array(
				'name'        => 'wp_list_roles',
				'category'    => 'Users',
				'description' => 'List roles and their capabilities.',
				'capability'  => 'list_users',
				'read_only'   => true,
				'idempotent'  => true,
				'callback'    => array( __CLASS__, 'list_roles' ),
			)
		);
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_users( array $args ) {
		list( $per_page, $page ) = Util::paging( $args );
		$query                   = array(
			'number'      => $per_page,
			'paged'       => $page,
			'count_total' => true,
			'orderby'     => 'registered',
			'order'       => 'DESC',
		);
		if ( ! empty( $args['role'] ) ) {
			$query['role'] = (string) $args['role'];
		}
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = '*' . $args['search'] . '*';
		}
		$q = new \WP_User_Query( $query );
		return array(
			'total' => (int) $q->get_total(),
			'page'  => $page,
			'items' => array_map(
				static function ( $u ) {
					return Util::format_user( $u );
				},
				$q->get_results()
			),
		);
	}

	/**
	 * @param mixed $id ID / login / email / "me".
	 * @return \WP_User|false
	 */
	private static function find( $id ) {
		if ( 'me' === $id ) {
			return wp_get_current_user();
		}
		if ( is_numeric( $id ) ) {
			return get_userdata( (int) $id );
		}
		return is_email( $id ) ? get_user_by( 'email', $id ) : get_user_by( 'login', $id );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function get_user( array $args ) {
		$user = self::find( $args['id'] );
		if ( ! $user ) {
			return new \WP_Error( 'not_found', 'User not found.' );
		}
		if ( $user->ID !== get_current_user_id() && ! current_user_can( 'list_users' ) ) {
			return new \WP_Error( 'forbidden', 'You cannot view other users.' );
		}
		return Util::format_user( $user, true );
	}

	/**
	 * Map tool args to wp_insert_user fields.
	 *
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	private static function fields( array $args ) {
		$map = array(
			'email'        => 'user_email',
			'first_name'   => 'first_name',
			'last_name'    => 'last_name',
			'display_name' => 'display_name',
			'nickname'     => 'nickname',
			'url'          => 'user_url',
			'bio'          => 'description',
			'password'     => 'user_pass',
		);
		$out = array();
		foreach ( $map as $from => $to ) {
			if ( isset( $args[ $from ] ) ) {
				$out[ $to ] = (string) $args[ $from ];
			}
		}
		if ( isset( $args['role'] ) ) {
			Util::admin_includes( 'user.php' );
			if ( ! array_key_exists( $args['role'], get_editable_roles() ) ) {
				return new \WP_Error( 'invalid_role', "You cannot assign the role '{$args['role']}'." );
			}
			$out['role'] = (string) $args['role'];
		}
		return $out;
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function create_user( array $args ) {
		$fields = self::fields( $args );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['user_login'] = sanitize_user( (string) $args['username'], true );
		if ( empty( $fields['user_pass'] ) ) {
			$fields['user_pass'] = wp_generate_password( 24, true, true );
		}
		if ( empty( $fields['role'] ) ) {
			$fields['role'] = get_option( 'default_role', 'subscriber' );
		}
		$id = wp_insert_user( wp_slash( $fields ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		if ( ! isset( $args['send_notification'] ) || $args['send_notification'] ) {
			wp_new_user_notification( $id, null, 'user' );
		}
		return Util::format_user( get_userdata( $id ), true );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function update_user( array $args ) {
		$id = (int) $args['id'];
		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'not_found', 'User not found.' );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new \WP_Error( 'forbidden', 'You cannot edit this user.' );
		}
		if ( isset( $args['role'] ) ) {
			if ( ! current_user_can( 'promote_user', $id ) ) {
				return new \WP_Error( 'forbidden', 'You cannot change this user\'s role.' );
			}
			if ( get_current_user_id() === $id ) {
				return new \WP_Error( 'forbidden', 'You cannot change your own role via MCP.' );
			}
		}
		$fields = self::fields( $args );
		if ( is_wp_error( $fields ) ) {
			return $fields;
		}
		$fields['ID'] = $id;
		$res          = wp_update_user( wp_slash( $fields ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return Util::format_user( get_userdata( $id ), true );
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function delete_user( array $args ) {
		$id = (int) $args['id'];
		if ( get_current_user_id() === $id ) {
			return new \WP_Error( 'forbidden', 'You cannot delete the connected account.' );
		}
		if ( ! get_userdata( $id ) ) {
			return new \WP_Error( 'not_found', 'User not found.' );
		}
		if ( ! current_user_can( 'delete_user', $id ) ) {
			return new \WP_Error( 'forbidden', 'You cannot delete this user.' );
		}
		$reassign = isset( $args['reassign'] ) ? (int) $args['reassign'] : null;
		if ( $reassign && ( $reassign === $id || ! get_userdata( $reassign ) ) ) {
			return new \WP_Error( 'invalid_reassign', 'Invalid reassign user.' );
		}
		Util::admin_includes( 'user.php' );
		if ( ! wp_delete_user( $id, $reassign ) ) {
			return new \WP_Error( 'delete_failed', 'Could not delete the user.' );
		}
		return array(
			'deleted'     => $id,
			'reassign_to' => $reassign,
		);
	}

	/** @return array */
	public static function list_roles() {
		$out = array();
		foreach ( wp_roles()->roles as $slug => $role ) {
			$out[] = array(
				'slug'         => $slug,
				'name'         => translate_user_role( $role['name'] ),
				'capabilities' => array_keys( array_filter( (array) $role['capabilities'] ) ),
			);
		}
		return array( 'roles' => $out );
	}
}
