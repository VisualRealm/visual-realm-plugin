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
- **Consent screen**: Only users with the chosen capability can connect (Administrators by default). Apps may only return to exact addresses: `https://claude.ai/api/mcp/auth_callback`, any extra exact https addresses added in settings, and Claude Code's `http://localhost:<port>/callback` (can be switched off).
- **Change log with undo**: Every write is snapshotted. Undo from the tool or from the Visual Realm menu in WordPress admin. Snapshots hold only the fields a change touched, so undoing an old edit never reverts a later publish.
- **Uninstall**: Deleting the plugin drops all tokens and tables, which ends every connection.

## Tools

| Area | Tools |
|---|---|
| Site | `mcp_ping`, `wp_list_plugins`, `wp_get_post_types` |
| Posts | `wp_get_posts`, `wp_get_post` (sliced content), `wp_create_post` (draft default), `wp_update_post` (never touches status/dates unless passed), `wp_alter_post` (exact match + expected count), `wp_append_content`, `wp_delete_post` (trash only) |
| Meta / SEO | `wp_get_post_meta`, `wp_update_post_meta` (structured values), `wp_delete_post_meta`, `wp_get_seo`, `wp_update_seo` (Rank Math) |
| Verify | `wp_get_rendered` (origin fetch, cache bypassed, string counts) |
| Media | `wp_get_media`, `wp_upload_media` (URL or base64, SEO filename/alt/title/caption; SVGs sanitized; images, media, fonts, PDFs and office files only), `wp_update_media`, `wp_set_featured_image` |
| Taxonomy / menus | `wp_get_terms`, `wp_create_term`, `wp_set_post_terms`, `wp_get_menus`, `wp_add_menu_item` |
| Revisions | `wp_get_revisions`, `wp_restore_revision` |
| Options | `wp_get_option` (dot-path `key`, credentials hidden), `wp_update_option` (allowlist: site title/tagline, reading and media settings, widgets, theme mods, `fusion_*`, `avada_*`, `rank-math-options-*`) |
| Cache | `vr_purge_cache`: WP Rocket page cache and Used CSS, Avada compiled CSS, Kinsta, SiteGround, Cloudflare. Layout sections, menus and global elements purge the whole site. |
| Menus | `wp_update_menu_item`, `wp_delete_menu_item` (undoable) |
| Files | `wp_get_file` (uploads only; text, images, PDFs, fonts, media) |
| Log | `mcp_list_changes`, `mcp_undo_change` |

## Install on a client site

1. Upload `visual-realm.zip` under Plugins → Add New → Upload, then activate.
2. Open **Visual Realm** in the WordPress admin sidebar and copy the Connection URL.
3. claude.ai → Organization settings → Connectors → Add → Custom. Paste the URL and leave OAuth fields blank.
4. Connect, sign in to WordPress, then click **Allow**.
5. Optional: add a Cloudflare zone ID and an API token scoped to Zone → Cache Purge.

## Requirements

- WordPress 6.0+ and PHP 7.4+.
- Works on hosts that block the root `/.well-known/` folder (SiteGround). Discovery is also served at the issuer path and through `?rest_route=`.
- Bot protection (Cloudflare WAF, Kinsta) must let `/vr-oauth/*`, `/.well-known/oauth-*` and `/wp-json/visual-realm/*` through.

## Branding

- `assets/menu-icon.svg` is the admin sidebar icon, a single-color SVG that WordPress tints to the admin color scheme.
- `assets/logo.svg` is the full-color logo shown in the page header.

## Updates

Sites check `github.com/VisualRealm/visual-realm-plugin` releases every six hours and show the update on the Plugins screen. A release is only offered and installed when:

- it has two assets, `visual-realm.zip` and `visual-realm.zip.sig`;
- the signature verifies against the Ed25519 public key built into the plugin;
- the zip contains the same version as the release tag, and that version is newer than the installed one.

To publish a release:

1. Bump `Version:` and `VR_PLUGIN_VERSION` in `visual-realm.php`.
2. Push to `main`. The Release workflow sees a version with no release yet, builds the zip, signs it with the `VR_SIGNING_KEY` repository secret, checks the signature against the key built into the plugin, and publishes release `vX.Y.Z` with both files. It can also be started by hand under Actions → Release → Run workflow.

To build by hand instead: `VR_SIGNING_KEY_FILE=/path/to/vr-release-signing.secret bin/build-release.sh`, then upload `dist/visual-realm.zip` and `dist/visual-realm.zip.sig` to a release tagged `vX.Y.Z`.

Keep the signing key out of the repo. Store it in a password manager or as a GitHub Actions secret. If the repository is private, enter a read-only, single-repository GitHub token under **Update access token** on each site.
