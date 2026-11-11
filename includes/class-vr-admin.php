<?php
/**
 * Visual Realm admin screen (top-level menu).
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_Admin {

	const SLUG = 'visual-realm';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_vr_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'admin_post_vr_revoke', array( __CLASS__, 'revoke' ) );
		add_action( 'admin_post_vr_undo', array( __CLASS__, 'undo' ) );
		add_action( 'admin_post_vr_check_updates', array( __CLASS__, 'check_updates' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( VR_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Add the settings page.
	 */
	public static function menu() {
		add_menu_page( 'Visual Realm', 'Visual Realm', 'manage_options', self::SLUG, array( __CLASS__, 'render' ), self::menu_icon(), 3 );
	}

	/**
	 * Admin URL of the plugin page.
	 *
	 * @return string
	 */
	public static function page_url() {
		return admin_url( 'admin.php?page=' . self::SLUG );
	}

	/**
	 * Menu icon: the bundled logo as a data URI so WordPress tints it to the admin color scheme.
	 *
	 * @return string
	 */
	protected static function menu_icon() {
		$file = VR_PLUGIN_DIR . 'assets/menu-icon.svg';
		if ( is_readable( $file ) ) {
			return 'data:image/svg+xml;base64,' . base64_encode( (string) file_get_contents( $file ) ); // phpcs:ignore
		}
		return 'dashicons-visibility';
	}

	/**
	 * Full-color logo for the page header, if bundled.
	 *
	 * @return string
	 */
	protected static function header_logo() {
		$file = VR_PLUGIN_DIR . 'assets/logo.svg';
		if ( ! is_readable( $file ) ) {
			return '';
		}
		$svg = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! preg_match( '#^<svg[^>]*>.*</svg>\s*$#s', $svg ) || false !== stripos( $svg, '<script' ) || false !== stripos( $svg, ' on' ) ) {
			return '';
		}
		$svg = preg_replace( '#^<svg #', '<svg role="img" aria-label="Visual Realm" style="height:40px;width:40px;vertical-align:middle;margin-right:12px" ', $svg );
		return $svg;
	}

	/**
	 * Settings link on the Plugins screen.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::page_url() ) . '">' . esc_html__( 'Settings', 'visual-realm' ) . '</a>' );
		return $links;
	}

	/**
	 * Back to the page with a notice.
	 *
	 * @param string $msg Notice key.
	 */
	protected static function back( $msg ) {
		wp_safe_redirect( add_query_arg( 'vr_msg', $msg, self::page_url() ) );
		exit;
	}

	/**
	 * Save settings.
	 */
	public static function save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'visual-realm' ) );
		}
		check_admin_referer( 'vr_save_settings' );
		$cap = isset( $_POST['capability'] ) ? sanitize_key( wp_unslash( $_POST['capability'] ) ) : 'manage_options';
		if ( ! in_array( $cap, array( 'manage_options', 'edit_others_pages' ), true ) ) {
			$cap = 'manage_options';
		}
		$values = array(
			'capability'         => $cap,
			'redirect_uris'      => isset( $_POST['redirect_uris'] ) ? sanitize_textarea_field( wp_unslash( $_POST['redirect_uris'] ) ) : VR_Settings::CLAUDE_CALLBACK,
			'allow_loopback'     => empty( $_POST['allow_loopback'] ) ? 0 : 1,
			'cloudflare_zone_id' => isset( $_POST['cloudflare_zone_id'] ) ? sanitize_text_field( wp_unslash( $_POST['cloudflare_zone_id'] ) ) : '',
		);
		$token = isset( $_POST['cloudflare_token'] ) ? sanitize_text_field( wp_unslash( $_POST['cloudflare_token'] ) ) : '';
		if ( '' !== $token ) {
			$values['cloudflare_token'] = $token;
		}
		if ( ! empty( $_POST['cloudflare_clear'] ) ) {
			$values['cloudflare_token'] = '';
		}
		$update_token = isset( $_POST['update_token'] ) ? sanitize_text_field( wp_unslash( $_POST['update_token'] ) ) : '';
		if ( '' !== $update_token ) {
			$values['update_token'] = $update_token;
		}
		if ( ! empty( $_POST['update_clear'] ) ) {
			$values['update_token'] = '';
		}
		delete_transient( VR_Updater::TRANSIENT );
		VR_Settings::save( $values );
		self::back( 'saved' );
	}

	/**
	 * Revoke a connection.
	 */
	public static function revoke() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'visual-realm' ) );
		}
		check_admin_referer( 'vr_revoke' );
		$family = isset( $_POST['family'] ) ? sanitize_key( wp_unslash( $_POST['family'] ) ) : '';
		if ( '' !== $family ) {
			VR_OAuth::revoke_family( $family );
		}
		self::back( 'revoked' );
	}

	/**
	 * Check GitHub for a newer release now.
	 */
	public static function check_updates() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'visual-realm' ) );
		}
		check_admin_referer( 'vr_check_updates' );
		delete_transient( VR_Updater::TRANSIENT );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();
		$info = VR_Updater::latest();
		self::back( $info && version_compare( $info['version'], VR_PLUGIN_VERSION, '>' ) ? 'update_found' : 'up_to_date' );
	}

	/**
	 * Undo a change from the log.
	 */
	public static function undo() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'visual-realm' ) );
		}
		check_admin_referer( 'vr_undo' );
		$id  = isset( $_POST['change'] ) ? absint( $_POST['change'] ) : 0;
		$res = VR_Changes::undo( $id );
		self::back( is_wp_error( $res ) ? 'undo_failed' : 'undone' );
	}

	/**
	 * Active connections, one row per grant.
	 *
	 * @return array
	 */
	protected static function connections() {
		global $wpdb;
		$t    = VR_DB::table( 'tokens' );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT family, client_id, user_id, MIN(created_at) AS created_at, MAX(last_used_at) AS last_used_at FROM {$t} WHERE revoked = 0 AND expires_at > %s GROUP BY family, client_id, user_id ORDER BY created_at DESC",
				VR_DB::now()
			),
			ARRAY_A
		);
		foreach ( $rows as &$r ) {
			$c             = VR_OAuth::get_client( $r['client_id'] );
			$r['app']      = $c && '' !== $c['client_name'] ? $c['client_name'] : $r['client_id'];
			$u             = get_userdata( (int) $r['user_id'] );
			$r['user']     = $u ? $u->user_login : '#' . $r['user_id'];
		}
		return $rows;
	}

	/**
	 * Format a UTC datetime in site time.
	 *
	 * @param string|null $utc UTC datetime.
	 * @return string
	 */
	protected static function when( $utc ) {
		if ( empty( $utc ) ) {
			return '—';
		}
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $utc . ' UTC' ) );
	}

	/**
	 * Render the page.
	 */
	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s       = VR_Settings::get();
		$msg     = isset( $_GET['vr_msg'] ) ? sanitize_key( wp_unslash( $_GET['vr_msg'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification
		$notices = array(
			'saved'       => array( 'success', __( 'Settings saved.', 'visual-realm' ) ),
			'revoked'     => array( 'success', __( 'Connection removed.', 'visual-realm' ) ),
			'undone'      => array( 'success', __( 'Change undone.', 'visual-realm' ) ),
			'undo_failed' => array( 'error', __( 'That change could not be undone.', 'visual-realm' ) ),
			'up_to_date'  => array( 'success', __( 'You have the latest version.', 'visual-realm' ) ),
			'update_found' => array( 'warning', __( 'A new version is available. Update it from the Plugins screen.', 'visual-realm' ) ),
		);
		?>
		<div class="wrap">
			<h1><?php echo self::header_logo(); // phpcs:ignore WordPress.Security.EscapeOutput -- escaped in header_logo(). ?>Visual Realm</h1>
			<?php if ( isset( $notices[ $msg ] ) ) : ?>
				<div class="notice notice-<?php echo esc_attr( $notices[ $msg ][0] ); ?> is-dismissible"><p><?php echo esc_html( $notices[ $msg ][1] ); ?></p></div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Connection URL', 'visual-realm' ); ?></h2>
			<p><?php esc_html_e( 'Add this URL as a custom connector. You will be asked to sign in and approve the connection.', 'visual-realm' ); ?></p>
			<p><input type="text" class="large-text code" readonly value="<?php echo esc_attr( VR_MCP::endpoint_url() ); ?>" onclick="this.select()"></p>

			<h2><?php esc_html_e( 'Active connections', 'visual-realm' ); ?></h2>
			<?php $conns = self::connections(); ?>
			<?php if ( empty( $conns ) ) : ?>
				<p><?php esc_html_e( 'No active connections.', 'visual-realm' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th><?php esc_html_e( 'App', 'visual-realm' ); ?></th><th><?php esc_html_e( 'User', 'visual-realm' ); ?></th><th><?php esc_html_e( 'Connected', 'visual-realm' ); ?></th><th><?php esc_html_e( 'Last used', 'visual-realm' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $conns as $c ) : ?>
						<tr>
							<td><?php echo esc_html( $c['app'] ); ?></td>
							<td><?php echo esc_html( $c['user'] ); ?></td>
							<td><?php echo esc_html( self::when( $c['created_at'] ) ); ?></td>
							<td><?php echo esc_html( self::when( $c['last_used_at'] ) ); ?></td>
							<td>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'vr_revoke' ); ?>
									<input type="hidden" name="action" value="vr_revoke">
									<input type="hidden" name="family" value="<?php echo esc_attr( $c['family'] ); ?>">
									<button class="button" type="submit"><?php esc_html_e( 'Disconnect', 'visual-realm' ); ?></button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Recent changes', 'visual-realm' ); ?></h2>
			<?php $changes = VR_Changes::recent( 25 ); ?>
			<?php if ( empty( $changes ) ) : ?>
				<p><?php esc_html_e( 'No changes yet.', 'visual-realm' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead><tr><th>#</th><th><?php esc_html_e( 'When', 'visual-realm' ); ?></th><th><?php esc_html_e( 'User', 'visual-realm' ); ?></th><th><?php esc_html_e( 'Change', 'visual-realm' ); ?></th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $changes as $c ) : ?>
						<tr>
							<td><?php echo (int) $c['id']; ?></td>
							<td><?php echo esc_html( self::when( str_replace( ' UTC', '', $c['created_at'] ) ) ); ?></td>
							<td><?php echo esc_html( $c['user'] ); ?></td>
							<td><?php echo esc_html( $c['summary'] ); ?><?php echo $c['undone'] ? ' <em>(' . esc_html__( 'undone', 'visual-realm' ) . ')</em>' : ''; ?></td>
							<td>
								<?php if ( $c['undoable'] ) : ?>
								<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
									<?php wp_nonce_field( 'vr_undo' ); ?>
									<input type="hidden" name="action" value="vr_undo">
									<input type="hidden" name="change" value="<?php echo (int) $c['id']; ?>">
									<button class="button" type="submit"><?php esc_html_e( 'Undo', 'visual-realm' ); ?></button>
								</form>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'visual-realm' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'vr_save_settings' ); ?>
				<input type="hidden" name="action" value="vr_save_settings">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="vr-cap"><?php esc_html_e( 'Who can connect', 'visual-realm' ); ?></label></th>
						<td><select id="vr-cap" name="capability">
							<option value="manage_options" <?php selected( $s['capability'], 'manage_options' ); ?>><?php esc_html_e( 'Administrators', 'visual-realm' ); ?></option>
							<option value="edit_others_pages" <?php selected( $s['capability'], 'edit_others_pages' ); ?>><?php esc_html_e( 'Editors and administrators', 'visual-realm' ); ?></option>
						</select></td>
					</tr>
					<tr>
						<th scope="row"><label for="vr-uris"><?php esc_html_e( 'Allowed return addresses', 'visual-realm' ); ?></label></th>
						<td><textarea id="vr-uris" name="redirect_uris" rows="3" class="large-text code"><?php echo esc_textarea( $s['redirect_uris'] ); ?></textarea>
						<p class="description"><?php esc_html_e( 'Exact https addresses, one per line. Claude (claude.ai, desktop and mobile) is always allowed.', 'visual-realm' ); ?></p>
						<label><input type="checkbox" name="allow_loopback" value="1" <?php checked( (int) $s['allow_loopback'], 1 ); ?>> <?php esc_html_e( 'Allow Claude Code (returns to http://localhost/callback on your computer)', 'visual-realm' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><label for="vr-cfz"><?php esc_html_e( 'Cloudflare zone ID', 'visual-realm' ); ?></label></th>
						<td><input id="vr-cfz" type="text" class="regular-text code" name="cloudflare_zone_id" value="<?php echo esc_attr( $s['cloudflare_zone_id'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="vr-cft"><?php esc_html_e( 'Cloudflare API token', 'visual-realm' ); ?></label></th>
						<td><input id="vr-cft" type="password" class="regular-text code" name="cloudflare_token" value="" autocomplete="off" placeholder="<?php echo '' !== $s['cloudflare_token'] ? esc_attr__( 'Saved — leave blank to keep', 'visual-realm' ) : ''; ?>">
						<?php if ( '' !== $s['cloudflare_token'] ) : ?>
							<label><input type="checkbox" name="cloudflare_clear" value="1"> <?php esc_html_e( 'Remove saved token', 'visual-realm' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Token needs Zone → Cache Purge permission for this zone only.', 'visual-realm' ); ?></p></td>
					</tr>
					<tr>
						<th scope="row"><label for="vr-upt"><?php esc_html_e( 'Update access token', 'visual-realm' ); ?></label></th>
						<td><input id="vr-upt" type="password" class="regular-text code" name="update_token" value="" autocomplete="off" placeholder="<?php echo '' !== $s['update_token'] ? esc_attr__( 'Saved — leave blank to keep', 'visual-realm' ) : ''; ?>">
						<?php if ( '' !== $s['update_token'] ) : ?>
							<label><input type="checkbox" name="update_clear" value="1"> <?php esc_html_e( 'Remove saved token', 'visual-realm' ); ?></label>
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Only needed while the update source is private: a read-only GitHub token for the plugin repository.', 'visual-realm' ); ?></p></td>
					</tr>
				</table>
				<?php submit_button(); ?>
			</form>

			<h2><?php esc_html_e( 'Version', 'visual-realm' ); ?></h2>
			<?php $latest = get_transient( VR_Updater::TRANSIENT ); ?>
			<p><?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Installed: %s', 'visual-realm' ), VR_PLUGIN_VERSION ) ); ?>
			<?php if ( is_array( $latest ) && ! empty( $latest['version'] ) ) : ?>
				· <?php echo esc_html( sprintf( /* translators: %s: version */ __( 'Latest release: %s', 'visual-realm' ), $latest['version'] ) ); ?>
			<?php elseif ( is_array( $latest ) && isset( $latest['status'] ) && 200 !== (int) $latest['status'] ) : ?>
				· <?php echo esc_html( sprintf( /* translators: %d: HTTP status */ __( 'Update check failed (HTTP %d). Check the update access token.', 'visual-realm' ), (int) $latest['status'] ) ); ?>
			<?php endif; ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'vr_check_updates' ); ?>
				<input type="hidden" name="action" value="vr_check_updates">
				<button class="button" type="submit"><?php esc_html_e( 'Check for updates', 'visual-realm' ); ?></button>
			</form>
		</div>
		<?php
	}
}
