<?php
/**
 * Who and what may reach the connector: Claude's server addresses, named
 * accounts, the post types tools can touch, per-site tool switches, and
 * connection alerts.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_Access {

	/**
	 * Anthropic's published outbound ranges (MCP connector traffic).
	 */
	const ANTHROPIC = array( '160.79.104.0/21', '153.61.208.0/20' );

	/**
	 * Cloudflare edge ranges. CF-Connecting-IP is only trusted from these.
	 */
	const CLOUDFLARE = array(
		'173.245.48.0/20',
		'103.21.244.0/22',
		'103.22.200.0/22',
		'103.31.4.0/22',
		'141.101.64.0/18',
		'108.162.192.0/18',
		'190.93.240.0/20',
		'188.114.96.0/20',
		'197.234.240.0/22',
		'198.41.128.0/17',
		'162.158.0.0/15',
		'104.16.0.0/13',
		'104.24.0.0/14',
		'172.64.0.0/13',
		'131.0.72.0/22',
		'2400:cb00::/32',
		'2606:4700::/32',
		'2803:f800::/32',
		'2405:b500::/32',
		'2405:8100::/32',
		'2a06:98c0::/29',
		'2c0f:f248::/32',
	);

	/**
	 * Content the tools may read and change. Everything else is walled off.
	 */
	const POST_TYPES = array(
		'page',
		'post',
		'attachment',
		'nav_menu_item',
		'wp_block',
		'wp_template_part',
		'product',
		'product_variation',
		'avada_portfolio',
		'avada_faq',
		'fusion_tb_section',
		'fusion_tb_layout',
		'fusion_element',
		'fusion_template',
		'slide',
	);

	/**
	 * Post types that hold submissions, orders or people and can never be
	 * added, whatever the settings say.
	 */
	const NEVER_TYPES = '/(order|entr(y|ies)|submission|^nf_sub$|flamingo|user_request|subscri|payment|invoice|applica|lead|customer|message|inbox)/i';

	/**
	 * Options that belong to form plugins (entries, keys, notification settings).
	 */
	const FORM_OPTIONS = '/^(rg_|gform|gf_|gravity|gpaee|gp_|gwiz|wpforms|wpcf7|ninja_forms|nf_|frm_|forminator|fluentform|_fluentform|__fluentform|everest_forms|evf_|caldera|formidable|happyforms|jetpack_forms)/';

	/**
	 * Upload folders form plugins write to.
	 */
	const FORM_DIRS = '#^(gravity_forms|gravityforms|wpforms|wpcf7_uploads|ninja-forms|formidable|forminator|fluentform|everest_forms_uploads|caldera-forms)(/|$)#i';

	/**
	 * Tools that cannot be switched off.
	 */
	const ALWAYS_ON = array( 'mcp_ping' );

	/* ---------------------------------------------------------------------
	 * Network
	 * ------------------------------------------------------------------- */

	/**
	 * Normalize an address string (IPv4-mapped IPv6 becomes IPv4).
	 *
	 * @param string $ip IP.
	 * @return string Empty when invalid.
	 */
	protected static function clean_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( preg_match( '/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $ip, $m ) ) {
			$ip = $m[1];
		}
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '';
	}

	/**
	 * The address a request came from. Cloudflare's visitor header is used
	 * only when the connection itself comes from a Cloudflare edge.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$remote = self::clean_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' ); // phpcs:ignore
		if ( '' !== $remote && ! empty( $_SERVER['HTTP_CF_CONNECTING_IP'] ) && self::in_ranges( $remote, self::CLOUDFLARE ) ) {
			$cf = self::clean_ip( wp_unslash( $_SERVER['HTTP_CF_CONNECTING_IP'] ) ); // phpcs:ignore
			if ( '' !== $cf ) {
				return $cf;
			}
		}
		return $remote;
	}

	/**
	 * Whether an address is inside a CIDR range (IPv4 or IPv6).
	 *
	 * @param string $ip   IP.
	 * @param string $cidr Range, or a single address.
	 * @return bool
	 */
	public static function ip_in( $ip, $cidr ) {
		$cidr = trim( (string) $cidr );
		if ( false === strpos( $cidr, '/' ) ) {
			$cidr .= false !== strpos( $cidr, ':' ) ? '/128' : '/32';
		}
		list( $net, $bits ) = explode( '/', $cidr, 2 );
		$ipb  = @inet_pton( self::clean_ip( $ip ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		$netb = @inet_pton( trim( $net ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( ! $ipb || ! $netb || strlen( $ipb ) !== strlen( $netb ) || ! ctype_digit( $bits ) ) {
			return false;
		}
		$bits = (int) $bits;
		if ( $bits > strlen( $ipb ) * 8 ) {
			return false;
		}
		$whole = intdiv( $bits, 8 );
		$rest  = $bits % 8;
		if ( $whole && substr( $ipb, 0, $whole ) !== substr( $netb, 0, $whole ) ) {
			return false;
		}
		if ( $rest ) {
			$mask = chr( ( 0xff << ( 8 - $rest ) ) & 0xff );
			if ( ( $ipb[ $whole ] & $mask ) !== ( $netb[ $whole ] & $mask ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Whether an address is in any of the ranges.
	 *
	 * @param string   $ip     IP.
	 * @param string[] $ranges Ranges.
	 * @return bool
	 */
	public static function in_ranges( $ip, array $ranges ) {
		foreach ( $ranges as $r ) {
			if ( self::ip_in( $ip, $r ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Parse and validate the extra ranges setting.
	 *
	 * @param string $text Text.
	 * @return string[]
	 */
	public static function parse_ranges( $text ) {
		$out = array();
		foreach ( preg_split( '/[\s,]+/', (string) $text ) as $r ) {
			$r = trim( $r );
			if ( '' === $r ) {
				continue;
			}
			$parts = explode( '/', $r, 2 );
			$ip    = self::clean_ip( $parts[0] );
			if ( '' === $ip ) {
				continue;
			}
			$max = false !== strpos( $ip, ':' ) ? 128 : 32;
			if ( isset( $parts[1] ) ) {
				// Very wide ranges defeat the purpose of the allowlist.
				if ( ! ctype_digit( $parts[1] ) || (int) $parts[1] > $max || (int) $parts[1] < ( 128 === $max ? 32 : 16 ) ) {
					continue;
				}
				$out[] = $ip . '/' . (int) $parts[1];
			} else {
				$out[] = $ip;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Ranges allowed to call the connector.
	 *
	 * @return string[]
	 */
	public static function server_ranges() {
		return array_merge( self::ANTHROPIC, self::parse_ranges( VR_Settings::get( 'extra_ips' ) ) );
	}

	/**
	 * Whether this request may reach the server-to-server endpoints (MCP,
	 * token, registration, revocation). Blocks are logged for diagnosis.
	 *
	 * @param string $where Endpoint name for the log.
	 * @return bool
	 */
	public static function server_allowed( $where ) {
		if ( ! (int) VR_Settings::get( 'restrict_ips' ) ) {
			return true;
		}
		$ip = self::client_ip();
		if ( '' !== $ip && self::in_ranges( $ip, self::server_ranges() ) ) {
			return true;
		}
		self::log_block( $ip, $where );
		return false;
	}

	/**
	 * Remember a blocked request (deduplicated per address for 5 minutes).
	 *
	 * @param string $ip    Address used for the decision.
	 * @param string $where Endpoint.
	 */
	protected static function log_block( $ip, $where ) {
		$key = 'vr_blk_' . md5( $ip . '|' . $where );
		if ( get_transient( $key ) ) {
			return;
		}
		set_transient( $key, 1, 5 * MINUTE_IN_SECONDS );
		$log   = (array) get_option( 'vr_blocked_log', array() );
		$entry = array(
			'time'   => time(),
			'ip'     => $ip,
			'remote' => self::clean_ip( isset( $_SERVER['REMOTE_ADDR'] ) ? wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '' ), // phpcs:ignore
			'where'  => sanitize_key( $where ),
		);
		array_unshift( $log, $entry );
		update_option( 'vr_blocked_log', array_slice( $log, 0, 20 ), false );
	}

	/**
	 * Recent blocked requests, newest first.
	 *
	 * @return array
	 */
	public static function blocked_log() {
		$log = get_option( 'vr_blocked_log', array() );
		return is_array( $log ) ? $log : array();
	}

	/* ---------------------------------------------------------------------
	 * Accounts
	 * ------------------------------------------------------------------- */

	/**
	 * IDs of the accounts allowed to connect.
	 *
	 * @return int[]
	 */
	public static function allowed_users() {
		$ids = VR_Settings::get( 'allowed_users' );
		return array_values( array_unique( array_filter( array_map( 'absint', is_array( $ids ) ? $ids : array() ) ) ) );
	}

	/**
	 * Whether an account may connect and keep using a connection: it must be
	 * named in the settings and still hold the required role.
	 *
	 * @param WP_User|int $user User.
	 * @return bool
	 */
	public static function user_allowed( $user ) {
		$user = $user instanceof WP_User ? $user : get_userdata( (int) $user );
		if ( ! $user || ! $user->exists() ) {
			return false;
		}
		return in_array( (int) $user->ID, self::allowed_users(), true ) && user_can( $user, VR_Settings::get( 'capability' ) );
	}

	/**
	 * Accounts that could be named (they hold the required role).
	 *
	 * @return WP_User[]
	 */
	public static function eligible_users() {
		return get_users(
			array(
				'capability' => VR_Settings::get( 'capability' ),
				'number'     => 200,
				'orderby'    => 'login',
			)
		);
	}

	/**
	 * Change the named accounts. Connections of removed accounts are ended.
	 *
	 * @param int[] $ids IDs.
	 */
	public static function set_allowed_users( array $ids ) {
		global $wpdb;
		$before = self::allowed_users();
		$ids    = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		VR_Settings::save( array( 'allowed_users' => $ids ) );
		$removed = array_diff( $before, $ids );
		foreach ( $removed as $uid ) {
			$wpdb->update( VR_DB::table( 'tokens' ), array( 'revoked' => 1 ), array( 'user_id' => (int) $uid ) );
		}
		$added = array_diff( $ids, $before );
		if ( $added || $removed ) {
			$names = function ( $list ) {
				return implode( ', ', array_map( function ( $id ) {
					$u = get_userdata( $id );
					return $u ? $u->user_login : '#' . $id;
				}, $list ) );
			};
			$lines = array();
			if ( $added ) {
				$lines[] = 'Added: ' . $names( $added );
			}
			if ( $removed ) {
				$lines[] = 'Removed: ' . $names( $removed );
			}
			$who = wp_get_current_user();
			self::alert(
				'Accounts allowed to connect changed',
				array_merge(
					$lines,
					array(
						'Changed by: ' . ( $who && $who->exists() ? $who->user_login : 'system' ),
						'From address: ' . self::client_ip(),
					)
				),
				array_unique( array_merge( $before, $ids ) )
			);
		}
	}

	/**
	 * One-time setup for sites upgrading from 0.2.x, and first activation:
	 * name the accounts already connected (plus the admin doing the update)
	 * and shorten long-lived refresh tokens.
	 */
	public static function maybe_migrate() {
		if ( (int) get_option( 'vr_access_rev' ) >= 3 ) {
			return;
		}
		global $wpdb;
		$ids = self::allowed_users();
		if ( empty( $ids ) ) {
			$table = VR_DB::table( 'tokens' );
			if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
				$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT user_id FROM {$table} WHERE revoked = 0 AND expires_at > %s", VR_DB::now() ) ) ); // phpcs:ignore WordPress.DB
			}
			$me = get_current_user_id();
			if ( $me && user_can( $me, VR_Settings::get( 'capability' ) ) ) {
				$ids[] = $me;
			}
			VR_Settings::save( array( 'allowed_users' => array_values( array_unique( array_filter( $ids ) ) ) ) );
		}
		$ttl = 30 * DAY_IN_SECONDS;
		VR_Settings::save( array( 'refresh_ttl' => $ttl ) );
		$table = VR_DB::table( 'tokens' );
		$wpdb->query( $wpdb->prepare( "UPDATE {$table} SET expires_at = %s WHERE token_type = 'refresh' AND expires_at > %s", VR_DB::now( $ttl ), VR_DB::now( $ttl ) ) ); // phpcs:ignore WordPress.DB
		update_option( 'vr_access_rev', 3, false );
	}

	/**
	 * On activation, the activating admin is named if nobody is yet.
	 */
	public static function on_activate() {
		$me = get_current_user_id();
		if ( empty( self::allowed_users() ) && $me && user_can( $me, VR_Settings::get( 'capability' ) ) ) {
			VR_Settings::save( array( 'allowed_users' => array( $me ) ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Content walls
	 * ------------------------------------------------------------------- */

	/**
	 * Post types the tools may use.
	 *
	 * @return string[]
	 */
	public static function post_types() {
		$types = self::POST_TYPES;
		foreach ( preg_split( '/[\s,]+/', (string) VR_Settings::get( 'extra_post_types' ) ) as $t ) {
			$t = sanitize_key( $t );
			if ( '' !== $t ) {
				$types[] = $t;
			}
		}
		$types = array_filter(
			$types,
			function ( $t ) {
				return ! preg_match( self::NEVER_TYPES, $t );
			}
		);
		return array_values( array_unique( $types ) );
	}

	/**
	 * Whether the tools may use a post type.
	 *
	 * @param string $type Type.
	 * @return bool
	 */
	public static function post_type_allowed( $type ) {
		return is_string( $type ) && in_array( $type, self::post_types(), true );
	}

	/**
	 * Whether an option belongs to a form plugin.
	 *
	 * @param string $name Option.
	 * @return bool
	 */
	public static function is_form_option( $name ) {
		return (bool) preg_match( self::FORM_OPTIONS, (string) $name );
	}

	/**
	 * Whether a file (relative to the uploads root) is a media library item
	 * or one of its generated sizes.
	 *
	 * @param string $rel Relative path, forward slashes.
	 * @return bool
	 */
	public static function is_media_file( $rel ) {
		global $wpdb;
		$rel = ltrim( str_replace( '\\', '/', (string) $rel ), '/' );
		if ( '' === $rel || preg_match( self::FORM_DIRS, $rel ) ) {
			return false;
		}
		$dir  = dirname( $rel );
		$dir  = '.' === $dir ? '' : $dir . '/';
		$name = basename( $rel );
		$ext  = pathinfo( $name, PATHINFO_EXTENSION );
		$stem = '' === $ext ? $name : substr( $name, 0, -strlen( $ext ) - 1 );
		$base = preg_replace( '/-\d+x\d+$/', '', $stem );
		$dot  = '' === $ext ? '' : '.' . $ext;
		$cand = array_unique( array( $rel, $dir . $base . $dot, $dir . $base . '-scaled' . $dot, $dir . $base . '-rotated' . $dot ) );

		$place = implode( ',', array_fill( 0, count( $cand ), '%s' ) );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value IN ({$place})", $cand ) ); // phpcs:ignore WordPress.DB
		foreach ( $ids as $id ) {
			if ( 'attachment' !== get_post_type( (int) $id ) ) {
				continue;
			}
			$attached = (string) get_post_meta( (int) $id, '_wp_attached_file', true );
			if ( $attached === $rel ) {
				return true;
			}
			$meta = wp_get_attachment_metadata( (int) $id );
			if ( ! is_array( $meta ) || dirname( $attached ) !== dirname( $rel ) ) {
				continue;
			}
			if ( isset( $meta['original_image'] ) && $meta['original_image'] === $name ) {
				return true;
			}
			foreach ( isset( $meta['sizes'] ) && is_array( $meta['sizes'] ) ? $meta['sizes'] : array() as $size ) {
				if ( isset( $size['file'] ) && $size['file'] === $name ) {
					return true;
				}
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Tool switches
	 * ------------------------------------------------------------------- */

	/**
	 * Tools switched off on this site.
	 *
	 * @return string[]
	 */
	public static function disabled_tools() {
		$list = VR_Settings::get( 'disabled_tools' );
		return array_values( array_diff( array_map( 'sanitize_key', is_array( $list ) ? $list : array() ), self::ALWAYS_ON ) );
	}

	/**
	 * Whether a tool is switched on.
	 *
	 * @param string $name Tool.
	 * @return bool
	 */
	public static function tool_enabled( $name ) {
		return ! in_array( $name, self::disabled_tools(), true );
	}

	/* ---------------------------------------------------------------------
	 * Alerts
	 * ------------------------------------------------------------------- */

	/**
	 * Email the named accounts.
	 *
	 * @param string $subject Subject (site name is prefixed).
	 * @param array  $lines   Body lines.
	 * @param int[]  $users   Recipients (defaults to the named accounts).
	 */
	public static function alert( $subject, array $lines, array $users = array() ) {
		$to = array();
		foreach ( $users ? $users : self::allowed_users() as $id ) {
			$u = get_userdata( (int) $id );
			if ( $u && is_email( $u->user_email ) ) {
				$to[] = $u->user_email;
			}
		}
		$to = array_values( array_unique( $to ) );
		if ( empty( $to ) ) {
			return;
		}
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$body = implode( "\n", array_merge( array( 'Site: ' . $site . ' (' . home_url() . ')' ), $lines, array( '', 'Review or end connections: ' . admin_url( 'admin.php?page=visual-realm' ) ) ) );
		wp_mail( $to, '[' . $site . '] ' . $subject, $body );
	}

	/**
	 * Alert that a connection was approved.
	 *
	 * @param WP_User $user     Approving account.
	 * @param array   $client   Client.
	 * @param string  $redirect Return address.
	 */
	public static function notify_connection( WP_User $user, array $client, $redirect ) {
		self::alert(
			'New Visual Realm connection approved',
			array(
				'Account: ' . $user->user_login,
				'App: ' . ( '' !== $client['client_name'] ? $client['client_name'] : $client['client_id'] ),
				'Returns to: ' . $redirect,
				'Approved from address: ' . self::client_ip(),
				'Time: ' . wp_date( 'Y-m-d H:i:s T' ),
				'',
				'If this was not you, disconnect it now and change the account password.',
			)
		);
	}
}
