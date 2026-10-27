<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * Self-check: can Claude still reach this site, and can it still get updates?
 * Runs after every WordPress, plugin or theme update, and with each check-in.
 */
class Health {

	const OPTION = 'signal_flare_health';

	public static function init(): void {
		add_action( 'upgrader_process_complete', array( __CLASS__, 'run' ), 99, 0 );
		add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
	}

	/**
	 * @return array{ok:bool,time:int,checks:array<string,array{ok:bool,label:string}>}
	 */
	public static function run(): array {
		global $wpdb;
		$checks = array(
			'php'           => array( version_compare( PHP_VERSION, '8.0', '>=' ), 'PHP 8.0 or newer (this site has ' . PHP_VERSION . ')' ),
			'app_passwords' => array( wp_is_application_passwords_available(), 'Application Passwords are available (Claude logs in with one)' ),
			'endpoint'      => array( self::endpoint_registered(), 'The Signal Flare connection address is registered' ),
			'log'           => array( Log::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Log::table() ) ), 'The activity log table exists' ), // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			'signatures'    => array( function_exists( 'sodium_crypto_sign_verify_detached' ), 'PHP can check update signatures (sodium)' ),
		);
		if ( Settings::code_tools() ) {
			$checks['wp_cli'] = array( '' !== Tools::find_wp_cli(), 'WP-CLI is available' );
		}

		$result = array(
			'ok'     => true,
			'time'   => time(),
			'checks' => array(),
		);
		foreach ( $checks as $key => list( $ok, $label ) ) {
			$result['checks'][ $key ] = array(
				'ok'    => (bool) $ok,
				'label' => $label,
			);
			$result['ok'] = $result['ok'] && $ok;
		}
		update_option( self::OPTION, $result, false );
		return $result;
	}

	public static function last(): array {
		$last = get_option( self::OPTION );
		return is_array( $last ) ? $last : self::run();
	}

	/** Labels of failed checks. */
	public static function failed( array $result ): array {
		return array_values( wp_list_pluck( array_filter( $result['checks'], static fn( $c ) => ! $c['ok'] ), 'label' ) );
	}

	private static function endpoint_registered(): bool {
		$routes = rest_get_server()->get_routes( Server::NAMESPACE );
		return isset( $routes[ '/' . Server::NAMESPACE . Server::ROUTE ] );
	}

	public static function notice(): void {
		if ( ! current_user_can( Settings::capability() ) ) {
			return;
		}
		$result = get_option( self::OPTION );
		if ( ! is_array( $result ) || $result['ok'] ) {
			return;
		}
		echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'Signal Flare self-check failed:', 'signal-flare' ) . '</strong> ' . esc_html( implode( '; ', self::failed( $result ) ) ) . '. ';
		echo '<a href="' . esc_url( admin_url( 'admin.php?page=' . Admin::SLUG ) ) . '">' . esc_html__( 'Details', 'signal-flare' ) . '</a></p></div>';
	}
}
