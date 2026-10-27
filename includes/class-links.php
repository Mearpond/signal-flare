<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * One-time links: an upload link (send a large file straight to a fixed path)
 * and a login link (open a browser already logged in, for testing).
 *
 * Each link carries a long random token. Only its hash is stored, it expires
 * within minutes, and it works once.
 */
class Links {

	const ROUTE_UPLOAD = '/upload';
	const ROUTE_LOGIN  = '/login';

	const UPLOAD_HEADER = 'X-Signal-Flare-Upload-Token';

	/** Largest upload. */
	const MAX_UPLOAD_BYTES = 64 * MB_IN_BYTES;

	/** How long a login link lasts, and the browser session it opens. */
	const LOGIN_TTL   = 2 * MINUTE_IN_SECONDS;
	const SESSION_TTL = HOUR_IN_SECONDS;

	/** File types an upload link may write. Nothing the server would run as code. */
	const UPLOAD_TYPES = array( 'zip', 'gz', 'tgz', 'sql', 'csv', 'json', 'txt', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'pdf', 'mp4', 'webm', 'mp3', 'woff', 'woff2' );

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes(): void {
		register_rest_route(
			Server::NAMESPACE,
			self::ROUTE_UPLOAD,
			array(
				'methods'             => 'PUT, POST',
				'callback'            => array( __CLASS__, 'receive_upload' ),
				'permission_callback' => '__return_true', // The token is the credential; checked in the callback.
			)
		);
		register_rest_route(
			Server::NAMESPACE,
			self::ROUTE_LOGIN . '/(?P<token>[A-Za-z0-9]{48})',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'login' ),
				'permission_callback' => '__return_true', // The token is the credential; checked in the callback.
			)
		);
	}

	/* ---------- Creating links (called by tools) ---------- */

	/**
	 * @throws \RuntimeException When the destination isn't allowed.
	 */
	public static function create_upload( string $path, bool $overwrite, int $minutes ): array {
		$real = Paths::resolve( $path );
		if ( Paths::is_self( $real ) ) {
			throw new \RuntimeException( 'Upload links cannot write into Signal Flare itself.' );
		}
		if ( is_dir( $real ) ) {
			throw new \RuntimeException( 'That path is a folder; give the full file path.' );
		}
		$name = basename( $real );
		$type = strtolower( pathinfo( $real, PATHINFO_EXTENSION ) );
		// Some servers run "x.php.zip" as PHP, so a code extension anywhere in the name is refused too.
		if ( str_starts_with( $name, '.' ) || preg_match( '/\.(php\d?|phtml|phar|pht|phps|cgi|pl|py|sh|shtml|asp|aspx|jsp)(\.|$)/i', $name ) || ! in_array( $type, self::UPLOAD_TYPES, true ) ) {
			throw new \RuntimeException( 'Upload links only accept these file types: ' . implode( ', ', self::UPLOAD_TYPES ) . '. Use write_file for code.' );
		}
		if ( ! $overwrite && file_exists( $real ) ) {
			throw new \RuntimeException( Paths::relative( $real ) . ' already exists; set overwrite to replace it.' );
		}

		$ttl   = min( 60, max( 1, $minutes ) ) * MINUTE_IN_SECONDS;
		$token = self::issue(
			'upload',
			array(
				'path'      => $real,
				'overwrite' => $overwrite,
			),
			$ttl
		);
		return array(
			'upload_url' => rest_url( Server::NAMESPACE . self::ROUTE_UPLOAD ),
			'method'     => 'PUT',
			'header'     => self::UPLOAD_HEADER,
			'token'      => $token,
			'path'       => Paths::relative( $real ),
			'max_bytes'  => self::MAX_UPLOAD_BYTES,
			'expires_in' => $ttl,
			'example'    => 'curl -sf -X PUT -H "' . self::UPLOAD_HEADER . ': <token>" -H "Content-Type: application/octet-stream" --data-binary @file.zip ' . rest_url( Server::NAMESPACE . self::ROUTE_UPLOAD ),
		);
	}

	/**
	 * @throws \RuntimeException When the destination isn't on this site.
	 */
	public static function create_login( string $redirect ): array {
		$redirect = trim( $redirect );
		$target   = '' === $redirect ? admin_url() : home_url( '/' . ltrim( $redirect, '/' ) );
		if ( preg_match( '#^\s*[a-z][a-z0-9+.-]*:|^\s*//#i', $redirect ) || ! wp_validate_redirect( $target, false ) ) {
			throw new \RuntimeException( 'redirect must be a path on this site, like "wp-admin/" or "/some-page/".' );
		}
		$token = self::issue(
			'login',
			array( 'redirect' => $target ),
			self::LOGIN_TTL
		);
		return array(
			'login_url'     => rest_url( Server::NAMESPACE . self::ROUTE_LOGIN . '/' . $token ),
			'redirects_to'  => $target,
			'expires_in'    => self::LOGIN_TTL,
			'session_lasts' => self::SESSION_TTL,
			'note'          => 'Open it once in a browser. It logs that browser in as you for an hour.',
		);
	}

	/* ---------- Using links ---------- */

	public static function receive_upload( \WP_REST_Request $request ) {
		$link = self::redeem( 'upload', (string) $request->get_header( 'x_signal_flare_upload_token' ) );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		if ( ! user_can( (int) $link['user_id'], Settings::capability() ) ) {
			return new \WP_Error( 'signal_flare_link', 'This link is no longer valid.', array( 'status' => 403 ) );
		}
		wp_set_current_user( (int) $link['user_id'] ); // So the log names who made the link.

		$start = microtime( true );
		// Resolve again: a folder on the way could have been swapped for a symlink since the link was made.
		try {
			$path = Paths::resolve( (string) $link['data']['path'] );
		} catch ( \RuntimeException $e ) {
			return new \WP_Error( 'signal_flare_upload', $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( $path !== $link['data']['path'] || Paths::is_self( $path ) ) {
			return new \WP_Error( 'signal_flare_upload', 'The destination changed since the link was made.', array( 'status' => 400 ) );
		}
		$body  = $request->get_body();
		$files = $request->get_file_params();
		if ( '' === $body && isset( $files['file']['tmp_name'] ) && is_uploaded_file( $files['file']['tmp_name'] ) ) {
			$body = (string) file_get_contents( $files['file']['tmp_name'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		$error = null;
		if ( '' === $body ) {
			$error = 'The upload was empty.';
		} elseif ( strlen( $body ) > self::MAX_UPLOAD_BYTES ) {
			$error = 'The upload is larger than ' . size_format( self::MAX_UPLOAD_BYTES ) . '.';
		} elseif ( empty( $link['data']['overwrite'] ) && file_exists( $path ) ) {
			$error = Paths::relative( $path ) . ' already exists.';
		} else {
			$dir = dirname( $path );
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				$error = 'Could not create the folder ' . Paths::relative( $dir ) . '.';
			} else {
				// Write beside the target and rename, so a half-finished upload never sits at the real path.
				$tmp = $path . '.part-' . wp_generate_password( 8, false );
				if ( false === file_put_contents( $tmp, $body ) || ! rename( $tmp, $path ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors, WordPress.WP.AlternativeFunctions
					$error = 'Could not write ' . Paths::relative( $path ) . '.';
				}
			}
		}

		$summary = (string) wp_json_encode( array( 'path' => Paths::relative( $path ), 'bytes' => strlen( $body ) ), JSON_UNESCAPED_SLASHES );
		Log::add( 'upload', $summary, $error ? 'error' : 'ok', (string) $error, (int) round( ( microtime( true ) - $start ) * 1000 ) );
		if ( $error ) {
			return new \WP_Error( 'signal_flare_upload', $error, array( 'status' => 400 ) );
		}
		return rest_ensure_response(
			array(
				'path'   => Paths::relative( $path ),
				'bytes'  => strlen( $body ),
				'sha256' => hash( 'sha256', $body ),
			)
		);
	}

	public static function login( \WP_REST_Request $request ) {
		$link = self::redeem( 'login', (string) $request['token'] );
		if ( is_wp_error( $link ) ) {
			return $link;
		}
		$user = get_userdata( (int) $link['user_id'] );
		if ( ! $user || ! user_can( $user, Settings::capability() ) ) {
			return new \WP_Error( 'signal_flare_link', 'This link is no longer valid.', array( 'status' => 403 ) );
		}

		wp_set_current_user( $user->ID );
		$session = static fn() => self::SESSION_TTL;
		add_filter( 'auth_cookie_expiration', $session, 99 );
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		remove_filter( 'auth_cookie_expiration', $session, 99 );
		Log::add( 'login_link', (string) wp_json_encode( array( 'redirect' => $link['data']['redirect'] ), JSON_UNESCAPED_SLASHES ), 'ok', '', 0 );

		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		wp_safe_redirect( (string) $link['data']['redirect'] );
		exit;
	}

	/* ---------- Tokens ---------- */

	private static function issue( string $kind, array $data, int $ttl ): string {
		$token = wp_generate_password( 48, false );
		set_transient(
			self::key( $token ),
			array(
				'kind'    => $kind,
				'data'    => $data,
				'user_id' => get_current_user_id(),
				'expires' => time() + $ttl,
			),
			$ttl
		);
		return $token;
	}

	/**
	 * Check a token and use it up. Every failure gives the same answer,
	 * so a guess learns nothing.
	 *
	 * @return array|\WP_Error
	 */
	private static function redeem( string $kind, string $token ) {
		$invalid = new \WP_Error( 'signal_flare_link', 'This link has expired or was already used.', array( 'status' => 403 ) );
		if ( ! Settings::enabled() || ! Settings::code_tools() || ! preg_match( '/^[A-Za-z0-9]{48}$/', $token ) ) {
			return $invalid;
		}
		$key  = self::key( $token );
		$link = get_transient( $key );
		// Deleting first makes the link single-use: of two requests racing, only one delete succeeds.
		if ( ! is_array( $link ) || ! delete_transient( $key ) ) {
			return $invalid;
		}
		if ( ( $link['kind'] ?? '' ) !== $kind || (int) ( $link['expires'] ?? 0 ) < time() ) {
			return $invalid;
		}
		return $link;
	}

	private static function key( string $token ): string {
		return 'signal_flare_link_' . hash( 'sha256', $token );
	}
}
