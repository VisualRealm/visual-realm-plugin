<?php
/**
 * Database tables for OAuth clients, tokens and the change log.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_DB {

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name: clients, tokens, changes.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'vr_' . $name;
	}

	/**
	 * Create or update tables.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		$clients = self::table( 'clients' );
		$tokens  = self::table( 'tokens' );
		$changes = self::table( 'changes' );

		dbDelta(
			"CREATE TABLE {$clients} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			client_id varchar(255) NOT NULL,
			client_secret_hash char(64) NOT NULL DEFAULT '',
			client_name varchar(255) NOT NULL DEFAULT '',
			redirect_uris longtext NOT NULL,
			source varchar(20) NOT NULL DEFAULT 'dcr',
			created_at datetime NOT NULL,
			fetched_at datetime NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY client_id (client_id(191))
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$tokens} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			token_hash char(64) NOT NULL,
			token_type varchar(10) NOT NULL,
			family char(32) NOT NULL,
			client_id varchar(255) NOT NULL,
			user_id bigint(20) unsigned NOT NULL,
			scope varchar(100) NOT NULL DEFAULT 'mcp',
			resource varchar(255) NOT NULL DEFAULT '',
			expires_at datetime NOT NULL,
			created_at datetime NOT NULL,
			last_used_at datetime NULL,
			revoked tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY token_hash (token_hash),
			KEY family (family),
			KEY user_id (user_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$changes} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tool varchar(64) NOT NULL,
			object_type varchar(20) NOT NULL,
			object_id varchar(191) NOT NULL DEFAULT '',
			summary text NOT NULL,
			snapshot longtext NOT NULL,
			undoable tinyint(1) NOT NULL DEFAULT 1,
			undone tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
			) {$charset};"
		);

		update_option( 'vr_db_version', VR_DB_VERSION, false );
	}

	/**
	 * Run install when the schema version changes.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'vr_db_version' ) !== VR_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Prune expired tokens, stale clients and old change-log rows.
	 */
	public static function cleanup() {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'tokens' ) . ' WHERE expires_at < %s', self::now( -DAY_IN_SECONDS ) ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table( 'changes' ) . ' WHERE created_at < %s', self::now( -90 * DAY_IN_SECONDS ) ) );
		// Registered clients that never received a token within 30 days.
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . self::table( 'clients' ) . ' WHERE created_at < %s AND client_id NOT IN ( SELECT client_id FROM ' . self::table( 'tokens' ) . ' )',
				self::now( -30 * DAY_IN_SECONDS )
			)
		);
	}

	/**
	 * Current UTC datetime string.
	 *
	 * @param int $offset Seconds to add.
	 * @return string
	 */
	public static function now( $offset = 0 ) {
		return gmdate( 'Y-m-d H:i:s', time() + $offset );
	}
}
