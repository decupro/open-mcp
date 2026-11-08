<?php
/**
 * Plugin bootstrap and settings.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin container.
 */
final class Plugin {

	const OPT_SETTINGS = 'open_mcp_settings';

	/** @var Plugin|null */
	private static $instance = null;

	/** @var Logger */
	public $logger;

	/** @var Auth */
	public $auth;

	/** @var OAuth */
	public $oauth;

	/** @var Tool_Registry */
	public $tools;

	/** @var Server */
	public $server;

	/** @var Transport */
	public $transport;

	/**
	 * Singleton accessor.
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Wire up components.
	 */
	private function __construct() {
		$this->logger    = new Logger();
		$this->auth      = new Auth();
		$this->oauth     = new OAuth( $this->auth );
		$this->tools     = new Tool_Registry();
		$this->server    = new Server( $this->tools, $this->logger, $this->auth );
		$this->transport = new Transport( $this->server, $this->auth, $this->oauth );

		$this->oauth->hooks();
		$this->transport->hooks();

		if ( is_admin() ) {
			( new Admin( $this ) )->hooks();
		}
	}

	/**
	 * Activation: make sure settings exist.
	 */
	public static function activate() {
		if ( false === get_option( self::OPT_SETTINGS ) ) {
			add_option( self::OPT_SETTINGS, self::defaults(), '', false );
		}
	}

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'enabled'             => true,
			'read_only'           => false,
			'disabled_tools'      => array(),
			'expose_abilities'    => true,
			'allow_app_passwords' => true,
			'allow_url_key'       => true,
			'oauth_enabled'       => true,
			'min_capability'      => 'manage_options',
			'log_enabled'         => true,
			'allowed_origins'     => '',
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function settings() {
		$saved = get_option( self::OPT_SETTINGS, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	/**
	 * Single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function setting( $key ) {
		$settings = self::settings();
		return isset( $settings[ $key ] ) ? $settings[ $key ] : null;
	}

	/**
	 * Public MCP endpoint URL.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( Transport::NS . '/mcp' );
	}
}
