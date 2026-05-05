<?php
/**
 * Plugin Name:       Fundolar
 * Plugin URI:        https://fundolar.com/
 * Description:       Accept donations through a shortcode form, sync gateways via Fundolar Central, and track activity with a dashboard widget and transaction log—including fee reporting.
 * Version:           1.1.14
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Dchamp Legacy
 * Author URI:        https://dchamplegacy.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fundolar
 * Domain Path:       /languages
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

define( 'FUNDOLAR_VERSION', '1.1.14' );
define( 'FUNDOLAR_PLUGIN_FILE', __FILE__ );
define( 'FUNDOLAR_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'FUNDOLAR_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'FUNDOLAR_PLATFORM_FEE_RATE', 0.035 );

require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-author-credentials.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-crypto.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-db.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-emails.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-fees.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-platform.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-payments.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-rest.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-form.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-dashboard-widget.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-admin.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-plugin-information.php';
require_once FUNDOLAR_PLUGIN_DIR . 'includes/class-fundolar-plugin.php';

/**
 * Bootstrap.
 */
function fundolar() {
	return Fundolar_Plugin::instance();
}

fundolar();

register_activation_hook( __FILE__, array( 'Fundolar_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Fundolar_Plugin', 'deactivate' ) );
