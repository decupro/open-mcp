<?php
/**
 * MCP Streamable HTTP transport on top of the WordPress REST API.
 *
 * Endpoint: /wp-json/open-mcp/v1/mcp
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * REST routes.
 */
class Transport {

	const NS = 'open-mcp/v1';

	/** @var Server */
	private $server;

	/** @var Auth */
	private $auth;

	/** @var OAuth */
	private $oauth;

	/**
	 * Constructor.
	 *
	 * @param Server $server Server.
	 * @param Auth   $auth   Auth.
	 * @param OAuth  $oauth  OAuth.
	 */
	public function __construct( Server $server, Auth $auth, OAuth $oauth ) {
		$this->server = $server;
		$this->auth   = $auth;
		$this->oauth  = $oauth;
	}

	/** Hooks. */
	public function hooks() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_filter( 'rest_pre_serve_request', array( $this, 'serve_empty_body' ), 10, 4 );
		add_filter( 'rest_allowed_cors_headers', array( $this, 'cors_allowed_headers' ) );
		add_filter( 'rest_exposed_cors_headers', array( $this, 'cors_exposed_headers' ) );
	}

	/** Register routes. */
	public function register_routes() {
		$mcp = array(
			'methods'             => array( 'GET', 'POST', 'DELETE' ),
			'callback'            => array( $this, 'handle' ),
			'permission_callback' => '__return_true', // Auth is done inside handle() so we can send a proper WWW-Authenticate challenge.
		);
		register_rest_route( self::NS, '/mcp', $mcp );
		register_rest_route( self::NS, '/mcp/(?P<key>omcp_[A-Za-z0-9]+)', $mcp );

		register_rest_route(
			self::NS,
			'/oauth/protected-resource',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return new \WP_REST_Response( OAuth::protected_resource_metadata() );
				},
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/metadata',
			array(
				'methods'             => 'GET',
				'callback'            => static function () {
					return new \WP_REST_Response( OAuth::server_metadata() );
				},
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/register',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->oauth, 'rest_register' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/oauth/token',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this->oauth, 'rest_token' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Handle an MCP HTTP request.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function handle( \WP_REST_Request $request ) {
		$settings = Plugin::settings();

		if ( ! $settings['enabled'] ) {
			return $this->jsonrpc_http_error( 503, -32000, 'The MCP server is disabled on this site.' );
		}

		if ( ! $this->origin_allowed( $request ) ) {
			return $this->jsonrpc_http_error( 403, -32000, 'Origin not allowed. Add it under MCP → Settings → Allowed origins.' );
		}

		$user_id = $this->auth->authenticate( $request );
		if ( is_wp_error( $user_id ) ) {
			$data     = $user_id->get_error_data();
			$status   = isset( $data['status'] ) ? (int) $data['status'] : 401;
			$response = $this->jsonrpc_http_error( $status, -32001, $user_id->get_error_message() );
			if ( 401 === $status ) {
				$challenge = 'Bearer realm="' . esc_attr( get_bloginfo( 'name' ) ) . '"';
				if ( $settings['oauth_enabled'] ) {
					$challenge .= ', resource_metadata="' . OAuth::resource_metadata_url() . '"';
				}
				if ( 'invalid_token' === $user_id->get_error_code() ) {
					$challenge .= ', error="invalid_token"';
				}
				$response->header( 'WWW-Authenticate', $challenge );
			}
			return $response;
		}

		$method = $request->get_method();
		if ( 'GET' === $method ) {
			// No server-initiated SSE stream: all responses are returned inline.
			$response = new \WP_REST_Response( null, 405 );
			$response->header( 'Allow', 'POST' );
			return $response;
		}
		if ( 'DELETE' === $method ) {
			return new \WP_REST_Response( null, 200 ); // Stateless: nothing to terminate.
		}

		$body    = (string) $request->get_body();
		$payload = '' !== $body ? json_decode( $body, true ) : $request->get_json_params();
		if ( null === $payload && '' === $body ) {
			$payload = $request->get_body_params() ? $request->get_body_params() : null; // Internal rest_do_request() calls.
		}
		if ( null === $payload ) {
			return new \WP_REST_Response( Server::error( null, -32700, 'Parse error' ), 400 );
		}

		$result = $this->server->handle( $payload );
		if ( null === $result ) {
			return new \WP_REST_Response( null, 202 );
		}
		return new \WP_REST_Response( $result, 200 );
	}

	/**
	 * JSON-RPC error with HTTP status.
	 *
	 * @param int    $status  HTTP status.
	 * @param int    $code    JSON-RPC code.
	 * @param string $message Message.
	 * @return \WP_REST_Response
	 */
	private function jsonrpc_http_error( $status, $code, $message ) {
		return new \WP_REST_Response( Server::error( null, $code, $message ), $status );
	}

	/**
	 * DNS-rebinding protection: browser requests must come from an allowed Origin.
	 * Server-to-server clients (Claude.ai connectors, Claude Code, Cursor…) send no Origin.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return bool
	 */
	private function origin_allowed( \WP_REST_Request $request ) {
		$origin = (string) $request->get_header( 'origin' );
		if ( '' === $origin || 'null' === $origin ) {
			return true;
		}
		$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
		if ( $origin_host && in_array( $origin_host, array( wp_parse_url( home_url(), PHP_URL_HOST ), wp_parse_url( site_url(), PHP_URL_HOST ) ), true ) ) {
			return true;
		}
		$allowed = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) Plugin::setting( 'allowed_origins' ) ) ) );
		if ( in_array( '*', $allowed, true ) ) {
			return true;
		}
		return in_array( untrailingslashit( $origin ), array_map( 'untrailingslashit', $allowed ), true );
	}

	/**
	 * Send 202/405/200-with-null responses with an empty body instead of "null".
	 *
	 * @param bool              $served  Whether served.
	 * @param \WP_REST_Response $result  Result.
	 * @param \WP_REST_Request  $request Request.
	 * @return bool
	 */
	public function serve_empty_body( $served, $result, $request ) {
		if ( $served || ! ( $request instanceof \WP_REST_Request ) ) {
			return $served;
		}
		if ( 0 === strpos( $request->get_route(), '/' . self::NS . '/mcp' ) && null === $result->get_data() ) {
			return true;
		}
		return $served;
	}

	/**
	 * @param array $headers Headers.
	 * @return array
	 */
	public function cors_allowed_headers( $headers ) {
		return array_unique( array_merge( (array) $headers, array( 'Authorization', 'Mcp-Session-Id', 'Mcp-Protocol-Version', 'Last-Event-ID', 'X-MCP-API-Key' ) ) );
	}

	/**
	 * @param array $headers Headers.
	 * @return array
	 */
	public function cors_exposed_headers( $headers ) {
		return array_unique( array_merge( (array) $headers, array( 'Mcp-Session-Id', 'WWW-Authenticate' ) ) );
	}
}
