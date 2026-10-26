<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * The MCP endpoint: JSON-RPC 2.0 over MCP's Streamable HTTP transport, answered with plain JSON.
 */
class Server {

	const NAMESPACE = 'signal-flare/v1';
	const ROUTE     = '/mcp';

	const PROTOCOL_VERSIONS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/** The tool call in progress, so a fatal error can still be answered and logged. */
	private static ?array $current = null;

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function url(): string {
		return rest_url( self::NAMESPACE . self::ROUTE );
	}

	public static function routes(): void {
		register_rest_route(
			self::NAMESPACE,
			self::ROUTE,
			array(
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'handle' ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
				array(
					// No server-to-client stream: MCP clients treat 405 as "use POST only".
					'methods'             => 'GET, DELETE',
					'callback'            => static fn() => new \WP_REST_Response( null, 405 ),
					'permission_callback' => array( __CLASS__, 'permission' ),
				),
			)
		);
	}

	/**
	 * Who may talk to the endpoint: Signal Flare must be on, and the caller must be an
	 * admin logged in with an Application Password. Browser cookies are refused, so a
	 * web page can't use an admin's open session to drive it.
	 */
	public static function permission() {
		if ( ! Settings::enabled() ) {
			return new \WP_Error( 'signal_flare_off', 'Signal Flare is switched off. Turn it on under Signal Flare in the WordPress dashboard.', array( 'status' => 503 ) );
		}
		if ( ! is_user_logged_in() ) {
			return new \WP_Error( 'signal_flare_login', 'Log in with a WordPress Application Password (HTTP Basic auth).', array( 'status' => 401 ) );
		}
		if ( ! did_action( 'application_password_did_authenticate' ) ) {
			return new \WP_Error( 'signal_flare_app_password', 'Signal Flare only accepts Application Passwords, not browser logins.', array( 'status' => 401 ) );
		}
		if ( ! current_user_can( Settings::capability() ) ) {
			return new \WP_Error( 'signal_flare_forbidden', 'Only administrators can use Signal Flare.', array( 'status' => 403 ) );
		}
		return true;
	}

	public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
		$body = json_decode( $request->get_body(), true );
		if ( ! is_array( $body ) ) {
			return new \WP_REST_Response( self::error( null, -32700, 'Parse error: the body must be JSON.' ), 200 );
		}

		$is_batch  = array_is_list( $body );
		$messages  = $is_batch ? $body : array( $body );
		$responses = array();
		foreach ( $messages as $message ) {
			$response = is_array( $message ) ? self::dispatch( $message ) : self::error( null, -32600, 'Invalid request.' );
			if ( null !== $response ) {
				$responses[] = $response;
			}
		}

		if ( ! $responses ) {
			return new \WP_REST_Response( null, 202 ); // Only notifications: acknowledged.
		}
		$response = new \WP_REST_Response( $is_batch ? $responses : $responses[0], 200 );
		$response->header( 'Cache-Control', 'no-store' );
		return $response;
	}

	/**
	 * Answer one JSON-RPC message. Notifications and client responses get no reply.
	 */
	private static function dispatch( array $message ): ?array {
		$has_id = array_key_exists( 'id', $message );
		$id     = $message['id'] ?? null;
		$method = $message['method'] ?? null;
		$params = is_array( $message['params'] ?? null ) ? $message['params'] : array();

		if ( ! is_string( $method ) ) {
			return $has_id && ! isset( $message['result'] ) && ! isset( $message['error'] ) ? self::error( $id, -32600, 'Invalid request.' ) : null;
		}
		if ( ! $has_id ) {
			return null;
		}

		switch ( $method ) {
			case 'initialize':
				$requested = (string) ( $params['protocolVersion'] ?? '' );
				return self::result(
					$id,
					array(
						'protocolVersion' => in_array( $requested, self::PROTOCOL_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSIONS[0],
						'capabilities'    => array( 'tools' => array( 'listChanged' => false ) ),
						'serverInfo'      => array(
							'name'    => 'signal-flare',
							'title'   => 'Signal Flare',
							'version' => SIGNAL_FLARE_VERSION,
						),
						'instructions'    => self::instructions(),
					)
				);

			case 'ping':
				return self::result( $id, new \stdClass() );

			case 'tools/list':
				$tools = array();
				foreach ( Tools::definitions() as $name => $tool ) {
					$tools[] = array(
						'name'        => $name,
						'description' => $tool['description'],
						'inputSchema' => $tool['inputSchema'],
					);
				}
				return self::result( $id, array( 'tools' => $tools ) );

			case 'tools/call':
				return self::result( $id, self::call_tool( (string) ( $params['name'] ?? '' ), is_array( $params['arguments'] ?? null ) ? $params['arguments'] : array() ) );

			case 'resources/list':
				return self::result( $id, array( 'resources' => array() ) );

			case 'prompts/list':
				return self::result( $id, array( 'prompts' => array() ) );

			default:
				return self::error( $id, -32601, "Method not found: $method" );
		}
	}

	private static function call_tool( string $name, array $args ): array {
		$start         = microtime( true );
		$summary       = Log::summarize( $args );
		self::$current = array(
			'name'    => $name,
			'summary' => $summary,
			'start'   => $start,
		);
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

		try {
			$data   = Tools::call( $name, $args );
			$text   = (string) wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
			$status = 'ok';
			$error  = false;
		} catch ( \Throwable $e ) {
			$text   = $e instanceof \RuntimeException ? $e->getMessage() : get_class( $e ) . ': ' . $e->getMessage();
			$status = 'error';
			$error  = true;
		}
		self::$current = null;

		Log::add( $name, $summary, $status, $error ? $text : '', (int) round( ( microtime( true ) - $start ) * 1000 ) );
		return array(
			'content' => array(
				array(
					'type' => 'text',
					'text' => $text,
				),
			),
			'isError' => $error,
		);
	}

	/**
	 * A fatal error mid-tool would otherwise end the request with nothing useful.
	 * Log it and send Claude a readable error instead.
	 */
	public static function on_shutdown(): void {
		if ( null === self::$current ) {
			return;
		}
		$fatal = error_get_last();
		if ( ! $fatal || ! in_array( $fatal['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ), true ) ) {
			return;
		}
		$message = 'Fatal error: ' . $fatal['message'] . ' in ' . basename( (string) $fatal['file'] ) . ' on line ' . $fatal['line'];
		Log::add( self::$current['name'], self::$current['summary'], 'fatal', $message, (int) round( ( microtime( true ) - self::$current['start'] ) * 1000 ) );

		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: application/json; charset=utf-8' );
		}
		echo wp_json_encode( // phpcs:ignore WordPress.Security.EscapeOutput -- JSON response.
			array(
				'jsonrpc' => '2.0',
				'id'      => null,
				'error'   => array(
					'code'    => -32603,
					'message' => $message,
				),
			)
		);
	}

	private static function instructions(): string {
		return 'Signal Flare gives you administrator-level access to the WordPress site at ' . home_url() . '. '
			. 'Call site_info first. Every call is logged and visible to the site owner. '
			. 'Ask before changing or deleting existing pages, posts, users or settings unless the user has asked for that change. '
			. 'Signal Flare cannot edit its own files; never deactivate or delete the Signal Flare plugin, since that ends this connection. '
			. 'PHP files are syntax-checked before they are written.';
	}

	private static function result( $id, $result ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	private static function error( $id, int $code, string $message ): array {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
