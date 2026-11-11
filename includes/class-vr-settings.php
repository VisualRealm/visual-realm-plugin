<?php
/**
 * Plugin settings.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_Settings {

	const OPTION = 'vr_settings';

	/**
	 * The callback every hosted Claude app (web, desktop, mobile) uses.
	 */
	const CLAUDE_CALLBACK = 'https://claude.ai/api/mcp/auth_callback';

	/**
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'capability'         => 'manage_options',
			'redirect_uris'      => self::CLAUDE_CALLBACK,
			'allow_loopback'     => 1,
			'cloudflare_zone_id' => '',
			'cloudflare_token'   => '',
			'update_token'       => '',
			'access_ttl'         => HOUR_IN_SECONDS,
			'refresh_ttl'        => 30 * DAY_IN_SECONDS,
			'restrict_ips'       => 1,
			'extra_ips'          => '',
			'allowed_users'      => array(),
			'disabled_tools'     => array(),
			'extra_post_types'   => '',
		);
	}

	/**
	 * Get all settings or one key.
	 *
	 * @param string|null $key Key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$stored = (array) get_option( self::OPTION, array() );
		unset( $stored['allowed_redirects'] ); // Host-based list from 0.1.x, replaced by exact URIs.
		$all = wp_parse_args( $stored, self::defaults() );
		if ( null === $key ) {
			return $all;
		}
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Save settings.
	 *
	 * @param array $values Values.
	 */
	public static function save( array $values ) {
		$all = self::get();
		foreach ( $values as $k => $v ) {
			if ( array_key_exists( $k, $all ) ) {
				$all[ $k ] = $v;
			}
		}
		update_option( self::OPTION, $all, false );
	}

	/**
	 * Exact redirect URIs allowed besides Claude Code's loopback callback.
	 *
	 * @return string[]
	 */
	public static function redirect_uris() {
		$lines = preg_split( '/[\s,]+/', (string) self::get( 'redirect_uris' ) );
		$uris  = array( self::CLAUDE_CALLBACK );
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( '' !== $line && self::well_formed( $line ) && 0 === strpos( $line, 'https://' ) ) {
				$uris[] = $line;
			}
		}
		return array_values( array_unique( $uris ) );
	}

	/**
	 * Strict shape check: no backslashes, whitespace, control characters,
	 * user info, fragments or encoded characters in the authority, and the
	 * URI must survive WordPress's URL sanitizer unchanged.
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	public static function well_formed( $uri ) {
		if ( ! is_string( $uri ) || strlen( $uri ) > 512 ) {
			return false;
		}
		if ( preg_match( '/[\x00-\x20\x7f\\\\@#]/', $uri ) ) {
			return false;
		}
		if ( ! preg_match( '#^https?://([a-z0-9.\-]+|\[::1\])(:\d{1,5})?(/[A-Za-z0-9._~!$&\'()*+,;=:/\-]*)?(\?[A-Za-z0-9._~!$&\'()*+,;=:/?\-%]*)?$#', $uri ) ) {
			return false;
		}
		return esc_url_raw( $uri ) === $uri;
	}

	/**
	 * Whether a URI is Claude Code's loopback callback (any port, path /callback).
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	public static function is_loopback( $uri ) {
		return (bool) preg_match( '#^http://(localhost|127\.0\.0\.1)(:\d{1,5})?/callback$#', (string) $uri );
	}

	/**
	 * Whether a redirect URI is acceptable.
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	public static function redirect_allowed( $uri ) {
		if ( ! self::well_formed( $uri ) ) {
			return false;
		}
		if ( in_array( $uri, self::redirect_uris(), true ) ) {
			return true;
		}
		return self::is_loopback( $uri ) && (bool) self::get( 'allow_loopback' );
	}
}
