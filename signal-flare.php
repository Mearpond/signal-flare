<?php
/**
 * Plugin Name:       Signal Flare
 * Description:       Connects Claude to this site over MCP so it can build and maintain it. Full access, admins only, every action logged.
 * Version:           0.4.0
 * Requires at least: 6.5
 * Tested up to:      7.1
 * Requires PHP:      8.0
 * Author:            Clint Dehner
 * Update URI:        https://clintdehner.com/signal-tower
 * License:           GPL-2.0-or-later
 * Text Domain:       signal-flare
 */

defined( 'ABSPATH' ) || exit;

define( 'SIGNAL_FLARE_VERSION', '0.4.0' );
define( 'SIGNAL_FLARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIGNAL_FLARE_URL', plugin_dir_url( __FILE__ ) );
// Where sites not connected to Signal Tower get updates (GitHub Releases).
define( 'SIGNAL_FLARE_GITHUB_REPO', 'Mearpond/signal-flare' );

require_once SIGNAL_FLARE_DIR . 'includes/class-settings.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-log.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-paths.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-tools.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-links.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-server.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-health.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-reporter.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-updater.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'SignalFlare\\Log', 'install' ) );
register_deactivation_hook(
	__FILE__,
	static function () {
		wp_clear_scheduled_hook( SignalFlare\Reporter::HOOK );
	}
);

add_action(
	'plugins_loaded',
	static function () {
		SignalFlare\Log::maybe_upgrade();
		SignalFlare\Server::init();
		SignalFlare\Links::init();
		SignalFlare\Health::init();
		SignalFlare\Reporter::init();
		// Sites connected to Signal Tower update from it (and through Update all); the rest from GitHub.
		( new SignalFlare\Updater( __FILE__, 'signal-flare', SIGNAL_FLARE_VERSION, SignalFlare\Reporter::connected() ? null : SIGNAL_FLARE_GITHUB_REPO ) )->init();
		if ( is_admin() ) {
			SignalFlare\Admin::init();
		}
	}
);
