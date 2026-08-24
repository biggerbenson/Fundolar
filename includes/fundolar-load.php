<?php
/**
 * Shared plugin bootstrap (loaded from fundolar.php or fundora.php).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'FUNDOLAR_VERSION' ) ) {
	define( 'FUNDOLAR_VERSION', '1.4.0' );
}
if ( ! defined( 'FUNDOLAR_CENTRAL_ONLY' ) ) {
	define( 'FUNDOLAR_CENTRAL_ONLY', true );
}
if ( ! defined( 'FUNDOLAR_PLUGIN_FILE' ) ) {
	define( 'FUNDOLAR_PLUGIN_FILE', dirname( __DIR__ ) . '/fundolar.php' );
}
if ( ! defined( 'FUNDOLAR_PLUGIN_DIR' ) ) {
	define( 'FUNDOLAR_PLUGIN_DIR', plugin_dir_path( FUNDOLAR_PLUGIN_FILE ) );
}
if ( ! defined( 'FUNDOLAR_PLUGIN_URL' ) ) {
	define( 'FUNDOLAR_PLUGIN_URL', plugin_dir_url( FUNDOLAR_PLUGIN_FILE ) );
}
if ( ! defined( 'FUNDOLAR_PLATFORM_FEE_RATE' ) ) {
	define( 'FUNDOLAR_PLATFORM_FEE_RATE', 0.035 );
}
if ( ! defined( 'FUNDOLAR_UPDATE_PACKAGE_URL' ) ) {
	define( 'FUNDOLAR_UPDATE_PACKAGE_URL', 'https://fundolar.com/plugin/fundolar.zip' );
}
if ( ! defined( 'FUNDOLAR_UPDATE_INFO_URL' ) ) {
	define( 'FUNDOLAR_UPDATE_INFO_URL', 'https://fundolar.com/plugin/info.json' );
}
if ( ! defined( 'FUNDOLAR_UPDATE_VERSION_URL' ) ) {
	define( 'FUNDOLAR_UPDATE_VERSION_URL', 'https://fundolar.com/plugin/version.txt' );
}

require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-author-credentials.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-crypto.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-db.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-emails.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-fees.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-platform.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-payments.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-ledger.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-rest.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-form.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-dashboard-widget.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-admin.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-marzpay.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-gateway-connect.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-admin-notices.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-plugin-information.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-remote-updater.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-migration.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-plugin.php';

Fundolar_Migration::register_bootstrap_hooks();

if ( ! Fundolar_Payments::is_central_only_distribution() ) {
	Fundolar_Gateway_Connect::init();
}
Fundolar_Admin_Notices::init();
Fundolar_Remote_Updater::init();

/**
 * Bootstrap.
 *
 * @return Fundolar_Plugin
 */
function fundolar() {
	return Fundolar_Plugin::instance();
}

fundolar();

register_activation_hook( FUNDOLAR_PLUGIN_FILE, array( 'Fundolar_Plugin', 'activate' ) );
register_deactivation_hook( FUNDOLAR_PLUGIN_FILE, array( 'Fundolar_Plugin', 'deactivate' ) );
