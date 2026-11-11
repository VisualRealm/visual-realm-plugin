<?php
/**
 * OAuth 2.1 authorization server for the MCP endpoint.
 *
 * Supports authorization code + PKCE (S256), refresh-token rotation,
 * dynamic client registration (RFC 7591), client ID metadata documents,
 * token revocation (RFC 7009) and the discovery documents in
 * RFC 8414 and RFC 9728.
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_OAuth {

	const CODE_TTL = 600;

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'route' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );
	}

	/* ---------------------------------------------------------------------
	 * URLs
	 * ------------------------------------------------------------------- */

	/**
	 * Authorization server issuer.
	 *
	 * @return string
	 */
	public static function issuer() {
		// A path-based issuer lets clients find metadata at
		// {issuer}/.well-known/openid-configuration, which reaches WordPress even on
		// hosts (SiteGround) that answer everything under the root /.well-known/ themselves.
		return untrailingslashit( rest_url( VR_MCP::NS ) );
	}

	/**
	 * Absolute URL for one of our OAuth endpoints.
	 *
	 * @param string $name authorize|token|register|revoke.
	 * @return string
	 */
	public static function endpoint( $name ) {
		return home_url( '/vr-oauth/' . $name );
	}

	/**
	 * Protected-resource metadata URL advertised in 401 challenges.
	 *
	 * @return string
	 */
	public static function resource_metadata_url() {
		// Query-string form: never intercepted by host /.well-known/ rules or nginx
		// "deny /." dotfile rules, and works in subfolder installs.
		return add_query_arg( 'rest_route', '/' . VR_MCP::NS . '/.well-known/oauth-protected-resource', home_url( '/' ) );
	}

	/**
	 * Register REST copies of the discovery documents.
	 */
	public static function register_rest_routes() {
		$docs = array(
			'/.well-known/oauth-protected-resource'   => 'resource_metadata',
			'/.well-known/oauth-authorization-server' => 'server_metadata',
			'/.well-known/openid-configuration'       => 'server_metadata',
		);
		foreach ( $docs as $route => $method ) {
			register_rest_route(
				VR_MCP::NS,
				$route,
				array(
					'methods'             => 'GET',
					'permission_callback' => '__return_true',
					'callback'            => function () use ( $method ) {
						self::json( self::$method() );
					},
				)
			);
		}
	}

	/* ---------------------------------------------------------------------
	 * Routing
	 * ------------------------------------------------------------------- */

	/**
	 * Path of the current request relative to the site home, without query.
	 *
	 * @return array{0:string,1:string} [relative path, full path]
	 */
	protected static function request_paths() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore
		$full = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$full = '/' . ltrim( $full, '/' );
		$home = (string) wp_parse_url( home_url(), PHP_URL_PATH );
		$home = rtrim( $home, '/' );
		$rel  = $full;
		if ( '' !== $home && 0 === strpos( $full, $home . '/' ) ) {
			$rel = substr( $full, strlen( $home ) );
		}
		return array( untrailingslashit( $rel ) ? untrailingslashit( $rel ) : '/', $full );
	}

	/**
	 * Dispatch our endpoints before WordPress resolves the main query.
	 */
	public static function route() {
		list( $rel, $full ) = self::request_paths();

		foreach ( array( $rel, $full ) as $path ) {
			if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
				self::json( self::resource_metadata() );
			}
			if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $path, '/.well-known/openid-configuration' ) ) {
				self::json( self::server_metadata() );
			}
		}

		if ( 0 !== strpos( $rel, '/vr-oauth/' ) ) {
			return;
		}

		self::cors();
		// Keep page caches (SG Optimizer, WP Rocket, Cloudflare) off the sign-in flow.
		if ( ! defined( 'DONOTCACHEPAGE' ) ) {
			define( 'DONOTCACHEPAGE', true );
		}
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( $_SERVER['REQUEST_METHOD'] ) ) : 'GET';
		if ( 'OPTIONS' === $method ) {
			status_header( 204 );
			exit;
		}

		switch ( $rel ) {
			case '/vr-oauth/authorize':
				self::authorize( $method );
				break;
			case '/vr-oauth/token':
				self::require_post( $method );
				self::token();
				break;
			case '/vr-oauth/register':
				self::require_post( $method );
				self::register();
				break;
			case '/vr-oauth/revoke':
				self::require_post( $method );
				self::revoke_endpoint();
				break;
			default:
				self::json( array( 'error' => 'not_found' ), 404 );
		}
	}

	/**
	 * Allow cross-origin calls to the JSON endpoints.
	 */
	protected static function cors() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
	}

	/**
	 * Reject non-POST requests.
	 *
	 * @param string $method Method.
	 */
	protected static function require_post( $method ) {
		if ( 'POST' !== $method ) {
			header( 'Allow: POST' );
			self::json( array( 'error' => 'invalid_request', 'error_description' => 'POST required.' ), 405 );
		}
	}

	/**
	 * Send JSON and exit.
	 *
	 * @param mixed $data   Data.
	 * @param int   $status Status.
	 * @param array $headers Extra headers.
	 */
	public static function json( $data, $status = 200, $headers = array() ) {
		self::cors();
		nocache_headers();
		header( 'Cache-Control: no-store' );
		header( 'Content-Type: application/json; charset=utf-8' );
		foreach ( $headers as $h ) {
			header( $h );
		}
		status_header( $status );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Discovery
	 * ------------------------------------------------------------------- */

	/**
	 * RFC 9728 protected resource metadata.
	 *
	 * @return array
	 */
	public static function resource_metadata() {
		return array(
			'resource'                 => VR_MCP::endpoint_url(),
			'authorization_servers'    => array( self::issuer() ),
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => array( 'mcp' ),
			'resource_name'            => get_bloginfo( 'name' ),
		);
	}

	/**
	 * RFC 8414 authorization server metadata.
	 *
	 * @return array
	 */
	public static function server_metadata() {
		return array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::endpoint( 'authorize' ),
			'token_endpoint'                        => self::endpoint( 'token' ),
			'registration_endpoint'                 => self::endpoint( 'register' ),
			'revocation_endpoint'                   => self::endpoint( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => array( 'mcp' ),
			'authorization_response_iss_parameter_supported' => true,
			'client_id_metadata_document_supported' => true,
		);
	}

	/* ---------------------------------------------------------------------
	 * Clients
	 * ------------------------------------------------------------------- */

	/**
	 * Look up a client, fetching its metadata document when the ID is a URL.
	 *
	 * @param string $client_id Client ID.
	 * @return array|null
	 */
	public static function get_client( $client_id ) {
		global $wpdb;
		$client_id = (string) $client_id;
		if ( '' === $client_id || strlen( $client_id ) > 255 ) {
			return null;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VR_DB::table( 'clients' ) . ' WHERE client_id = %s', $client_id ), ARRAY_A );

		$is_url = 0 === strpos( $client_id, 'https://' );
		if ( $is_url ) {
			$stale = ! $row || empty( $row['fetched_at'] ) || strtotime( $row['fetched_at'] . ' UTC' ) < time() - DAY_IN_SECONDS;
			if ( $stale ) {
				if ( ! $row && ! self::rate_ok( 'cimd', 30, HOUR_IN_SECONDS ) ) {
					return null;
				}
				$doc = self::fetch_client_metadata( $client_id );
				if ( ! $doc ) {
					return $row ? self::hydrate_client( $row ) : null;
				}
				$uris = array_values( array_filter( array_filter( (array) $doc['redirect_uris'], 'is_string' ), array( 'VR_Settings', 'redirect_allowed' ) ) );
				if ( empty( $uris ) ) {
					return $row ? self::hydrate_client( $row ) : null;
				}
				$data = array(
					'client_id'     => $client_id,
					'client_name'   => isset( $doc['client_name'] ) ? sanitize_text_field( $doc['client_name'] ) : '',
					'redirect_uris' => wp_json_encode( $uris ),
					'source'        => 'cimd',
					'fetched_at'    => VR_DB::now(),
				);
				if ( $row ) {
					$wpdb->update( VR_DB::table( 'clients' ), $data, array( 'client_id' => $client_id ) );
				} else {
					$data['created_at'] = VR_DB::now();
					$wpdb->insert( VR_DB::table( 'clients' ), $data );
				}
				$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VR_DB::table( 'clients' ) . ' WHERE client_id = %s', $client_id ), ARRAY_A );
			}
		}

		return $row ? self::hydrate_client( $row ) : null;
	}

	/**
	 * Normalise a client row.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	protected static function hydrate_client( array $row ) {
		$uris                 = json_decode( (string) $row['redirect_uris'], true );
		$row['redirect_uris'] = is_array( $uris ) ? $uris : array();
		return $row;
	}

	/**
	 * Fetch and validate a client ID metadata document.
	 *
	 * @param string $url Client ID URL.
	 * @return array|null
	 */
	protected static function fetch_client_metadata( $url ) {
		$res = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return null;
		}
		$doc = json_decode( wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $doc ) || ! isset( $doc['client_id'] ) || $doc['client_id'] !== $url ) {
			return null;
		}
		if ( empty( $doc['redirect_uris'] ) || ! is_array( $doc['redirect_uris'] ) ) {
			return null;
		}
		return $doc;
	}

	/**
	 * Dynamic client registration (RFC 7591).
	 */
	protected static function register() {
		if ( ! self::rate_ok( 'register', 20, HOUR_IN_SECONDS ) ) {
			self::json( array( 'error' => 'slow_down', 'error_description' => 'Too many registrations.' ), 429 );
		}
		$body = json_decode( (string) file_get_contents( 'php://input' ), true );
		if ( ! is_array( $body ) ) {
			self::json( array( 'error' => 'invalid_client_metadata', 'error_description' => 'JSON body required.' ), 400 );
		}
		$uris = isset( $body['redirect_uris'] ) && is_array( $body['redirect_uris'] ) ? array_values( array_filter( $body['redirect_uris'], 'is_string' ) ) : array();
		if ( empty( $uris ) ) {
			self::json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'redirect_uris required.' ), 400 );
		}
		foreach ( $uris as $uri ) {
			if ( ! VR_Settings::redirect_allowed( $uri ) ) {
				self::json( array( 'error' => 'invalid_redirect_uri', 'error_description' => 'Redirect URI host is not allowed on this site.' ), 400 );
			}
		}

		$method = isset( $body['token_endpoint_auth_method'] ) ? (string) $body['token_endpoint_auth_method'] : 'none';
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$method = 'none';
		}
		$client_id = 'vrc_' . bin2hex( random_bytes( 16 ) );
		$secret    = 'none' === $method ? '' : 'vrs_' . bin2hex( random_bytes( 32 ) );
		$name      = isset( $body['client_name'] ) ? sanitize_text_field( (string) $body['client_name'] ) : '';

		global $wpdb;
		$wpdb->insert(
			VR_DB::table( 'clients' ),
			array(
				'client_id'          => $client_id,
				'client_secret_hash' => '' === $secret ? '' : hash( 'sha256', $secret ),
				'client_name'        => substr( $name, 0, 200 ),
				'redirect_uris'      => wp_json_encode( $uris ),
				'source'             => 'dcr',
				'created_at'         => VR_DB::now(),
			)
		);

		$out = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'token_endpoint_auth_method' => $method,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'scope'                      => 'mcp',
		);
		if ( '' !== $secret ) {
			$out['client_secret']            = $secret;
			$out['client_secret_expires_at'] = 0;
		}
		self::json( $out, 201 );
	}

	/* ---------------------------------------------------------------------
	 * Authorization endpoint + consent screen
	 * ------------------------------------------------------------------- */

	/**
	 * Handle /vr-oauth/authorize.
	 *
	 * @param string $method HTTP method.
	 */
	protected static function authorize( $method ) {
		// phpcs:disable WordPress.Security.NonceVerification
		$src    = 'POST' === $method ? $_POST : $_GET;
		$src    = wp_unslash( $src );
		$params = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'code_challenge', 'code_challenge_method', 'state', 'scope', 'resource' ) as $k ) {
			$params[ $k ] = isset( $src[ $k ] ) && is_string( $src[ $k ] ) ? $src[ $k ] : '';
		}
		// phpcs:enable

		$client = self::get_client( $params['client_id'] );
		if ( ! $client ) {
			self::error_page( __( 'This connection request came from an unknown app.', 'visual-realm' ) );
		}

		$redirect = $params['redirect_uri'];
		if ( '' === $redirect && 1 === count( $client['redirect_uris'] ) ) {
			$redirect = $client['redirect_uris'][0];
		}
		if ( '' === $redirect || ! self::client_has_redirect( $client, $redirect ) || ! VR_Settings::redirect_allowed( $redirect ) ) {
			self::error_page( __( 'This connection request has a return address this site does not allow.', 'visual-realm' ) );
		}
		$params['redirect_uri'] = $redirect;

		if ( 'code' !== $params['response_type'] ) {
			self::redirect_error( $params, 'unsupported_response_type' );
		}
		if ( '' === $params['code_challenge'] || 'S256' !== $params['code_challenge_method'] ) {
			self::redirect_error( $params, 'invalid_request', 'PKCE with S256 is required.' );
		}

		if ( ! is_user_logged_in() ) {
			$here = home_url( '/vr-oauth/authorize' ) . '?' . http_build_query( array_filter( $params, 'strlen' ) );
			wp_safe_redirect( wp_login_url( $here ) );
			exit;
		}

		$user = wp_get_current_user();
		if ( ! user_can( $user, VR_Settings::get( 'capability' ) ) ) {
			self::error_page( __( 'Your account does not have permission to connect this site.', 'visual-realm' ) );
		}

		if ( 'POST' === $method ) {
			if ( ! isset( $_POST['_vrnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_vrnonce'] ) ), 'vr_authorize_' . $params['client_id'] ) ) {
				self::error_page( __( 'This approval screen expired. Start the connection again.', 'visual-realm' ) );
			}
			$decision = isset( $_POST['decision'] ) ? sanitize_key( wp_unslash( $_POST['decision'] ) ) : '';
			if ( 'approve' !== $decision ) {
				self::redirect_error( $params, 'access_denied' );
			}
			$code = 'vrcode_' . bin2hex( random_bytes( 32 ) );
			set_transient(
				'vr_code_' . hash( 'sha256', $code ),
				array(
					'client_id'      => $params['client_id'],
					'user_id'        => $user->ID,
					'redirect_uri'   => $params['redirect_uri'],
					'code_challenge' => $params['code_challenge'],
					'resource'       => $params['resource'],
					'scope'          => 'mcp',
				),
				self::CODE_TTL
			);
			self::redirect_to(
				$params['redirect_uri'],
				array(
					'code'  => $code,
					'state' => $params['state'],
					'iss'   => self::issuer(),
				)
			);
		}

		self::consent_page( $client, $params, $user );
	}

	/**
	 * Whether a client registered this redirect URI. Loopback callbacks match
	 * on host and path with the port ignored (RFC 8252 7.3).
	 *
	 * @param array  $client   Client.
	 * @param string $redirect Redirect URI.
	 * @return bool
	 */
	protected static function client_has_redirect( array $client, $redirect ) {
		if ( in_array( $redirect, $client['redirect_uris'], true ) ) {
			return true;
		}
		if ( ! VR_Settings::is_loopback( $redirect ) ) {
			return false;
		}
		$strip = function ( $u ) {
			return preg_replace( '#^(http://(?:localhost|127\.0\.0\.1))(:\d{1,5})?#', '$1', $u );
		};
		foreach ( $client['redirect_uris'] as $registered ) {
			if ( VR_Settings::is_loopback( $registered ) && $strip( $registered ) === $strip( $redirect ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Redirect back to the client with query args.
	 *
	 * @param string $uri  Redirect URI (already validated).
	 * @param array  $args Args.
	 */
	protected static function redirect_to( $uri, array $args ) {
		$args = array_filter( $args, 'strlen' );
		$sep  = false === strpos( $uri, '?' ) ? '?' : '&';
		nocache_headers();
		header( 'Location: ' . $uri . $sep . http_build_query( $args ), true, 302 );
		exit;
	}

	/**
	 * Redirect back with an OAuth error.
	 *
	 * @param array  $params Params.
	 * @param string $error  Error code.
	 * @param string $desc   Description.
	 */
	protected static function redirect_error( array $params, $error, $desc = '' ) {
		self::redirect_to(
			$params['redirect_uri'],
			array(
				'error'             => $error,
				'error_description' => $desc,
				'state'             => $params['state'],
				'iss'               => self::issuer(),
			)
		);
	}

	/**
	 * Shared page chrome.
	 *
	 * @param string $title Title.
	 * @param string $body  Inner HTML.
	 * @param int    $status HTTP status.
	 */
	protected static function page( $title, $body, $status = 200 ) {
		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: DENY' );
		status_header( $status );
		$site = esc_html( get_bloginfo( 'name' ) );
		echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">';
		echo '<title>' . esc_html( $title ) . ' · ' . $site . '</title>';
		echo '<style>
		:root{--bg:#f4f5f7;--card:#fff;--ink:#111;--muted:#5b6170;--line:#e3e5ea;--btn:#000;--btnInk:#fff}
		@media (prefers-color-scheme:dark){:root{--bg:#0f1013;--card:#181a1f;--ink:#f2f3f5;--muted:#a3a8b4;--line:#2a2d35;--btn:#fff;--btnInk:#000}}
		*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:16px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;display:flex;min-height:100vh;align-items:center;justify-content:center;padding:16px}
		.card{background:var(--card);border:1px solid var(--line);border-radius:14px;max-width:440px;width:100%;padding:32px}
		.brand{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--muted);margin:0 0 18px}
		h1{font-size:22px;line-height:1.3;margin:0 0 12px}p{margin:0 0 14px;color:var(--muted)}
		ul{margin:0 0 20px;padding-left:18px;color:var(--muted)}li{margin:4px 0}
		.meta{font-size:14px;border-top:1px solid var(--line);padding-top:14px;margin-top:6px}
		.row{display:flex;gap:10px;margin-top:22px}button{flex:1;font:inherit;font-weight:600;padding:12px;border-radius:10px;cursor:pointer;border:1px solid var(--line);background:transparent;color:var(--ink)}
		button.primary{background:var(--btn);color:var(--btnInk);border-color:var(--btn)}
		</style></head><body><main class="card"><p class="brand">Visual Realm</p>';
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- built from escaped parts.
		echo '</main></body></html>';
		exit;
	}

	/**
	 * Error page (no redirect).
	 *
	 * @param string $message Message.
	 */
	protected static function error_page( $message ) {
		self::page( __( 'Connection not allowed', 'visual-realm' ), '<h1>' . esc_html__( 'Connection not allowed', 'visual-realm' ) . '</h1><p>' . esc_html( $message ) . '</p>', 400 );
	}

	/**
	 * Consent screen.
	 *
	 * @param array   $client Client.
	 * @param array   $params Params.
	 * @param WP_User $user   User.
	 */
	protected static function consent_page( array $client, array $params, WP_User $user ) {
		$app  = '' !== $client['client_name'] ? $client['client_name'] : __( 'An app', 'visual-realm' );
		$host = (string) wp_parse_url( $params['redirect_uri'], PHP_URL_HOST );
		$site = get_bloginfo( 'name' );

		$fields = '';
		foreach ( $params as $k => $v ) {
			$fields .= '<input type="hidden" name="' . esc_attr( $k ) . '" value="' . esc_attr( $v ) . '">';
		}
		$fields .= wp_nonce_field( 'vr_authorize_' . $params['client_id'], '_vrnonce', false, false );

		$body  = '<h1>' . esc_html( sprintf( /* translators: 1: app, 2: site */ __( 'Allow %1$s to manage %2$s?', 'visual-realm' ), $app, $site ) ) . '</h1>';
		$body .= '<p>' . esc_html__( 'It will act with your account\'s permissions on this site, including:', 'visual-realm' ) . '</p>';
		$body .= '<ul><li>' . esc_html__( 'Reading and editing pages, posts and their SEO settings', 'visual-realm' ) . '</li><li>' . esc_html__( 'Uploading and updating media', 'visual-realm' ) . '</li><li>' . esc_html__( 'Changing site options and clearing caches', 'visual-realm' ) . '</li></ul>';
		$body .= '<p>' . esc_html__( 'Every change is logged and can be undone. You can disconnect any time under the Visual Realm menu in WordPress admin.', 'visual-realm' ) . '</p>';
		if ( VR_Settings::is_loopback( $params['redirect_uri'] ) ) {
			$body .= '<p><strong>' . esc_html__( 'This connection returns to an app on your own computer (Claude Code). Only allow it if you started this from Claude Code just now.', 'visual-realm' ) . '</strong></p>';
		}
		$body .= '<div class="meta">' . esc_html( sprintf( /* translators: 1: user, 2: host */ __( 'Signed in as %1$s · returns to %2$s', 'visual-realm' ), $user->user_login, $host ) ) . '</div>';
		$body .= '<form method="post" action="' . esc_url( home_url( '/vr-oauth/authorize' ) ) . '">' . $fields;
		$body .= '<div class="row"><button type="submit" name="decision" value="deny">' . esc_html__( 'Cancel', 'visual-realm' ) . '</button><button class="primary" type="submit" name="decision" value="approve">' . esc_html__( 'Allow', 'visual-realm' ) . '</button></div></form>';

		self::page( __( 'Connect', 'visual-realm' ), $body );
	}

	/* ---------------------------------------------------------------------
	 * Token endpoint
	 * ------------------------------------------------------------------- */

	/**
	 * Send an OAuth token error.
	 *
	 * @param string $error  Code.
	 * @param string $desc   Description.
	 * @param int    $status Status.
	 */
	protected static function token_error( $error, $desc = '', $status = 400 ) {
		$headers = 401 === $status ? array( 'WWW-Authenticate: Basic realm="vr-oauth"' ) : array();
		self::json( array_filter( array( 'error' => $error, 'error_description' => $desc ) ), $status, $headers );
	}

	/**
	 * Authenticate the client on the token or revoke endpoint.
	 *
	 * @param array $post Request body.
	 * @return array Client.
	 */
	protected static function authenticate_client( array $post ) {
		$id     = isset( $post['client_id'] ) ? (string) $post['client_id'] : '';
		$secret = isset( $post['client_secret'] ) ? (string) $post['client_secret'] : '';

		$auth = self::authorization_header();
		if ( 0 === stripos( $auth, 'basic ' ) ) {
			$decoded = base64_decode( substr( $auth, 6 ), true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $bid, $bsecret ) = explode( ':', $decoded, 2 );
				$id                    = rawurldecode( $bid );
				$secret                = rawurldecode( $bsecret );
			}
		}

		$client = self::get_client( $id );
		if ( ! $client ) {
			self::token_error( 'invalid_client', 'Unknown client.', 401 );
		}
		if ( '' !== $client['client_secret_hash'] && ! hash_equals( $client['client_secret_hash'], hash( 'sha256', $secret ) ) ) {
			self::token_error( 'invalid_client', 'Client authentication failed.', 401 );
		}
		return $client;
	}

	/**
	 * Handle /vr-oauth/token.
	 */
	protected static function token() {
		if ( ! self::rate_ok( 'token', 60, MINUTE_IN_SECONDS ) ) {
			self::token_error( 'slow_down', 'Too many requests.', 429 );
		}
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		if ( empty( $post ) ) {
			$json = json_decode( (string) file_get_contents( 'php://input' ), true );
			$post = is_array( $json ) ? $json : array();
		}
		$client = self::authenticate_client( $post );
		$grant  = isset( $post['grant_type'] ) ? (string) $post['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			$code = isset( $post['code'] ) ? (string) $post['code'] : '';
			$key  = 'vr_code_' . hash( 'sha256', $code );
			$data = '' === $code ? false : get_transient( $key );
			// Only the request that actually deletes the code may use it.
			$won = is_array( $data ) && delete_transient( $key );
			if ( ! $won || $data['client_id'] !== $client['client_id'] ) {
				self::token_error( 'invalid_grant', 'Code is invalid or expired.' );
			}
			$redirect = isset( $post['redirect_uri'] ) ? (string) $post['redirect_uri'] : '';
			if ( '' !== $redirect && $redirect !== $data['redirect_uri'] ) {
				self::token_error( 'invalid_grant', 'redirect_uri mismatch.' );
			}
			$verifier = isset( $post['code_verifier'] ) ? (string) $post['code_verifier'] : '';
			if ( strlen( $verifier ) < 43 || strlen( $verifier ) > 128 || ! hash_equals( $data['code_challenge'], self::b64url( hash( 'sha256', $verifier, true ) ) ) ) {
				self::token_error( 'invalid_grant', 'PKCE verification failed.' );
			}
			if ( ! user_can( (int) $data['user_id'], VR_Settings::get( 'capability' ) ) ) {
				self::token_error( 'invalid_grant', 'User no longer has access.' );
			}
			self::json( self::issue_tokens( $client['client_id'], (int) $data['user_id'], bin2hex( random_bytes( 16 ) ), (string) $data['resource'] ) );
		}

		if ( 'refresh_token' === $grant ) {
			global $wpdb;
			$token = isset( $post['refresh_token'] ) ? (string) $post['refresh_token'] : '';
			$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VR_DB::table( 'tokens' ) . ' WHERE token_hash = %s AND token_type = %s', hash( 'sha256', $token ), 'refresh' ), ARRAY_A );
			if ( ! $row || $row['client_id'] !== $client['client_id'] ) {
				self::token_error( 'invalid_grant', 'Refresh token is invalid.' );
			}
			if ( (int) $row['revoked'] ) {
				// Reuse of a rotated token: shut the whole grant down.
				self::revoke_family( $row['family'] );
				self::token_error( 'invalid_grant', 'Refresh token was already used.' );
			}
			if ( strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
				self::token_error( 'invalid_grant', 'Refresh token expired.' );
			}
			if ( ! user_can( (int) $row['user_id'], VR_Settings::get( 'capability' ) ) ) {
				self::revoke_family( $row['family'] );
				self::token_error( 'invalid_grant', 'User no longer has access.' );
			}
			// Rotate atomically: if another request already used this token, treat it as reuse.
			$won = (int) $wpdb->query( $wpdb->prepare( 'UPDATE ' . VR_DB::table( 'tokens' ) . ' SET revoked = 1 WHERE id = %d AND revoked = 0', $row['id'] ) );
			if ( 1 !== $won ) {
				self::revoke_family( $row['family'] );
				self::token_error( 'invalid_grant', 'Refresh token was already used.' );
			}
			self::json( self::issue_tokens( $client['client_id'], (int) $row['user_id'], $row['family'], $row['resource'] ) );
		}

		self::token_error( 'unsupported_grant_type' );
	}

	/**
	 * Create an access + refresh token pair.
	 *
	 * @param string $client_id Client.
	 * @param int    $user_id   User.
	 * @param string $family    Grant family.
	 * @param string $resource  Resource indicator.
	 * @return array Token response.
	 */
	protected static function issue_tokens( $client_id, $user_id, $family, $resource ) {
		global $wpdb;
		$access     = 'vra_' . bin2hex( random_bytes( 32 ) );
		$refresh    = 'vrr_' . bin2hex( random_bytes( 32 ) );
		$access_ttl = (int) VR_Settings::get( 'access_ttl' );
		$base       = array(
			'family'     => $family,
			'client_id'  => $client_id,
			'user_id'    => $user_id,
			'scope'      => 'mcp',
			'resource'   => substr( $resource, 0, 255 ),
			'created_at' => VR_DB::now(),
		);
		$wpdb->insert( VR_DB::table( 'tokens' ), $base + array( 'token_hash' => hash( 'sha256', $access ), 'token_type' => 'access', 'expires_at' => VR_DB::now( $access_ttl ) ) );
		$wpdb->insert( VR_DB::table( 'tokens' ), $base + array( 'token_hash' => hash( 'sha256', $refresh ), 'token_type' => 'refresh', 'expires_at' => VR_DB::now( (int) VR_Settings::get( 'refresh_ttl' ) ) ) );
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => $access_ttl,
			'refresh_token' => $refresh,
			'scope'         => 'mcp',
		);
	}

	/**
	 * Handle /vr-oauth/revoke (RFC 7009).
	 */
	protected static function revoke_endpoint() {
		$post = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
		self::authenticate_client( $post );
		$token = isset( $post['token'] ) ? (string) $post['token'] : '';
		if ( '' !== $token ) {
			global $wpdb;
			$family = $wpdb->get_var( $wpdb->prepare( 'SELECT family FROM ' . VR_DB::table( 'tokens' ) . ' WHERE token_hash = %s', hash( 'sha256', $token ) ) );
			if ( $family ) {
				self::revoke_family( $family );
			}
		}
		self::json( new stdClass() );
	}

	/**
	 * Revoke every token in a grant.
	 *
	 * @param string $family Family.
	 */
	public static function revoke_family( $family ) {
		global $wpdb;
		$wpdb->update( VR_DB::table( 'tokens' ), array( 'revoked' => 1 ), array( 'family' => $family ) );
	}

	/* ---------------------------------------------------------------------
	 * Bearer validation (used by the MCP endpoint)
	 * ------------------------------------------------------------------- */

	/**
	 * Read the Authorization header, including the copies some servers make.
	 *
	 * @return string
	 */
	public static function authorization_header() {
		foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				return trim( wp_unslash( $_SERVER[ $key ] ) ); // phpcs:ignore
			}
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( (array) getallheaders() as $name => $value ) {
				if ( 'authorization' === strtolower( $name ) ) {
					return trim( $value );
				}
			}
		}
		return '';
	}

	/**
	 * Validate a bearer access token.
	 *
	 * @param string $header Authorization header value.
	 * @return WP_User|null
	 */
	public static function user_from_bearer( $header ) {
		if ( 0 !== stripos( $header, 'bearer ' ) ) {
			return null;
		}
		$token = trim( substr( $header, 7 ) );
		if ( '' === $token ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . VR_DB::table( 'tokens' ) . ' WHERE token_hash = %s AND token_type = %s', hash( 'sha256', $token ), 'access' ), ARRAY_A );
		if ( ! $row || (int) $row['revoked'] || strtotime( $row['expires_at'] . ' UTC' ) < time() ) {
			return null;
		}
		$user = get_user_by( 'id', (int) $row['user_id'] );
		if ( ! $user || ! user_can( $user, VR_Settings::get( 'capability' ) ) ) {
			return null;
		}
		if ( empty( $row['last_used_at'] ) || strtotime( $row['last_used_at'] . ' UTC' ) < time() - MINUTE_IN_SECONDS ) {
			$wpdb->update( VR_DB::table( 'tokens' ), array( 'last_used_at' => VR_DB::now() ), array( 'family' => $row['family'], 'token_type' => 'refresh', 'revoked' => 0 ) );
			$wpdb->update( VR_DB::table( 'tokens' ), array( 'last_used_at' => VR_DB::now() ), array( 'id' => $row['id'] ) );
		}
		return $user;
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Base64url without padding.
	 *
	 * @param string $bin Binary.
	 * @return string
	 */
	public static function b64url( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' );
	}

	/**
	 * Simple per-IP rate limit.
	 *
	 * @param string $bucket Bucket.
	 * @param int    $max    Max hits.
	 * @param int    $window Window seconds.
	 * @return bool
	 */
	protected static function rate_ok( $bucket, $max, $window ) {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0';
		$key = 'vr_rl_' . $bucket . '_' . md5( $ip );
		$n   = (int) get_transient( $key );
		if ( $n >= $max ) {
			return false;
		}
		set_transient( $key, $n + 1, $window );
		return true;
	}
}
