<?php
// Local test-site installer (CLI only).
define( 'WP_INSTALLING', true );
$_SERVER['HTTP_HOST']   = 'localhost:8890';
$_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/wp/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) {
	wp_install( 'VR Test', 'admin', 'kyle@example.com', true, '', 'admin-test-pass' );
}
update_option( 'siteurl', 'http://localhost:8890' );
update_option( 'home', 'http://localhost:8890' );
update_option( 'permalink_structure', '/%postname%/' );
echo "installed: " . get_option( 'siteurl' ) . "\n";
