<?php
/**
 * Tool registry.
 *
 * Third-party plugins can add tools:
 *
 *     add_action( 'open_mcp_register_tools', function ( $registry ) {
 *         $registry->register( array(
 *             'name'         => 'my_tool',
 *             'description'  => 'Does something.',
 *             'input_schema' => \OpenMCP\Schema::obj( array( 'x' => \OpenMCP\Schema::str( 'X' ) ), array( 'x' ) ),
 *             'capability'   => 'edit_posts',
 *             'read_only'    => true,
 *             'callback'     => function ( $args ) { return array( 'ok' => true ); },
 *         ) );
 *     } );
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Holds tool definitions.
 */
class Tool_Registry {

	/** @var array */
	private $tools = array();

	/** @var bool */
	private $loaded = false;

	/**
	 * Register a tool.
	 *
	 * @param array $tool Definition.
	 */
	public function register( array $tool ) {
		$tool = wp_parse_args(
			$tool,
			array(
				'name'         => '',
				'title'        => '',
				'description'  => '',
				'category'     => 'Other',
				'input_schema' => Schema::obj(),
				'capability'   => 'read',
				'read_only'    => false,
				'destructive'  => false,
				'idempotent'   => false,
				'open_world'   => false,
				'callback'     => null,
			)
		);
		if ( ! preg_match( '/^[A-Za-z0-9_.-]{1,64}$/', $tool['name'] ) || ! is_callable( $tool['callback'] ) ) {
			return;
		}
		if ( '' === $tool['title'] ) {
			$tool['title'] = ucwords( str_replace( array( 'wp_', '_' ), array( '', ' ' ), $tool['name'] ) );
		}
		$this->tools[ $tool['name'] ] = $tool;
	}

	/**
	 * Remove a tool.
	 *
	 * @param string $name Tool name.
	 */
	public function unregister( $name ) {
		unset( $this->tools[ $name ] );
	}

	/** Load built-in and third-party tools once. */
	public function load() {
		if ( $this->loaded ) {
			return;
		}
		$this->loaded = true;

		Tools\Site::register( $this );
		Tools\Content::register( $this );
		Tools\Taxonomies::register( $this );
		Tools\Media::register( $this );
		Tools\Comments::register( $this );
		Tools\Users::register( $this );
		Tools\Menus::register( $this );
		Tools\Appearance::register( $this );
		Tools\Plugins::register( $this );
		Tools\Advanced::register( $this );

		/**
		 * Register additional MCP tools.
		 *
		 * @param Tool_Registry $registry Registry.
		 */
		do_action( 'open_mcp_register_tools', $this );

		if ( Plugin::setting( 'expose_abilities' ) ) {
			Tools\Abilities::register( $this );
		}
	}

	/**
	 * All tools (enabled or not).
	 *
	 * @return array
	 */
	public function all() {
		$this->load();
		return $this->tools;
	}

	/**
	 * One tool.
	 *
	 * @param string $name Name.
	 * @return array|null
	 */
	public function get( $name ) {
		$this->load();
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * Is the tool switched on by the site admin?
	 *
	 * @param array $tool Tool.
	 * @return bool
	 */
	public function is_enabled( array $tool ) {
		$settings = Plugin::settings();
		if ( in_array( $tool['name'], (array) $settings['disabled_tools'], true ) ) {
			return false;
		}
		if ( $settings['read_only'] && ! $tool['read_only'] && 'wp_rest_request' !== $tool['name'] ) {
			return false;
		}
		return true;
	}

	/**
	 * Can the current user run this tool?
	 *
	 * @param array $tool Tool.
	 * @param array $args Arguments.
	 * @return bool
	 */
	public function user_can( array $tool, array $args = array() ) {
		$cap = $tool['capability'];
		// Strings are always capability names (some, like "delete_plugins", are also PHP function names).
		if ( ! is_string( $cap ) && is_callable( $cap ) ) {
			return (bool) call_user_func( $cap, $args );
		}
		return current_user_can( $cap );
	}

	/**
	 * Tools the current user can see.
	 *
	 * @return array
	 */
	public function available() {
		$out = array();
		foreach ( $this->all() as $tool ) {
			if ( $this->is_enabled( $tool ) && ( ! is_string( $tool['capability'] ) || current_user_can( $tool['capability'] ) ) ) {
				$out[] = $tool;
			}
		}
		return $out;
	}

	/**
	 * MCP wire format for tools/list.
	 *
	 * @param array $tool Tool.
	 * @return array
	 */
	public function to_mcp( array $tool ) {
		return array(
			'name'        => $tool['name'],
			'title'       => $tool['title'],
			'description' => $tool['description'],
			'inputSchema' => $tool['input_schema'],
			'annotations' => array(
				'title'           => $tool['title'],
				'readOnlyHint'    => (bool) $tool['read_only'],
				'destructiveHint' => (bool) $tool['destructive'],
				'idempotentHint'  => (bool) $tool['idempotent'],
				'openWorldHint'   => (bool) $tool['open_world'],
			),
		);
	}
}
