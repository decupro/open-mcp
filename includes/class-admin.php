<?php
/**
 * wp-admin screens: Connect, Keys & connections, Tools, Activity, Settings.
 *
 * @package OpenMCP
 */

namespace OpenMCP;

defined( 'ABSPATH' ) || exit;

/**
 * Admin UI.
 */
class Admin {

	const SLUG = 'open-mcp';

	/** @var Plugin */
	private $plugin;

	/**
	 * Constructor.
	 *
	 * @param Plugin $plugin Plugin.
	 */
	public function __construct( Plugin $plugin ) {
		$this->plugin = $plugin;
	}

	/** Hooks. */
	public function hooks() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_post_open_mcp', array( $this, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( OPEN_MCP_FILE ), array( $this, 'action_links' ) );
	}

	/** Menu. */
	public function menu() {
		add_menu_page(
			__( 'MCP', 'open-mcp' ),
			__( 'MCP', 'open-mcp' ),
			'read',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-rest-api',
			81
		);
	}

	/**
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::SLUG ) ) . '">' . esc_html__( 'Connect', 'open-mcp' ) . '</a>' );
		return $links;
	}

	/**
	 * @param string $hook Hook suffix.
	 */
	public function assets( $hook ) {
		if ( 'toplevel_page_' . self::SLUG !== $hook ) {
			return;
		}
		wp_enqueue_style( 'open-mcp-admin', OPEN_MCP_URL . 'assets/admin.css', array(), OPEN_MCP_VERSION );
		wp_enqueue_script( 'open-mcp-admin', OPEN_MCP_URL . 'assets/admin.js', array(), OPEN_MCP_VERSION, true );
	}

	/** @return bool Can the current user connect clients at all? */
	private function can_connect() {
		return current_user_can( Plugin::setting( 'min_capability' ) );
	}

	/** @return string */
	private function url( $tab = 'connect', array $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Form handling                                                       */
	/* ------------------------------------------------------------------ */

	/** Handle all admin-post submissions. */
	public function handle_post() {
		$do = isset( $_POST['do'] ) ? sanitize_key( wp_unslash( $_POST['do'] ) ) : '';
		check_admin_referer( 'open_mcp_' . $do );
		$tab = 'connect';

		switch ( $do ) {
			case 'create_key':
				if ( ! $this->can_connect() ) {
					wp_die( esc_html__( 'You are not allowed to create MCP keys.', 'open-mcp' ) );
				}
				$label = isset( $_POST['label'] ) ? sanitize_text_field( wp_unslash( $_POST['label'] ) ) : '';
				$token = $this->plugin->auth->create_key( get_current_user_id(), $label );
				set_transient( 'open_mcp_new_key_' . get_current_user_id(), $token, 300 );
				$tab = 'keys';
				break;

			case 'revoke_key':
				$id   = isset( $_POST['key_id'] ) ? sanitize_text_field( wp_unslash( $_POST['key_id'] ) ) : '';
				$keys = $this->plugin->auth->keys();
				if ( isset( $keys[ $id ] ) && ( (int) $keys[ $id ]['user_id'] === get_current_user_id() || current_user_can( 'manage_options' ) ) ) {
					$this->plugin->auth->revoke_key( $id );
				}
				$tab = 'keys';
				break;

			case 'revoke_client':
				$client_id = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
				$own       = wp_list_filter( $this->plugin->oauth->connections(), array( 'client_id' => $client_id, 'user_id' => get_current_user_id() ) );
				if ( $own || current_user_can( 'manage_options' ) ) {
					$this->plugin->oauth->revoke_client( $client_id );
				}
				$tab = 'keys';
				break;

			case 'save_tools':
				$this->require_admin();
				$enabled  = isset( $_POST['tools'] ) ? array_map( 'sanitize_text_field', (array) wp_unslash( $_POST['tools'] ) ) : array();
				$disabled = array();
				foreach ( array_keys( $this->plugin->tools->all() ) as $name ) {
					if ( ! in_array( $name, $enabled, true ) ) {
						$disabled[] = $name;
					}
				}
				$this->save( array( 'disabled_tools' => $disabled ) );
				$tab = 'tools';
				break;

			case 'save_settings':
				$this->require_admin();
				$caps = array_keys( self::capability_choices() );
				$cap  = isset( $_POST['min_capability'] ) ? sanitize_key( wp_unslash( $_POST['min_capability'] ) ) : 'manage_options';
				$this->save(
					array(
						'enabled'             => ! empty( $_POST['enabled'] ),
						'read_only'           => ! empty( $_POST['read_only'] ),
						'expose_abilities'    => ! empty( $_POST['expose_abilities'] ),
						'allow_app_passwords' => ! empty( $_POST['allow_app_passwords'] ),
						'allow_url_key'       => ! empty( $_POST['allow_url_key'] ),
						'oauth_enabled'       => ! empty( $_POST['oauth_enabled'] ),
						'log_enabled'         => ! empty( $_POST['log_enabled'] ),
						'min_capability'      => in_array( $cap, $caps, true ) ? $cap : 'manage_options',
						'allowed_origins'     => isset( $_POST['allowed_origins'] ) ? sanitize_textarea_field( wp_unslash( $_POST['allowed_origins'] ) ) : '',
					)
				);
				$tab = 'settings';
				break;

			case 'revoke_all':
				$this->require_admin();
				delete_option( Auth::OPT_KEYS );
				$this->plugin->oauth->revoke_all();
				$tab = 'settings';
				break;

			case 'clear_log':
				$this->require_admin();
				$this->plugin->logger->clear();
				$tab = 'activity';
				break;
		}

		wp_safe_redirect( $this->url( $tab, array( 'updated' => 1 ) ) );
		exit;
	}

	/** Bail unless admin. */
	private function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Only administrators can change MCP settings.', 'open-mcp' ) );
		}
	}

	/**
	 * Merge into settings.
	 *
	 * @param array $values Values.
	 */
	private function save( array $values ) {
		update_option( Plugin::OPT_SETTINGS, array_merge( Plugin::settings(), $values ), false );
	}

	/** @return array */
	public static function capability_choices() {
		return array(
			'manage_options'    => __( 'Administrators', 'open-mcp' ),
			'edit_others_posts' => __( 'Editors and above', 'open-mcp' ),
			'publish_posts'     => __( 'Authors and above', 'open-mcp' ),
			'edit_posts'        => __( 'Contributors and above', 'open-mcp' ),
		);
	}

	/**
	 * Hidden fields + nonce for an action.
	 *
	 * @param string $do Action.
	 */
	private function form_fields( $do ) {
		echo '<input type="hidden" name="action" value="open_mcp">';
		echo '<input type="hidden" name="do" value="' . esc_attr( $do ) . '">';
		wp_nonce_field( 'open_mcp_' . $do );
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                           */
	/* ------------------------------------------------------------------ */

	/** Page. */
	public function render() {
		$tabs = array(
			'connect' => __( 'Connect', 'open-mcp' ),
			'keys'    => __( 'Keys & connections', 'open-mcp' ),
		);
		if ( current_user_can( 'manage_options' ) ) {
			$tabs['tools']    = __( 'Tools', 'open-mcp' );
			$tabs['activity'] = __( 'Activity', 'open-mcp' );
			$tabs['settings'] = __( 'Settings', 'open-mcp' );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'connect'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'connect';
		}
		?>
		<div class="wrap omcp">
			<h1><?php esc_html_e( 'MCP — connect AI assistants to this site', 'open-mcp' ); ?></h1>
			<?php if ( ! Plugin::setting( 'enabled' ) ) : ?>
				<div class="notice notice-warning"><p><?php esc_html_e( 'The MCP server is currently disabled (Settings tab).', 'open-mcp' ); ?></p></div>
			<?php endif; ?>
			<?php if ( Plugin::setting( 'read_only' ) ) : ?>
				<div class="notice notice-info"><p><?php esc_html_e( 'Read-only mode is on: AI clients can read but not change anything.', 'open-mcp' ); ?></p></div>
			<?php endif; ?>
			<nav class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a href="<?php echo esc_url( $this->url( $slug ) ); ?>" class="nav-tab <?php echo $slug === $tab ? 'nav-tab-active' : ''; ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
			<div class="omcp-body">
				<?php
				switch ( $tab ) {
					case 'keys':
						$this->tab_keys();
						break;
					case 'tools':
						$this->tab_tools();
						break;
					case 'activity':
						$this->tab_activity();
						break;
					case 'settings':
						$this->tab_settings();
						break;
					default:
						$this->tab_connect();
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Copyable code block.
	 *
	 * @param string $text Text.
	 */
	private function code( $text ) {
		echo '<div class="omcp-code"><pre>' . esc_html( $text ) . '</pre><button type="button" class="button omcp-copy">' . esc_html__( 'Copy', 'open-mcp' ) . '</button></div>';
	}

	/** Connect tab. */
	private function tab_connect() {
		$url   = Plugin::endpoint_url();
		$https = 0 === strpos( $url, 'https://' );
		?>
		<?php if ( ! $this->can_connect() ) : ?>
			<div class="notice notice-error inline"><p><?php esc_html_e( 'Your role is not allowed to connect AI clients on this site. Ask an administrator.', 'open-mcp' ); ?></p></div>
			<?php return; ?>
		<?php endif; ?>

		<h2><?php esc_html_e( 'Your MCP server URL', 'open-mcp' ); ?></h2>
		<?php $this->code( $url ); ?>
		<?php if ( ! $https ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'This site is not served over HTTPS. Claude.ai and most hosted AI apps require an https:// URL; local clients (Claude Code, Cursor, MCP Inspector) still work.', 'open-mcp' ); ?></p></div>
		<?php endif; ?>
		<?php if ( ! get_option( 'permalink_structure' ) ) : ?>
			<div class="notice notice-warning inline"><p><?php esc_html_e( 'Plain permalinks are enabled. The endpoint works, but OAuth discovery (/.well-known/…) needs pretty permalinks. For one-click Claude.ai connectors, enable any permalink structure under Settings → Permalinks, or use the secret-URL method below.', 'open-mcp' ); ?></p></div>
		<?php endif; ?>

		<div class="omcp-grid">
			<div class="omcp-card">
				<h3><?php esc_html_e( 'Claude (claude.ai, Desktop & mobile)', 'open-mcp' ); ?></h3>
				<?php if ( Plugin::setting( 'oauth_enabled' ) ) : ?>
					<ol>
						<li><?php esc_html_e( 'In Claude open Settings → Connectors → Add custom connector.', 'open-mcp' ); ?></li>
						<li><?php esc_html_e( 'Name it (e.g. your site name) and paste the URL above.', 'open-mcp' ); ?></li>
						<li><?php esc_html_e( 'Click Connect. You will be sent to this site to log in and approve access.', 'open-mcp' ); ?></li>
					</ol>
					<p class="description"><?php esc_html_e( 'The connector syncs to Claude Desktop and the mobile apps automatically. ChatGPT (developer mode connectors) and other apps with OAuth support work the same way.', 'open-mcp' ); ?></p>
				<?php else : ?>
					<p><?php esc_html_e( 'OAuth is disabled. Create an API key and use its secret URL as the connector URL.', 'open-mcp' ); ?></p>
				<?php endif; ?>
			</div>

			<div class="omcp-card">
				<h3><?php esc_html_e( 'Claude Code', 'open-mcp' ); ?></h3>
				<p><?php esc_html_e( 'Create an API key on the next tab, then run:', 'open-mcp' ); ?></p>
				<?php $this->code( 'claude mcp add --transport http wordpress ' . $url . ' --header "Authorization: Bearer YOUR_API_KEY"' ); ?>
				<p class="description"><?php esc_html_e( 'Or omit the header and run /mcp inside Claude Code to log in with OAuth.', 'open-mcp' ); ?></p>
			</div>

			<div class="omcp-card">
				<h3><?php esc_html_e( 'Cursor, VS Code, Windsurf, Cline…', 'open-mcp' ); ?></h3>
				<p><?php esc_html_e( 'Add to the client\'s MCP JSON config (e.g. .cursor/mcp.json, .vscode/mcp.json uses "servers"):', 'open-mcp' ); ?></p>
				<?php
				$this->code(
					wp_json_encode(
						array(
							'mcpServers' => array(
								'wordpress' => array(
									'type'    => 'http',
									'url'     => $url,
									'headers' => array( 'Authorization' => 'Bearer YOUR_API_KEY' ),
								),
							),
						),
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					)
				);
				?>
			</div>

			<div class="omcp-card">
				<h3><?php esc_html_e( 'Clients that only speak stdio', 'open-mcp' ); ?></h3>
				<p><?php esc_html_e( 'Bridge with mcp-remote (requires Node.js):', 'open-mcp' ); ?></p>
				<?php
				$this->code(
					wp_json_encode(
						array(
							'mcpServers' => array(
								'wordpress' => array(
									'command' => 'npx',
									'args'    => array( '-y', 'mcp-remote', $url, '--header', 'Authorization:Bearer YOUR_API_KEY' ),
								),
							),
						),
						JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
					)
				);
				?>
			</div>

			<div class="omcp-card">
				<h3><?php esc_html_e( 'Other ways to authenticate', 'open-mcp' ); ?></h3>
				<ul class="ul-disc">
					<?php if ( Plugin::setting( 'allow_url_key' ) ) : ?>
						<li><?php esc_html_e( 'Secret URL: for apps that cannot send headers, append your key to the URL:', 'open-mcp' ); ?> <code><?php echo esc_html( trailingslashit( $url ) . 'omcp_…' ); ?></code></li>
					<?php endif; ?>
					<?php if ( Plugin::setting( 'allow_app_passwords' ) ) : ?>
						<li><?php esc_html_e( 'WordPress Application Passwords (Users → Profile) via HTTP Basic auth.', 'open-mcp' ); ?></li>
					<?php endif; ?>
					<li><?php esc_html_e( 'Header "X-MCP-API-Key: omcp_…" as an alternative to Authorization.', 'open-mcp' ); ?></li>
				</ul>
				<p class="description"><?php esc_html_e( 'Every request runs as the WordPress user who owns the key or approved the connection, so normal role permissions always apply.', 'open-mcp' ); ?></p>
			</div>

			<div class="omcp-card">
				<h3><?php esc_html_e( 'Test it', 'open-mcp' ); ?></h3>
				<?php $this->code( 'npx @modelcontextprotocol/inspector' ); ?>
				<p class="description"><?php esc_html_e( 'Choose transport "Streamable HTTP", paste the URL and add an Authorization header.', 'open-mcp' ); ?></p>
			</div>
		</div>
		<?php
	}

	/** Keys tab. */
	private function tab_keys() {
		if ( ! $this->can_connect() ) {
			echo '<p>' . esc_html__( 'Your role is not allowed to connect AI clients.', 'open-mcp' ) . '</p>';
			return;
		}
		$user_id = get_current_user_id();
		$new     = get_transient( 'open_mcp_new_key_' . $user_id );
		if ( $new ) {
			delete_transient( 'open_mcp_new_key_' . $user_id );
			?>
			<div class="notice notice-success inline omcp-newkey">
				<p><strong><?php esc_html_e( 'New API key — copy it now, it will not be shown again:', 'open-mcp' ); ?></strong></p>
				<?php $this->code( $new ); ?>
				<?php if ( Plugin::setting( 'allow_url_key' ) ) : ?>
					<p><?php esc_html_e( 'Secret URL (for apps that cannot set headers — treat it like a password):', 'open-mcp' ); ?></p>
					<?php $this->code( trailingslashit( Plugin::endpoint_url() ) . $new ); ?>
				<?php endif; ?>
			</div>
			<?php
		}
		$is_admin = current_user_can( 'manage_options' );
		?>
		<h2><?php esc_html_e( 'API keys', 'open-mcp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="omcp-inline">
			<?php $this->form_fields( 'create_key' ); ?>
			<input type="text" name="label" class="regular-text" placeholder="<?php esc_attr_e( 'Label, e.g. "Claude Code on laptop"', 'open-mcp' ); ?>">
			<?php submit_button( __( 'Create API key', 'open-mcp' ), 'primary', 'submit', false ); ?>
		</form>
		<p class="description"><?php esc_html_e( 'Keys act as your WordPress account.', 'open-mcp' ); ?></p>

		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'Label', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Key', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'User', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Created', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Last used', 'open-mcp' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			$rows = 0;
			foreach ( $this->plugin->auth->keys() as $id => $key ) {
				if ( ! $is_admin && (int) $key['user_id'] !== $user_id ) {
					continue;
				}
				++$rows;
				$u = get_userdata( $key['user_id'] );
				?>
				<tr>
					<td><?php echo esc_html( $key['label'] ); ?></td>
					<td><code><?php echo esc_html( $key['hint'] ); ?></code></td>
					<td><?php echo esc_html( $u ? $u->user_login : '#' . $key['user_id'] ); ?></td>
					<td><?php echo esc_html( self::ago( $key['created'] ) ); ?></td>
					<td><?php echo esc_html( $key['last_used'] ? self::ago( $key['last_used'] ) : '—' ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php $this->form_fields( 'revoke_key' ); ?>
							<input type="hidden" name="key_id" value="<?php echo esc_attr( $id ); ?>">
							<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke this key?', 'open-mcp' ) ); ?>')"><?php esc_html_e( 'Revoke', 'open-mcp' ); ?></button>
						</form>
					</td>
				</tr>
				<?php
			}
			if ( ! $rows ) {
				echo '<tr><td colspan="6">' . esc_html__( 'No API keys yet.', 'open-mcp' ) . '</td></tr>';
			}
			?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'OAuth connections', 'open-mcp' ); ?></h2>
		<p class="description"><?php esc_html_e( 'Apps you approved through the browser (e.g. Claude custom connectors).', 'open-mcp' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'App', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'User', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Last token issued', 'open-mcp' ); ?></th>
				<th></th>
			</tr></thead>
			<tbody>
			<?php
			$rows = 0;
			foreach ( $this->plugin->oauth->connections() as $c ) {
				if ( ! $is_admin && $c['user_id'] !== $user_id ) {
					continue;
				}
				++$rows;
				$u = get_userdata( $c['user_id'] );
				?>
				<tr>
					<td><?php echo esc_html( $c['name'] ); ?></td>
					<td><?php echo esc_html( $u ? $u->user_login : '#' . $c['user_id'] ); ?></td>
					<td><?php echo esc_html( $c['last_used'] ? self::ago( $c['last_used'] ) : '—' ); ?></td>
					<td>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php $this->form_fields( 'revoke_client' ); ?>
							<input type="hidden" name="client_id" value="<?php echo esc_attr( $c['client_id'] ); ?>">
							<button class="button button-link-delete"><?php esc_html_e( 'Disconnect', 'open-mcp' ); ?></button>
						</form>
					</td>
				</tr>
				<?php
			}
			if ( ! $rows ) {
				echo '<tr><td colspan="4">' . esc_html__( 'No OAuth connections.', 'open-mcp' ) . '</td></tr>';
			}
			?>
			</tbody>
		</table>
		<?php
	}

	/** Tools tab. */
	private function tab_tools() {
		$disabled = (array) Plugin::setting( 'disabled_tools' );
		$groups   = array();
		foreach ( $this->plugin->tools->all() as $tool ) {
			$groups[ $tool['category'] ][] = $tool;
		}
		?>
		<p><?php esc_html_e( 'Choose which tools AI clients can see and use. Each tool still checks the connected user\'s WordPress permissions.', 'open-mcp' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->form_fields( 'save_tools' ); ?>
			<?php foreach ( $groups as $category => $tools ) : ?>
				<fieldset class="omcp-card omcp-tools">
					<legend>
						<strong><?php echo esc_html( $category ); ?></strong>
						<label class="omcp-toggle-all"><input type="checkbox" class="omcp-group-toggle"> <?php esc_html_e( 'all', 'open-mcp' ); ?></label>
					</legend>
					<?php foreach ( $tools as $tool ) : ?>
						<label class="omcp-tool">
							<input type="checkbox" name="tools[]" value="<?php echo esc_attr( $tool['name'] ); ?>" <?php checked( ! in_array( $tool['name'], $disabled, true ) ); ?>>
							<code><?php echo esc_html( $tool['name'] ); ?></code>
							<?php if ( $tool['read_only'] ) : ?>
								<span class="omcp-badge"><?php esc_html_e( 'read', 'open-mcp' ); ?></span>
							<?php elseif ( $tool['destructive'] ) : ?>
								<span class="omcp-badge omcp-badge-danger"><?php esc_html_e( 'destructive', 'open-mcp' ); ?></span>
							<?php else : ?>
								<span class="omcp-badge omcp-badge-write"><?php esc_html_e( 'write', 'open-mcp' ); ?></span>
							<?php endif; ?>
							<span class="description"><?php echo esc_html( wp_trim_words( $tool['description'], 22 ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<?php endforeach; ?>
			<?php submit_button( __( 'Save tools', 'open-mcp' ) ); ?>
		</form>
		<?php
	}

	/** Activity tab. */
	private function tab_activity() {
		$entries = $this->plugin->logger->entries();
		?>
		<p><?php esc_html_e( 'The last 200 tool calls made by AI clients.', 'open-mcp' ); ?></p>
		<table class="widefat striped">
			<thead><tr>
				<th><?php esc_html_e( 'When', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Tool', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'User', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Via', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Result', 'open-mcp' ); ?></th>
				<th><?php esc_html_e( 'Time', 'open-mcp' ); ?></th>
			</tr></thead>
			<tbody>
			<?php if ( ! $entries ) : ?>
				<tr><td colspan="6"><?php esc_html_e( 'No activity yet.', 'open-mcp' ); ?></td></tr>
			<?php endif; ?>
			<?php foreach ( $entries as $e ) : ?>
				<?php $u = get_userdata( $e['user'] ); ?>
				<tr>
					<td><?php echo esc_html( wp_date( 'Y-m-d H:i:s', $e['time'] ) ); ?></td>
					<td><code><?php echo esc_html( $e['tool'] ); ?></code></td>
					<td><?php echo esc_html( $u ? $u->user_login : '—' ); ?></td>
					<td><?php echo esc_html( isset( $e['via'] ) ? $e['via'] : '' ); ?></td>
					<td><?php echo $e['ok'] ? '<span class="omcp-ok">✓</span>' : '<span class="omcp-err">✗ ' . esc_html( $e['error'] ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
					<td><?php echo esc_html( $e['ms'] . ' ms' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:12px">
			<?php $this->form_fields( 'clear_log' ); ?>
			<?php submit_button( __( 'Clear log', 'open-mcp' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/** Settings tab. */
	private function tab_settings() {
		$s = Plugin::settings();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->form_fields( 'save_settings' ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th><?php esc_html_e( 'MCP server', 'open-mcp' ); ?></th>
					<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Enabled', 'open-mcp' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Read-only mode', 'open-mcp' ); ?></th>
					<td><label><input type="checkbox" name="read_only" value="1" <?php checked( $s['read_only'] ); ?>> <?php esc_html_e( 'Only expose tools that read data (REST passthrough limited to GET).', 'open-mcp' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Who can connect', 'open-mcp' ); ?></th>
					<td>
						<select name="min_capability">
							<?php foreach ( self::capability_choices() as $cap => $label ) : ?>
								<option value="<?php echo esc_attr( $cap ); ?>" <?php selected( $s['min_capability'], $cap ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Users below this level cannot create keys or approve connections. Each user\'s own permissions still limit what tools can do.', 'open-mcp' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Authentication', 'open-mcp' ); ?></th>
					<td>
						<label><input type="checkbox" name="oauth_enabled" value="1" <?php checked( $s['oauth_enabled'] ); ?>> <?php esc_html_e( 'OAuth 2.1 (one-click connectors in Claude, ChatGPT…)', 'open-mcp' ); ?></label><br>
						<label><input type="checkbox" name="allow_url_key" value="1" <?php checked( $s['allow_url_key'] ); ?>> <?php esc_html_e( 'Allow API keys in the URL (secret URL)', 'open-mcp' ); ?></label><br>
						<label><input type="checkbox" name="allow_app_passwords" value="1" <?php checked( $s['allow_app_passwords'] ); ?>> <?php esc_html_e( 'Allow WordPress Application Passwords', 'open-mcp' ); ?></label>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Abilities API', 'open-mcp' ); ?></th>
					<td><label><input type="checkbox" name="expose_abilities" value="1" <?php checked( $s['expose_abilities'] ); ?>> <?php esc_html_e( 'Expose abilities registered by WordPress core and plugins (WordPress 6.9+) as tools', 'open-mcp' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Activity log', 'open-mcp' ); ?></th>
					<td><label><input type="checkbox" name="log_enabled" value="1" <?php checked( $s['log_enabled'] ); ?>> <?php esc_html_e( 'Record tool calls', 'open-mcp' ); ?></label></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Allowed browser origins', 'open-mcp' ); ?></th>
					<td>
						<textarea name="allowed_origins" rows="3" class="large-text code" placeholder="http://localhost:6274"><?php echo esc_textarea( $s['allowed_origins'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Only needed for MCP clients running inside a web browser. Server-side clients send no Origin and are always allowed. One per line; * allows any.', 'open-mcp' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button(); ?>
		</form>

		<h2><?php esc_html_e( 'Danger zone', 'open-mcp' ); ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<?php $this->form_fields( 'revoke_all' ); ?>
			<button class="button button-link-delete" onclick="return confirm('<?php echo esc_js( __( 'Revoke every API key and OAuth connection for all users?', 'open-mcp' ) ); ?>')"><?php esc_html_e( 'Revoke all keys and connections', 'open-mcp' ); ?></button>
		</form>
		<?php
	}

	/**
	 * Relative time.
	 *
	 * @param int $ts Timestamp.
	 * @return string
	 */
	private static function ago( $ts ) {
		/* translators: %s: human time diff */
		return sprintf( __( '%s ago', 'open-mcp' ), human_time_diff( (int) $ts ) );
	}
}
