<?php
/**
 * MCP endpoint (streamable HTTP transport, stateless JSON responses).
 *
 * @package VisualRealm
 */

defined( 'ABSPATH' ) || exit;

class VR_MCP {

	const NS    = 'visual-realm/v1';
	const ROUTE = '/mcp';

	/**
	 * Protocol versions this server speaks, newest first.
	 */
	const PROTOCOLS = array( '2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * Hook in.
	 */
	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
	}

	/**
	 * Public URL of the endpoint.
	 *
	 * @return string
	 */
	public static function endpoint_url() {
		return rest_url( self::NS . self::ROUTE );
	}

	/**
	 * Register the REST route.
	 */
	public static function register_route() {
		register_rest_route(
			self::NS,
			self::ROUTE,
			array(
				'methods'             => array( 'GET', 'POST', 'DELETE' ),
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => '__return_true', // Bearer auth is enforced in handle().
			)
		);
	}

	/**
	 * Write a response and stop.
	 *
	 * @param int        $status  Status.
	 * @param mixed|null $body    Body (null for none).
	 * @param array      $headers Headers.
	 */
	protected static function send( $status, $body = null, array $headers = array() ) {
		nocache_headers();
		header( 'Cache-Control: no-store' );
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Expose-Headers: WWW-Authenticate, MCP-Protocol-Version' );
		foreach ( $headers as $h ) {
			header( $h, false );
		}
		status_header( $status );
		if ( null !== $body ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( $body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE );
		}
		exit;
	}

	/**
	 * Handle a request.
	 *
	 * @param WP_REST_Request $request Request.
	 */
	public static function handle( WP_REST_Request $request ) {
		$auth = VR_OAuth::authorization_header();
		if ( '' === $auth ) {
			$auth = (string) $request->get_header( 'authorization' );
		}
		$user = VR_OAuth::user_from_bearer( $auth );
		if ( ! $user ) {
			$challenge = 'Bearer resource_metadata="' . VR_OAuth::resource_metadata_url() . '", scope="mcp"';
			if ( '' !== $auth ) {
				$challenge .= ', error="invalid_token"';
			}
			self::send( 401, array( 'error' => 'unauthorized' ), array( 'WWW-Authenticate: ' . $challenge ) );
		}
		wp_set_current_user( $user->ID );

		$method = $request->get_method();
		if ( 'POST' !== $method ) {
			self::send( 405, array( 'error' => 'method_not_allowed' ), array( 'Allow: POST' ) );
		}

		$raw     = $request->get_body();
		$payload = json_decode( (string) $raw, true );
		if ( null === $payload && JSON_ERROR_NONE !== json_last_error() ) {
			self::send( 400, self::error( null, -32700, 'Parse error' ) );
		}
		if ( ! is_array( $payload ) ) {
			self::send( 400, self::error( null, -32600, 'Invalid Request' ) );
		}

		$is_batch = array_keys( $payload ) === range( 0, count( $payload ) - 1 );
		$messages = $is_batch ? $payload : array( $payload );
		$out      = array();
		$version  = '';
		foreach ( $messages as $msg ) {
			$res = self::dispatch( $msg, $version );
			if ( null !== $res ) {
				$out[] = $res;
			}
		}

		$headers = '' !== $version ? array( 'MCP-Protocol-Version: ' . $version ) : array();
		if ( empty( $out ) ) {
			self::send( 202, null, $headers );
		}
		self::send( 200, $is_batch ? $out : $out[0], $headers );
	}

	/**
	 * JSON-RPC error object.
	 *
	 * @param mixed  $id      ID.
	 * @param int    $code    Code.
	 * @param string $message Message.
	 * @return array
	 */
	protected static function error( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}

	/**
	 * Dispatch one JSON-RPC message.
	 *
	 * @param mixed  $msg     Message.
	 * @param string $version Negotiated protocol version (out).
	 * @return array|null Response, or null for notifications/responses.
	 */
	protected static function dispatch( $msg, &$version ) {
		if ( ! is_array( $msg ) || ! isset( $msg['jsonrpc'] ) || '2.0' !== $msg['jsonrpc'] ) {
			return self::error( null, -32600, 'Invalid Request' );
		}
		if ( ! isset( $msg['method'] ) ) {
			return null; // A response from the client; nothing to do.
		}
		$has_id = array_key_exists( 'id', $msg );
		$id     = $has_id ? $msg['id'] : null;
		$params = isset( $msg['params'] ) && is_array( $msg['params'] ) ? $msg['params'] : array();

		if ( ! $has_id ) {
			return null; // Notification (e.g. notifications/initialized).
		}

		switch ( $msg['method'] ) {
			case 'initialize':
				$asked   = isset( $params['protocolVersion'] ) ? (string) $params['protocolVersion'] : '';
				$version = in_array( $asked, self::PROTOCOLS, true ) ? $asked : self::PROTOCOLS[0];
				$result  = array(
					'protocolVersion' => $version,
					'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
					'serverInfo'      => array(
						'name'    => 'visual-realm',
						'title'   => 'Visual Realm · ' . wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
						'version' => VR_PLUGIN_VERSION,
					),
					'instructions'    => self::instructions(),
				);
				break;
			case 'ping':
				$result = new stdClass();
				break;
			case 'tools/list':
				$result = array( 'tools' => VR_Tools::definitions() );
				break;
			case 'tools/call':
				$name = isset( $params['name'] ) ? (string) $params['name'] : '';
				if ( ! VR_Tools::exists( $name ) ) {
					return self::error( $id, -32602, 'Unknown tool: ' . $name );
				}
				$args   = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();
				$result = VR_Tools::call( $name, $args );
				break;
			default:
				return self::error( $id, -32601, 'Method not found' );
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Server instructions shown to the client.
	 *
	 * @return string
	 */
	protected static function instructions() {
		$site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		return sprintf(
			'These tools manage the WordPress site "%1$s" (%2$s). New posts and pages are drafts unless status "publish" is passed. wp_update_post never changes status or dates unless you pass them. For large page bodies, create the post with the first part and add the rest with wp_append_content. wp_alter_post replaces exact text and fails unless the match count equals "expected" (default 1). Every write is logged; mcp_list_changes shows the log and mcp_undo_change reverts one change. After editing a published page, call vr_purge_cache for that page. Use wp_get_rendered to confirm text on the live page.',
			$site,
			home_url()
		);
	}
}
