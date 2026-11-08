<?php
/**
 * Generic WordPress REST API access: reach every route core and plugins expose
 * (WooCommerce, forms, SEO plugins, …) with the connected user's permissions.
 *
 * @package OpenMCP
 */

namespace OpenMCP\Tools;

use OpenMCP\Plugin;
use OpenMCP\Schema;
use OpenMCP\Tool_Registry;
use OpenMCP\Transport;

defined( 'ABSPATH' ) || exit;

/**
 * Advanced tools.
 */
class Advanced {

	/**
	 * Register.
	 *
	 * @param Tool_Registry $r Registry.
	 */
	public static function register( Tool_Registry $r ) {
		$r->register(
			array(
				'name'         => 'wp_list_rest_routes',
				'category'     => 'Advanced',
				'description'  => 'Discover REST API routes on this site (core and plugins). Without "namespace" returns the list of namespaces; with it, the routes and methods in that namespace (e.g. "wc/v3", "wp/v2", "yoast/v1").',
				'capability'   => 'read',
				'read_only'    => true,
				'idempotent'   => true,
				'input_schema' => Schema::obj(
					array(
						'namespace'    => Schema::str( 'Namespace, e.g. "wp/v2".' ),
						'with_args'    => Schema::bool( 'Include argument names and descriptions for each endpoint (larger).' ),
					)
				),
				'callback'     => array( __CLASS__, 'list_routes' ),
			)
		);

		$r->register(
			array(
				'name'         => 'wp_rest_request',
				'category'     => 'Advanced',
				'description'  => 'Call any WordPress REST API route internally as the connected user (permissions enforced by the route). Use for anything without a dedicated tool: WooCommerce products/orders (wc/v3), widgets, block patterns, site editor, plugin settings… Example: {"method":"GET","path":"/wc/v3/products","query":{"per_page":5,"_fields":"id,name,price"}}.',
				'capability'   => 'read',
				'input_schema' => Schema::obj(
					array(
						'method' => Schema::enum( 'HTTP method.', array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ) ),
						'path'   => Schema::str( 'Route path starting with "/", e.g. "/wp/v2/blocks" or "/wc/v3/orders/123".' ),
						'query'  => Schema::map( 'Query parameters. Use "_fields" to limit response size.' ),
						'body'   => Schema::map( 'JSON body for POST/PUT/PATCH.' ),
					),
					array( 'method', 'path' )
				),
				'callback'     => array( __CLASS__, 'rest_request' ),
			)
		);
	}

	/**
	 * Dispatch an internal REST request.
	 *
	 * @param string $method Method.
	 * @param string $path   Path.
	 * @param array  $query  Query params.
	 * @param array  $body   Body params.
	 * @return array|\WP_Error ['status' => int, 'data' => mixed, 'headers' => array]
	 */
	public static function rest( $method, $path, array $query = array(), array $body = array() ) {
		$path = '/' . ltrim( (string) $path, '/' );
		$request = new \WP_REST_Request( strtoupper( $method ), $path );
		if ( $query ) {
			$request->set_query_params( $query );
		}
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
			$request->set_body_params( $body );
		}
		$server   = rest_get_server();
		$response = rest_do_request( $request );
		/** This filter is documented in wp-includes/rest-api/class-wp-rest-server.php (applies _fields etc.) */
		$response = apply_filters( 'rest_post_dispatch', rest_ensure_response( $response ), $server, $request ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		$data     = $server->response_to_data( $response, ! empty( $query['_embed'] ) );
		$status   = $response->get_status();

		if ( $status >= 400 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? $data['message'] : 'REST request failed.';
			$code    = is_array( $data ) && isset( $data['code'] ) ? $data['code'] : 'rest_error';
			return new \WP_Error( $code, "HTTP $status: $message" );
		}
		return array(
			'status' => $status,
			'data'   => $data,
			'total'  => $response->get_headers()['X-WP-Total'] ?? null,
		);
	}

	/**
	 * @param array $args Args.
	 * @return array|\WP_Error
	 */
	public static function rest_request( array $args ) {
		$method = strtoupper( (string) $args['method'] );
		$path   = '/' . ltrim( (string) $args['path'], '/' );
		if ( 0 === strpos( $path, '/' . Transport::NS ) ) {
			return new \WP_Error( 'forbidden', 'The MCP endpoints themselves cannot be called through this tool.' );
		}
		if ( Plugin::setting( 'read_only' ) && 'GET' !== $method ) {
			return new \WP_Error( 'read_only', 'The site is in read-only MCP mode; only GET requests are allowed.' );
		}
		$query = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
		$body  = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();
		$res   = self::rest( $method, $path, $query, $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( null === $res['total'] ) {
			unset( $res['total'] );
		}
		return $res;
	}

	/**
	 * @param array $args Args.
	 * @return array
	 */
	public static function list_routes( array $args ) {
		$server = rest_get_server();
		if ( empty( $args['namespace'] ) ) {
			return array(
				'namespaces' => array_values(
					array_filter(
						$server->get_namespaces(),
						static function ( $ns ) {
							return Transport::NS !== $ns;
						}
					)
				),
			);
		}
		$ns     = trim( (string) $args['namespace'], '/' );
		$routes = array();
		foreach ( $server->get_routes( $ns ) as $route => $handlers ) {
			$methods   = array();
			$arguments = array();
			foreach ( $handlers as $handler ) {
				$methods = array_merge( $methods, array_keys( array_filter( (array) $handler['methods'] ) ) );
				if ( ! empty( $args['with_args'] ) && ! empty( $handler['args'] ) ) {
					foreach ( $handler['args'] as $name => $def ) {
						$arguments[ $name ] = isset( $def['description'] ) ? $def['description'] : ( isset( $def['type'] ) ? ( is_array( $def['type'] ) ? implode( '|', $def['type'] ) : $def['type'] ) : '' );
					}
				}
			}
			$row = array(
				'route'   => $route,
				'methods' => array_values( array_unique( $methods ) ),
			);
			if ( $arguments ) {
				$row['args'] = $arguments;
			}
			$routes[] = $row;
		}
		return array(
			'namespace' => $ns,
			'routes'    => $routes,
		);
	}
}
