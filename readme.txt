=== Open MCP ===
Contributors: openmcp
Tags: mcp, ai, claude, chatgpt, model context protocol
Requires at least: 6.4
Tested up to: 6.9
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free, open-source MCP server for WordPress. Connect Claude, ChatGPT, Cursor or any MCP client and manage your whole site. No paid tier, no limits.

== Description ==

Open MCP turns your WordPress site into a Model Context Protocol (MCP) server. Paste one URL into Claude's "Add custom connector" dialog, approve access, and your AI assistant can write and edit posts, manage media, moderate comments, build menus, restyle your block theme, install plugins and more — always within your WordPress role's permissions.

Everything is free. There is no "Pro" version.

= What it can do (71 built-in tools) =

* **Content** – list, read, create, update (including small find/replace edits), duplicate, trash, restore and delete posts, pages and any custom post type; custom fields (including SEO plugin fields); revisions.
* **Taxonomies** – categories, tags and custom taxonomies; assign terms to posts.
* **Media** – upload from URL or base64, edit alt text and captions, set featured images, delete.
* **Comments** – list, reply, approve, unapprove, spam, trash, delete.
* **Users** – list, create, update, change roles, delete with content reassignment.
* **Menus** – classic menus, items and theme locations.
* **Appearance** – switch, install and delete themes; edit block templates and template parts; global styles (colors, typography); Additional CSS.
* **Plugins** – list, search the directory, install, activate, deactivate, update, delete.
* **Site** – site overview, core settings, any option (with critical options protected), search, cache flush.
* **Everything else** – `wp_rest_request` calls any REST route on the site as the connected user (WooCommerce, forms, SEO plugins…), and `wp_list_rest_routes` lets the AI discover them.
* **Abilities API** – on WordPress 6.9+, every ability registered by core or plugins is exposed as a tool automatically.

= Connect any client =

* **Claude (claude.ai, Desktop, mobile)** – Settings → Connectors → Add custom connector → paste the URL → approve. Uses OAuth 2.1 with PKCE and dynamic client registration.
* **ChatGPT** and other apps with remote MCP + OAuth support – same URL.
* **Claude Code** – `claude mcp add --transport http wordpress https://example.com/wp-json/open-mcp/v1/mcp --header "Authorization: Bearer omcp_…"`
* **Cursor, VS Code, Windsurf, Cline** – Streamable HTTP with an `Authorization: Bearer` header.
* **stdio-only clients** – via `npx mcp-remote`.

= Security =

* Every request runs as a real WordPress user; normal capabilities are enforced in every tool.
* Choose which roles may connect (default: administrators).
* Turn individual tools off, or switch the whole server to read-only.
* API keys are stored hashed and shown once; OAuth tokens are hashed, short-lived and rotated.
* Activity log of the last 200 tool calls.
* Origin validation against DNS-rebinding for browser-based clients.
* The plugin cannot deactivate or delete itself, read its own keys, or change site URL, salts, roles or the active-plugins list through raw option writes.

= For developers =

Add your own tools:

`add_action( 'open_mcp_register_tools', function ( $registry ) {
    $registry->register( array(
        'name'         => 'my_tool',
        'description'  => 'What it does, for the AI.',
        'input_schema' => \OpenMCP\Schema::obj( array( 'id' => \OpenMCP\Schema::int( 'Post ID' ) ), array( 'id' ) ),
        'capability'   => 'edit_posts',
        'read_only'    => true,
        'callback'     => function ( $args ) { return array( 'ok' => true ); },
    ) );
} );`

Filters and actions: `open_mcp_instructions`, `open_mcp_pre_call_tool`, `open_mcp_tool_called`, `open_mcp_flush_cache`.

== Installation ==

1. Upload the `open-mcp` folder to `/wp-content/plugins/` or install the zip via Plugins → Add New → Upload.
2. Activate the plugin.
3. Open **MCP** in the admin menu and follow the instructions for your AI app.

Requirements for one-click OAuth connectors: HTTPS and pretty permalinks (any structure other than "Plain"). Without them, use an API key.

== Frequently Asked Questions ==

= Is it really free? =
Yes. GPL, no upsells, no usage limits, no external service. Requests go directly from your AI app to your site.

= Does it send my data anywhere? =
No. The plugin makes no outbound calls except when you ask it to install plugins/themes from WordPress.org or to download media from a URL.

= My host strips the Authorization header =
Use the `X-MCP-API-Key` header, or the secret URL (`…/mcp/omcp_yourkey`), or add `SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=$1` to .htaccess.

= Claude says it can't connect =
Check that the site is on HTTPS, permalinks are not "Plain", and that `https://yoursite/.well-known/oauth-authorization-server` returns JSON. Some security plugins and firewalls block `/.well-known/` or REST API requests; allow `/wp-json/open-mcp/`.

= WordPress in a subdirectory =
OAuth discovery needs `/.well-known/` at the domain root. If WordPress lives in a subdirectory, use an API key instead.

== Changelog ==

= 1.0.0 =
* First release: Streamable HTTP MCP server (protocol 2024-11-05 → 2025-11-25), OAuth 2.1, API keys, Application Passwords, 71 tools, Abilities API bridge, admin UI, activity log.
