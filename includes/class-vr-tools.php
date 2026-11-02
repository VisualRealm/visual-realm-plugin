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
	 * Options that can never be written through the connector.
	 */
	const PROTECTED_OPTIONS = array( 'siteurl', 'home', 'users_can_register', 'default_role', 'active_plugins', 'template', 'stylesheet', 'admin_email', 'new_admin_email', 'vr_settings', 'vr_db_version', 'wp_user_roles' );

	/**
	 * Options that can never be read through the connector.
	 */
	const SECRET_OPTIONS = array( 'vr_settings' );

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
			'wp_get_revisions'     => array( 'List revisions of a post.', array( 'id' => $id ), array( 'id' ), $read ),
			'wp_restore_revision'  => array( 'Restore a post to a revision.', array( 'revision_id' => self::p( 'integer', 'Revision ID.' ) ), array( 'revision_id' ), $edit ),
			'wp_get_option'        => array( 'Read a site option.', array( 'name' => self::p( 'string', 'Option name.' ) ), array( 'name' ), $read ),
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
	 * MCP tool definitions.
	 *
	 * @return array
	 */
	public static function definitions() {
		$out = array();
		foreach ( self::registry() as $name => $def ) {
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
		$q        = array(
			'post_type'      => self::str( $a, 'post_type', 'any' ),
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
			if ( current_user_can( 'read_post', $p->ID ) ) {
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
		if ( is_protected_meta( $key, 'post' ) && ! current_user_can( 'edit_post', $p->ID ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$snap = VR_Changes::snapshot_post( $p->ID, array( $key ) );
		unset( $snap['fields'] );
		update_post_meta( $p->ID, $key, wp_slash( $a['value'] ) );
		$change = VR_Changes::record( 'wp_update_post_meta', 'post', $p->ID, sprintf( 'Set meta %s on "%s"', $key, $p->post_title ), $snap );
		return array(
			'id'        => $p->ID,
			'key'       => $key,
			'stored'    => get_post_meta( $p->ID, $key, true ) === $a['value'] || wp_json_encode( get_post_meta( $p->ID, $key, true ) ) === wp_json_encode( $a['value'] ),
			'change_id' => $change,
		);
	}

	protected static function tool_wp_delete_post_meta( array $a ) {
		$p   = self::post( self::int( $a, 'id' ) );
		$key = self::str( $a, 'key' );
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
		$home_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== $home_host ) {
			throw new VR_Tool_Error( 'URL must be on this site.' );
		}
		$fetch = add_query_arg( 'vr_nocache', wp_generate_password( 8, false ), $url );
		$res   = wp_remote_get(
			$fetch,
			array(
				'timeout'    => 20,
				'sslverify'  => false,
				'user-agent' => 'VisualRealm-Verify/' . VR_PLUGIN_VERSION,
			)
		);
		if ( is_wp_error( $res ) ) {
			throw new VR_Tool_Error( 'Fetch failed: ' . $res->get_error_message() );
		}
		$html = wp_remote_retrieve_body( $res );
		$out  = array(
			'url'    => $url,
			'status' => (int) wp_remote_retrieve_response_code( $res ),
			'length' => strlen( $html ),
		);
		if ( isset( $a['count'] ) && is_array( $a['count'] ) ) {
			$counts = array();
			foreach ( $a['count'] as $needle ) {
				if ( is_string( $needle ) && '' !== $needle ) {
					$counts[ $needle ] = substr_count( $html, $needle );
				}
			}
			$out['counts'] = $counts;
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

		$file = array(
			'name'     => $filename,
			'tmp_name' => $tmp,
		);
		$post_id = self::int( $a, 'post_id', 0 );
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
			if ( ! $target ) {
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

	protected static function tool_wp_get_option( array $a ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$name = self::str( $a, 'name' );
		if ( in_array( $name, self::SECRET_OPTIONS, true ) ) {
			throw new VR_Tool_Error( 'That option cannot be read here.' );
		}
		$sentinel = '__vr_missing__';
		$value    = get_option( $name, $sentinel );
		return array(
			'name'   => $name,
			'exists' => $sentinel !== $value,
			'value'  => $sentinel === $value ? null : $value,
		);
	}

	protected static function tool_wp_update_option( array $a ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new VR_Tool_Error( 'Not allowed.' );
		}
		$name = self::str( $a, 'name' );
		if ( '' === $name || in_array( $name, self::PROTECTED_OPTIONS, true ) || 0 === strpos( $name, 'vr_' ) ) {
			throw new VR_Tool_Error( 'That option is protected and cannot be changed here.' );
		}
		$sentinel = '__vr_missing__';
		$before   = get_option( $name, $sentinel );
		update_option( $name, $a['value'] );
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
		$id         = self::int( $a, 'id' );
		$everything = ! $id || ! empty( $a['everything'] );
		$url        = $id ? get_permalink( $id ) : '';
		$done       = array();

		if ( $everything && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			$done['wp_rocket'] = 'site';
		} elseif ( $id && function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $id );
			$done['wp_rocket'] = 'page';
		}

		global $kinsta_cache;
		if ( is_object( $kinsta_cache ) && isset( $kinsta_cache->kinsta_cache_purge ) ) {
			$purge = $kinsta_cache->kinsta_cache_purge;
			if ( $everything && method_exists( $purge, 'purge_complete_caches' ) ) {
				$purge->purge_complete_caches();
				$done['kinsta'] = 'site';
			} elseif ( $id && method_exists( $purge, 'initiate_purge' ) ) {
				$purge->initiate_purge( $id );
				$done['kinsta'] = 'page';
			}
		}

		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $everything ? null : $url );
			$done['siteground'] = $everything ? 'site' : 'page';
		}

		$zone  = (string) VR_Settings::get( 'cloudflare_zone_id' );
		$token = (string) VR_Settings::get( 'cloudflare_token' );
		if ( '' !== $zone && '' !== $token ) {
			$body = $everything ? array( 'purge_everything' => true ) : array( 'files' => array( $url, trailingslashit( $url ) ) );
			$res  = wp_remote_post(
				'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode( $body ),
				)
			);
			$ok                 = ! is_wp_error( $res ) && 200 === (int) wp_remote_retrieve_response_code( $res );
			$done['cloudflare'] = $ok ? ( $everything ? 'site' : 'page' ) : 'failed: ' . ( is_wp_error( $res ) ? $res->get_error_message() : wp_remote_retrieve_body( $res ) );
		} else {
			$done['cloudflare'] = 'not configured';
		}

		return array(
			'scope'  => $everything ? 'site' : 'page',
			'url'    => $url,
			'purged' => $done,
		);
	}

	/* ---------------------------------------------------------------------
	 * Change log
	 * ------------------------------------------------------------------- */

	protected static function tool_mcp_list_changes( array $a ) {
		return VR_Changes::recent( self::int( $a, 'limit', 20 ), self::str( $a, 'object_id' ) );
	}

	protected static function tool_mcp_undo_change( array $a ) {
		return VR_Changes::undo( self::int( $a, 'id' ) );
	}
}
