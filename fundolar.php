<?php
/**
 * Plugin Name:       Fundolar
 * Plugin URI:        https://fundolar.com/
 * Description:       Accept donations with Fundolar Central — connect your site, sync payment gateways, and track donations from WordPress.
 * Version:           1.5.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Fundolar
 * Author URI:        https://fundolar.com/#contact
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fundolar
 * Domain Path:       /languages
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

define( 'FUNDOLAR_PLUGIN_FILE', __FILE__ );
require_once __DIR__ . '/includes/fundolar-load.php';
