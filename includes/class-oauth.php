<?php
/**
 * Minimal OAuth 2.1 authorization server for MCP clients
 * (Dynamic Client Registration, Authorization Code + PKCE S256, refresh token rotation).
 *
 * This is what lets you paste a single URL into Claude's "Add custom connector" dialog.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * OAuth server.
 */
class OAuth {

	const OPT_CLIENTS   = 'open_mcp_oauth_clients';
	const OPT_TOKENS    = 'open_mcp_oauth_tokens';
	const ACCESS_TTL    = 3600;
	const REFRESH_TTL   = 2592000; // 30 days.
	const CODE_TTL      = 600;
	const MAX_CLIENTS   = 500;
	const ACCESS_PREFIX = 'omcpat_';
	const REFRESH_PREFIX = 'omcprt_';

	/** @var Auth */
	private $auth;

	/** @var array|null Token record of the current request. */
	private $current = null;

	/**
	 * Constructor.
	 *
	 * @param Auth $auth Auth.
	 */
	public function __construct( Auth $auth ) {
		$this->auth = $auth;
		$auth->set_oauth( $this );
	}

	/** Hooks. */
	public function hooks() {
		add_action( 'init', array( $this, 'maybe_serve_well_known' ), 0 );
		add_action( 'init', array( $this, 'maybe_authorize' ), 20 );
		// Stop core from treating client_id:client_secret Basic credentials on our token endpoint as an Application Password.
		add_filter( 'application_password_is_api_request', array( $this, 'skip_app_passwords_on_token_endpoint' ) );
	}

	/** @return bool */
	public static function enabled() {
		return (bool) Plugin::setting( 'oauth_enabled' );
	}

	/* ------------------------------------------------------------------ */
	/* URLs & metadata                                                     */
	/* ------------------------------------------------------------------ */

	/** @return string */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/** @return string */
	public static function authorize_url() {
		return add_query_arg( 'open-mcp-oauth', 'authorize', trailingslashit( home_url() ) );
	}

	/** @return string */
	public static function resource_metadata_url() {
		return rest_url( Transport::NS . '/oauth/protected-resource' );
	}

	/** @return array RFC 9728 protected resource metadata. */
	public static function protected_resource_metadata() {
		return array(
			'resource'                 => Plugin::endpoint_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);
	}

