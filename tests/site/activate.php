<?php
// CLI: activate the plugin on the test site and create a second, non-admin user.
$_SERVER['HTTP_HOST']   = 'localhost:8890';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
$r = activate_plugin( 'visual-realm/visual-realm.php' );
echo is_wp_error( $r ) ? 'ERR ' . $r->get_error_message() : 'active';
echo "\n";
if ( ! get_user_by( 'login', 'author1' ) ) {
	wp_create_user( 'author1', 'author-test-pass', 'author1@example.com' );
	( new WP_User( get_user_by( 'login', 'author1' )->ID ) )->set_role( 'author' );
}
global $wpdb;
echo implode( ',', $wpdb->get_col( "SELECT name FROM sqlite_master WHERE type='table' AND name LIKE 'wp_vr_%'" ) ) . "\n";
