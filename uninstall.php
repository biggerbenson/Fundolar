<?php
/**
 * Uninstall Fundolar — remove options and custom table.
 *
 * @package Fundolar
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'fundolar_settings' );

global $wpdb;
$table = $wpdb->prefix . 'fundolar_transactions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name from trusted prefix.
$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