	/** @return array RFC 8414 authorization server metadata. */
	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::authorize_url(),
			'token_endpoint'                        => rest_url( Transport::NS . '/oauth/token' ),
			'registration_endpoint'                 => rest_url( Transport::NS . '/oauth/register' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array( 'mcp' ),
			'service_documentation'                 => admin_url( 'admin.php?page=open-mcp' ),
		);
	}

	/**
	 * Serve /.well-known/oauth-* documents (incl. RFC 8414 / 9728 path-suffixed variants).
	 */
	public function maybe_serve_well_known() {
		if ( ! self::enabled() || empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$path = (string) wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		if ( '' !== $home && 0 === strpos( $path, $home ) ) {
			$path = substr( $path, strlen( untrailingslashit( $home ) ) );
		}
		if ( 0 !== strpos( $path, '/.well-known/' ) ) {
			return;
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			// Only answer for our own resource, so other MCP plugins' discovery documents keep working.
			$suffix = substr( $path, strlen( '/.well-known/oauth-protected-resource' ) );
			$ours   = (string) wp_parse_url( Plugin::endpoint_url(), PHP_URL_PATH );
			if ( '' === $suffix || '/' === $suffix || 0 === strpos( $ours, untrailingslashit( $suffix ) ) ) {
				self::send_json( self::protected_resource_metadata() );
			}
			return;
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $path, '/.well-known/openid-configuration' ) ) {
			self::send_json( self::server_metadata() );
		}
	}

	/**
	 * Output JSON and exit.
	 *
	 * @param array $data   Data.
	 * @param int   $status HTTP status.
	 */
	private static function send_json( $data, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Cache-Control: no-store' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	/**
	 * Filter: don't try Application Password auth on the OAuth token/register routes.
	 *
	 * @param bool $is_api Whether this is an API request.
	 * @return bool
	 */
	public function skip_app_passwords_on_token_endpoint( $is_api ) {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $uri, Transport::NS . '/oauth/' ) ) {
			return false;
		}
		return $is_api;
	}

	/* ------------------------------------------------------------------ */
	/* Dynamic client registration (RFC 7591)                              */
	/* ------------------------------------------------------------------ */

	/**
	 * POST /oauth/register
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_register( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return self::oauth_error( 'access_denied', 'OAuth is disabled on this site.', 403 );
		}
		$body          = $request->get_json_params();
		$body          = is_array( $body ) ? $body : $request->get_body_params();
		$redirect_uris = isset( $body['redirect_uris'] ) ? (array) $body['redirect_uris'] : array();
		$redirect_uris = array_values( array_filter( array_map( 'strval', $redirect_uris ) ) );

		if ( ! $redirect_uris ) {
			return self::oauth_error( 'invalid_redirect_uri', 'At least one redirect_uri is required.' );
		}
		foreach ( $redirect_uris as $uri ) {
			if ( ! self::valid_redirect_uri( $uri ) ) {
				return self::oauth_error( 'invalid_redirect_uri', "Redirect URI not allowed: $uri" );
			}
		}

		$method = isset( $body['token_endpoint_auth_method'] ) ? (string) $body['token_endpoint_auth_method'] : 'none';
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$method = 'none';
		}

		$client_id = 'omcpc_' . bin2hex( random_bytes( 12 ) );
		$secret    = 'none' === $method ? null : bin2hex( random_bytes( 24 ) );
		$name      = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : 'MCP client';

		$clients               = $this->clients();
		$clients[ $client_id ] = array(
			'name'          => '' !== $name ? substr( $name, 0, 100 ) : 'MCP client',
			'redirect_uris' => $redirect_uris,
			'auth_method'   => $method,
			'secret_hash'   => $secret ? hash( 'sha256', $secret ) : null,
			'created'       => time(),
			'last_used'     => 0,
		);
		if ( count( $clients ) > self::MAX_CLIENTS ) {
			uasort(
				$clients,
				static function ( $a, $b ) {
					return max( $a['last_used'], $a['created'] ) <=> max( $b['last_used'], $b['created'] );
				}
			);
			$clients = array_slice( $clients, -self::MAX_CLIENTS, null, true );
		}
		update_option( self::OPT_CLIENTS, $clients, false );

		$out = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $clients[ $client_id ]['name'],
			'redirect_uris'              => $redirect_uris,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'token_endpoint_auth_method' => $method,
		);
		if ( $secret ) {
			$out['client_secret']            = $secret;
			$out['client_secret_expires_at'] = 0;
		}
		$response = new \WP_REST_Response( $out, 201 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Allowed redirect URIs: https, loopback http, or a private-use app scheme.
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	private static function valid_redirect_uri( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( ! $parts || empty( $parts['scheme'] ) || isset( $parts['fragment'] ) ) {
			return false;
		}
		$scheme = strtolower( $parts['scheme'] );
		if ( in_array( $scheme, array( 'javascript', 'data', 'vbscript', 'file' ), true ) ) {
			return false;
		}
		if ( 'http' === $scheme ) {
			$host = isset( $parts['host'] ) ? strtolower( $parts['host'] ) : '';
			return in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		}
		return 'https' !== $scheme || ! empty( $parts['host'] );
	}

	/** @return array */
	public function clients() {
		$clients = get_option( self::OPT_CLIENTS, array() );
		return is_array( $clients ) ? $clients : array();
	}

	/* ------------------------------------------------------------------ */
	/* Authorization endpoint (consent screen)                             */
	/* ------------------------------------------------------------------ */

	/** Handle ?open-mcp-oauth=authorize */
	public function maybe_authorize() {
		if ( ! isset( $_GET['open-mcp-oauth'] ) || 'authorize' !== $_GET['open-mcp-oauth'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		nocache_headers();
		header( 'X-Frame-Options: DENY' );

		if ( ! self::enabled() ) {
			$this->render_error( 'OAuth connections are disabled on this site. Use an API key instead.' );
		}

		// phpcs:disable WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash
		$q = array();
		foreach ( array( 'client_id', 'redirect_uri', 'response_type', 'state', 'code_challenge', 'code_challenge_method', 'scope', 'resource' ) as $k ) {
			$q[ $k ] = isset( $_GET[ $k ] ) ? (string) wp_unslash( $_GET[ $k ] ) : '';
		}
		// phpcs:enable

		$clients = $this->clients();
		$client  = isset( $clients[ $q['client_id'] ] ) ? $clients[ $q['client_id'] ] : null;
		if ( ! $client ) {
			$this->render_error( 'Unknown client. Remove the connector in your AI app and add it again.' );
		}
		if ( '' === $q['redirect_uri'] && 1 === count( $client['redirect_uris'] ) ) {
			$q['redirect_uri'] = $client['redirect_uris'][0];
		}
		if ( ! in_array( $q['redirect_uri'], $client['redirect_uris'], true ) ) {
			$this->render_error( 'The redirect URI does not match the registered client.' );
		}
		if ( 'code' !== $q['response_type'] ) {
			$this->redirect_error( $q, 'unsupported_response_type', 'Only response_type=code is supported.' );
		}
		if ( '' === $q['code_challenge'] || 'S256' !== $q['code_challenge_method'] ) {
			$this->redirect_error( $q, 'invalid_request', 'PKCE with S256 is required.' );
		}

		if ( ! is_user_logged_in() ) {
			$back = self::append_query( self::authorize_url(), array_filter( $q, 'strlen' ) );
			wp_safe_redirect( wp_login_url( $back ) );
			exit;
		}

		$user = wp_get_current_user();
		if ( ! user_can( $user, Plugin::setting( 'min_capability' ) ) ) {
			$this->render_error( 'Your account is not allowed to connect AI clients to this site. Ask an administrator.' );
		}

		if ( 'POST' === $_SERVER['REQUEST_METHOD'] ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			check_admin_referer( 'open_mcp_authorize_' . $q['client_id'] );
			if ( empty( $_POST['approve'] ) ) {
				$this->redirect_error( $q, 'access_denied', 'The user denied the request.' );
			}
			$code = bin2hex( random_bytes( 24 ) );
			set_transient(
				'open_mcp_code_' . hash( 'sha256', $code ),
				array(
					'client_id'      => $q['client_id'],
					'user_id'        => $user->ID,
					'redirect_uri'   => $q['redirect_uri'],
					'code_challenge' => $q['code_challenge'],
					'resource'       => $q['resource'],
				),
				self::CODE_TTL
			);
			$args = array( 'code' => $code );
			if ( '' !== $q['state'] ) {
				$args['state'] = $q['state'];
			}
			$args['iss'] = self::issuer();
			wp_redirect( self::append_query( $q['redirect_uri'], $args ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- exact-match registered URI.
			exit;
		}

		$this->render_consent( $client, $q, $user );
	}

	/**
	 * Append query args without mangling custom schemes.
	 *
	 * @param string $url  URL.
	 * @param array  $args Args.
	 * @return string
	 */
	private static function append_query( $url, array $args ) {
		return $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . http_build_query( $args, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Redirect back to the client with an error.
	 *
	 * @param array  $q    Request params.
	 * @param string $code Error code.
	 * @param string $desc Description.
	 */
	private function redirect_error( array $q, $code, $desc ) {
		$args = array(
			'error'             => $code,
			'error_description' => $desc,
		);
		if ( '' !== $q['state'] ) {
			$args['state'] = $q['state'];
		}
		wp_redirect( self::append_query( $q['redirect_uri'], $args ) ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect
		exit;
	}

	/**
	 * Error page.
	 *
	 * @param string $message Message.
	 */
	private function render_error( $message ) {
		$this->page_open( __( 'Connection error', 'open-mcp' ) );
		echo '<p>' . esc_html( $message ) . '</p>';
		$this->page_close();
	}

	/**
	 * Consent screen.
	 *
	 * @param array    $client Client.
	 * @param array    $q      Params.
	 * @param \WP_User $user   User.
	 */
	private function render_consent( array $client, array $q, \WP_User $user ) {
		$host = wp_parse_url( $q['redirect_uri'], PHP_URL_HOST );
		$this->page_open( __( 'Connect an AI assistant', 'open-mcp' ) );
		?>
		<p><strong><?php echo esc_html( $client['name'] ); ?></strong>
		<?php
		/* translators: %s: site name */
		echo esc_html( sprintf( __( 'wants to connect to %s.', 'open-mcp' ), get_bloginfo( 'name' ) ) );
		?>
		</p>
		<p>
		<?php
		/* translators: 1: username, 2: role list */
		echo esc_html( sprintf( __( 'It will act as %1$s (%2$s) and can do anything this account can do on the site — create, edit and delete content, and more depending on your role.', 'open-mcp' ), $user->user_login, implode( ', ', $user->roles ) ) );
		?>
		</p>
		<p class="omcp-small">
		<?php
		/* translators: %s: hostname */
		echo esc_html( sprintf( __( 'You will be sent back to %s.', 'open-mcp' ), $host ? $host : $q['redirect_uri'] ) );
		?>
		</p>
		<form method="post">
			<?php wp_nonce_field( 'open_mcp_authorize_' . $q['client_id'] ); ?>
			<button type="submit" name="approve" value="1" class="omcp-btn omcp-primary"><?php esc_html_e( 'Allow access', 'open-mcp' ); ?></button>
			<button type="submit" name="deny" value="1" class="omcp-btn"><?php esc_html_e( 'Cancel', 'open-mcp' ); ?></button>
		</form>
		<p class="omcp-small"><?php esc_html_e( 'You can revoke access at any time under MCP → Connections in wp-admin.', 'open-mcp' ); ?></p>
		<?php
		$this->page_close();
	}

	/**
	 * Page chrome.
	 *
	 * @param string $title Title.
	 */
	private function page_open( $title ) {
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex">
<title><?php echo esc_html( $title ); ?></title>
<style>
body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f0f0f1;color:#1d2327;margin:0;display:flex;min-height:100vh;align-items:center;justify-content:center}
.omcp-card{background:#fff;max-width:440px;width:calc(100% - 32px);padding:32px;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
h1{font-size:20px;margin:0 0 16px}.omcp-small{color:#646970;font-size:13px}
.omcp-btn{font-size:14px;padding:10px 18px;border-radius:4px;border:1px solid #2271b1;background:#fff;color:#2271b1;cursor:pointer;margin:8px 8px 0 0}
.omcp-primary{background:#2271b1;color:#fff}
</style>
</head>
<body><div class="omcp-card"><h1><?php echo esc_html( $title ); ?></h1>
		<?php
	}

	/** Close page and exit. */
	private function page_close() {
		echo '</div></body></html>';
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Token endpoint                                                      */
	/* ------------------------------------------------------------------ */

	/**
	 * POST /oauth/token
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public function rest_token( \WP_REST_Request $request ) {
		if ( ! self::enabled() ) {
			return self::oauth_error( 'access_denied', 'OAuth is disabled on this site.', 403 );
		}
		$p = array_merge( (array) $request->get_json_params(), $request->get_body_params(), $request->get_query_params() );

		$client_id     = isset( $p['client_id'] ) ? (string) $p['client_id'] : '';
		$client_secret = isset( $p['client_secret'] ) ? (string) $p['client_secret'] : '';
		// client_secret_basic.
		if ( isset( $_SERVER['PHP_AUTH_USER'] ) ) {
			$client_id     = rawurldecode( (string) wp_unslash( $_SERVER['PHP_AUTH_USER'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			$client_secret = isset( $_SERVER['PHP_AUTH_PW'] ) ? rawurldecode( (string) wp_unslash( $_SERVER['PHP_AUTH_PW'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		} elseif ( preg_match( '/^Basic\s+(.+)$/i', (string) $request->get_header( 'authorization' ), $m ) ) {
			$decoded = base64_decode( $m[1], true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $client_id, $client_secret ) = array_map( 'rawurldecode', explode( ':', $decoded, 2 ) );
			}
		}

		$clients = $this->clients();
		if ( ! isset( $clients[ $client_id ] ) ) {
			return self::oauth_error( 'invalid_client', 'Unknown client.', 401 );
		}
		$client = $clients[ $client_id ];
		if ( $client['secret_hash'] && ! hash_equals( $client['secret_hash'], hash( 'sha256', $client_secret ) ) ) {
			return self::oauth_error( 'invalid_client', 'Client authentication failed.', 401 );
		}

		$grant = isset( $p['grant_type'] ) ? (string) $p['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			$code     = isset( $p['code'] ) ? (string) $p['code'] : '';
			$key      = 'open_mcp_code_' . hash( 'sha256', $code );
			$data     = get_transient( $key );
			$verifier = isset( $p['code_verifier'] ) ? (string) $p['code_verifier'] : '';
			delete_transient( $key ); // Single use.

			if ( ! $data || $data['client_id'] !== $client_id ) {
				return self::oauth_error( 'invalid_grant', 'Invalid or expired authorization code.' );
			}
			if ( isset( $p['redirect_uri'] ) && (string) $p['redirect_uri'] !== $data['redirect_uri'] ) {
				return self::oauth_error( 'invalid_grant', 'redirect_uri mismatch.' );
			}
			$expected = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			if ( '' === $verifier || ! hash_equals( $data['code_challenge'], $expected ) ) {
				return self::oauth_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			return $this->issue_tokens( $client_id, (int) $data['user_id'] );
		}

		if ( 'refresh_token' === $grant ) {
			$refresh = isset( $p['refresh_token'] ) ? (string) $p['refresh_token'] : '';
			$tokens  = $this->tokens();
			$hash    = hash( 'sha256', $refresh );
			if ( ! isset( $tokens[ $hash ] ) || 'refresh' !== $tokens[ $hash ]['type'] || $tokens[ $hash ]['client_id'] !== $client_id || $tokens[ $hash ]['exp'] < time() ) {
				return self::oauth_error( 'invalid_grant', 'Invalid or expired refresh token.' );
			}
			$user_id = (int) $tokens[ $hash ]['user_id'];
			unset( $tokens[ $hash ] ); // Rotate.
			update_option( self::OPT_TOKENS, $tokens, false );
			return $this->issue_tokens( $client_id, $user_id );
		}

		return self::oauth_error( 'unsupported_grant_type', 'Supported: authorization_code, refresh_token.' );
	}

	/**
	 * Issue an access + refresh token pair.
	 *
	 * @param string $client_id Client.
	 * @param int    $user_id   User.
	 * @return \WP_REST_Response
	 */
	private function issue_tokens( $client_id, $user_id ) {
		$access  = self::ACCESS_PREFIX . bin2hex( random_bytes( 24 ) );
		$refresh = self::REFRESH_PREFIX . bin2hex( random_bytes( 24 ) );
		$now     = time();

		$tokens                             = $this->prune( $this->tokens() );
		$tokens[ hash( 'sha256', $access ) ]  = array(
			'type'      => 'access',
			'client_id' => $client_id,
			'user_id'   => $user_id,
			'exp'       => $now + self::ACCESS_TTL,
		);
		$tokens[ hash( 'sha256', $refresh ) ] = array(
			'type'      => 'refresh',
			'client_id' => $client_id,
			'user_id'   => $user_id,
			'exp'       => $now + self::REFRESH_TTL,
		);
		update_option( self::OPT_TOKENS, $tokens, false );

		$clients = $this->clients();
		if ( isset( $clients[ $client_id ] ) ) {
			$clients[ $client_id ]['last_used'] = $now;
			update_option( self::OPT_CLIENTS, $clients, false );
		}

		$response = new \WP_REST_Response(
			array(
				'access_token'  => $access,
				'token_type'    => 'Bearer',
				'expires_in'    => self::ACCESS_TTL,
				'refresh_token' => $refresh,
				'scope'         => 'mcp',
			),
			200
		);
		$response->header( 'Cache-Control', 'no-store' );
		$response->header( 'Pragma', 'no-cache' );
		return $response;
	}

	/**
	 * Verify an access token.
	 *
	 * @param string $token Token.
	 * @return int User ID or 0.
	 */
	public function verify_access_token( $token ) {
		if ( 0 !== strpos( $token, self::ACCESS_PREFIX ) ) {
			return 0;
		}
		$tokens = $this->tokens();
		$hash   = hash( 'sha256', $token );
		if ( ! isset( $tokens[ $hash ] ) || 'access' !== $tokens[ $hash ]['type'] || $tokens[ $hash ]['exp'] < time() ) {
			return 0;
		}
		$clients = $this->clients();
		if ( ! isset( $clients[ $tokens[ $hash ]['client_id'] ] ) ) {
			return 0; // Client was revoked.
		}
		$this->current = $tokens[ $hash ];
		return (int) $tokens[ $hash ]['user_id'];
	}

	/** @return string */
	public function current_client_name() {
		if ( ! $this->current ) {
			return '';
		}
		$clients = $this->clients();
		return isset( $clients[ $this->current['client_id'] ] ) ? $clients[ $this->current['client_id'] ]['name'] : '';
	}

	/** @return array */
	public function tokens() {
		$tokens = get_option( self::OPT_TOKENS, array() );
		return is_array( $tokens ) ? $tokens : array();
	}

	/**
	 * Drop expired tokens.
	 *
	 * @param array $tokens Tokens.
	 * @return array
	 */
	private function prune( array $tokens ) {
		$now = time();
		return array_filter(
			$tokens,
			static function ( $t ) use ( $now ) {
				return $t['exp'] >= $now;
			}
		);
	}

	/**
	 * Active connections grouped by client + user (for the admin screen).
	 *
	 * @return array
	 */
	public function connections() {
		$clients = $this->clients();
		$out     = array();
		foreach ( $this->prune( $this->tokens() ) as $t ) {
			if ( 'refresh' !== $t['type'] || ! isset( $clients[ $t['client_id'] ] ) ) {
				continue;
			}
			$key         = $t['client_id'] . ':' . $t['user_id'];
			$out[ $key ] = array(
				'client_id' => $t['client_id'],
				'name'      => $clients[ $t['client_id'] ]['name'],
				'user_id'   => (int) $t['user_id'],
				'last_used' => (int) $clients[ $t['client_id'] ]['last_used'],
			);
		}
		return array_values( $out );
	}

	/**
	 * Revoke all tokens for a client (and delete the client registration).
	 *
	 * @param string $client_id Client.
	 */
	public function revoke_client( $client_id ) {
		$tokens = array_filter(
			$this->tokens(),
			static function ( $t ) use ( $client_id ) {
				return $t['client_id'] !== $client_id;
			}
		);
		update_option( self::OPT_TOKENS, $tokens, false );
		$clients = $this->clients();
		unset( $clients[ $client_id ] );
		update_option( self::OPT_CLIENTS, $clients, false );
	}

	/** Revoke everything. */
	public function revoke_all() {
		delete_option( self::OPT_TOKENS );
		delete_option( self::OPT_CLIENTS );
	}

	/**
	 * OAuth error response.
	 *
	 * @param string $code   Code.
	 * @param string $desc   Description.
	 * @param int    $status HTTP status.
	 * @return \WP_REST_Response
	 */
	private static function oauth_error( $code, $desc, $status = 400 ) {
		$response = new \WP_REST_Response(
			array(
				'error'             => $code,
				'error_description' => $desc,
			),
			$status
		);
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}
}
