<?php
/**
 * Remove all plugin data. Deleting the plugin ends every connection.
 *
 * @package VisualRealm
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
foreach ( array( 'clients', 'tokens', 'changes' ) as $t ) {
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'vr_' . $t ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
delete_option( 'vr_settings' );
delete_option( 'vr_db_version' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_vr\\_%' OR option_name LIKE '\\_transient\\_timeout\\_vr\\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_clear_scheduled_hook( 'vr_daily_cleanup' );
