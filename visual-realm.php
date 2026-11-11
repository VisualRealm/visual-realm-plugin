<?php
/**
 * Plugin Name:       Visual Realm
 * Plugin URI:        https://thevisualrealm.com
 * Description:       Secure connector that lets Visual Realm build and manage this site's pages, SEO and media.
 * Version:           0.1.3
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Visual Realm
 * Author URI:        https://thevisualrealm.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       visual-realm
 */

defined( 'ABSPATH' ) || exit;

define( 'VR_PLUGIN_VERSION', '0.1.3' );
define( 'VR_PLUGIN_FILE', __FILE__ );
define( 'VR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'VR_DB_VERSION', '1' );

require_once VR_PLUGIN_DIR . 'includes/class-vr-db.php';
require_once VR_PLUGIN_DIR . 'includes/class-vr-settings.php';
require_once VR_PLUGIN_DIR . 'includes/class-vr-changes.php';
require_once VR_PLUGIN_DIR . 'includes/class-vr-oauth.php';
require_once VR_PLUGIN_DIR . 'includes/class-vr-tools.php';
require_once VR_PLUGIN_DIR . 'includes/class-vr-mcp.php';
require_once VR_PLUGIN_DIR . 'includes/class-vr-admin.php';

register_activation_hook( __FILE__, array( 'VR_DB', 'install' ) );

add_action( 'plugins_loaded', 'vr_plugin_boot' );

/**
 * Boot the plugin.
 */
function vr_plugin_boot() {
	VR_DB::maybe_upgrade();
	VR_OAuth::init();
	VR_MCP::init();
	if ( is_admin() ) {
		VR_Admin::init();
	}
	add_action( 'vr_daily_cleanup', array( 'VR_DB', 'cleanup' ) );
	if ( ! wp_next_scheduled( 'vr_daily_cleanup' ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'vr_daily_cleanup' );
	}
}

register_deactivation_hook(
	__FILE__,
	function () {
		wp_clear_scheduled_hook( 'vr_daily_cleanup' );
	}
);
