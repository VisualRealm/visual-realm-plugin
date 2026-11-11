<?php
// Router for PHP's built-in server: serve real files, send everything else to WordPress.
$root = __DIR__ . '/wp';
$path = parse_url( $_SERVER['REQUEST_URI'], PHP_URL_PATH );
// Simulate SiteGround: the web server answers anything under the root /.well-known/ itself.
if ( getenv( 'SIMULATE_SITEGROUND' ) && 0 === strpos( $path, '/.well-known/' ) ) {
	http_response_code( 404 );
	echo '<html><body>404 Not Found (host)</body></html>';
	return true;
}
if ( '/' !== $path && is_file( $root . $path ) && ! preg_match( '/\.php$/', $path ) ) {
	return false;
}
if ( preg_match( '/\.php$/', $path ) && is_file( $root . $path ) ) {
	chdir( dirname( $root . $path ) );
	$_SERVER['SCRIPT_NAME']     = $path;
	$_SERVER['SCRIPT_FILENAME'] = $root . $path;
	require $root . $path;
	return true;
}
chdir( $root );
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = $root . '/index.php';
require $root . '/index.php';
