<?php
/**
 * Database helpers.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_DB
 */
class Fundolar_DB {

	const TABLE = 'fundolar_transactions';

	/**
	 * Table name with prefix.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	/**
	 * Create tables.
	 */
	public static function create_tables() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$sql = "CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			donor_name varchar(190) NOT NULL DEFAULT '',
			donor_email varchar(190) NOT NULL DEFAULT '',
			currency char(3) NOT NULL DEFAULT 'USD',
			amount_gross decimal(14,4) NOT NULL DEFAULT 0,
			amount_platform_fee decimal(14,4) NOT NULL DEFAULT 0,
			amount_net decimal(14,4) NOT NULL DEFAULT 0,
			status varchar(20) NOT NULL DEFAULT 'pending',
			gateway varchar(32) NOT NULL DEFAULT '',
			gateway_ref varchar(190) NOT NULL DEFAULT '',
			receipt_amount_display decimal(14,4) NOT NULL DEFAULT 0,
			meta longtext NULL,
			PRIMARY KEY  (id),
			KEY status (status),
			KEY created_at (created_at),
			KEY gateway (gateway)
		) {$charset};";
		dbDelta( $sql );
	}

	/**
	 * Insert transaction.
	 *
	 * @param array $row Row data.
	 * @return int|false Insert ID.
	 */
	public static function insert( array $row ) {
		global $wpdb;
		$defaults = array(
			'donor_name'             => '',
			'donor_email'            => '',
			'currency'               => 'USD',
			'amount_gross'           => 0,
			'amount_platform_fee'    => 0,
			'amount_net'             => 0,
			'status'                 => 'pending',
			'gateway'                => '',
			'gateway_ref'            => '',
			'receipt_amount_display' => 0,
			'meta'                   => null,
		);
		$row = wp_parse_args( $row, $defaults );
		if ( is_array( $row['meta'] ) ) {
			$row['meta'] = wp_json_encode( $row['meta'] );
		}
		$ok = $wpdb->insert(
			self::table(),
			array(
				'donor_name'             => sanitize_text_field( $row['donor_name'] ),
				'donor_email'            => sanitize_email( $row['donor_email'] ),
				'currency'               => strtoupper( substr( sanitize_text_field( $row['currency'] ), 0, 3 ) ),
				'amount_gross'           => $row['amount_gross'],
				'amount_platform_fee'    => $row['amount_platform_fee'],
				'amount_net'             => $row['amount_net'],
				'status'                 => sanitize_key( $row['status'] ),
				'gateway'                => sanitize_key( $row['gateway'] ),
				'gateway_ref'            => sanitize_text_field( $row['gateway_ref'] ),
				'receipt_amount_display' => $row['receipt_amount_display'],
				'meta'                   => $row['meta'],
			),
			array( '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%f', '%s' )
		);
		return $ok ? (int) $wpdb->insert_id : false;
	}

	/**
	 * Update by ID.
	 *
	 * @param int   $id ID.
	 * @param array $data Data.
	 * @return bool
	 */
	public static function update( $id, array $data ) {
		global $wpdb;
		$formats = array();
		$clean   = array();
		foreach ( $data as $k => $v ) {
			if ( 'meta' === $k && is_array( $v ) ) {
				$v = wp_json_encode( $v );
			}
			$clean[ $k ] = $v;
			if ( in_array( $k, array( 'amount_gross', 'amount_platform_fee', 'amount_net', 'receipt_amount_display' ), true ) ) {
				$formats[] = '%f';
			} else {
				$formats[] = '%s';
			}
		}
		return false !== $wpdb->update( self::table(), $clean, array( 'id' => (int) $id ), $formats, array( '%d' ) );
	}

	/**
	 * Get row.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
	}

	/**
	 * Sum completed donations in date range.
	 *
	 * @param string     $start Start Y-m-d.
	 * @param string     $end   End Y-m-d.
	 * @param string|null $currency Currency or null for all.
	 * @return float
	 */
	public static function sum_completed( $start, $end, $currency = null ) {
		global $wpdb;
		$table = self::table();
		if ( $currency ) {
			return (float) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COALESCE(SUM(amount_gross),0) FROM {$table} WHERE status = %s AND created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 DAY) AND currency = %s",
					'completed',
					$start,
					$end,
					strtoupper( $currency )
				)
			);
		}
		return (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_gross),0) FROM {$table} WHERE status = %s AND created_at >= %s AND created_at < DATE_ADD(%s, INTERVAL 1 DAY)",
				'completed',
				$start,
				$end
			)
		);
	}

	/**
	 * Daily aggregates for chart (completed only).
	 *
	 * @param int $days Number of days.
	 * @return array{labels: string[], values: float[]}
	 */
	public static function daily_series( $days = 14 ) {
		global $wpdb;
		$table = self::table();
		$days  = max( 1, min( 90, (int) $days ) );
		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT DATE(created_at) as d, SUM(amount_gross) as total
				FROM {$table}
				WHERE status = %s AND created_at >= %s
				GROUP BY DATE(created_at)
				ORDER BY d ASC",
				'completed',
				$start . ' 00:00:00'
			),
			ARRAY_A
		);
		$map = array();
		foreach ( $rows as $r ) {
			$map[ $r['d'] ] = (float) $r['total'];
		}
		$labels = array();
		$values = array();
		for ( $i = 0; $i < $days; $i++ ) {
			$d = gmdate( 'Y-m-d', strtotime( $start . ' +' . $i . ' days' ) );
			$labels[] = gmdate( 'M j', strtotime( $d ) );
			$values[] = isset( $map[ $d ] ) ? $map[ $d ] : 0.0;
		}
		return array(
			'labels' => $labels,
			'values' => $values,
		);
	}

	/**
	 * KPI stats vs previous period.
	 *
	 * @param int $days Window days.
	 * @return array
	 */
	public static function kpi_snapshot( $days = 30 ) {
		global $wpdb;
		$table = self::table();
		$days  = max( 7, min( 90, (int) $days ) );
		$end   = gmdate( 'Y-m-d' );
		$start = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$prev_end   = gmdate( 'Y-m-d', strtotime( $start . ' -1 day' ) );
		$prev_start = gmdate( 'Y-m-d', strtotime( $prev_end . ' -' . ( $days - 1 ) . ' days' ) );

		$revenue_now = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_gross),0) FROM {$table} WHERE status = %s AND DATE(created_at) BETWEEN %s AND %s",
				'completed',
				$start,
				$end
			)
		);
		$revenue_prev = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_gross),0) FROM {$table} WHERE status = %s AND DATE(created_at) BETWEEN %s AND %s",
				'completed',
				$prev_start,
				$prev_end
			)
		);

		$count_now = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND DATE(created_at) BETWEEN %s AND %s",
				'completed',
				$start,
				$end
			)
		);
		$count_prev = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE status = %s AND DATE(created_at) BETWEEN %s AND %s",
				'completed',
				$prev_start,
				$prev_end
			)
		);

		$avg_now = $count_now > 0 ? $revenue_now / $count_now : 0;
		$avg_prev = $count_prev > 0 ? $revenue_prev / $count_prev : 0;

		$refunds_now = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_gross),0) FROM {$table} WHERE status = %s AND DATE(created_at) BETWEEN %s AND %s",
				'refunded',
				$start,
				$end
			)
		);
		$refunds_prev = (float) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COALESCE(SUM(amount_gross),0) FROM {$table} WHERE status = %s AND DATE(created_at) BETWEEN %s AND %s",
				'refunded',
				$prev_start,
				$prev_end
			)
		);

		return array(
			'revenue'      => array( 'now' => $revenue_now, 'prev' => $revenue_prev ),
			'avg'          => array( 'now' => $avg_now, 'prev' => $avg_prev ),
			'donors'       => array( 'now' => $count_now, 'prev' => $count_prev ),
			'refunds'      => array( 'now' => $refunds_now, 'prev' => $refunds_prev ),
		);
	}

	/**
	 * Query transactions for admin list.
	 *
	 * @param array $args Args.
	 * @return object{rows: array, total: int}
	 */
	public static function query_transactions( array $args ) {
		global $wpdb;
		$table  = self::table();
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $args['status'] ) && in_array( $args['status'], array( 'completed', 'failed', 'pending', 'refunded' ), true ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['gateway'] ) ) {
			$where[]  = 'gateway = %s';
			$params[] = sanitize_key( $args['gateway'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(donor_email LIKE %s OR donor_name LIKE %s OR gateway_ref LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$page      = max( 1, (int) ( $args['page'] ?? 1 ) );
		$per       = max( 5, min( 100, (int) ( $args['per_page'] ?? 20 ) ) );
		$offset    = ( $page - 1 ) * $per;

		if ( empty( $params ) ) {
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}" );
			$rows  = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d",
					$per,
					$offset
				),
				ARRAY_A
			);
		} else {
			$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
			$total     = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) );
			$list_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";
			$all_params = array_merge( $params, array( $per, $offset ) );
			$rows       = $wpdb->get_results( $wpdb->prepare( $list_sql, $all_params ), ARRAY_A );
		}

		return (object) array(
			'rows'  => $rows ? $rows : array(),
			'total' => $total,
		);
	}
}
