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
			payment_currency char(3) NULL DEFAULT NULL,
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
			'payment_currency'       => null,
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
				'payment_currency'       => isset( $row['payment_currency'] ) && $row['payment_currency'] !== null && $row['payment_currency'] !== ''
					? strtoupper( substr( sanitize_text_field( (string) $row['payment_currency'] ), 0, 3 ) )
					: null,
				'amount_gross'           => $row['amount_gross'],
				'amount_platform_fee'    => $row['amount_platform_fee'],
				'amount_net'             => $row['amount_net'],
				'status'                 => sanitize_key( $row['status'] ),
				'gateway'                => sanitize_key( $row['gateway'] ),
				'gateway_ref'            => sanitize_text_field( $row['gateway_ref'] ),
				'receipt_amount_display' => $row['receipt_amount_display'],
				'meta'                   => $row['meta'],
			),
			array( '%s', '%s', '%s', '%s', '%f', '%f', '%f', '%s', '%s', '%s', '%f', '%s' )
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
				"SELECT COUNT(DISTINCT donor_email) FROM {$table} WHERE status = %s AND donor_email <> '' AND DATE(created_at) BETWEEN %s AND %s",
				'completed',
				$start,
				$end
			)
		);
		$count_prev = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT donor_email) FROM {$table} WHERE status = %s AND donor_email <> '' AND DATE(created_at) BETWEEN %s AND %s",
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

	/**
	 * Local transactions not yet linked to Fundolar Central.
	 *
	 * @param int $limit Max rows per batch.
	 * @return array<int,object>
	 */
	public static function unsynced_transactions( $limit = 100 ) {
		global $wpdb;
		$table = self::table();
		$limit = max( 1, min( 500, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table}
				WHERE meta IS NULL
					OR meta NOT LIKE %s
				ORDER BY id ASC
				LIMIT %d",
				'%platform_donation_id%',
				$limit
			)
		);
		return $rows ? $rows : array();
	}

	/**
	 * Count local transactions missing a Central donation id.
	 *
	 * @return int
	 */
	public static function count_unsynced_transactions() {
		global $wpdb;
		$table = self::table();
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE meta IS NULL
					OR meta NOT LIKE %s",
				'%platform_donation_id%'
			)
		);
	}

	/**
	 * Delete a local transaction row.
	 *
	 * @param int $id Local transaction id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		if ( $id <= 0 ) {
			return false;
		}
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Delete local rows linked to a Central donation id stored in meta.
	 *
	 * @param int $platform_donation_id Central donation id.
	 * @return int Number of rows deleted.
	 */
	public static function delete_by_platform_donation_id( $platform_donation_id ) {
		global $wpdb;
		$platform_donation_id = (int) $platform_donation_id;
		if ( $platform_donation_id <= 0 ) {
			return 0;
		}
		$like1 = '%"platform_donation_id":' . $platform_donation_id . ',%';
		$like2 = '%"platform_donation_id":' . $platform_donation_id . '}%';
		$ids   = $wpdb->get_col(
			$wpdb->prepare(
				'SELECT id FROM ' . self::table() . ' WHERE meta LIKE %s OR meta LIKE %s',
				$like1,
				$like2
			)
		);
		$deleted = 0;
		foreach ( (array) $ids as $row_id ) {
			if ( self::delete( (int) $row_id ) ) {
				++$deleted;
			}
		}
		return $deleted;
	}

	/**
	 * Insert a pending checkout transaction using USD ledger + payment currency split.
	 *
	 * @param string               $donor_name  Donor name.
	 * @param string               $donor_email Donor email.
	 * @param array<string,mixed>  $split       Checkout split from Fundolar_Fees::split_for_checkout().
	 * @param string               $gateway     Gateway slug.
	 * @param string               $gateway_ref Gateway reference.
	 * @param array<string,mixed>  $meta        Optional meta.
	 * @return int|false
	 */
	public static function insert_checkout_transaction( $donor_name, $donor_email, array $split, $gateway, $gateway_ref, array $meta = array() ) {
		$ledger = Fundolar_Ledger::row_from_checkout_split( $split );
		return self::insert(
			array_merge(
				$ledger,
				array(
					'donor_name'  => $donor_name,
					'donor_email' => $donor_email,
					'status'      => 'pending',
					'gateway'     => $gateway,
					'gateway_ref' => $gateway_ref,
					'meta'        => $meta,
				)
			)
		);
	}

	/**
	 * Convert legacy non-USD transaction rows to USD ledger storage.
	 *
	 * @return int Number of rows converted.
	 */
	public static function backfill_usd_ledger() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} WHERE currency <> 'USD' OR payment_currency IS NULL OR payment_currency = ''" );
		$count = 0;
		foreach ( (array) $rows as $row ) {
			$patch = Fundolar_Ledger::backfill_row( $row );
			if ( ! is_array( $patch ) ) {
				continue;
			}
			if ( self::update( (int) $row->id, $patch ) ) {
				++$count;
			}
		}
		return $count;
	}
}
