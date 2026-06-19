<?php
/**
 * Plugin Name:       Fundolar
 * Plugin URI:        https://fundolar.com/
 * Description:       Accept donations through a shortcode form, sync gateways via Fundolar Central, and track activity with a dashboard widget and transaction log—including fee reporting.
 * Version:           1.3.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Fundolar
 * Author URI:        https://fundolar.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fundolar
 * Domain Path:       /languages
 *
 * In-place update entry point for sites that originally installed the Fundora plugin
 * (same folder slug and bootstrap file as before — WordPress treats this as a normal update).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

define( 'FUNDOLAR_PLUGIN_FILE', __FILE__ );
require_once __DIR__ . '/includes/fundolar-load.php';
