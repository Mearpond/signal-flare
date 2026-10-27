<?php
/**
 * Deleting Signal Flare from the Plugins screen removes everything it stored:
 * settings, the activity log and any unused one-time links.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'signal_flare_log' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
delete_option( 'signal_flare_settings' );
delete_option( 'signal_flare_schema' );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_signal\_flare\_link\_%' OR option_name LIKE '\_transient\_timeout\_signal\_flare\_link\_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
