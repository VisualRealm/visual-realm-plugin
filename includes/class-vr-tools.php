<?php
/**
 * Tool registry and implementations.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

/**
 * Error raised inside a tool; reported to the client as an isError result.
 */
class VR_Tool_Error extends Exception {}

class VR_Tools {

	/**
	 * Options the connector may change: exact names.
	 */
	const WRITABLE_OPTIONS = array(
		'blogname', 'blogdescription', 'site_icon', 'show_on_front', 'page_on_front', 'page_for_posts',
		'posts_per_page', 'posts_per_rss', 'rss_use_excerpt', 'date_format', 'time_format', 'start_of_week',
		'timezone_string', 'gmt_offset', 'default_category', 'sidebars_widgets', 'wp_page_for_privacy_policy',
		'thumbnail_size_w', 'thumbnail_size_h', 'medium_size_w', 'medium_size_h', 'large_size_w', 'large_size_h',
	);

	/**
	 * Options the connector may change: name prefixes (theme, widget, Avada and Rank Math settings).
	 */
	const WRITABLE_PREFIXES = array( 'theme_mods_', 'widget_', 'fusion_', 'avada_', 'rank-math-options-' );

	/**
	 * Option names (or parts of names) that hold credentials and are never returned.
	 */
	const SECRET_PATTERN = '/(pass(word|wd)?|secret|token|api[_-]?key|private|licen[cs]e|salt|auth_key|credential|webhook)/i';

	/**
	 * Cached registry.
	 *
	 * @var array|null
	 */
	protected static $registry = null;

	/* ---------------------------------------------------------------------
	 * Registry
	 * ------------------------------------------------------------------- */

	/**
	 * Shorthand for a schema property.
	 *
	 * @param string $type Type.
	 * @param string $desc Description.
	 * @param array  $extra Extra.
	 * @return array
	 */
	protected static function p( $type, $desc, array $extra = array() ) {
		$out = array( 'description' => $desc );
		if ( '' !== $type ) {
			$out['type'] = $type;
		}
		return $out + $extra;
	}

	/**
	 * All tools.
	 *
	 * @return array
	 */
	protected static function registry() {
		if ( null !== self::$registry ) {
			return self::$registry;
		}
		$id   = self::p( 'integer', 'Post or page ID.' );
		$any  = self::p( '', 'Any JSON value (string, number, boolean, array or object).' );
		$read = array( 'readOnlyHint' => true );
		$edit = array( 'readOnlyHint' => false, 'destructiveHint' => false );
		$risk = array( 'readOnlyHint' => false, 'destructiveHint' => true );

		self::$registry = array(
			'mcp_ping'             => array( 'Site, WordPress, theme and connection details.', array(), array(), $read ),
			'wp_get_posts'         => array(
				'List posts, pages or any post type. Returns IDs, titles, status, slugs, links and parent.',
				array(
					'post_type' => self::p( 'string', 'Post type (post, page, any registered type, or "any"). Default "any".' ),
					'status'    => self::p( 'string', 'publish, draft, pending, private, future, trash or "any" (default, excludes trash).' ),
					'search'    => self::p( 'string', 'Search text.' ),
					'parent'    => self::p( 'integer', 'Only children of this ID.' ),
					'per_page'  => self::p( 'integer', '1-100, default 20.' ),
					'page'      => self::p( 'integer', 'Page number, default 1.' ),
					'orderby'   => self::p( 'string', 'date, modified, title, menu_order or ID. Default modified.' ),
					'order'     => self::p( 'string', 'ASC or DESC. Default DESC.' ),
				),
				array(),
				$read,
			),
			'wp_get_post'          => array(
				'Get one post with raw content. Long content is returned in slices: use offset/limit and content_length.',
				array(
					'id'              => $id,
					'include_content' => self::p( 'boolean', 'Default true.' ),
					'offset'          => self::p( 'integer', 'Content character offset. Default 0.' ),
					'limit'           => self::p( 'integer', 'Max content characters, default 60000.' ),
				),
				array( 'id' ),
				$read,
			),
			'wp_create_post'       => array(
				'Create a post or page. Status defaults to draft; only "publish" if explicitly asked.',
				array(
					'post_type' => self::p( 'string', 'Default "post".' ),
					'title'     => self::p( 'string', 'Title.' ),
					'content'   => self::p( 'string', 'Raw content (shortcodes/HTML).' ),
					'excerpt'   => self::p( 'string', 'Excerpt.' ),
					'status'    => self::p( 'string', 'draft (default), publish, pending, private.' ),
					'slug'      => self::p( 'string', 'Slug.' ),
					'parent'    => self::p( 'integer', 'Parent ID.' ),
					'menu_order' => self::p( 'integer', 'Menu order.' ),
					'template'  => self::p( 'string', 'Page template file.' ),
					'meta'      => self::p( 'object', 'Post meta to set, key → value.' ),
				),
				array( 'title' ),
				$edit,
			),
			'wp_update_post'       => array(
				'Update fields on a post. Only the fields you pass change; status and dates are never touched unless passed.',
				array(
					'id'         => $id,
					'title'      => self::p( 'string', 'Title.' ),
					'content'    => self::p( 'string', 'Replaces the full content.' ),
					'excerpt'    => self::p( 'string', 'Excerpt.' ),
					'status'     => self::p( 'string', 'draft, publish, pending, private.' ),
					'slug'       => self::p( 'string', 'Slug.' ),
					'parent'     => self::p( 'integer', 'Parent ID.' ),
					'menu_order' => self::p( 'integer', 'Menu order.' ),
					'template'   => self::p( 'string', 'Page template file.' ),
				),
				array( 'id' ),
				$edit,
			),
			'wp_alter_post'        => array(
				'Replace exact text in a post field (no regex). Fails without writing unless the number of matches equals "expected".',
				array(
					'id'       => $id,
					'search'   => self::p( 'string', 'Exact text to find.' ),
					'replace'  => self::p( 'string', 'Replacement text (may be empty).' ),
					'field'    => self::p( 'string', 'content (default), title or excerpt.' ),
					'expected' => self::p( 'integer', 'Required match count. Default 1. Use 0 to replace every match.' ),
				),
				array( 'id', 'search', 'replace' ),
				$edit,
			),
			'wp_append_content'    => array(
				'Append text to the end of a post\'s content. Use to write large page bodies in parts.',
				array(
					'id'      => $id,
					'content' => self::p( 'string', 'Text to append.' ),
				),
				array( 'id', 'content' ),
				$edit,
			),
			'wp_delete_post'       => array( 'Move a post to the trash (never permanent).', array( 'id' => $id ), array( 'id' ), $risk ),
			'wp_get_post_meta'     => array(
				'Get post meta. With "key", returns the full value; without, lists all keys (long values shortened).',
				array(
					'id'  => $id,
					'key' => self::p( 'string', 'Meta key.' ),
				),
				array( 'id' ),
				$read,
			),
			'wp_update_post_meta'  => array(
				'Set one meta value. Arrays and objects are stored as structured data (e.g. Rank Math schema).',
				array(
					'id'    => $id,
					'key'   => self::p( 'string', 'Meta key.' ),
					'value' => $any,
				),
				array( 'id', 'key', 'value' ),
				$edit,
			),
			'wp_delete_post_meta'  => array(
				'Delete one meta key from a post.',
				array(
					'id'  => $id,
					'key' => self::p( 'string', 'Meta key.' ),
				),
				array( 'id', 'key' ),
				$risk,
			),
			'wp_get_seo'           => array( 'Rank Math SEO title, description, focus keyword, robots, canonical and custom schema keys for a post.', array( 'id' => $id ), array( 'id' ), $read ),
			'wp_update_seo'        => array(
				'Update Rank Math SEO fields. Only the fields you pass change.',
				array(
					'id'            => $id,
					'title'         => self::p( 'string', 'SEO title.' ),
					'description'   => self::p( 'string', 'Meta description.' ),
					'focus_keyword' => self::p( 'string', 'Focus keyword(s), comma separated.' ),
					'canonical'     => self::p( 'string', 'Canonical URL.' ),
					'robots'        => self::p( 'array', 'Robots directives, e.g. ["index","follow"].', array( 'items' => array( 'type' => 'string' ) ) ),
				),
				array( 'id' ),
				$edit,
			),
			'wp_get_rendered'      => array(
				'Fetch the live front-end page from the origin (cache bypassed) and count exact strings in the HTML.',
				array(
					'id'     => $id,
					'url'    => self::p( 'string', 'URL on this site, instead of id.' ),
					'count'  => self::p( 'array', 'Exact strings to count in the HTML.', array( 'items' => array( 'type' => 'string' ) ) ),
					'find'   => self::p( 'string', 'Return the HTML around the first occurrence of this text.' ),
					'offset' => self::p( 'integer', 'HTML character offset for the returned slice.' ),
					'limit'  => self::p( 'integer', 'HTML characters to return. Default 0 (counts only).' ),
				),
				array(),
				$read,
			),
			'wp_get_media'         => array(
				'List media or get one item: URL, filename, alt, title, caption, size.',
				array(
					'id'       => self::p( 'integer', 'Attachment ID.' ),
					'search'   => self::p( 'string', 'Search text.' ),
					'per_page' => self::p( 'integer', '1-100, default 20.' ),
					'page'     => self::p( 'integer', 'Page number.' ),
				),
				array(),
				$read,
			),
			'wp_upload_media'      => array(
				'Upload an image or file from a URL or base64 data. Set an SEO filename, title, alt and caption.',
				array(
					'url'         => self::p( 'string', 'Source URL.' ),
					'data'        => self::p( 'string', 'Base64 file contents (instead of url).' ),
					'filename'    => self::p( 'string', 'File name to save as, e.g. clinical-trial-tampa.jpg.' ),
					'title'       => self::p( 'string', 'Title.' ),
					'alt'         => self::p( 'string', 'Alt text.' ),
					'caption'     => self::p( 'string', 'Caption.' ),
					'description' => self::p( 'string', 'Description.' ),
					'post_id'     => self::p( 'integer', 'Attach to this post.' ),
				),
				array(),
				$edit,
			),
			'wp_update_media'      => array(
				'Update an attachment\'s title, alt, caption or description.',
				array(
					'id'          => self::p( 'integer', 'Attachment ID.' ),
					'title'       => self::p( 'string', 'Title.' ),
					'alt'         => self::p( 'string', 'Alt text.' ),
					'caption'     => self::p( 'string', 'Caption.' ),
					'description' => self::p( 'string', 'Description.' ),
				),
				array( 'id' ),
				$edit,
			),
			'wp_set_featured_image' => array(
				'Set or remove (media_id 0) a post\'s featured image.',
				array(
					'id'       => $id,
					'media_id' => self::p( 'integer', 'Attachment ID, or 0 to remove.' ),
				),
				array( 'id', 'media_id' ),
				$edit,
			),
			'wp_get_terms'         => array(
				'List terms in a taxonomy.',
				array(
					'taxonomy' => self::p( 'string', 'Default "category".' ),
					'search'   => self::p( 'string', 'Search text.' ),
					'per_page' => self::p( 'integer', '1-200, default 100.' ),
				),
				array(),
				$read,
			),
			'wp_create_term'       => array(
				'Create a term.',
				array(
					'taxonomy'    => self::p( 'string', 'Taxonomy.' ),
					'name'        => self::p( 'string', 'Name.' ),
					'slug'        => self::p( 'string', 'Slug.' ),
					'parent'      => self::p( 'integer', 'Parent term ID.' ),
					'description' => self::p( 'string', 'Description.' ),
				),
				array( 'taxonomy', 'name' ),
				$edit,
			),
			'wp_set_post_terms'    => array(
				'Set a post\'s terms for one taxonomy (IDs, names or slugs).',
				array(
					'id'       => $id,
					'taxonomy' => self::p( 'string', 'Taxonomy.' ),
					'terms'    => self::p( 'array', 'Term IDs, names or slugs.', array( 'items' => array( 'type' => array( 'integer', 'string' ) ) ) ),
					'append'   => self::p( 'boolean', 'Add to existing terms instead of replacing. Default false.' ),
				),
				array( 'id', 'taxonomy', 'terms' ),
				$edit,
			),
			'wp_get_post_types'    => array( 'List registered post types.', array(), array(), $read ),
			'wp_get_menus'         => array( 'List navigation menus, their locations and items.', array(), array(), $read ),
			'wp_add_menu_item'     => array(
				'Add an item to a navigation menu: a page/post (object_id) or a custom URL.',
				array(
					'menu_id'   => self::p( 'integer', 'Menu (term) ID.' ),
					'title'     => self::p( 'string', 'Label. Defaults to the linked page title.' ),
					'object_id' => self::p( 'integer', 'Page or post ID to link.' ),
					'url'       => self::p( 'string', 'Custom URL (instead of object_id).' ),
					'parent'    => self::p( 'integer', 'Parent menu item ID.' ),
					'position'  => self::p( 'integer', 'Menu order position.' ),
				),
				array( 'menu_id' ),
				$edit,
			),
			'wp_update_menu_item'  => array(
				'Change a menu item: label, custom URL, parent or position.',
				array(
					'item_id'  => self::p( 'integer', 'Menu item ID (from wp_get_menus).' ),
					'title'    => self::p( 'string', 'New label.' ),
					'url'      => self::p( 'string', 'New URL (custom links only).' ),
					'parent'   => self::p( 'integer', 'Parent menu item ID (0 for top level).' ),
					'position' => self::p( 'integer', 'Menu order position.' ),
				),
				array( 'item_id' ),
				$edit,
			),
			'wp_delete_menu_item'  => array( 'Remove an item from a menu. Undo puts it back.', array( 'item_id' => self::p( 'integer', 'Menu item ID.' ) ), array( 'item_id' ), $risk ),
			'wp_get_file'          => array(
				'Read an uploaded media file. Text files (SVG, CSS, JSON, TXT, CSV) return their content; images, PDFs, fonts and media return base64 when "base64" is true (2 MB max).',
				array(
					'id'     => self::p( 'integer', 'Attachment ID.' ),
					'url'    => self::p( 'string', 'Or a file URL under this site\'s uploads folder.' ),
					'base64' => self::p( 'boolean', 'Return binary files as base64. Default false.' ),
				),
				array(),
				$read,
			),
			'wp_get_revisions'     => array( 'List revisions of a post.', array( 'id' => $id ), array( 'id' ), $read ),
			'wp_restore_revision'  => array( 'Restore a post to a revision.', array( 'revision_id' => self::p( 'integer', 'Revision ID.' ) ), array( 'revision_id' ), $edit ),
			'wp_get_option'        => array(
				'Read a site option. For large options pass "key" (dot path, e.g. "color_palette.color4"); without it, big values return their top-level keys only.',
				array(
					'name' => self::p( 'string', 'Option name.' ),
					'key'  => self::p( 'string', 'Dot path inside an array option.' ),
				),
				array( 'name' ),
				$read,
			),
			'wp_update_option'     => array(
				'Set a site option. Core identity and security options are blocked.',
				array(
					'name'  => self::p( 'string', 'Option name.' ),
					'value' => $any,
				),
				array( 'name', 'value' ),
				$risk,
			),
			'wp_list_plugins'      => array( 'List installed plugins with version and active state.', array(), array(), $read ),
			'vr_purge_cache'       => array(
				'Clear page caches (WP Rocket, Kinsta, SiteGround, Cloudflare when configured) for one page or the whole site.',
				array(
					'id'         => self::p( 'integer', 'Post ID to purge. Omit to purge the whole site.' ),
					'everything' => self::p( 'boolean', 'Purge the whole site. Default false when id is given.' ),
				),
				array(),
				$edit,
			),
			'mcp_list_changes'     => array(
				'List recent changes made through this connector, newest first.',
				array(
					'limit'     => self::p( 'integer', '1-100, default 20.' ),
					'object_id' => self::p( 'string', 'Only changes to this object ID.' ),
				),
				array(),
				$read,
			),
			'mcp_undo_change'      => array( 'Undo one logged change by its ID.', array( 'id' => self::p( 'integer', 'Change ID.' ) ), array( 'id' ), $edit ),
		);
		return self::$registry;
	}

