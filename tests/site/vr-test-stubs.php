<?php
/**
 * Local test stand-ins for WP Rocket, Avada and the GitHub releases API.
 * Every call is recorded in the vr_test_calls option so tests can assert on it.
 */

function vr_test_log( $what ) {
	$calls   = get_option( 'vr_test_calls', array() );
	$calls[] = $what;
	update_option( 'vr_test_calls', $calls, false );
}

if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
	define( 'WP_ROCKET_VERSION', '3.24-test' );
}
function rocket_clean_domain() { vr_test_log( 'rocket_clean_domain' ); }
function rocket_clean_minify() { vr_test_log( 'rocket_clean_minify' ); }
function rocket_clean_post( $id ) { vr_test_log( 'rocket_clean_post:' . $id ); }
function fusion_reset_all_caches() { vr_test_log( 'fusion_reset_all_caches' ); }

add_action(
	'init',
	function () {
		register_post_type( 'fusion_tb_section', array( 'public' => false, 'show_ui' => true, 'label' => 'Layout Sections' ) );
		if ( false === get_option( 'wp_rocket_settings' ) ) {
			update_option( 'wp_rocket_settings', array( 'remove_unused_css' => 1 ) );
		}
		global $wpdb;
		$t = $wpdb->prefix . 'wpr_rucss_used_css';
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$t} ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, url varchar(2000) NOT NULL, css longtext, PRIMARY KEY (id) )" );
	}
);

// The test release zip is served from localhost, which WordPress blocks by default.
add_filter( 'http_request_host_is_external', function ( $ok, $host ) { return 'localhost' === $host ? true : $ok; }, 10, 2 );

// Fake GitHub "latest release" when a test release file exists.
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.github.com/repos/VisualRealm/visual-realm-plugin/releases/latest' ) ) {
			return $pre;
		}
		$file = ABSPATH . 'release/release.json';
		if ( ! file_exists( $file ) ) {
			return array( 'headers' => array(), 'body' => '{"message":"Not Found"}', 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'cookies' => array(), 'filename' => null );
		}
		vr_test_log( 'github_api:' . ( isset( $args['headers']['Authorization'] ) ? 'auth' : 'noauth' ) );
		return array( 'headers' => array(), 'body' => file_get_contents( $file ), 'response' => array( 'code' => 200, 'message' => 'OK' ), 'cookies' => array(), 'filename' => null );
	},
	10,
	3
);

// Content the connector must never reach, plus one type an admin may opt in.
add_action(
	'init',
	function () {
		register_post_type( 'shop_order', array( 'public' => false, 'show_ui' => true, 'label' => 'Orders' ) );
		register_post_type( 'secret_cpt', array( 'public' => true, 'show_ui' => true, 'label' => 'Secret' ) );
	}
);

// Record outgoing mail instead of sending it.
add_filter(
	'pre_wp_mail',
	function ( $null, $atts ) {
		vr_test_log( 'mail:' . implode( ',', (array) $atts['to'] ) . '|' . $atts['subject'] . '|' . $atts['message'] );
		return true;
	},
	10,
	2
);
