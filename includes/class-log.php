<?php
namespace SignalFlare;

defined( 'ABSPATH' ) || exit;

/**
 * Activity log: one row per tool call.
 */
class Log {

	const SCHEMA_VERSION = '1';

	/** Rows older than this are pruned. */
	const KEEP_DAYS = 90;

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'signal_flare_log';
	}

	public static function maybe_upgrade(): void {
		if ( get_option( 'signal_flare_schema' ) !== self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta(
			'CREATE TABLE ' . self::table() . " (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created datetime NOT NULL,
			user_id bigint(20) unsigned NOT NULL DEFAULT 0,
			tool varchar(64) NOT NULL,
			summary text NULL,
			status varchar(16) NOT NULL,
			message text NULL,
			duration_ms int(11) NOT NULL DEFAULT 0,
			ip varchar(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY created (created)
		) " . $wpdb->get_charset_collate() . ';'
		);
		update_option( 'signal_flare_schema', self::SCHEMA_VERSION );
	}

	public static function add( string $tool, string $summary, string $status, string $message, int $duration_ms ): void {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created'     => current_time( 'mysql', true ),
				'user_id'     => get_current_user_id(),
				'tool'        => substr( $tool, 0, 64 ),
				'summary'     => mb_substr( $summary, 0, 4000 ),
				'status'      => $status,
				'message'     => mb_substr( $message, 0, 1000 ),
				'duration_ms' => $duration_ms,
				'ip'          => substr( sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ?? '' ) ), 0, 64 ),
			)
		);
		if ( 1 === wp_rand( 1, 50 ) ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created < %s', gmdate( 'Y-m-d H:i:s', time() - self::KEEP_DAYS * DAY_IN_SECONDS ) ) );
		}
	}

	public static function recent( int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' ORDER BY id DESC LIMIT %d OFFSET %d', $limit, $offset ) );
	}

	public static function count(): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::table() );
	}

	/**
	 * A loggable summary of a tool's arguments: file contents become byte counts,
	 * everything else is kept but truncated.
	 */
	public static function summarize( array $args ): string {
		foreach ( array( 'content', 'new_string', 'old_string' ) as $bulky ) {
			if ( isset( $args[ $bulky ] ) && is_string( $args[ $bulky ] ) ) {
				$args[ $bulky ] = '[' . strlen( $args[ $bulky ] ) . ' bytes]';
			}
		}
		if ( isset( $args['code'] ) && is_string( $args['code'] ) && strlen( $args['code'] ) > 2000 ) {
			$args['code'] = substr( $args['code'], 0, 2000 ) . '…';
		}
		return (string) wp_json_encode( $args, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}
