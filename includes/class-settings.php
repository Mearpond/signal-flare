<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's switches. Everything starts off.
 */
class Settings {

	const OPTION = 'signal_flare_settings';

	public static function get(): array {
		return wp_parse_args(
			(array) get_option( self::OPTION, array() ),
			array(
				'enabled'    => false, // Master switch: nothing answers while off.
				'code_tools' => false, // Code and file tools (run PHP, WP-CLI, files).
			)
		);
	}

	public static function enabled(): bool {
		return (bool) self::get()['enabled'];
	}

	public static function code_tools(): bool {
		return (bool) self::get()['code_tools'];
	}

	public static function update( array $values ): void {
		$settings = self::get();
		foreach ( array( 'enabled', 'code_tools' ) as $key ) {
			if ( array_key_exists( $key, $values ) ) {
				$settings[ $key ] = (bool) $values[ $key ];
			}
		}
		update_option( self::OPTION, $settings, true );
	}

	/**
	 * Who may connect. Filterable, but never below admins in practice.
	 */
	public static function capability(): string {
		return (string) apply_filters( 'signal_flare_capability', 'manage_options' );
	}
}
