<?php
/**
 * Cache purging across the layers VR sites use: WP Rocket (page cache and
 * Remove Unused CSS), Avada compiled CSS, Kinsta, SiteGround and Cloudflare.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_Cache {

	/**
	 * Post types whose edits show on every page, so a page purge is escalated.
	 */
	const GLOBAL_TYPES = array( 'fusion_tb_section', 'fusion_tb_layout', 'fusion_element', 'fusion_template', 'nav_menu_item', 'wp_template_part' );

	/**
	 * Purge caches.
	 *
	 * @param int  $post_id    Post ID (0 for whole site).
	 * @param bool $everything Force a full-site purge.
	 * @return array Report.
	 */
	public static function purge( $post_id = 0, $everything = false ) {
		$post_id    = (int) $post_id;
		$escalated  = false;
		if ( $post_id && in_array( get_post_type( $post_id ), self::GLOBAL_TYPES, true ) ) {
			$everything = true;
			$escalated  = true;
		}
		$everything = $everything || ! $post_id;
		$url        = $post_id ? (string) get_permalink( $post_id ) : '';
		$done       = array();

		$done['wp_rocket']          = self::wp_rocket( $post_id, $url, $everything );
		$done['wp_rocket_used_css'] = self::wp_rocket_used_css( $url, $everything );
		$done['avada']              = self::avada( $post_id, $everything );
		$done['kinsta']             = self::kinsta( $post_id, $everything );
		$done['siteground']         = self::siteground( $url, $everything );
		$done['cloudflare']         = self::cloudflare( $url, $everything );

		$out = array(
			'scope'  => $everything ? 'site' : 'page',
			'url'    => $url,
			'purged' => $done,
		);
		if ( $escalated ) {
			$out['note'] = 'This item appears on every page, so the whole site was purged.';
		}
		return $out;
	}

	/**
	 * WP Rocket page cache.
	 *
	 * @param int    $post_id    Post.
	 * @param string $url        URL.
	 * @param bool   $everything Full site.
	 * @return string
	 */
	protected static function wp_rocket( $post_id, $url, $everything ) {
		if ( $everything && function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
			if ( function_exists( 'rocket_clean_minify' ) ) {
				rocket_clean_minify();
			}
			return 'site';
		}
		if ( $post_id && function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
			return 'page';
		}
		if ( '' !== $url && function_exists( 'rocket_clean_files' ) ) {
			rocket_clean_files( array( $url ) );
			return 'page';
		}
		return 'not installed';
	}

	/**
	 * WP Rocket Remove Unused CSS. Deleting the stored rows makes WP Rocket
	 * rebuild the used CSS on the next visit.
	 *
	 * @param string $url        URL.
	 * @param bool   $everything Full site.
	 * @return string
	 */
	protected static function wp_rocket_used_css( $url, $everything ) {
		if ( ! defined( 'WP_ROCKET_VERSION' ) ) {
			return 'not installed';
		}
		$settings = get_option( 'wp_rocket_settings', array() );
		if ( empty( $settings['remove_unused_css'] ) ) {
			return 'feature off';
		}
		global $wpdb;
		$table = $wpdb->prefix . 'wpr_rucss_used_css';
		if ( $table !== $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) ) {
			return 'no used-CSS table';
		}
		if ( $everything ) {
			$rows = (int) $wpdb->query( "DELETE FROM {$table}" ); // phpcs:ignore WordPress.DB
			do_action( 'rocket_after_clean_used_css' );
			return sprintf( 'site (%d rows)', $rows );
		}
		$base = untrailingslashit( strtok( $url, '?' ) );
		$rows = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE url = %s OR url = %s", $base, $base . '/' ) ); // phpcs:ignore WordPress.DB
		return sprintf( 'page (%d rows)', $rows );
	}

	/**
	 * Avada / Fusion compiled CSS and caches.
	 *
	 * @param int  $post_id    Post.
	 * @param bool $everything Full site.
	 * @return string
	 */
	protected static function avada( $post_id, $everything ) {
		if ( ! class_exists( 'Fusion_Cache' ) && ! function_exists( 'fusion_reset_all_caches' ) && ! class_exists( 'Avada' ) ) {
			return 'not installed';
		}
		if ( $everything ) {
			if ( function_exists( 'fusion_reset_all_caches' ) ) {
				fusion_reset_all_caches();
				return 'site';
			}
			if ( class_exists( 'Fusion_Cache' ) ) {
				$cache = new Fusion_Cache();
				if ( method_exists( $cache, 'reset_all_caches' ) ) {
					$cache->reset_all_caches();
					return 'site';
				}
			}
			return 'no reset function found';
		}
		// Per-page compiled CSS lives in post meta; dropping it forces a rebuild.
		delete_post_meta( $post_id, '_fusion_builder_css' );
		delete_post_meta( $post_id, '_fusion_css' );
		return 'page';
	}

	/**
	 * Kinsta server cache.
	 *
	 * @param int  $post_id    Post.
	 * @param bool $everything Full site.
	 * @return string
	 */
	protected static function kinsta( $post_id, $everything ) {
		global $kinsta_cache;
		if ( ! is_object( $kinsta_cache ) || ! isset( $kinsta_cache->kinsta_cache_purge ) ) {
			return 'not on Kinsta';
		}
		$purge = $kinsta_cache->kinsta_cache_purge;
		if ( $everything && method_exists( $purge, 'purge_complete_caches' ) ) {
			$purge->purge_complete_caches();
			return 'site';
		}
		if ( $post_id && method_exists( $purge, 'initiate_purge' ) ) {
			$purge->initiate_purge( $post_id );
			return 'page';
		}
		return 'no purge method';
	}

	/**
	 * Whether this site sits behind SiteGround's dynamic cache (checked once a day).
	 *
	 * @return bool
	 */
	public static function on_siteground() {
		$cached = get_transient( 'vr_sg_detect' );
		if ( false !== $cached ) {
			return 'yes' === $cached;
		}
		$res = wp_remote_head( home_url( '/' ), array( 'timeout' => 5, 'sslverify' => false, 'redirection' => 2 ) );
		$yes = ! is_wp_error( $res ) && '' !== (string) wp_remote_retrieve_header( $res, 'x-proxy-cache' );
		set_transient( 'vr_sg_detect', $yes ? 'yes' : 'no', DAY_IN_SECONDS );
		return $yes;
	}

	/**
	 * SiteGround dynamic cache. Uses their plugin when present, otherwise sends
	 * the PURGE request their cache accepts from the site itself.
	 *
	 * @param string $url        URL.
	 * @param bool   $everything Full site.
	 * @return string
	 */
	protected static function siteground( $url, $everything ) {
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache( $everything ? null : $url );
			return $everything ? 'site' : 'page';
		}
		global $kinsta_cache;
		if ( is_object( $kinsta_cache ) ) {
			return 'not on SiteGround';
		}
		// SiteGround's cache accepts PURGE on the local web server; the path may end in (.*) to cover children.
		$host = preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		$path = $everything ? '/' : (string) wp_parse_url( $url, PHP_URL_PATH );
		$path = ( '' === $path ? '/' : $path ) . ( $everything ? '(.*)' : '' );
		$sock = @fsockopen( '127.0.0.1', 80, $errno, $errstr, 2 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors,WordPress.WP.AlternativeFunctions
		if ( ! $sock ) {
			return 'not on SiteGround';
		}
		fwrite( $sock, "PURGE {$path} HTTP/1.0\r\nHost: {$host}\r\nConnection: Close\r\n\r\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		stream_set_timeout( $sock, 2 );
		$status = (string) fgets( $sock, 128 );
		fclose( $sock ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( preg_match( '#^HTTP/\S+\s+(\d{3})#', $status, $m ) ) {
			$code = (int) $m[1];
			if ( $code < 300 ) {
				return $everything ? 'site' : 'page';
			}
			return 405 === $code || 501 === $code ? 'not on SiteGround' : 'purge returned ' . $code;
		}
		return 'not on SiteGround';
	}

	/**
	 * Cloudflare API purge.
	 *
	 * @param string $url        URL.
	 * @param bool   $everything Full site.
	 * @return string
	 */
	protected static function cloudflare( $url, $everything ) {
		$zone  = (string) VR_Settings::get( 'cloudflare_zone_id' );
		$token = (string) VR_Settings::get( 'cloudflare_token' );
		if ( '' === $zone || '' === $token ) {
			return 'not configured';
		}
		$body = $everything ? array( 'purge_everything' => true ) : array( 'files' => array_values( array_unique( array( $url, trailingslashit( $url ) ) ) ) );
		$res  = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . rawurlencode( $zone ) . '/purge_cache',
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return 'failed: ' . $res->get_error_message();
		}
		return 200 === (int) wp_remote_retrieve_response_code( $res ) ? ( $everything ? 'site' : 'page' ) : 'failed: ' . wp_remote_retrieve_body( $res );
	}
}
