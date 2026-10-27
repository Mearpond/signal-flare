<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * Checks in with Signal Tower every hour: versions, updates waiting, health.
 *
 * Reporting only goes out. The tower's only say in return is "install this
 * release of Field Mark or Signal Flare", and a release installs only if it's
 * signed with the key built into the plugin, so the tower can't push anything else.
 */
class Reporter {

	const OPTION       = 'signal_flare_tower';
	const HOOK         = 'signal_flare_report';
	const INSTALL_HOOK = 'signal_flare_install';

	/** Plugins the tower may ask this site to update. */
	const UPDATABLE = array( 'fieldmark', 'signal-flare' );

	public static function init(): void {
		add_action( self::HOOK, array( __CLASS__, 'send' ) );
		add_action( self::INSTALL_HOOK, array( __CLASS__, 'install' ) );
		// After any update, re-check health and tell the tower soon (not mid-update).
		add_action(
			'upgrader_process_complete',
			static function () {
				if ( self::connected() && ! wp_next_scheduled( self::HOOK ) ) {
					wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::HOOK );
				}
			},
			100,
			0
		);
		if ( self::connected() && ! wp_next_scheduled( self::HOOK ) ) {
			wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK );
		}
	}

	public static function settings(): array {
		$value = get_option( self::OPTION );
		return is_array( $value ) ? $value : array();
	}

	public static function connected(): bool {
		$s = self::settings();
		return ! empty( $s['url'] ) && ! empty( $s['site'] ) && ! empty( $s['secret'] );
	}

	/**
	 * Save a connection code from the tower's dashboard.
	 *
	 * @throws \RuntimeException When the code isn't valid.
	 */
	public static function connect( string $code ): void {
		$code = trim( $code );
		$json = str_starts_with( $code, 'st1.' ) ? base64_decode( strtr( substr( $code, 4 ), '-_', '+/' ), true ) : false;
		$data = $json ? json_decode( $json, true ) : null;
		$url  = is_array( $data ) ? (string) ( $data['u'] ?? '' ) : '';
		if ( ! is_array( $data )
			|| ! str_starts_with( $url, 'https://' ) || ! str_ends_with( untrailingslashit( $url ), '/signal-tower/v1' )
			|| absint( $data['i'] ?? 0 ) < 1
			|| ! preg_match( '/^[a-f0-9]{64}$/', (string) ( $data['s'] ?? '' ) )
		) {
			throw new \RuntimeException( __( 'That is not a valid Signal Tower connection code.', 'signal-flare' ) );
		}
		update_option(
			self::OPTION,
			array(
				'url'    => untrailingslashit( esc_url_raw( $url ) ),
				'site'   => absint( $data['i'] ),
				'secret' => (string) $data['s'],
			),
			false
		);
		wp_clear_scheduled_hook( self::HOOK );
		wp_schedule_event( time() + MINUTE_IN_SECONDS, 'hourly', self::HOOK );
	}

	public static function disconnect(): void {
		delete_option( self::OPTION );
		wp_clear_scheduled_hook( self::HOOK );
	}

	/**
	 * Send a check-in now. Updates the tower asks for are installed in a
	 * separate background run, so they never happen mid-request.
	 */
	public static function send(): array {
		$settings = self::settings();
		if ( ! self::connected() ) {
			return array( 'ok' => false, 'message' => 'Not connected to Signal Tower.' );
		}

		$body     = (string) wp_json_encode( self::report(), JSON_UNESCAPED_SLASHES );
		$time     = time();
		$response = wp_remote_post(
			$settings['url'] . '/report',
			array(
				'timeout' => 20,
				'headers' => array(
					'Content-Type'             => 'application/json',
					'X-Signal-Tower-Site'      => (string) $settings['site'],
					'X-Signal-Tower-Time'      => (string) $time,
					'X-Signal-Tower-Signature' => hash_hmac( 'sha256', $time . "\n" . $body, $settings['secret'] ),
				),
				'body'    => $body,
			)
		);

		$code   = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$answer = is_wp_error( $response ) ? null : json_decode( wp_remote_retrieve_body( $response ), true );
		$ok     = 200 === $code && is_array( $answer ) && ! empty( $answer['ok'] );
		$result = array(
			'ok'      => $ok,
			'time'    => $time,
			'message' => $ok ? '' : ( is_wp_error( $response ) ? $response->get_error_message() : 'Signal Tower answered HTTP ' . $code . '.' ),
		);

		$install = array();
		foreach ( $ok ? (array) ( $answer['install'] ?? array() ) : array() as $item ) {
			$slug    = (string) ( $item['slug'] ?? '' );
			$version = (string) ( $item['version'] ?? '' );
			if ( in_array( $slug, self::UPDATABLE, true ) && preg_match( '/^\d+(\.\d+){1,3}$/', $version ) ) {
				$install[ $slug ] = $version;
			}
		}
		if ( $install && ! wp_next_scheduled( self::INSTALL_HOOK, array( $install ) ) ) {
			wp_schedule_single_event( time(), self::INSTALL_HOOK, array( $install ) );
			$result['message'] = 'Installing: ' . implode( ', ', array_keys( $install ) );
		}

		$settings['last'] = $result;
		update_option( self::OPTION, $settings, false );
		return $result;
	}

	/**
	 * Install releases the tower asked for. Each goes through the normal WordPress
	 * updater, whose download is checked against the release signature.
	 *
	 * @param array<string,string> $install slug => version
	 */
	public static function install( $install ): void {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

		foreach ( (array) $install as $slug => $version ) {
			$slug     = (string) $slug;
			$basename = $slug . '/' . $slug . '.php';
			if ( ! in_array( $slug, self::UPDATABLE, true ) || ! file_exists( WP_PLUGIN_DIR . '/' . $basename ) ) {
				continue;
			}
			$current = get_plugin_data( WP_PLUGIN_DIR . '/' . $basename, false, false )['Version'];
			if ( ! version_compare( (string) $version, $current, '>' ) ) {
				continue;
			}

			// Fetch the latest release info now, then let WordPress pick it up as an available update.
			delete_site_transient( Updater::cache_key( $slug ) );
			$transient = get_site_transient( 'update_plugins' );
			set_site_transient( 'update_plugins', is_object( $transient ) ? $transient : new \stdClass() );

			$active   = is_plugin_active( $basename );
			$start    = microtime( true );
			$upgrader = new \Plugin_Upgrader( new \Automatic_Upgrader_Skin() );
			$result   = $upgrader->upgrade( $basename );
			if ( $active && ! is_plugin_active( $basename ) ) {
				activate_plugin( $basename, '', false, true );
			}
			$error = is_wp_error( $result ) ? $result->get_error_message() : ( true === $result ? '' : 'The update did not complete.' );
			if ( '' !== $error && is_wp_error( $upgrader->skin->result ?? null ) ) {
				$error = $upgrader->skin->result->get_error_message();
			}
			Log::add( 'tower_update', (string) wp_json_encode( array( 'plugin' => $slug, 'from' => $current, 'to' => $version ) ), $error ? 'error' : 'ok', $error, (int) round( ( microtime( true ) - $start ) * 1000 ) );
		}
		// Report the new versions straight away.
		wp_schedule_single_event( time() + 30, self::HOOK );
	}

	/**
	 * What the tower sees. No passwords, file contents or code: versions, update
	 * availability, switches, health and counts only.
	 */
	public static function report(): array {
		global $wpdb;
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$plugin_updates = get_site_transient( 'update_plugins' );
		$plugins        = array();
		foreach ( get_plugins() as $file => $data ) {
			$plugins[] = array(
				'file'    => $file,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
				'update'  => (string) ( $plugin_updates->response[ $file ]->new_version ?? '' ),
			);
		}

		$theme_updates = get_site_transient( 'update_themes' );
		$active_theme  = get_stylesheet();
		$themes        = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => $slug === $active_theme,
				'update'  => (string) ( $theme_updates->response[ $slug ]['new_version'] ?? '' ),
			);
		}

		$core_update = '';
		$core        = get_site_transient( 'update_core' );
		foreach ( (array) ( $core->updates ?? array() ) as $offer ) {
			if ( 'upgrade' === ( $offer->response ?? '' ) ) {
				$core_update = (string) $offer->current;
				break;
			}
		}

		$health = Health::run();
		$log    = Log::table();
		return array(
			'site'         => home_url(),
			'name'         => get_bloginfo( 'name' ),
			'wordpress'    => get_bloginfo( 'version' ),
			'php'          => PHP_VERSION,
			'core_update'  => $core_update,
			'plugins'      => $plugins,
			'themes'       => $themes,
			'signal_flare' => array(
				'version'    => SIGNAL_FLARE_VERSION,
				'enabled'    => Settings::enabled(),
				'code_tools' => Settings::code_tools(),
			),
			'health'       => array(
				'ok'     => $health['ok'],
				'failed' => Health::failed( $health ),
			),
			'activity'     => array(
				'last_call'  => (int) strtotime( (string) $wpdb->get_var( "SELECT MAX(created) FROM $log" ) . ' UTC' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
				'errors_24h' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $log WHERE status <> 'ok' AND created > %s", gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS ) ) ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			),
		);
	}
}