	/**
	 * Whether a tool exists.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function exists( $name ) {
		return isset( self::registry()[ $name ] );
	}

	/**
	 * Every tool with its first-sentence description, for the settings screen.
	 *
	 * @return array name => description
	 */
	public static function catalog() {
		$out = array();
		foreach ( self::registry() as $name => $def ) {
			$out[ $name ] = preg_replace( '/(?<=\.)\s.*$/s', '', (string) $def[0] );
		}
		return $out;
	}

	/**
	 * MCP tool definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$out = array();
		foreach ( self::registry() as $name => $def ) {
			if ( ! VR_Access::tool_enabled( $name ) ) {
				continue;
			}
			list( $desc, $props, $required, $hints ) = $def;
			$schema = array(
				'type'       => 'object',
				'properties' => empty( $props ) ? new stdClass() : $props,
			);
			if ( ! empty( $required ) ) {
				$schema['required'] = $required;
			}
			$out[] = array(
				'name'        => $name,
				'description' => $desc,
				'inputSchema' => $schema,
				'annotations' => $hints,
			);
		}
		return $out;
	}

	/**
	 * Run a tool and wrap the result for MCP.
	 *
	 * @param string $name Name.
	 * @param array  $args Arguments.
	 * @return array
	 */
	public static function call( $name, array $args ) {
		$method = 'tool_' . $name;
		try {
			if ( ! VR_Access::tool_enabled( $name ) ) {
				throw new VR_Tool_Error( 'The ' . $name . ' tool is switched off on this site.' );
			}
			$required = self::registry()[ $name ][2];
			foreach ( $required as $r ) {
				if ( ! array_key_exists( $r, $args ) ) {
					throw new VR_Tool_Error( 'Missing required argument: ' . $r );
				}
			}
			$data = self::$method( $args );
			if ( is_wp_error( $data ) ) {
				throw new VR_Tool_Error( $data->get_error_message() );
			}
			return array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT ),
					),
				),
				'isError' => false,
			);
		} catch ( VR_Tool_Error $e ) {
			return array(
				'content' => array( array( 'type' => 'text', 'text' => $e->getMessage() ) ),
				'isError' => true,
			);
		} catch ( Throwable $e ) {
			return array(
				'content' => array( array( 'type' => 'text', 'text' => 'Server error: ' . $e->getMessage() ) ),
				'isError' => true,
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Int argument.
	 *
	 * @param array  $a   Args.
	 * @param string $k   Key.
	 * @param int    $def Default.
	 * @return int
	 */
	protected static function int( array $a, $k, $def = 0 ) {
		return isset( $a[ $k ] ) && is_numeric( $a[ $k ] ) ? (int) $a[ $k ] : $def;
	}

	/**
	 * String argument.
	 *
	 * @param array  $a   Args.
	 * @param string $k   Key.
	 * @param string $def Default.
	 * @return string
	 */
	protected static function str( array $a, $k, $def = '' ) {
		return isset( $a[ $k ] ) && is_scalar( $a[ $k ] ) ? (string) $a[ $k ] : $def;
	}

	/**
	 * Load a post the user can edit.
	 *
	 * @param int  $id   ID.
	 * @param bool $edit Require edit permission.
	 * @return WP_Post
	 * @throws VR_Tool_Error When missing or forbidden.
	 */
	protected static function post( $id, $edit = true ) {
		$post = get_post( (int) $id );
		if ( ! $post ) {
			throw new VR_Tool_Error( 'No post with ID ' . (int) $id . '.' );
		}
		if ( ! VR_Access::post_type_allowed( $post->post_type ) ) {
			throw new VR_Tool_Error( 'Post ' . (int) $id . ' is a ' . $post->post_type . ', which this connector cannot access.' );
		}
		if ( $edit ? ! current_user_can( 'edit_post', $post->ID ) : ! current_user_can( 'read_post', $post->ID ) ) {
			throw new VR_Tool_Error( 'Not allowed to access post ' . $post->ID . '.' );
		}
		return $post;
	}

	/**
	 * Summary links for a post.
	 *
	 * @param int $id ID.
	 * @return array
	 */
	protected static function links( $id ) {
		$post = get_post( $id );
		return array(
			'id'        => (int) $id,
			'status'    => $post ? $post->post_status : '',
			'edit_link' => admin_url( 'post.php?post=' . (int) $id . '&action=edit' ),
			'view_link' => $post && 'publish' === $post->post_status ? get_permalink( $id ) : get_preview_post_link( $id ),
		);
	}

	/**
	 * Short post row.
	 *
	 * @param WP_Post $p Post.
	 * @return array
	 */
	protected static function row( WP_Post $p ) {
		return array(
			'id'       => $p->ID,
			'type'     => $p->post_type,
			'title'    => $p->post_title,
			'status'   => $p->post_status,
			'slug'     => $p->post_name,
			'parent'   => $p->post_parent,
			'date'     => $p->post_date,
			'modified' => $p->post_modified,
			'link'     => get_permalink( $p ),
		);
	}

	/**
	 * Map tool args to wp_insert_post fields.
	 *
	 * @param array $a Args.
	 * @return array
	 */
	protected static function post_fields( array $a ) {
		$map = array(
			'title'      => 'post_title',
			'content'    => 'post_content',
			'excerpt'    => 'post_excerpt',
			'status'     => 'post_status',
			'slug'       => 'post_name',
			'parent'     => 'post_parent',
			'menu_order' => 'menu_order',
			'template'   => 'page_template',
		);
		$out = array();
		foreach ( $map as $in => $field ) {
			if ( array_key_exists( $in, $a ) && is_scalar( $a[ $in ] ) ) {
				$out[ $field ] = in_array( $in, array( 'parent', 'menu_order' ), true ) ? (int) $a[ $in ] : (string) $a[ $in ];
			}
		}
		if ( isset( $out['post_status'] ) && ! in_array( $out['post_status'], array( 'draft', 'publish', 'pending', 'private', 'future' ), true ) ) {
			throw new VR_Tool_Error( 'Unsupported status: ' . $out['post_status'] );
		}
		if ( ! empty( $out['post_parent'] ) ) {
			$parent = get_post( $out['post_parent'] );
			if ( ! $parent || ! VR_Access::post_type_allowed( $parent->post_type ) ) {
				throw new VR_Tool_Error( 'Parent not found.' );
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Site
	 * ------------------------------------------------------------------- */

	protected static function tool_mcp_ping( array $a ) {
		$theme = wp_get_theme();
		return array(
			'site'           => get_bloginfo( 'name' ),
			'url'            => home_url(),
			'wordpress'      => get_bloginfo( 'version' ),
			'php'            => PHP_VERSION,
			'theme'          => $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ),
			'plugin_version' => VR_PLUGIN_VERSION,
			'user'           => wp_get_current_user()->user_login,
			'timezone'       => wp_timezone_string(),
			'rank_math'      => defined( 'RANK_MATH_VERSION' ),
			'wp_rocket'      => function_exists( 'rocket_clean_post' ),
		);
	}

	protected static function tool_wp_list_plugins( array $a ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
				'file'    => $file,
			);
		}
		return $out;
	}

	protected static function tool_wp_get_post_types( array $a ) {
		$out = array();
		foreach ( get_post_types( array( 'show_ui' => true ), 'objects' ) as $pt ) {
			if ( ! VR_Access::post_type_allowed( $pt->name ) ) {
				continue;
			}
			$out[] = array(
				'name'         => $pt->name,
				'label'        => $pt->label,
				'public'       => (bool) $pt->public,
				'hierarchical' => (bool) $pt->hierarchical,
			);
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Posts
	 * ------------------------------------------------------------------- */

	protected static function tool_wp_get_posts( array $a ) {
		$order_by = self::str( $a, 'orderby', 'modified' );
		$type     = self::str( $a, 'post_type', 'any' );
		if ( 'any' === $type ) {
			$type = array_values( array_intersect( VR_Access::post_types(), get_post_types( array( 'exclude_from_search' => false ) ) ) );
			if ( empty( $type ) ) {
				return array( 'total' => 0, 'page' => 1, 'posts' => array() );
			}
		} elseif ( ! VR_Access::post_type_allowed( $type ) ) {
			throw new VR_Tool_Error( 'This connector cannot access ' . $type . ' content.' );
		}
		$q        = array(
			'post_type'      => $type,
			'post_status'    => self::str( $a, 'status', 'any' ),
			'posts_per_page' => max( 1, min( 100, self::int( $a, 'per_page', 20 ) ) ),
			'paged'          => max( 1, self::int( $a, 'page', 1 ) ),
			'orderby'        => in_array( $order_by, array( 'date', 'modified', 'title', 'menu_order', 'ID' ), true ) ? $order_by : 'modified',
			'order'          => 'ASC' === strtoupper( self::str( $a, 'order', 'DESC' ) ) ? 'ASC' : 'DESC',
			'no_found_rows'  => false,
		);
		if ( '' !== self::str( $a, 'search' ) ) {
			$q['s'] = self::str( $a, 'search' );
		}
		if ( isset( $a['parent'] ) ) {
			$q['post_parent'] = self::int( $a, 'parent' );
		}
		$query = new WP_Query( $q );
		$rows  = array();
		foreach ( $query->posts as $p ) {
			if ( VR_Access::post_type_allowed( $p->post_type ) && current_user_can( 'read_post', $p->ID ) ) {
				$rows[] = self::row( $p );
			}
		}
		return array(
			'total' => (int) $query->found_posts,
			'page'  => $q['paged'],
			'posts' => $rows,
		);
	}

	protected static function tool_wp_get_post( array $a ) {
		$p   = self::post( self::int( $a, 'id' ), false );
		$out = self::row( $p ) + array(
			'excerpt'        => $p->post_excerpt,
			'menu_order'     => $p->menu_order,
			'template'       => get_page_template_slug( $p ),
			'author'         => (int) $p->post_author,
			'featured_media' => (int) get_post_thumbnail_id( $p ),
			'edit_link'      => admin_url( 'post.php?post=' . $p->ID . '&action=edit' ),
			'content_length' => strlen( $p->post_content ),
		);
		if ( ! isset( $a['include_content'] ) || $a['include_content'] ) {
			$offset = max( 0, self::int( $a, 'offset', 0 ) );
			$limit  = max( 1, self::int( $a, 'limit', 60000 ) );
			$slice  = (string) substr( $p->post_content, $offset, $limit );
			// Do not split a multibyte character at the end.
			$slice                  = mb_strcut( $slice, 0, strlen( $slice ), 'UTF-8' );
			$out['content']         = $slice;
			$out['content_offset']  = $offset;
			$next                   = $offset + strlen( $slice );
			$out['content_more']    = $next < $out['content_length'];
			if ( $out['content_more'] ) {
				$out['next_offset'] = $next;
			}
		}
		return $out;
	}

	protected static function tool_wp_create_post( array $a ) {
		$type = self::str( $a, 'post_type', 'post' );
		$pto  = get_post_type_object( $type );
		if ( ! $pto ) {
			throw new VR_Tool_Error( 'Unknown post type: ' . $type );
		}
		if ( ! VR_Access::post_type_allowed( $type ) ) {
			throw new VR_Tool_Error( 'This connector cannot create ' . $type . ' content.' );
		}
		if ( in_array( $type, array( 'attachment', 'nav_menu_item' ), true ) ) {
			throw new VR_Tool_Error( 'Use wp_upload_media for media and wp_add_menu_item for menu items.' );
		}
		if ( ! current_user_can( $pto->cap->create_posts ) ) {
			throw new VR_Tool_Error( 'Not allowed to create ' . $type . '.' );
		}
		$arr              = self::post_fields( $a );
		$arr['post_type'] = $type;
		if ( empty( $arr['post_status'] ) ) {
			$arr['post_status'] = 'draft';
		}
		if ( ! isset( $arr['post_content'] ) ) {
			$arr['post_content'] = '';
		}
		if ( isset( $a['meta'] ) && is_array( $a['meta'] ) ) {
			foreach ( array_keys( $a['meta'] ) as $mk ) {
				if ( ! VR_Access::meta_writable( (string) $mk ) ) {
					throw new VR_Tool_Error( 'The ' . $mk . ' field is managed by WordPress and cannot be set here.' );
				}
			}
			$arr['meta_input'] = $a['meta'];
		}
		$id = wp_insert_post( wp_slash( $arr ), true );
		if ( is_wp_error( $id ) ) {
			throw new VR_Tool_Error( $id->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_create_post', 'post_create', $id, sprintf( 'Created %s "%s" (%s)', $type, $arr['post_title'], $arr['post_status'] ), array( 'post_id' => $id ) );
		return self::links( $id ) + array( 'change_id' => $change );
	}

	protected static function tool_wp_update_post( array $a ) {
		$p      = self::post( self::int( $a, 'id' ) );
		$fields = self::post_fields( $a );
		if ( empty( $fields ) ) {
			throw new VR_Tool_Error( 'Nothing to update.' );
		}
		if ( isset( $fields['post_status'] ) && 'publish' === $fields['post_status'] && ! current_user_can( 'publish_post', $p->ID ) ) {
			throw new VR_Tool_Error( 'Not allowed to publish.' );
		}
		$touched      = array_keys( $fields );
		$snap         = VR_Changes::snapshot_post( $p->ID, isset( $fields['page_template'] ) ? array( '_wp_page_template' ) : array(), $touched );
		if ( array( 'page_template' ) === $touched ) {
			$snap['fields'] = array();
		}
		$fields['ID'] = $p->ID;
		$res          = wp_update_post( wp_slash( $fields ), true );
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( $res->get_error_message() );
		}
		unset( $fields['ID'] );
		$change = VR_Changes::record( 'wp_update_post', 'post', $p->ID, sprintf( 'Updated %s on "%s"', implode( ', ', array_keys( $fields ) ), $p->post_title ), $snap );
		return self::links( $p->ID ) + array( 'updated' => array_keys( $fields ), 'change_id' => $change );
	}

	protected static function tool_wp_alter_post( array $a ) {
		$p     = self::post( self::int( $a, 'id' ) );
		$map   = array( 'content' => 'post_content', 'title' => 'post_title', 'excerpt' => 'post_excerpt' );
		$field = self::str( $a, 'field', 'content' );
		if ( ! isset( $map[ $field ] ) ) {
			throw new VR_Tool_Error( 'field must be content, title or excerpt.' );
		}
		$col      = $map[ $field ];
		$search   = self::str( $a, 'search' );
		$replace  = self::str( $a, 'replace' );
		$expected = self::int( $a, 'expected', 1 );
		if ( '' === $search ) {
			throw new VR_Tool_Error( 'search cannot be empty.' );
		}
		$count = substr_count( $p->$col, $search );
		if ( 0 === $count ) {
			throw new VR_Tool_Error( 'Search text not found; nothing changed.' );
		}
		if ( $expected > 0 && $count !== $expected ) {
			throw new VR_Tool_Error( sprintf( 'Found %d matches but expected %d; nothing changed. Widen the search text or set expected.', $count, $expected ) );
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array(), array( $col ) );
		$new  = str_replace( $search, $replace, $p->$col );
		$res  = wp_update_post( wp_slash( array( 'ID' => $p->ID, $col => $new ) ), true );
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( $res->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_alter_post', 'post', $p->ID, sprintf( 'Replaced %d match(es) in %s of "%s"', $count, $field, $p->post_title ), $snap );
		return self::links( $p->ID ) + array(
			'replaced'   => $count,
			'new_length' => strlen( $new ),
			'change_id'  => $change,
		);
	}

	protected static function tool_wp_append_content( array $a ) {
		$p    = self::post( self::int( $a, 'id' ) );
		$add  = self::str( $a, 'content' );
		$snap = VR_Changes::snapshot_post( $p->ID, array(), array( 'post_content' ) );
		$new  = $p->post_content . $add;
		$res  = wp_update_post( wp_slash( array( 'ID' => $p->ID, 'post_content' => $new ) ), true );
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( $res->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_append_content', 'post', $p->ID, sprintf( 'Appended %d characters to "%s"', strlen( $add ), $p->post_title ), $snap );
		return self::links( $p->ID ) + array(
			'content_length' => strlen( $new ),
			'change_id'      => $change,
		);
	}

	protected static function tool_wp_delete_post( array $a ) {
		$p = self::post( self::int( $a, 'id' ) );
		if ( ! current_user_can( 'delete_post', $p->ID ) ) {
			throw new VR_Tool_Error( 'Not allowed to delete.' );
		}
		if ( ! wp_trash_post( $p->ID ) ) {
			throw new VR_Tool_Error( 'Could not move to trash.' );
		}
		$change = VR_Changes::record( 'wp_delete_post', 'post_trash', $p->ID, sprintf( 'Trashed "%s"', $p->post_title ), array( 'post_id' => $p->ID ) );
		return array(
			'trashed'   => $p->ID,
			'change_id' => $change,
		);
	}

	/* ---------------------------------------------------------------------
	 * Meta + SEO
	 * ------------------------------------------------------------------- */

	protected static function tool_wp_get_post_meta( array $a ) {
		$p   = self::post( self::int( $a, 'id' ), false );
		$key = self::str( $a, 'key' );
		if ( '' !== $key ) {
			return array(
				'key'    => $key,
				'exists' => metadata_exists( 'post', $p->ID, $key ),
				'value'  => get_post_meta( $p->ID, $key, true ),
			);
		}
		$out = array();
		foreach ( get_post_meta( $p->ID ) as $k => $vals ) {
			$v = maybe_unserialize( $vals[0] );
			$s = is_scalar( $v ) ? (string) $v : wp_json_encode( $v );
			if ( strlen( $s ) > 2000 ) {
				$out[ $k ] = array(
					'shortened' => true,
					'length'    => strlen( $s ),
					'start'     => mb_strcut( $s, 0, 300, 'UTF-8' ),
				);
			} else {
				$out[ $k ] = $v;
			}
		}
		return $out;
	}

	protected static function tool_wp_update_post_meta( array $a ) {
		$p   = self::post( self::int( $a, 'id' ) );
		$key = self::str( $a, 'key' );
		if ( '' === $key ) {
			throw new VR_Tool_Error( 'key is required.' );
		}
		if ( ! VR_Access::meta_writable( $key ) ) {
			throw new VR_Tool_Error( 'The ' . $key . ' field is managed by WordPress and cannot be changed here. Use wp_set_featured_image for featured images.' );
		}
		if ( is_protected_meta( $key, 'post' ) && ! current_user_can( 'edit_post', $p->ID ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$value = $a['value'];
		$note  = '';
		if ( 0 === strpos( $key, 'rank_math_schema_' ) && is_array( $value ) ) {
			// Rank Math prints a stored true as "1", which is not a valid schema.org Boolean.
			$fixed = self::schema_booleans( $value );
			if ( $fixed !== $value ) {
				$value = $fixed;
				$note  = 'true/false values were saved as "true"/"false" so Rank Math outputs valid schema.';
			}
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array( $key ) );
		unset( $snap['fields'] );
		update_post_meta( $p->ID, $key, wp_slash( $value ) );
		$change = VR_Changes::record( 'wp_update_post_meta', 'post', $p->ID, sprintf( 'Set meta %s on "%s"', $key, $p->post_title ), $snap );
		$out    = array(
			'id'        => $p->ID,
			'key'       => $key,
			'stored'    => get_post_meta( $p->ID, $key, true ) === $value || wp_json_encode( get_post_meta( $p->ID, $key, true ) ) === wp_json_encode( $value ),
			'change_id' => $change,
		);
		if ( '' !== $note ) {
			$out['note'] = $note;
		}
		return $out;
	}

	/**
	 * Replace booleans with "true"/"false" strings, leaving Rank Math's own metadata block alone.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	protected static function schema_booleans( $v ) {
		if ( is_bool( $v ) ) {
			return $v ? 'true' : 'false';
		}
		if ( is_array( $v ) ) {
			foreach ( $v as $k => $item ) {
				if ( 'metadata' === $k ) {
					continue;
				}
				$v[ $k ] = self::schema_booleans( $item );
			}
		}
		return $v;
	}

	protected static function tool_wp_delete_post_meta( array $a ) {
		$p   = self::post( self::int( $a, 'id' ) );
		$key = self::str( $a, 'key' );
		if ( ! VR_Access::meta_writable( $key ) ) {
			throw new VR_Tool_Error( 'The ' . $key . ' field is managed by WordPress and cannot be removed here.' );
		}
		if ( ! metadata_exists( 'post', $p->ID, $key ) ) {
			throw new VR_Tool_Error( 'Meta key not found.' );
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array( $key ) );
		unset( $snap['fields'] );
		delete_post_meta( $p->ID, $key );
		$change = VR_Changes::record( 'wp_delete_post_meta', 'post', $p->ID, sprintf( 'Deleted meta %s on "%s"', $key, $p->post_title ), $snap );
		return array(
			'deleted'   => $key,
			'change_id' => $change,
		);
	}

	protected static function tool_wp_get_seo( array $a ) {
		$p      = self::post( self::int( $a, 'id' ), false );
		$schema = array();
		foreach ( array_keys( get_post_meta( $p->ID ) ) as $k ) {
			if ( 0 === strpos( $k, 'rank_math_schema_' ) ) {
				$schema[] = $k;
			}
		}
		return array(
			'id'            => $p->ID,
			'rank_math'     => defined( 'RANK_MATH_VERSION' ),
			'title'         => get_post_meta( $p->ID, 'rank_math_title', true ),
			'description'   => get_post_meta( $p->ID, 'rank_math_description', true ),
			'focus_keyword' => get_post_meta( $p->ID, 'rank_math_focus_keyword', true ),
			'robots'        => get_post_meta( $p->ID, 'rank_math_robots', true ),
			'canonical'     => get_post_meta( $p->ID, 'rank_math_canonical_url', true ),
			'schema_keys'   => $schema,
		);
	}

	protected static function tool_wp_update_seo( array $a ) {
		$p   = self::post( self::int( $a, 'id' ) );
		$map = array(
			'title'         => 'rank_math_title',
			'description'   => 'rank_math_description',
			'focus_keyword' => 'rank_math_focus_keyword',
			'canonical'     => 'rank_math_canonical_url',
			'robots'        => 'rank_math_robots',
		);
		$set = array();
		foreach ( $map as $in => $key ) {
			if ( array_key_exists( $in, $a ) ) {
				$set[ $key ] = 'robots' === $in ? array_values( array_map( 'sanitize_key', (array) $a[ $in ] ) ) : (string) $a[ $in ];
			}
		}
		if ( empty( $set ) ) {
			throw new VR_Tool_Error( 'Nothing to update.' );
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array_keys( $set ) );
		unset( $snap['fields'] );
		foreach ( $set as $key => $val ) {
			update_post_meta( $p->ID, $key, wp_slash( $val ) );
		}
		$change = VR_Changes::record( 'wp_update_seo', 'post', $p->ID, sprintf( 'Updated SEO (%s) on "%s"', implode( ', ', array_keys( $set ) ), $p->post_title ), $snap );
		return array(
			'id'        => $p->ID,
			'updated'   => array_keys( $set ),
			'change_id' => $change,
		);
	}

	protected static function tool_wp_get_rendered( array $a ) {
		$url = self::str( $a, 'url' );
		if ( '' === $url ) {
			$p = self::post( self::int( $a, 'id' ), false );
			if ( 'publish' !== $p->post_status ) {
				throw new VR_Tool_Error( 'Only published pages can be fetched; this one is ' . $p->post_status . '.' );
			}
			$url = get_permalink( $p );
		}
		$home  = wp_parse_url( home_url() );
		$parts = wp_parse_url( $url );
		$same  = is_array( $parts ) && isset( $parts['scheme'], $parts['host'] )
			&& in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true )
			&& strtolower( $parts['host'] ) === strtolower( $home['host'] )
			&& ( isset( $parts['port'] ) ? (int) $parts['port'] : null ) === ( isset( $home['port'] ) ? (int) $home['port'] : null )
			&& ! isset( $parts['user'] ) && ! isset( $parts['pass'] )
			&& ! preg_match( '/[\x00-\x20\x7f\\\\]/', $url );
		if ( ! $same ) {
			throw new VR_Tool_Error( 'URL must be on this site.' );
		}
		// Pages only: never files, the REST API, admin or feeds of raw data.
		$path = strtolower( rawurldecode( isset( $parts['path'] ) ? $parts['path'] : '/' ) );
		$home_path = strtolower( isset( $home['path'] ) ? untrailingslashit( $home['path'] ) : '' );
		$rel_path  = '' !== $home_path && 0 === strpos( $path, $home_path ) ? substr( $path, strlen( $home_path ) ) : $path;
		$query     = strtolower( rawurldecode( isset( $parts['query'] ) ? $parts['query'] : '' ) );
		$ext       = pathinfo( $rel_path, PATHINFO_EXTENSION );
		$page_like = '' === $ext || in_array( $ext, array( 'html', 'htm' ), true ) || '/robots.txt' === $rel_path || preg_match( '#^/[a-z0-9_\-]*sitemap[a-z0-9_\-]*\.xml$#', $rel_path );
		$odd       = preg_match( '#(^|/)\.{1,2}(/|$)|//|\\\\|index\.php/#', $rel_path );
		if ( $odd || ! $page_like || preg_match( '#/(wp-content|wp-includes|wp-admin|wp-json|xmlrpc|wp-login)#', $rel_path ) || false !== strpos( $query, 'rest_route' ) ) {
			throw new VR_Tool_Error( 'wp_get_rendered checks pages, not files or API endpoints. Use wp_get_file for media.' );
		}
		$fetch = add_query_arg( 'vr_nocache', wp_generate_password( 8, false ), $url );
		$res   = wp_remote_get(
			$fetch,
			array(
				'timeout'             => 20,
				'redirection'         => 0,
				'limit_response_size' => 8 * MB_IN_BYTES,
				'user-agent'          => 'VisualRealm-Verify/' . VR_PLUGIN_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( 'Fetch failed: ' . $res->get_error_message() );
		}
		$status = (int) wp_remote_retrieve_response_code( $res );
		$html   = wp_remote_retrieve_body( $res );
		$out    = array(
			'url'    => $url,
			'status' => $status,
			'length' => strlen( $html ),
		);
		if ( $status >= 300 && $status < 400 ) {
			// Redirects are reported, not followed.
			$out['redirect_to'] = (string) wp_remote_retrieve_header( $res, 'location' );
			return $out;
		}
		if ( isset( $a['count'] ) && is_array( $a['count'] ) ) {
			$counts = array();
			foreach ( $a['count'] as $needle ) {
				if ( is_string( $needle ) && '' !== $needle ) {
					$counts[] = array(
						'text'  => $needle,
						'count' => substr_count( $html, $needle ),
					);
				}
			}
			$out['counts'] = $counts;
		}
		$find = self::str( $a, 'find' );
		if ( '' !== $find ) {
			$pos = strpos( $html, $find );
			$out['find'] = false === $pos ? null : array(
				'offset'  => $pos,
				'excerpt' => mb_strcut( $html, max( 0, $pos - 200 ), 900, 'UTF-8' ),
			);
		}
		$limit = self::int( $a, 'limit', 0 );
		if ( $limit > 0 ) {
			$out['html'] = mb_strcut( $html, max( 0, self::int( $a, 'offset', 0 ) ), $limit, 'UTF-8' );
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Media
	 * ------------------------------------------------------------------- */

	/**
	 * Attachment summary.
	 *
	 * @param int $id ID.
	 * @return array
	 */
	protected static function media_row( $id ) {
		$meta = wp_get_attachment_metadata( $id );
		$post = get_post( $id );
		return array(
			'id'          => (int) $id,
			'url'         => wp_get_attachment_url( $id ),
			'filename'    => wp_basename( (string) get_attached_file( $id ) ),
			'title'       => $post ? $post->post_title : '',
			'alt'         => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'     => $post ? $post->post_excerpt : '',
			'description' => $post ? $post->post_content : '',
			'mime'        => $post ? $post->post_mime_type : '',
			'width'       => isset( $meta['width'] ) ? (int) $meta['width'] : null,
			'height'      => isset( $meta['height'] ) ? (int) $meta['height'] : null,
			'parent'      => $post ? (int) $post->post_parent : 0,
		);
	}

	protected static function tool_wp_get_media( array $a ) {
		if ( isset( $a['id'] ) ) {
			$post = get_post( self::int( $a, 'id' ) );
			if ( ! $post || 'attachment' !== $post->post_type ) {
				throw new VR_Tool_Error( 'No attachment with that ID.' );
			}
			return self::media_row( $post->ID );
		}
		$q = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => max( 1, min( 100, self::int( $a, 'per_page', 20 ) ) ),
			'paged'          => max( 1, self::int( $a, 'page', 1 ) ),
			'fields'         => 'ids',
		);
		if ( '' !== self::str( $a, 'search' ) ) {
			$q['s'] = self::str( $a, 'search' );
		}
		$query = new WP_Query( $q );
		return array(
			'total' => (int) $query->found_posts,
			'media' => array_map( array( __CLASS__, 'media_row' ), $query->posts ),
		);
	}

	protected static function tool_wp_upload_media( array $a ) {
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new VR_Tool_Error( 'Not allowed to upload.' );
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		$filename = sanitize_file_name( self::str( $a, 'filename' ) );
		$url      = self::str( $a, 'url' );
		$data     = self::str( $a, 'data' );

		if ( '' !== $url ) {
			$tmp = download_url( $url, 60 );
			if ( is_wp_error( $tmp ) ) {
				throw new VR_Tool_Error( 'Download failed: ' . $tmp->get_error_message() );
			}
			if ( '' === $filename ) {
				$filename = sanitize_file_name( wp_basename( (string) wp_parse_url( $url, PHP_URL_PATH ) ) );
			}
		} elseif ( '' !== $data ) {
			if ( '' === $filename ) {
				throw new VR_Tool_Error( 'filename is required with data.' );
			}
			$bin = base64_decode( preg_replace( '#^data:[^;]+;base64,#', '', $data ), true );
			if ( false === $bin ) {
				throw new VR_Tool_Error( 'data is not valid base64.' );
			}
			$tmp = wp_tempnam( $filename );
			file_put_contents( $tmp, $bin ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		} else {
			throw new VR_Tool_Error( 'Pass url or data.' );
		}

		if ( '' !== $url && filesize( $tmp ) > 50 * MB_IN_BYTES ) {
			wp_delete_file( $tmp );
			throw new VR_Tool_Error( 'File is over 50 MB.' );
		}
		$ext_ok = array( 'jpg', 'jpeg', 'jpe', 'png', 'gif', 'webp', 'avif', 'ico', 'svg', 'pdf', 'mp4', 'm4v', 'webm', 'mov', 'mp3', 'm4a', 'wav', 'ogg', 'woff', 'woff2', 'ttf', 'otf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'csv', 'txt', 'vtt' );
		if ( ! in_array( strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) ), $ext_ok, true ) ) {
			wp_delete_file( $tmp );
			throw new VR_Tool_Error( 'That file type cannot be uploaded through the connector (images, video, audio, fonts, PDFs and office documents only).' );
		}
		$is_svg = 'svg' === strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		if ( $is_svg ) {
			$clean = VR_SVG::sanitize( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			if ( is_wp_error( $clean ) ) {
				wp_delete_file( $tmp );
				throw new VR_Tool_Error( $clean->get_error_message() );
			}
			file_put_contents( $tmp, $clean ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			$allow_mime = function ( $mimes ) {
				$mimes['svg'] = 'image/svg+xml';
				return $mimes;
			};
			$allow_type = function ( $data, $file, $name ) {
				if ( 'svg' === strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
					return array( 'ext' => 'svg', 'type' => 'image/svg+xml', 'proper_filename' => false );
				}
				return $data;
			};
			add_filter( 'upload_mimes', $allow_mime );
			add_filter( 'wp_check_filetype_and_ext', $allow_type, 10, 3 );
		}

		$file = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$post_id = self::int( $a, 'post_id', 0 );
		if ( $post_id && ( ! get_post( $post_id ) || ! VR_Access::post_type_allowed( get_post_type( $post_id ) ) ) ) {
			throw new VR_Tool_Error( 'post_id not found.' );
		}
		$extra   = array();
		if ( '' !== self::str( $a, 'title' ) ) {
			$extra['post_title'] = self::str( $a, 'title' );
		}
		if ( '' !== self::str( $a, 'caption' ) ) {
			$extra['post_excerpt'] = self::str( $a, 'caption' );
		}
		if ( '' !== self::str( $a, 'description' ) ) {
			$extra['post_content'] = self::str( $a, 'description' );
		}
		$id = media_handle_sideload( $file, $post_id, null, $extra );
		if ( $is_svg ) {
			remove_filter( 'upload_mimes', $allow_mime );
			remove_filter( 'wp_check_filetype_and_ext', $allow_type, 10 );
		}
		if ( is_wp_error( $id ) ) {
			if ( file_exists( $tmp ) ) {
				wp_delete_file( $tmp );
			}
			throw new VR_Tool_Error( $id->get_error_message() );
		}
		if ( '' !== self::str( $a, 'alt' ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( self::str( $a, 'alt' ) ) );
		}
		$change = VR_Changes::record( 'wp_upload_media', 'post_create', $id, sprintf( 'Uploaded %s', $filename ), array( 'post_id' => $id ) );
		return self::media_row( $id ) + array( 'change_id' => $change );
	}

	protected static function tool_wp_update_media( array $a ) {
		$p = self::post( self::int( $a, 'id' ) );
		if ( 'attachment' !== $p->post_type ) {
			throw new VR_Tool_Error( 'Not an attachment.' );
		}
		$fields = array();
		if ( array_key_exists( 'title', $a ) ) {
			$fields['post_title'] = self::str( $a, 'title' );
		}
		if ( array_key_exists( 'caption', $a ) ) {
			$fields['post_excerpt'] = self::str( $a, 'caption' );
		}
		if ( array_key_exists( 'description', $a ) ) {
			$fields['post_content'] = self::str( $a, 'description' );
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array( '_wp_attachment_image_alt' ), array_keys( $fields ) );
		if ( ! $fields ) {
			$snap['fields'] = array();
		}
		if ( $fields ) {
			$fields['ID'] = $p->ID;
			$res          = wp_update_post( wp_slash( $fields ), true );
			if ( is_wp_error( $res ) ) {
				throw new VR_Tool_Error( $res->get_error_message() );
			}
		}
		if ( array_key_exists( 'alt', $a ) ) {
			update_post_meta( $p->ID, '_wp_attachment_image_alt', wp_slash( self::str( $a, 'alt' ) ) );
		}
		$change = VR_Changes::record( 'wp_update_media', 'post', $p->ID, sprintf( 'Updated media #%d', $p->ID ), $snap );
		return self::media_row( $p->ID ) + array( 'change_id' => $change );
	}

	protected static function tool_wp_set_featured_image( array $a ) {
		$p        = self::post( self::int( $a, 'id' ) );
		$media_id = self::int( $a, 'media_id' );
		if ( $media_id && 'attachment' !== get_post_type( $media_id ) ) {
			throw new VR_Tool_Error( 'media_id is not an attachment.' );
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array( '_thumbnail_id' ) );
		unset( $snap['fields'] );
		if ( $media_id ) {
			set_post_thumbnail( $p->ID, $media_id );
		} else {
			delete_post_thumbnail( $p->ID );
		}
		$change = VR_Changes::record( 'wp_set_featured_image', 'post', $p->ID, sprintf( 'Featured image on "%s" set to %d', $p->post_title, $media_id ), $snap );
		return array(
			'id'             => $p->ID,
			'featured_media' => (int) get_post_thumbnail_id( $p->ID ),
			'change_id'      => $change,
		);
	}

	/* ---------------------------------------------------------------------
	 * Terms + menus
	 * ------------------------------------------------------------------- */

	protected static function tool_wp_get_terms( array $a ) {
		$tax = self::str( $a, 'taxonomy', 'category' );
		if ( ! taxonomy_exists( $tax ) ) {
			throw new VR_Tool_Error( 'Unknown taxonomy.' );
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $tax,
				'hide_empty' => false,
				'number'     => max( 1, min( 200, self::int( $a, 'per_page', 100 ) ) ),
				'search'     => self::str( $a, 'search' ),
			)
		);
		if ( is_wp_error( $terms ) ) {
			throw new VR_Tool_Error( $terms->get_error_message() );
		}
		return array_map(
			function ( $t ) {
				return array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'parent' => $t->parent,
					'count'  => $t->count,
				);
			},
			$terms
		);
	}

	protected static function tool_wp_create_term( array $a ) {
		$tax = self::str( $a, 'taxonomy' );
		$to  = get_taxonomy( $tax );
		if ( ! $to ) {
			throw new VR_Tool_Error( 'Unknown taxonomy.' );
		}
		if ( ! current_user_can( $to->cap->edit_terms ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$args = array();
		foreach ( array( 'slug', 'description' ) as $k ) {
			if ( '' !== self::str( $a, $k ) ) {
				$args[ $k ] = self::str( $a, $k );
			}
		}
		if ( self::int( $a, 'parent' ) ) {
			$args['parent'] = self::int( $a, 'parent' );
		}
		$res = wp_insert_term( wp_slash( self::str( $a, 'name' ) ), $tax, wp_slash( $args ) );
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( $res->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_create_term', 'term_create', $res['term_id'], sprintf( 'Created %s term "%s"', $tax, self::str( $a, 'name' ) ), array( 'term_id' => $res['term_id'], 'taxonomy' => $tax ) );
		return array(
			'term_id'   => $res['term_id'],
			'change_id' => $change,
		);
	}

	protected static function tool_wp_set_post_terms( array $a ) {
		$p   = self::post( self::int( $a, 'id' ) );
		$tax = self::str( $a, 'taxonomy' );
		if ( ! taxonomy_exists( $tax ) || ! is_object_in_taxonomy( $p->post_type, $tax ) ) {
			throw new VR_Tool_Error( 'Taxonomy does not apply to this post type.' );
		}
		$ids = array();
		foreach ( (array) $a['terms'] as $t ) {
			if ( is_numeric( $t ) ) {
				$term = get_term( (int) $t, $tax );
			} else {
				$term = get_term_by( 'slug', sanitize_title( $t ), $tax );
				if ( ! $term ) {
					$term = get_term_by( 'name', (string) $t, $tax );
				}
			}
			if ( ! $term || is_wp_error( $term ) ) {
				throw new VR_Tool_Error( 'Term not found: ' . $t . '. Create it with wp_create_term first.' );
			}
			$ids[] = (int) $term->term_id;
		}
		$before = wp_get_object_terms( $p->ID, $tax, array( 'fields' => 'ids' ) );
		$res    = wp_set_object_terms( $p->ID, $ids, $tax, ! empty( $a['append'] ) );
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( $res->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_set_post_terms', 'terms', $p->ID, sprintf( 'Set %s terms on "%s"', $tax, $p->post_title ), array( 'post_id' => $p->ID, 'taxonomy' => $tax, 'term_ids' => is_wp_error( $before ) ? array() : $before ) );
		return array(
			'id'        => $p->ID,
			'term_ids'  => wp_get_object_terms( $p->ID, $tax, array( 'fields' => 'ids' ) ),
			'change_id' => $change,
		);
	}

	protected static function tool_wp_get_menus( array $a ) {
		$locations = get_nav_menu_locations();
		$out       = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$items = array();
			foreach ( (array) wp_get_nav_menu_items( $menu->term_id ) as $item ) {
				if ( 'post_type' === $item->type && ! VR_Access::post_type_allowed( $item->object ) ) {
					$items[] = array(
						'id'     => $item->ID,
						'hidden' => 'Links to content this connector cannot access.',
					);
					continue;
				}
				$items[] = array(
					'id'        => $item->ID,
					'title'     => $item->title,
					'url'       => $item->url,
					'object'    => $item->object,
					'object_id' => (int) $item->object_id,
					'parent'    => (int) $item->menu_item_parent,
					'order'     => (int) $item->menu_order,
				);
			}
			$out[] = array(
				'id'        => $menu->term_id,
				'name'      => $menu->name,
				'locations' => array_keys( $locations, $menu->term_id, true ),
				'items'     => $items,
			);
		}
		return $out;
	}

	protected static function tool_wp_add_menu_item( array $a ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$menu = wp_get_nav_menu_object( self::int( $a, 'menu_id' ) );
		if ( ! $menu ) {
			throw new VR_Tool_Error( 'Menu not found.' );
		}
		$data = array(
			'menu-item-status'    => 'publish',
			'menu-item-parent-id' => self::int( $a, 'parent' ),
			'menu-item-position'  => self::int( $a, 'position' ),
		);
		if ( '' !== self::str( $a, 'title' ) ) {
			$data['menu-item-title'] = self::str( $a, 'title' );
		}
		$object_id = self::int( $a, 'object_id' );
		if ( $object_id ) {
			$target = get_post( $object_id );
			if ( ! $target || ! VR_Access::post_type_allowed( $target->post_type ) ) {
				throw new VR_Tool_Error( 'object_id not found.' );
			}
			$data['menu-item-type']      = 'post_type';
			$data['menu-item-object']    = $target->post_type;
			$data['menu-item-object-id'] = $object_id;
		} elseif ( '' !== self::str( $a, 'url' ) ) {
			$data['menu-item-type'] = 'custom';
			$data['menu-item-url']  = esc_url_raw( self::str( $a, 'url' ) );
		} else {
			throw new VR_Tool_Error( 'Pass object_id or url.' );
		}
		$item_id = wp_update_nav_menu_item( $menu->term_id, 0, wp_slash( $data ) );
		if ( is_wp_error( $item_id ) ) {
			throw new VR_Tool_Error( $item_id->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_add_menu_item', 'post_create', $item_id, sprintf( 'Added menu item to "%s"', $menu->name ), array( 'post_id' => $item_id ) );
		return array(
			'item_id'   => $item_id,
			'change_id' => $change,
		);
	}

	/**
	 * Load a nav menu item the user may edit.
	 *
	 * @param int $id Item ID.
	 * @return WP_Post
	 * @throws VR_Tool_Error When missing or forbidden.
	 */
	protected static function menu_item( $id ) {
		if ( ! current_user_can( 'edit_theme_options' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$item = get_post( (int) $id );
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			throw new VR_Tool_Error( 'No menu item with ID ' . (int) $id . '.' );
		}
		return $item;
	}

	/**
	 * Full menu item data in the shape wp_update_nav_menu_item() takes.
	 *
	 * @param WP_Post $item Item.
	 * @return array{menu_id:int,data:array}
	 */
	protected static function menu_item_data( WP_Post $item ) {
		$menus   = wp_get_object_terms( $item->ID, 'nav_menu', array( 'fields' => 'ids' ) );
		$setup   = wp_setup_nav_menu_item( $item );
		return array(
			'menu_id' => is_wp_error( $menus ) || empty( $menus ) ? 0 : (int) $menus[0],
			'data'    => array(
				'menu-item-object-id'   => (int) $setup->object_id,
				'menu-item-object'      => $setup->object,
				'menu-item-parent-id'   => (int) $setup->menu_item_parent,
				'menu-item-position'    => (int) $setup->menu_order,
				'menu-item-type'        => $setup->type,
				'menu-item-title'       => $item->post_title,
				'menu-item-url'         => $setup->url,
				'menu-item-description' => $item->post_content,
				'menu-item-attr-title'  => $item->post_excerpt,
				'menu-item-target'      => $setup->target,
				'menu-item-classes'     => implode( ' ', (array) $setup->classes ),
				'menu-item-xfn'         => $setup->xfn,
				'menu-item-status'      => 'publish',
			),
		);
	}

	protected static function tool_wp_update_menu_item( array $a ) {
		$item  = self::menu_item( self::int( $a, 'item_id' ) );
		$state = self::menu_item_data( $item );
		if ( ! $state['menu_id'] ) {
			throw new VR_Tool_Error( 'That item is not in a menu.' );
		}
		$data    = $state['data'];
		$changed = array();
		if ( array_key_exists( 'title', $a ) ) {
			$data['menu-item-title'] = self::str( $a, 'title' );
			$changed[]               = 'title';
		}
		if ( array_key_exists( 'url', $a ) ) {
			if ( 'custom' !== $data['menu-item-type'] ) {
				throw new VR_Tool_Error( 'Only custom links have their own URL; this item links to a ' . $data['menu-item-object'] . '.' );
			}
			$data['menu-item-url'] = esc_url_raw( self::str( $a, 'url' ) );
			$changed[]             = 'url';
		}
		if ( array_key_exists( 'parent', $a ) ) {
			$data['menu-item-parent-id'] = self::int( $a, 'parent' );
			$changed[]                   = 'parent';
		}
		if ( array_key_exists( 'position', $a ) ) {
			$data['menu-item-position'] = self::int( $a, 'position' );
			$changed[]                  = 'position';
		}
		if ( empty( $changed ) ) {
			throw new VR_Tool_Error( 'Nothing to update.' );
		}
		$res = wp_update_nav_menu_item( $state['menu_id'], $item->ID, wp_slash( $data ) );
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( $res->get_error_message() );
		}
		$change = VR_Changes::record( 'wp_update_menu_item', 'menu_item', $item->ID, sprintf( 'Updated menu item "%s" (%s)', $state['data']['menu-item-title'], implode( ', ', $changed ) ), $state );
		return array(
			'item_id'   => $item->ID,
			'updated'   => $changed,
			'change_id' => $change,
		);
	}

	protected static function tool_wp_delete_menu_item( array $a ) {
		$item  = self::menu_item( self::int( $a, 'item_id' ) );
		$state = self::menu_item_data( $item );
		$label = '' !== $item->post_title ? $item->post_title : ( get_the_title( (int) $state['data']['menu-item-object-id'] ) );
		if ( ! wp_delete_post( $item->ID, true ) ) {
			throw new VR_Tool_Error( 'Could not remove the menu item.' );
		}
		$change = VR_Changes::record( 'wp_delete_menu_item', 'menu_item_delete', $item->ID, sprintf( 'Removed menu item "%s"', $label ), $state );
		return array(
			'removed'   => $item->ID,
			'change_id' => $change,
		);
	}

	protected static function tool_wp_get_file( array $a ) {
		$path = '';
		if ( ! current_user_can( 'upload_files' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		if ( isset( $a['id'] ) ) {
			$att = get_post( self::int( $a, 'id' ) );
			if ( ! $att || 'attachment' !== $att->post_type ) {
				throw new VR_Tool_Error( 'No attachment with that ID.' );
			}
			$path = (string) get_attached_file( $att->ID );
		} elseif ( '' !== self::str( $a, 'url' ) ) {
			$uploads = wp_get_upload_dir();
			$url     = strtok( self::str( $a, 'url' ), '?' );
			$base    = set_url_scheme( $uploads['baseurl'], 'https' );
			$url     = set_url_scheme( $url, 'https' );
			if ( 0 !== strpos( $url, trailingslashit( $base ) ) ) {
				throw new VR_Tool_Error( 'URL must be inside this site\'s uploads folder.' );
			}
			$path = $uploads['basedir'] . '/' . rawurldecode( substr( $url, strlen( trailingslashit( $base ) ) ) );
		} else {
			throw new VR_Tool_Error( 'Pass id or url.' );
		}
		$real = realpath( $path );
		// The root is fixed, not taken from the (changeable) upload_path option.
		$root = realpath( defined( 'UPLOADS' ) ? ABSPATH . UPLOADS : WP_CONTENT_DIR . '/uploads' );
		if ( ! $real || ! $root || 0 !== strpos( $real, $root . DIRECTORY_SEPARATOR ) || ! is_file( $real ) ) {
			throw new VR_Tool_Error( 'File not found.' );
		}
		if ( ! VR_Access::is_media_file( substr( $real, strlen( $root ) + 1 ) ) ) {
			throw new VR_Tool_Error( 'Only files in the media library can be read through the connector.' );
		}
		$ext    = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		$text   = array( 'svg', 'css', 'json', 'txt', 'csv', 'md' );
		$binary = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'ico', 'pdf', 'woff', 'woff2', 'ttf', 'otf', 'mp4', 'webm', 'mov', 'mp3', 'wav' );
		if ( ! in_array( $ext, $text, true ) && ! in_array( $ext, $binary, true ) ) {
			throw new VR_Tool_Error( 'That file type cannot be read through the connector.' );
		}
		$size = (int) filesize( $real );
		$out  = array(
			'path' => substr( $real, strlen( $root ) ),
			'size' => $size,
			'type' => wp_check_filetype( $real )['type'],
		);
		if ( in_array( $ext, $text, true ) ) {
			if ( $size > 500000 ) {
				throw new VR_Tool_Error( 'Text file is over 500 KB.' );
			}
			$out['content'] = (string) file_get_contents( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			return $out;
		}
		if ( empty( $a['base64'] ) ) {
			$out['hint'] = 'Binary file. Pass base64: true to receive it (2 MB max).';
			return $out;
		}
		if ( $size > 2 * MB_IN_BYTES ) {
			throw new VR_Tool_Error( 'File is over 2 MB.' );
		}
		$out['base64'] = base64_encode( (string) file_get_contents( $real ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Revisions
	 * ------------------------------------------------------------------- */

	protected static function tool_wp_get_revisions( array $a ) {
		$p   = self::post( self::int( $a, 'id' ), false );
		$out = array();
		foreach ( wp_get_post_revisions( $p->ID ) as $r ) {
			$u     = get_userdata( (int) $r->post_author );
			$out[] = array(
				'revision_id' => $r->ID,
				'date'        => $r->post_modified,
				'author'      => $u ? $u->user_login : '',
				'title'       => $r->post_title,
				'length'      => strlen( $r->post_content ),
			);
		}
		return $out;
	}

	protected static function tool_wp_restore_revision( array $a ) {
		$rev = wp_get_post_revision( self::int( $a, 'revision_id' ) );
		if ( ! $rev ) {
			throw new VR_Tool_Error( 'Revision not found.' );
		}
		$p    = self::post( $rev->post_parent );
		$snap = VR_Changes::snapshot_post( $p->ID, array(), array( 'post_title', 'post_content', 'post_excerpt' ) );
		$res  = wp_restore_post_revision( $rev->ID );
		if ( ! $res || is_wp_error( $res ) ) {
			throw new VR_Tool_Error( 'Restore failed.' );
		}
		$change = VR_Changes::record( 'wp_restore_revision', 'post', $p->ID, sprintf( 'Restored "%s" to revision %d', $p->post_title, $rev->ID ), $snap );
		return self::links( $p->ID ) + array( 'change_id' => $change );
	}

	/* ---------------------------------------------------------------------
	 * Options
	 * ------------------------------------------------------------------- */

	/**
	 * Option names must be written exactly as stored: lowercase, no spaces.
	 * MySQL compares option names case-insensitively and ignores trailing
	 * spaces, so anything looser would slip past name checks.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function option_name_ok( $name ) {
		return is_string( $name ) && (bool) preg_match( '/^[a-z0-9_\-]{1,191}$/', $name );
	}

	/**
	 * Whether the connector may change this option.
	 *
	 * @param string $name Name.
	 * @return bool
	 */
	public static function option_writable( $name ) {
		if ( ! self::option_name_ok( $name ) || preg_match( self::SECRET_PATTERN, $name ) ) {
			return false;
		}
		if ( in_array( $name, self::WRITABLE_OPTIONS, true ) ) {
			return true;
		}
		foreach ( self::WRITABLE_PREFIXES as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Blank out credential-looking keys inside an option value.
	 *
	 * @param mixed $v Value.
	 * @return mixed
	 */
	protected static function redact( $v ) {
		if ( is_object( $v ) ) {
			return (object) self::redact( get_object_vars( $v ) );
		}
		if ( ! is_array( $v ) ) {
			return $v;
		}
		foreach ( $v as $k => $item ) {
			if ( is_string( $k ) && preg_match( self::SECRET_PATTERN, $k ) && '' !== $item && null !== $item && array() !== $item ) {
				$v[ $k ] = '[hidden]';
			} else {
				$v[ $k ] = self::redact( $item );
			}
		}
		return $v;
	}

	/**
	 * Put the stored secret back wherever an incoming value still says "[hidden]".
	 *
	 * @param mixed $incoming Value being written.
	 * @param mixed $stored   Current stored value.
	 * @return mixed
	 * @throws VR_Tool_Error When a "[hidden]" placeholder has no stored value to restore.
	 */
	protected static function unredact( $incoming, $stored ) {
		if ( '[hidden]' === $incoming ) {
			if ( null === $stored || is_array( $stored ) || is_object( $stored ) ) {
				throw new VR_Tool_Error( 'The value contains "[hidden]" where there is no saved secret to keep. Remove that key or give a real value.' );
			}
			return $stored;
		}
		if ( is_array( $incoming ) ) {
			$stored_arr = is_object( $stored ) ? get_object_vars( $stored ) : ( is_array( $stored ) ? $stored : array() );
			foreach ( $incoming as $k => $item ) {
				$incoming[ $k ] = self::unredact( $item, array_key_exists( $k, $stored_arr ) ? $stored_arr[ $k ] : null );
			}
		}
		return $incoming;
	}

	protected static function tool_wp_get_option( array $a ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$name = self::str( $a, 'name' );
		if ( ! self::option_name_ok( $name ) ) {
			throw new VR_Tool_Error( 'Option names are lowercase letters, numbers, _ and - only.' );
		}
		if ( 0 === strpos( $name, 'vr_' ) || preg_match( self::SECRET_PATTERN, $name ) ) {
			throw new VR_Tool_Error( 'That option holds credentials and cannot be read here.' );
		}
		if ( VR_Access::is_form_option( $name ) ) {
			throw new VR_Tool_Error( 'Form plugin settings cannot be read through the connector.' );
		}
		$sentinel = '__vr_missing__';
		$value    = get_option( $name, $sentinel );
		if ( $sentinel === $value ) {
			return array(
				'name'   => $name,
				'exists' => false,
				'value'  => null,
			);
		}
		if ( is_string( $value ) && '' !== $value && ( '{' === $value[0] || '[' === $value[0] ) ) {
			$decoded = json_decode( wp_unslash( $value ), true );
			if ( is_array( $decoded ) ) {
				$value = $decoded;
			}
		}
		$value = self::redact( $value );
		$path  = self::str( $a, 'key' );
		if ( '' !== $path ) {
			foreach ( explode( '.', $path ) as $seg ) {
				if ( ! is_array( $value ) || ! array_key_exists( $seg, $value ) ) {
					throw new VR_Tool_Error( 'Key not found: ' . $path );
				}
				$value = $value[ $seg ];
			}
		}
		$out  = array(
			'name'   => $name,
			'exists' => true,
		);
		if ( '' !== $path ) {
			$out['key'] = $path;
		}
		$size = strlen( (string) wp_json_encode( $value ) );
		if ( is_array( $value ) && $size > 20000 ) {
			$out['too_large'] = $size;
			$out['keys']      = array_slice( array_keys( $value ), 0, 400 );
			$out['hint']      = 'Pass key to read one part.';
			return $out;
		}
		$out['value'] = $value;
		return $out;
	}

	protected static function tool_wp_update_option( array $a ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$name = self::str( $a, 'name' );
		if ( ! self::option_writable( $name ) ) {
			throw new VR_Tool_Error( 'That option cannot be changed through the connector. Allowed: site title, tagline, homepage and reading settings, image sizes, widgets, theme mods, Avada (fusion_*, avada_*) and Rank Math (rank-math-options-*) settings.' );
		}
		$sentinel = '__vr_missing__';
		$before   = get_option( $name, $sentinel );
		$stored_value = $sentinel === $before ? null : $before;
		if ( is_string( $stored_value ) && '' !== $stored_value && ( '{' === $stored_value[0] || '[' === $stored_value[0] ) && is_array( $a['value'] ) ) {
			$decoded      = json_decode( wp_unslash( $stored_value ), true );
			$stored_value = is_array( $decoded ) ? $decoded : $stored_value;
		}
		update_option( $name, self::unredact( $a['value'], $stored_value ) );
		$change = VR_Changes::record(
			'wp_update_option',
			'option',
			$name,
			sprintf( 'Set option %s', $name ),
			array(
				'name'    => $name,
				'existed' => $sentinel !== $before,
				'value'   => $sentinel === $before ? null : $before,
			)
		);
		return array(
			'name'      => $name,
			'change_id' => $change,
		);
	}

	/* ---------------------------------------------------------------------
	 * Cache
	 * ------------------------------------------------------------------- */

	protected static function tool_vr_purge_cache( array $a ) {
		$id = self::int( $a, 'id' );
		if ( $id && ! get_post( $id ) ) {
			throw new VR_Tool_Error( 'No post with ID ' . $id . '.' );
		}
		if ( $id && ! current_user_can( 'edit_post', $id ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$site = ! $id || ! empty( $a['everything'] ) || in_array( get_post_type( $id ), VR_Cache::GLOBAL_TYPES, true );
		if ( $site && ! current_user_can( 'manage_options' ) ) {
			throw new VR_Tool_Error( 'Only administrators can clear the whole site cache.' );
		}
		return VR_Cache::purge( $id, ! empty( $a['everything'] ) );
	}

	/* ---------------------------------------------------------------------
	 * Change log
	 * ------------------------------------------------------------------- */

	protected static function tool_mcp_list_changes( array $a ) {
		return VR_Changes::recent( self::int( $a, 'limit', 20 ), self::str( $a, 'object_id' ) );
	}

	protected static function tool_mcp_undo_change( array $a ) {
		$row = VR_Changes::get( self::int( $a, 'id' ) );
		if ( $row && ! VR_Access::tool_enabled( (string) $row['tool'] ) ) {
			throw new VR_Tool_Error( 'That change was made with ' . $row['tool'] . ', which is switched off on this site. Undo it from the Visual Realm screen in WordPress admin.' );
		}
		return VR_Changes::undo( self::int( $a, 'id' ) );
	}
}
