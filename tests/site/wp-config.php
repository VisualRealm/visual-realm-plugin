<?php
define( 'DB_NAME', 'wp' );
define( 'DB_USER', '' );
define( 'DB_PASSWORD', '' );
define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' );
define( 'DB_COLLATE', '' );
define( 'DB_ENGINE', 'sqlite' );
$table_prefix = 'wp_';
define( 'AUTH_KEY', 'test-a1' );
define( 'SECURE_AUTH_KEY', 'test-a2' );
define( 'LOGGED_IN_KEY', 'test-a3' );
define( 'NONCE_KEY', 'test-a4' );
define( 'AUTH_SALT', 'test-b1' );
define( 'SECURE_AUTH_SALT', 'test-b2' );
define( 'LOGGED_IN_SALT', 'test-b3' );
define( 'NONCE_SALT', 'test-b4' );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}
require_once ABSPATH . 'wp-settings.php';
