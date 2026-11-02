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
	 * Defaults.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'capability'          => 'manage_options',
			'allowed_redirects'   => "claude.ai\nclaude.com\nlocalhost\n127.0.0.1",
			'cloudflare_zone_id'  => '',
			'cloudflare_token'    => '',
			'access_ttl'          => HOUR_IN_SECONDS,
			'refresh_ttl'         => 90 * DAY_IN_SECONDS,
		);
	}

	/**
	 * Get all settings or one key.
	 *
	 * @param string|null $key Key.
	 * @return mixed
	 */
	public static function get( $key = null ) {
		$all = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );
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
	 * Hosts that OAuth redirect URIs may point to.
	 *
	 * @return string[]
	 */
	public static function allowed_redirect_hosts() {
		$lines = preg_split( '/[\s,]+/', strtolower( (string) self::get( 'allowed_redirects' ) ) );
		return array_values( array_filter( array_map( 'trim', $lines ) ) );
	}

	/**
	 * Whether a redirect URI is acceptable.
	 *
	 * @param string $uri URI.
	 * @return bool
	 */
	public static function redirect_allowed( $uri ) {
		$parts = wp_parse_url( $uri );
		if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		$host  = strtolower( $parts['host'] );
		$local = in_array( $host, array( 'localhost', '127.0.0.1', '[::1]', '::1' ), true );
		if ( 'https' !== $parts['scheme'] && ! ( 'http' === $parts['scheme'] && $local ) ) {
			return false;
		}
		foreach ( self::allowed_redirect_hosts() as $allowed ) {
			if ( $host === $allowed || substr( $host, -strlen( '.' . $allowed ) ) === '.' . $allowed ) {
				return true;
			}
		}
		return false;
	}
}
