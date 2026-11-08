<?php
/**
 * MCP JSON-RPC message handling.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Stateless MCP server.
 */
class Server {

	const PROTOCOL_VERSIONS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/** Responses above this size are truncated so they don't flood the model's context. */
	const MAX_RESULT_CHARS = 200000;

	/** @var Tool_Registry */
	private $tools;

	/** @var Logger */
	private $logger;

	/** @var Auth */
	private $auth;

	/**
	 * Constructor.
	 *
	 * @param Tool_Registry $tools  Tools.
	 * @param Logger        $logger Logger.
	 * @param Auth          $auth   Auth.
	 */
	public function __construct( Tool_Registry $tools, Logger $logger, Auth $auth ) {
		$this->tools  = $tools;
		$this->logger = $logger;
		$this->auth   = $auth;
	}

	/**
	 * Handle a decoded JSON-RPC payload (single message or batch).
	 *
	 * @param mixed $payload Decoded JSON.
	 * @return array|null Response, or null when nothing should be returned (notifications only).
	 */
	public function handle( $payload ) {
		if ( Util::is_list( $payload ) && array() !== $payload ) {
			$responses = array();
			foreach ( $payload as $message ) {
				$response = $this->handle_message( $message );
				if ( null !== $response ) {
					$responses[] = $response;
				}
			}
			return $responses ? $responses : null;
		}
		return $this->handle_message( $payload );
	}

	/**
	 * Handle one message.
	 *
	 * @param mixed $msg Message.
	 * @return array|null
	 */
	private function handle_message( $msg ) {
		if ( ! is_array( $msg ) || ! isset( $msg['jsonrpc'] ) || '2.0' !== $msg['jsonrpc'] || ! isset( $msg['method'] ) || ! is_string( $msg['method'] ) ) {
			if ( is_array( $msg ) && ( isset( $msg['result'] ) || isset( $msg['error'] ) ) ) {
				return null; // A response from the client; nothing to do.
			}
			return self::error( is_array( $msg ) && isset( $msg['id'] ) ? $msg['id'] : null, -32600, 'Invalid Request' );
		}

		$is_notification = ! array_key_exists( 'id', $msg );
		$id              = $is_notification ? null : $msg['id'];
		$params          = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( $is_notification ) {
			return null; // notifications/initialized, notifications/cancelled, ...
		}

		switch ( $msg['method'] ) {
			case 'initialize':
				return self::result( $id, $this->initialize( $params ) );
			case 'ping':
				return self::result( $id, new \stdClass() );
			case 'tools/list':
				return self::result( $id, $this->tools_list() );
			case 'tools/call':
				return $this->tools_call( $id, $params );
			case 'resources/list':
				return self::result( $id, array( 'resources' => array() ) );
			case 'resources/templates/list':
				return self::result( $id, array( 'resourceTemplates' => array() ) );
			case 'prompts/list':
				return self::result( $id, array( 'prompts' => array() ) );
			case 'logging/setLevel':
				return self::result( $id, new \stdClass() );
			case 'completion/complete':
				return self::result( $id, array( 'completion' => array( 'values' => array(), 'hasMore' => false ) ) );
			default:
				return self::error( $id, -32601, 'Method not found: ' . $msg['method'] );
		}
	}

	/**
	 * Initialize handshake.
	 *
	 * @param array $params Params.
	 * @return array
	 */
	private function initialize( array $params ) {
		$requested = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
		$version   = in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0];
		$user      = wp_get_current_user();

