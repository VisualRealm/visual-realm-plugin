<?php
/**
 * Self-updates from GitHub releases.
 *
 * A release tagged v0.2.1 (or 0.2.1) with an asset named visual-realm.zip
 * shows up as an available update on every site running an older version.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_Updater {

	const REPO      = 'VisualRealm/visual-realm-plugin';
	const ASSET     = 'visual-realm.zip';
	const SIG_ASSET = 'visual-realm.zip.sig';
	const TRANSIENT = 'vr_update_release';

	/**
	 * Ed25519 public key that every release zip must be signed with.
	 */
	const PUBLIC_KEY = '+/9sQc66iw7WJcW+dkiC+rWHrEU044yAxig+t+0Axlc=';

	/**
	 * Hook in.
	 */
	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'http_request_args', array( __CLASS__, 'auth_download' ), 10, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 2 );
		add_filter( 'upgrader_pre_download', array( __CLASS__, 'verified_download' ), 10, 4 );
	}

	/**
	 * Plugin basename.
	 *
	 * @return string
	 */
	protected static function basename() {
		return plugin_basename( VR_PLUGIN_FILE );
	}

	/**
	 * Latest release info, cached for six hours.
	 *
	 * @param bool $force Skip the cache.
	 * @return array|null {version, package, notes, published, url}
	 */
	public static function latest( $force = false ) {
		if ( ! $force ) {
			$cached = get_transient( self::TRANSIENT );
			if ( is_array( $cached ) ) {
				return empty( $cached['version'] ) ? null : $cached;
			}
		}
		$headers = array(
			'Accept'     => 'application/vnd.github+json',
			'User-Agent' => 'VisualRealm-Plugin/' . VR_PLUGIN_VERSION,
		);
		$token = (string) VR_Settings::get( 'update_token' );
		if ( '' !== $token ) {
			$headers['Authorization'] = 'Bearer ' . $token;
		}
		$res  = wp_remote_get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', array( 'timeout' => 10, 'headers' => $headers ) );
		$code = is_wp_error( $res ) ? 0 : (int) wp_remote_retrieve_response_code( $res );
		$body = 200 === $code ? json_decode( wp_remote_retrieve_body( $res ), true ) : null;
		$info = array();
		if ( is_array( $body ) && ! empty( $body['tag_name'] ) && empty( $body['draft'] ) ) {
			$zip = null;
			$sig = null;
			foreach ( (array) $body['assets'] as $asset ) {
				$name = isset( $asset['name'] ) ? $asset['name'] : '';
				$link = '' !== $token ? (string) $asset['url'] : (string) $asset['browser_download_url'];
				if ( self::ASSET === $name ) {
					$zip = $link;
				} elseif ( self::SIG_ASSET === $name ) {
					$sig = $link;
				}
			}
			// A release without a signature is never offered.
			if ( $zip && $sig ) {
				$info = array(
					'version'   => ltrim( (string) $body['tag_name'], 'vV' ),
					'package'   => $zip,
					'signature' => $sig,
					'notes'     => isset( $body['body'] ) ? (string) $body['body'] : '',
					'published' => isset( $body['published_at'] ) ? (string) $body['published_at'] : '',
					'url'       => isset( $body['html_url'] ) ? (string) $body['html_url'] : '',
				);
			}
		}
		$info['checked'] = time();
		$info['status']  = $code;
		set_transient( self::TRANSIENT, $info, ( 200 === $code ? 6 : 1 ) * HOUR_IN_SECONDS );
		return empty( $info['version'] ) ? null : $info;
	}

	/**
	 * Add our update to WordPress's plugin update list.
	 *
	 * @param object $transient Update transient.
	 * @return object
	 */
	public static function inject( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$info = self::latest();
		$base = self::basename();
		$item = (object) array(
			'id'          => self::REPO,
			'slug'        => 'visual-realm',
			'plugin'      => $base,
			'new_version' => $info ? $info['version'] : VR_PLUGIN_VERSION,
			'url'         => 'https://thevisualrealm.com',
			'package'     => $info ? $info['package'] : '',
		);
		if ( $info && version_compare( $info['version'], VR_PLUGIN_VERSION, '>' ) ) {
			$transient->response[ $base ] = $item;
		} else {
			$transient->no_update[ $base ] = $item;
		}
		return $transient;
	}

	/**
	 * "View details" popup.
	 *
	 * @param mixed  $result Result.
	 * @param string $action Action.
	 * @param object $args   Args.
	 * @return mixed
	 */
	public static function details( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || empty( $args->slug ) || 'visual-realm' !== $args->slug ) {
			return $result;
		}
		$info = self::latest();
		return (object) array(
			'name'          => 'Visual Realm',
			'slug'          => 'visual-realm',
			'version'       => $info ? $info['version'] : VR_PLUGIN_VERSION,
			'author'        => '<a href="https://thevisualrealm.com">Visual Realm</a>',
			'homepage'      => 'https://thevisualrealm.com',
			'last_updated'  => $info ? $info['published'] : '',
			'download_link' => $info ? $info['package'] : '',
			'sections'      => array(
				'description' => 'Secure connector that lets Visual Realm build and manage this site\'s pages, SEO and media.',
				'changelog'   => $info && '' !== $info['notes'] ? wpautop( esc_html( $info['notes'] ) ) : 'See release notes.',
			),
		);
	}

	/**
	 * Send the token when WordPress downloads a private release asset.
	 *
	 * @param array  $args Request args.
	 * @param string $url  URL.
	 * @return array
	 */
	public static function auth_download( $args, $url ) {
		if ( 0 !== strpos( $url, 'https://api.github.com/repos/' . self::REPO . '/releases/assets/' ) ) {
			return $args;
		}
		$token = (string) VR_Settings::get( 'update_token' );
		if ( '' === $token ) {
			return $args;
		}
		$args['headers']                  = isset( $args['headers'] ) ? (array) $args['headers'] : array();
		$args['headers']['Authorization'] = 'Bearer ' . $token;
		$args['headers']['Accept']        = 'application/octet-stream';
		$args['headers']['User-Agent']    = 'VisualRealm-Plugin/' . VR_PLUGIN_VERSION;
		return $args;
	}

	/**
	 * Download our release and refuse it unless its Ed25519 signature checks out.
	 *
	 * @param mixed       $reply      Short-circuit value.
	 * @param string      $package    Package URL.
	 * @param WP_Upgrader $upgrader   Upgrader.
	 * @param array       $hook_extra Extra.
	 * @return mixed File path or WP_Error, or $reply for other packages.
	 */
	public static function verified_download( $reply, $package, $upgrader = null, $hook_extra = array() ) {
		$ours = ( isset( $hook_extra['plugin'] ) && self::basename() === $hook_extra['plugin'] )
			|| 0 === strpos( (string) $package, 'https://github.com/' . self::REPO . '/' )
			|| 0 === strpos( (string) $package, 'https://api.github.com/repos/' . self::REPO . '/' );
		if ( false !== $reply || ! $ours ) {
			return $reply;
		}
		$info = self::latest();
		if ( ! $info || empty( $info['signature'] ) || $package !== $info['package'] ) {
			return new WP_Error( 'vr_unsigned', 'Update refused: no matching signed release was found.' );
		}
		if ( ! function_exists( 'download_url' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		$file = download_url( $package, 300 );
		if ( is_wp_error( $file ) ) {
			return $file;
		}
		$sig = wp_remote_get( $info['signature'], array( 'timeout' => 30, 'limit_response_size' => 4096 ) );
		$raw = is_wp_error( $sig ) ? '' : trim( wp_remote_retrieve_body( $sig ) );
		$bin = base64_decode( $raw, true );
		$ok  = false;
		if ( $bin && SODIUM_CRYPTO_SIGN_BYTES === strlen( $bin ) ) {
			try {
				$ok = sodium_crypto_sign_verify_detached( $bin, (string) file_get_contents( $file ), base64_decode( self::PUBLIC_KEY ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			} catch ( Exception $e ) {
				$ok = false;
			}
		}
		if ( ! $ok ) {
			wp_delete_file( $file );
			return new WP_Error( 'vr_bad_signature', 'Update refused: the release signature did not verify.' );
		}
		// The signed zip must contain the version the release claims, and it must be newer (no rollbacks).
		$inside = self::zip_version( $file );
		if ( null === $inside || $inside !== $info['version'] || ! version_compare( $inside, VR_PLUGIN_VERSION, '>' ) ) {
			wp_delete_file( $file );
			return new WP_Error( 'vr_bad_version', 'Update refused: the release does not contain a newer version of the plugin.' );
		}
		return $file;
	}

	/**
	 * Read the Version header from visual-realm/visual-realm.php inside a zip.
	 *
	 * @param string $file Zip path.
	 * @return string|null
	 */
	protected static function zip_version( $file ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return null;
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $file ) ) {
			return null;
		}
		$main = $zip->getFromName( 'visual-realm/visual-realm.php' );
		$zip->close();
		if ( ! is_string( $main ) || ! preg_match( '/^[ \t\/*#@]*Version:\s*([0-9][0-9A-Za-z.\-]*)/mi', $main, $m ) ) {
			return null;
		}
		return $m[1];
	}

	/**
	 * Make sure the unpacked folder is named visual-realm.
	 *
	 * @param string      $source        Source dir.
	 * @param string      $remote_source Remote source dir.
	 * @param WP_Upgrader $upgrader      Upgrader.
	 * @param array       $extra         Extra.
	 * @return string|WP_Error
	 */
	public static function fix_folder( $source, $remote_source, $upgrader = null, $extra = array() ) {
		if ( empty( $extra['plugin'] ) || self::basename() !== $extra['plugin'] ) {
			return $source;
		}
		$want = trailingslashit( $remote_source ) . 'visual-realm/';
		if ( trailingslashit( $source ) === $want ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $want, true ) ) {
			return $want;
		}
		return $source;
	}

	/**
	 * Clear the cached release after an update.
	 *
	 * @param WP_Upgrader $upgrader Upgrader.
	 * @param array       $options  Options.
	 */
	public static function flush( $upgrader, $options ) {
		if ( isset( $options['type'] ) && 'plugin' === $options['type'] ) {
			delete_transient( self::TRANSIENT );
		}
	}
}
