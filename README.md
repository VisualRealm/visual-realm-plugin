# Visual Realm (WordPress plugin)

Connector that lets Visual Realm build and manage a client site through Claude. It replaces AI Engine's MCP module.

## What it does

- **MCP server**: `/wp-json/visual-realm/v1/mcp`, streamable HTTP, stateless JSON responses.
- **OAuth 2.1 sign-in**:
  - Authorization code with PKCE (S256 only).
  - Refresh-token rotation with reuse detection.
  - Dynamic client registration and client ID metadata documents.
  - Token revocation.
  - Discovery at `/.well-known/oauth-protected-resource` and `/.well-known/oauth-authorization-server`.
- **Consent screen**: Only users with the chosen capability can connect (Administrators by default). Apps may only return to allowed hosts (claude.ai, claude.com, localhost by default).
- **Change log with undo**: Every write is snapshotted. Undo from the tool or from Settings → Visual Realm. Snapshots hold only the fields a change touched, so undoing an old edit never reverts a later publish.
- **Uninstall**: Deleting the plugin drops all tokens and tables, which ends every connection.

## Tools

| Area | Tools |
|---|---|
| Site | `mcp_ping`, `wp_list_plugins`, `wp_get_post_types` |
| Posts | `wp_get_posts`, `wp_get_post` (sliced content), `wp_create_post` (draft default), `wp_update_post` (never touches status/dates unless passed), `wp_alter_post` (exact match + expected count), `wp_append_content`, `wp_delete_post` (trash only) |
| Meta / SEO | `wp_get_post_meta`, `wp_update_post_meta` (structured values), `wp_delete_post_meta`, `wp_get_seo`, `wp_update_seo` (Rank Math) |
| Verify | `wp_get_rendered` (origin fetch, cache bypassed, string counts) |
| Media | `wp_get_media`, `wp_upload_media` (URL or base64, SEO filename/alt/title/caption), `wp_update_media`, `wp_set_featured_image` |
| Taxonomy / menus | `wp_get_terms`, `wp_create_term`, `wp_set_post_terms`, `wp_get_menus`, `wp_add_menu_item` |
| Revisions | `wp_get_revisions`, `wp_restore_revision` |
| Options | `wp_get_option`, `wp_update_option` (core identity/security options blocked) |
| Cache | `vr_purge_cache` (WP Rocket, Kinsta, SiteGround, Cloudflare API) |
| Log | `mcp_list_changes`, `mcp_undo_change` |

## Install on a client site

1. Upload `visual-realm.zip` under Plugins → Add New → Upload, then activate.
2. Settings → Visual Realm: copy the Connection URL.
3. claude.ai → Organization settings → Connectors → Add → Custom. Paste the URL and leave OAuth fields blank.
4. Connect, sign in to WordPress, then click **Allow**.
5. Optional: add a Cloudflare zone ID and an API token scoped to Zone → Cache Purge.

## Requirements

- WordPress 6.0+ and PHP 7.4+.
- Root installs only. A subdirectory install can't serve `/.well-known/` at the domain root.
- Bot protection (Cloudflare WAF, Kinsta) must let `/vr-oauth/*`, `/.well-known/oauth-*` and `/wp-json/visual-realm/*` through.