		$instructions = sprintf(
			"You are connected to the WordPress site \"%s\" (%s) as user \"%s\" (roles: %s). " .
			"Tools act with this user's WordPress permissions. Post content is stored as raw HTML / Gutenberg block markup: " .
			"read a post with wp_get_post before editing and preserve existing block comments (<!-- wp:... -->). " .
			"Prefer drafts unless the user asks to publish. Use wp_rest_request for anything not covered by a dedicated tool (e.g. WooCommerce or other plugins' REST routes); discover routes with wp_list_rest_routes.",
			get_bloginfo( 'name' ),
			home_url(),
			$user->user_login,
			implode( ', ', $user->roles )
		);

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'   => array( 'listChanged' => false ),
				'logging' => new \stdClass(),
			),
			'serverInfo'      => array(
				'name'    => 'open-mcp-wordpress',
				'title'   => get_bloginfo( 'name' ) . ' (WordPress)',
				'version' => OPEN_MCP_VERSION,
			),
			/** Filter the instructions sent to MCP clients on connect. */
			'instructions'    => apply_filters( 'open_mcp_instructions', $instructions ),
		);
	}

	/** @return array */
	private function tools_list() {
		$list = array();
		foreach ( $this->tools->available() as $tool ) {
			$list[] = $this->tools->to_mcp( $tool );
		}
		return array( 'tools' => $list );
	}

	/**
	 * Execute a tool.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $params Params.
	 * @return array
	 */
	private function tools_call( $id, array $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
		$tool = $this->tools->get( $name );

		if ( ! $tool ) {
			return self::error( $id, -32602, "Unknown tool: $name" );
		}
		if ( ! $this->tools->is_enabled( $tool ) ) {
			return self::result( $id, self::tool_error( "The tool '$name' is disabled on this site." ) );
		}
		if ( ! $this->tools->user_can( $tool, $args ) ) {
			return self::result( $id, self::tool_error( "Your WordPress account does not have permission to use '$name'." ) );
		}
		$required = isset( $tool['input_schema']['required'] ) ? (array) $tool['input_schema']['required'] : array();
		foreach ( $required as $field ) {
			if ( ! array_key_exists( $field, $args ) ) {
				return self::result( $id, self::tool_error( "Missing required argument '$field'." ) );
			}
		}

		$start = microtime( true );
		try {
			/**
			 * Short-circuit or alter a tool call. Return a non-null value to skip the callback.
			 */
			$result = apply_filters( 'open_mcp_pre_call_tool', null, $name, $args, $tool );
			if ( null === $result ) {
				$result = call_user_func( $tool['callback'], $args );
			}
		} catch ( \Throwable $e ) {
			$result = new \WP_Error( 'exception', $e->getMessage() );
		}
		$ms = (int) round( ( microtime( true ) - $start ) * 1000 );

		$is_error = is_wp_error( $result );
		$this->logger->log(
			array(
				'tool'  => $name,
				'ok'    => ! $is_error,
				'ms'    => $ms,
				'via'   => $this->auth->label(),
				'error' => $is_error ? $result->get_error_message() : '',
			)
		);
		do_action( 'open_mcp_tool_called', $name, $args, $result );

		if ( $is_error ) {
			$messages = $result->get_error_messages();
			return self::result( $id, self::tool_error( implode( ' ', $messages ) . ' [' . $result->get_error_code() . ']' ) );
		}

		$text = is_string( $result ) ? $result : wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
		if ( false === $text ) {
			$text = 'null';
		}
		if ( strlen( $text ) > self::MAX_RESULT_CHARS ) {
			$text = substr( $text, 0, self::MAX_RESULT_CHARS ) . "\n…[truncated: result was " . strlen( $text ) . ' characters. Narrow the request with paging, fields or filters.]';
		}

		return self::result(
			$id,
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $text,
					),
				),
				'isError' => false,
			)
		);
	}

	/**
	 * Tool-level error result (visible to the model).
	 *
	 * @param string $message Message.
	 * @return array
	 */
	private static function tool_error( $message ) {
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $message,
				),
			),
			'isError' => true,
		);
	}

	/**
	 * JSON-RPC result.
	 *
	 * @param mixed $id     ID.
	 * @param mixed $result Result.
	 * @return array
	 */
	public static function result( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * JSON-RPC error.
	 *
	 * @param mixed  $id      ID.
	 * @param int    $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	public static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
