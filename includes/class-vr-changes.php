<?php
/**
 * Change log with snapshots and undo.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_Changes {

	/**
	 * Post fields captured in snapshots and restored on undo.
	 */
	const POST_FIELDS = array( 'post_title', 'post_content', 'post_excerpt', 'post_status', 'post_name', 'post_parent', 'menu_order' );

	/**
	 * Record a change.
	 *
	 * @param string $tool     Tool name.
	 * @param string $type     Snapshot type.
	 * @param mixed  $object   Object ID.
	 * @param string $summary  Human summary.
	 * @param array  $snapshot Data needed to undo.
	 * @param bool   $undoable Whether undo is possible.
	 * @return int Change ID.
	 */
	public static function record( $tool, $type, $object, $summary, array $snapshot, $undoable = true ) {
		global $wpdb;
		$wpdb->insert(
			VR_DB::table( 'changes' ),
			array(
				'created_at'  => VR_DB::now(),
				'user_id'     => get_current_user_id(),
				'tool'        => $tool,
				'object_type' => $type,
				'object_id'   => (string) $object,
				'summary'     => $summary,
				'snapshot'    => wp_json_encode( $snapshot ),
				'undoable'    => $undoable ? 1 : 0,
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Snapshot the post fields a change touches, plus optional meta keys.
	 *
	 * Only the touched fields are captured so that undoing an older change
	 * never rolls back unrelated later edits (such as a publish).
	 *
	 * @param int      $post_id   Post.
	 * @param string[] $meta_keys Meta keys.
	 * @param string[] $only      Post fields to capture (default: all).
	 * @return array
	 */
	public static function snapshot_post( $post_id, array $meta_keys = array(), array $only = array() ) {
		$post   = get_post( $post_id, ARRAY_A );
		$fields = array();
		$want   = empty( $only ) ? self::POST_FIELDS : array_intersect( self::POST_FIELDS, $only );
		foreach ( $want as $f ) {
			$fields[ $f ] = $post ? $post[ $f ] : null;
		}
		$meta = array();
		foreach ( $meta_keys as $key ) {
			$meta[] = self::snapshot_meta( $post_id, $key );
		}
		return array(
			'post_id' => (int) $post_id,
			'fields'  => $fields,
			'meta'    => $meta,
		);
	}

	/**
	 * Snapshot one meta key.
	 *
	 * @param int    $post_id Post.
	 * @param string $key     Key.
	 * @return array
	 */
	public static function snapshot_meta( $post_id, $key ) {
		$existed = metadata_exists( 'post', $post_id, $key );
		return array(
			'key'     => $key,
			'existed' => $existed,
			'value'   => $existed ? get_post_meta( $post_id, $key, true ) : null,
		);
	}

	/**
	 * List recent changes.
	 *
	 * @param int    $limit     Limit.
	 * @param string $object_id Optional object filter.
	 * @return array
	 */
	public static function recent( $limit = 20, $object_id = '' ) {
		global $wpdb;
		$limit = max( 1, min( 100, (int) $limit ) );
		$t     = VR_DB::table( 'changes' );
		// Non-administrators only see their own changes.
		$mine = current_user_can( 'manage_options' ) ? '' : $wpdb->prepare( ' AND user_id = %d', get_current_user_id() );
		if ( '' !== (string) $object_id ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, user_id, tool, object_type, object_id, summary, undoable, undone FROM {$t} WHERE object_id = %s{$mine} ORDER BY id DESC LIMIT %d", (string) $object_id, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, created_at, user_id, tool, object_type, object_id, summary, undoable, undone FROM {$t} WHERE 1=1{$mine} ORDER BY id DESC LIMIT %d", $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		}
		foreach ( $rows as &$r ) {
			$u              = get_userdata( (int) $r['user_id'] );
			$r['id']        = (int) $r['id'];
			$r['user']      = $u ? $u->user_login : '';
			$r['undoable']  = (bool) $r['undoable'] && ! (bool) $r['undone'];
			$r['undone']    = (bool) $r['undone'];
			$r['created_at'] = $r['created_at'] . ' UTC';
			unset( $r['user_id'] );
		}
		return $rows;
	}

	/**
	 * Undo a change.
	 *
	 * @param int $id Change ID.
	 * @return array|WP_Error
	 */
	public static function undo( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VR_DB::table( 'changes' ) . ' WHERE id = %d', (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return new WP_Error( 'not_found', 'No change with that ID.' );
		}
		if ( (int) $row['user_id'] !== get_current_user_id() && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'forbidden', 'You can only undo your own changes.' );
		}
		if ( ! (int) $row['undoable'] ) {
			return new WP_Error( 'not_undoable', 'That change cannot be undone.' );
		}
		if ( (int) $row['undone'] ) {
			return new WP_Error( 'already_undone', 'That change was already undone.' );
		}
		$snap = json_decode( $row['snapshot'], true );
		if ( ! is_array( $snap ) ) {
			return new WP_Error( 'bad_snapshot', 'The saved snapshot is unreadable.' );
		}

		switch ( $row['object_type'] ) {
			case 'post':
				$result = self::restore_post( $snap );
				break;
			case 'post_create':
				$result = self::undo_create( (int) $snap['post_id'] );
				break;
			case 'post_trash':
				if ( ! current_user_can( 'delete_post', (int) $snap['post_id'] ) ) {
					return new WP_Error( 'forbidden', 'Not allowed.' );
				}
				add_filter( 'wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3 );
				$result = wp_untrash_post( (int) $snap['post_id'] ) ? true : new WP_Error( 'failed', 'Could not restore from trash.' );
				break;
			case 'option':
				if ( ! current_user_can( 'manage_options' ) || ! VR_Tools::option_writable( (string) $snap['name'] ) ) {
					return new WP_Error( 'forbidden', 'Not allowed.' );
				}
				$result = $snap['existed'] ? update_option( $snap['name'], $snap['value'] ) || true : delete_option( $snap['name'] ) || true;
				break;
			case 'terms':
				if ( ! current_user_can( 'edit_post', (int) $snap['post_id'] ) ) {
					return new WP_Error( 'forbidden', 'Not allowed.' );
				}
				$result = wp_set_object_terms( (int) $snap['post_id'], array_map( 'intval', (array) $snap['term_ids'] ), $snap['taxonomy'], false );
				$result = is_wp_error( $result ) ? $result : true;
				break;
			case 'menu_item':
			case 'menu_item_delete':
				if ( ! current_user_can( 'edit_theme_options' ) ) {
					return new WP_Error( 'forbidden', 'Not allowed.' );
				}
				$item_id = 'menu_item' === $row['object_type'] ? (int) $row['object_id'] : 0;
				if ( $item_id && ! get_post( $item_id ) ) {
					return new WP_Error( 'missing', 'That menu item no longer exists.' );
				}
				$res    = wp_update_nav_menu_item( (int) $snap['menu_id'], $item_id, wp_slash( $snap['data'] ) );
				$result = is_wp_error( $res ) ? $res : true;
				break;
			case 'term_create':
				$tax_obj = get_taxonomy( $snap['taxonomy'] );
				if ( ! $tax_obj || ! current_user_can( $tax_obj->cap->delete_terms ) ) {
					return new WP_Error( 'forbidden', 'Not allowed.' );
				}
				$result = wp_delete_term( (int) $snap['term_id'], $snap['taxonomy'] );
				$result = is_wp_error( $result ) ? $result : true;
				break;
			default:
				return new WP_Error( 'unknown_type', 'Unknown change type.' );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$wpdb->update( VR_DB::table( 'changes' ), array( 'undone' => 1 ), array( 'id' => (int) $id ) );
		return array(
			'undone'  => (int) $id,
			'summary' => $row['summary'],
		);
	}

	/**
	 * Restore a post snapshot.
	 *
	 * @param array $snap Snapshot.
	 * @return true|WP_Error
	 */
	protected static function restore_post( array $snap ) {
		$post_id = (int) $snap['post_id'];
		if ( ! get_post( $post_id ) ) {
			return new WP_Error( 'missing', 'The post no longer exists.' );
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Not allowed to edit this post.' );
		}
		if ( ! empty( $snap['fields'] ) ) {
			$arr       = $snap['fields'];
			$arr['ID'] = $post_id;
			$res       = wp_update_post( wp_slash( $arr ), true );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		foreach ( (array) $snap['meta'] as $m ) {
			if ( $m['existed'] ) {
				update_post_meta( $post_id, $m['key'], wp_slash( $m['value'] ) );
			} else {
				delete_post_meta( $post_id, $m['key'] );
			}
		}
		clean_post_cache( $post_id );
		return true;
	}

	/**
	 * Undo a creation.
	 *
	 * @param int $post_id Post.
	 * @return true|WP_Error
	 */
	protected static function undo_create( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return true;
		}
		if ( ! current_user_can( 'delete_post', $post_id ) ) {
			return new WP_Error( 'forbidden', 'Not allowed to delete this post.' );
		}
		if ( 'attachment' === $post->post_type ) {
			return wp_delete_attachment( $post_id, true ) ? true : new WP_Error( 'failed', 'Could not remove the upload.' );
		}
		if ( 'nav_menu_item' === $post->post_type ) {
			return wp_delete_post( $post_id, true ) ? true : new WP_Error( 'failed', 'Could not remove the menu item.' );
		}
		return wp_trash_post( $post_id ) ? true : new WP_Error( 'failed', 'Could not move to trash.' );
	}
}
