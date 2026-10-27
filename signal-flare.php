<?php
/**
 * Plugin Name:       Signal Flare
 * Description:       Connects Claude to this site over MCP so it can build and maintain it. Full access, admins only, every action logged.
 * Version:           0.2.1
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            Clint Dehner
 * License:           GPL-2.0-or-later
 * Text Domain:       signal-flare
 */

defined( 'ABSPATH' ) || exit;

define( 'SIGNAL_FLARE_VERSION', '0.2.1' );
define( 'SIGNAL_FLARE_DIR', plugin_dir_path( __FILE__ ) );
define( 'SIGNAL_FLARE_URL', plugin_dir_url( __FILE__ ) );

require_once SIGNAL_FLARE_DIR . 'includes/class-settings.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-log.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-paths.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-tools.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-links.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-server.php';
require_once SIGNAL_FLARE_DIR . 'includes/class-admin.php';

register_activation_hook( __FILE__, array( 'SignalFlare\\Log', 'install' ) );

add_action(
	'plugins_loaded',
	static function () {
		SignalFlare\Log::maybe_upgrade();
		SignalFlare\Server::init();
		SignalFlare\Links::init();
		if ( is_admin() ) {
			SignalFlare\Admin::init();
		}
	}
);
