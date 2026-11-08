<?php
/**
 * Authentication: API keys, OAuth access tokens, WordPress Application Passwords.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves the WordPress user behind an MCP request.
 */
class Auth {

	const OPT_KEYS   = 'open_mcp_api_keys';
	const KEY_PREFIX = 'omcp_';

	/** @var string Human-readable description of how the current request authenticated. */
	private $label = '';

	/** @var OAuth|null */
	private $oauth = null;

	/**
	 * Late-bind OAuth (avoids a constructor cycle).
	 *
	 * @param OAuth $oauth OAuth.
	 */
	public function set_oauth( OAuth $oauth ) {
		$this->oauth = $oauth;
	}

	/** @return string */
	public function label() {
		return $this->label;
	}

	/**
	 * Authenticate a REST request and set the current user.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return int|\WP_Error User ID or error (status 401/403).
	 */
	public function authenticate( \WP_REST_Request $request ) {
		$settings = Plugin::settings();
		$user_id  = 0;
		$token    = '';

		$header = (string) $request->get_header( 'authorization' );
		if ( preg_match( '/^\s*Bearer\s+(\S+)\s*$/i', $header, $m ) ) {
			$token = $m[1];
		} elseif ( $request->get_header( 'x-mcp-api-key' ) ) {
			$token = trim( (string) $request->get_header( 'x-mcp-api-key' ) );
		}

		$url_key = (string) $request->get_param( 'key' );

		if ( '' !== $token ) {
			if ( 0 === strpos( $token, self::KEY_PREFIX ) ) {
				$user_id = $this->verify_key( $token );
			} elseif ( $this->oauth && $settings['oauth_enabled'] ) {
				$user_id = $this->oauth->verify_access_token( $token );
				if ( $user_id ) {
					$this->label = 'OAuth: ' . $this->oauth->current_client_name();
				}
			}
			if ( ! $user_id ) {
				return new \WP_Error( 'invalid_token', 'The access token is invalid or expired.', array( 'status' => 401 ) );
			}
		} elseif ( '' !== $url_key ) {
			if ( ! $settings['allow_url_key'] ) {
				return new \WP_Error( 'url_key_disabled', 'Keys in the URL are disabled on this site. Send the key in an Authorization: Bearer header instead.', array( 'status' => 401 ) );
			}
			$user_id = $this->verify_key( $url_key );
			if ( ! $user_id ) {
				return new \WP_Error( 'invalid_token', 'Invalid API key.', array( 'status' => 401 ) );
			}
		} elseif ( $settings['allow_app_passwords'] && is_user_logged_in() && null !== rest_get_authenticated_app_password() ) {
			$user_id     = get_current_user_id();
			$this->label = 'Application Password';
		} else {
			return new \WP_Error( 'unauthorized', 'Authentication required.', array( 'status' => 401 ) );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'invalid_user', 'The user for this credential no longer exists.', array( 'status' => 401 ) );
		}

		wp_set_current_user( $user_id );

		if ( ! user_can( $user, $settings['min_capability'] ) ) {
			return new \WP_Error( 'forbidden', 'This WordPress account is not allowed to use MCP on this site.', array( 'status' => 403 ) );
		}

		return $user_id;
	}

	/**
	 * Look up an API key.
	 *
	 * @param string $token Plain key.
	 * @return int User ID or 0.
	 */
	public function verify_key( $token ) {
		$keys = $this->keys();
		$hash = hash( 'sha256', $token );
		foreach ( $keys as $id => $key ) {
			if ( hash_equals( $key['hash'], $hash ) ) {
				if ( time() - (int) $key['last_used'] > 300 ) {
					$keys[ $id ]['last_used'] = time();
					update_option( self::OPT_KEYS, $keys, false );
				}
				$this->label = 'API key: ' . $key['label'];
				return (int) $key['user_id'];
			}
		}
		return 0;
	}

	/**
	 * All stored keys (hashes only).
	 *
	 * @return array
	 */
	public function keys() {
		$keys = get_option( self::OPT_KEYS, array() );
		return is_array( $keys ) ? $keys : array();
	}

	/**
	 * Create a key. The plain token is returned once and never stored.
	 *
	 * @param int    $user_id User.
	 * @param string $label   Label.
	 * @return string Plain token.
	 */
	public function create_key( $user_id, $label ) {
		$token          = self::KEY_PREFIX . bin2hex( random_bytes( 24 ) );
		$keys           = $this->keys();
		$id             = wp_generate_uuid4();
		$keys[ $id ]    = array(
			'hash'      => hash( 'sha256', $token ),
			'hint'      => substr( $token, 0, 9 ) . '…' . substr( $token, -4 ),
			'user_id'   => (int) $user_id,
			'label'     => '' !== $label ? $label : 'API key',
			'created'   => time(),
			'last_used' => 0,
		);
		update_option( self::OPT_KEYS, $keys, false );
		return $token;
	}

	/**
	 * Revoke a key.
	 *
	 * @param string $id Key ID.
	 */
	public function revoke_key( $id ) {
		$keys = $this->keys();
		unset( $keys[ $id ] );
		update_option( self::OPT_KEYS, $keys, false );
	}
}
