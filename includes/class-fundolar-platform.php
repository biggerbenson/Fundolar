<?php
/**
 * Central platform API client.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Platform
 */
class Fundolar_Platform {

	/**
	 * Re-sync gateways when local cache is older than this (seconds).
	 */
	const SYNC_STALE_SECONDS = 21600;

	/**
	 * Shorter staleness window when rendering the public donation form.
	 */
	const DISPLAY_SYNC_STALE_SECONDS = 300;

	/**
	 * Transient lock to avoid concurrent sync requests.
	 */
	const SYNC_LOCK_TRANSIENT = 'fundolar_platform_sync_lock';
	/**
	 * Production Fundolar app base URL (default when no override is saved).
	 *
	 * @var string
	 */
	const PLATFORM_BASE_URL = 'https://app.fundolar.com';

	/**
	 * Match Central path normalization (see fundolar_normalize_request_path) for HMAC canonical strings.
	 *
	 * @param string $path Absolute path e.g. /api/plugin/gateways.
	 * @return string
	 */
	private static function normalize_api_path( $path ) {
		$path = (string) $path;
		if ( '' === $path ) {
			return '/';
		}
		if ( '/' !== $path && '/' === substr( $path, -1 ) ) {
			return rtrim( $path, '/' );
		}
		return $path;
	}

	/**
	 * Default HTTP args for Central (timeouts, TLS, User-Agent for strict hosts).
	 *
	 * @return array<string,mixed>
	 */
	private static function platform_http_defaults() {
		$ver = defined( 'FUNDOLAR_VERSION' ) ? FUNDOLAR_VERSION : '0';
		return array(
			'timeout'   => (int) apply_filters( 'fundolar_platform_http_timeout', 20 ),
			'sslverify' => (bool) apply_filters( 'fundolar_platform_sslverify', true ),
			'blocking'  => true,
			'headers'   => array(
				'Accept'     => 'application/json',
				'User-Agent' => 'WordPress/' . get_bloginfo( 'version' ) . '; Fundolar/' . $ver,
			),
		);
	}

	/**
	 * Extract error text from Central JSON or raw body.
	 *
	 * @param int               $code     HTTP status.
	 * @param string            $body_raw Raw response body.
	 * @param array<string,mixed> $decoded  json_decode result if array.
	 * @return string
	 */
	private static function parse_platform_error_message( $code, $body_raw, array $decoded ) {
		if ( isset( $decoded['message'] ) && is_string( $decoded['message'] ) && '' !== trim( $decoded['message'] ) ) {
			return trim( $decoded['message'] );
		}
		if ( isset( $decoded['error'] ) && is_string( $decoded['error'] ) && '' !== trim( $decoded['error'] ) ) {
			return trim( $decoded['error'] );
		}
		if ( $code >= 400 && '' !== $body_raw ) {
			$snippet = wp_strip_all_tags( substr( $body_raw, 0, 240 ) );
			if ( '' !== $snippet ) {
				/* translators: 1: HTTP status code, 2: response snippet */
				return sprintf( __( 'HTTP %1$d — %2$s', 'fundolar' ), $code, $snippet );
			}
		}
		return __( 'Platform request failed.', 'fundolar' );
	}

	/**
	 * Base URL used for plugin API requests.
	 *
	 * Priority: saved Payments field, then FUNDOLAR_CENTRAL_URL in wp-config, then PLATFORM_BASE_URL, then fundolar_platform_base_url filter.
	 *
	 * @return string Non-empty in normal setups; empty only if filtered away.
	 */
	public static function base_url() {
		$s      = Fundolar_Payments::get_settings();
		$stored = isset( $s['platform_base_url'] ) ? trim( (string) $s['platform_base_url'] ) : '';
		$base   = '';

		if ( '' !== $stored ) {
			$p = wp_parse_url( $stored );
			if ( is_array( $p ) && ! empty( $p['scheme'] ) && ! empty( $p['host'] )
				&& in_array( strtolower( (string) $p['scheme'] ), array( 'http', 'https' ), true ) ) {
				$base = $stored;
			}
		} elseif ( defined( 'FUNDOLAR_CENTRAL_URL' ) && is_string( FUNDOLAR_CENTRAL_URL ) ) {
			$cfg = trim( FUNDOLAR_CENTRAL_URL );
			if ( '' !== $cfg ) {
				$p = wp_parse_url( $cfg );
				if ( is_array( $p ) && ! empty( $p['scheme'] ) && ! empty( $p['host'] )
					&& in_array( strtolower( (string) $p['scheme'] ), array( 'http', 'https' ), true ) ) {
					$base = $cfg;
				}
			}
		}

		if ( '' === $base ) {
			$base = self::PLATFORM_BASE_URL;
		}

		$url = (string) apply_filters( 'fundolar_platform_base_url', $base );
		return rtrim( $url, '/' );
	}

	/**
	 * User-facing message when Central base URL is not configured.
	 *
	 * @return string
	 */
	private static function missing_base_url_message() {
		return __( 'Unable to reach the payment service.', 'fundolar' );
	}

	/**
	 * Append guidance when the server cannot resolve the Central hostname (cURL error 6, etc.).
	 *
	 * @param WP_Error $err Transport error.
	 * @return WP_Error
	 */
	private static function enrich_http_error( WP_Error $err ) {
		$msg = $err->get_error_message();
		$code = $err->get_error_code();
		if ( '' === $code ) {
			$code = 'http_request_failed';
		}
		return new WP_Error( $code, $msg, $err->get_error_data() );
	}

	/**
	 * Pair plugin with central platform using the site key.
	 *
	 * @return true|WP_Error
	 */
	public static function connect_site() {
		$s        = Fundolar_Payments::get_settings();
		$site_key = trim( (string) $s['platform_site_key'] );
		$base_url = self::base_url();

		if ( '' === $site_key ) {
			return new WP_Error( 'fundolar_platform_required', __( 'Site key is required.', 'fundolar' ) );
		}

		if ( '' === $base_url ) {
			return new WP_Error( 'fundolar_platform_no_base_url', self::missing_base_url_message() );
		}

		$args = self::platform_http_defaults();
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = wp_json_encode(
			array(
				'plugin_license_key' => $site_key,
				'site_url'           => home_url( '/' ),
			)
		);

		$response = wp_remote_post( $base_url . self::normalize_api_path( '/api/plugin/activate' ), $args );

		if ( is_wp_error( $response ) ) {
			return self::enrich_http_error( $response );
		}

		$code     = (int) wp_remote_retrieve_response_code( $response );
		$body_raw = (string) wp_remote_retrieve_body( $response );
		$body     = json_decode( $body_raw, true );

		if ( $code < 200 || $code >= 300 || ! is_array( $body ) ) {
			$msg = self::parse_platform_error_message( $code, $body_raw, is_array( $body ) ? $body : array() );
			return new WP_Error( 'fundolar_platform_activate', $msg );
		}

		$api_key = isset( $body['api_key'] ) ? trim( (string) $body['api_key'] ) : '';
		$secret  = isset( $body['signing_secret'] ) ? trim( (string) $body['signing_secret'] ) : '';
		if ( '' === $api_key || '' === $secret ) {
			return new WP_Error(
				'fundolar_platform_activate',
				__( 'Activation response was incomplete. Check your site key and try again.', 'fundolar' )
			);
		}

		Fundolar_Payments::save_remote_credentials( $body );
		$sync = self::sync_gateway_settings();
		if ( is_wp_error( $sync ) ) {
			return $sync;
		}
		self::sync_historical_donations();
		return true;
	}

	/**
	 * Fetch gateway credentials and runtime controls from platform.
	 *
	 * @return true|WP_Error
	 */
	public static function sync_gateway_settings() {
		$res = self::signed_request( 'GET', '/api/plugin/gateways' );
		if ( is_wp_error( $res ) ) {
			Fundolar_Payments::set_platform_sync_error( $res->get_error_message() );
			return $res;
		}
		Fundolar_Payments::save_remote_credentials( $res );
		self::sync_pending_deletions();
		return true;
	}

	/**
	 * Apply Central donation deletions to the local WordPress transaction log.
	 *
	 * @return array{deleted:int,acknowledged:int}
	 */
	public static function sync_pending_deletions() {
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return array(
				'deleted'      => 0,
				'acknowledged' => 0,
			);
		}

		$res = self::signed_request( 'GET', '/api/plugin/donation-deletions' );
		if ( is_wp_error( $res ) || ! is_array( $res ) ) {
			return array(
				'deleted'      => 0,
				'acknowledged' => 0,
			);
		}

		$events = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		if ( empty( $events ) ) {
			return array(
				'deleted'      => 0,
				'acknowledged' => 0,
			);
		}

		$deleted = 0;
		$acked   = array();
		foreach ( $events as $event ) {
			if ( ! is_array( $event ) ) {
				continue;
			}
			$event_id   = (int) ( $event['id'] ?? 0 );
			$local_id   = (int) ( $event['local_plugin_record_id'] ?? 0 );
			$central_id = (int) ( $event['central_donation_id'] ?? 0 );
			$removed    = false;

			if ( $local_id > 0 ) {
				$row = Fundolar_DB::get( $local_id );
				if ( ! $row ) {
					$removed = true;
				} else {
					$removed = Fundolar_DB::delete( $local_id );
				}
			} elseif ( $central_id > 0 ) {
				$count = Fundolar_DB::delete_by_platform_donation_id( $central_id );
				$removed = $count > 0;
				if ( ! $removed ) {
					$removed = true;
				}
			}

			if ( $removed ) {
				if ( $local_id > 0 || $central_id > 0 ) {
					++$deleted;
				}
				if ( $event_id > 0 ) {
					$acked[] = $event_id;
				}
			}
		}

		$acknowledged = 0;
		if ( ! empty( $acked ) ) {
			$ack = self::signed_request(
				'POST',
				'/api/plugin/donation-deletions/ack',
				array( 'event_ids' => array_values( array_unique( $acked ) ) )
			);
			if ( ! is_wp_error( $ack ) && is_array( $ack ) ) {
				$acknowledged = (int) ( $ack['acknowledged'] ?? count( $acked ) );
			}
		}

		return array(
			'deleted'      => $deleted,
			'acknowledged' => $acknowledged,
		);
	}

	/**
	 * Sync gateways when connected and cache is stale (or forced).
	 *
	 * @param bool $force Skip staleness check.
	 * @return true|WP_Error|null True on sync, null when skipped, WP_Error on failure.
	 */
	public static function maybe_sync_gateways( $force = false ) {
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return null;
		}
		if ( get_transient( self::SYNC_LOCK_TRANSIENT ) ) {
			return null;
		}
		if ( ! $force && ! Fundolar_Payments::platform_sync_is_stale() ) {
			return null;
		}
		set_transient( self::SYNC_LOCK_TRANSIENT, 1, 60 );
		$result = self::sync_gateway_settings();
		if ( true === $result ) {
			self::sync_pending_deletions();
		}
		delete_transient( self::SYNC_LOCK_TRANSIENT );
		return $result;
	}

	/**
	 * Ensure gateway settings are fresh before showing the donation form or bootstrap API.
	 *
	 * Forces a Central pull when no payment methods are ready, credentials are incomplete,
	 * or the last sync is older than the display staleness window.
	 *
	 * @param bool $force Always pull from Central when true (e.g. before checkout).
	 * @return void
	 */
	public static function ensure_gateways_synced_for_display( $force = false ) {
		self::repair_platform_connection_if_needed();

		if ( ! Fundolar_Payments::is_central_connected() ) {
			return;
		}

		$s     = Fundolar_Payments::get_settings();
		$ready = Fundolar_Payments::gateways_ready_for_front();
		if ( ! $force ) {
			$force = empty( $ready )
				|| empty( $s['enabled_gateways'] )
				|| ! empty( $s['platform_sync_error'] )
				|| Fundolar_Payments::platform_sync_is_stale( self::DISPLAY_SYNC_STALE_SECONDS );
		}

		self::maybe_sync_gateways( $force );
	}

	/**
	 * Re-activate with the saved site key when API credentials are missing or incomplete.
	 *
	 * @return void
	 */
	private static function repair_platform_connection_if_needed() {
		$s = Fundolar_Payments::get_settings();
		if ( Fundolar_Payments::has_complete_platform_credentials() ) {
			return;
		}
		$site_key = trim( (string) ( $s['platform_site_key'] ?? '' ) );
		if ( '' === $site_key ) {
			return;
		}
		self::connect_site();
	}

	/**
	 * Scheduled heartbeat: refresh gateway settings from Central.
	 *
	 * @return void
	 */
	public static function run_heartbeat() {
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return;
		}
		self::maybe_sync_gateways( true );
		self::sync_pending_deletions();
		self::reconcile_central_usd_ledger();
	}

	/**
	 * Push payment-currency amounts to Central for donations already linked to platform ids.
	 *
	 * @param int $limit Max rows per run.
	 * @return array{reconciled:int}
	 */
	public static function reconcile_central_usd_ledger( $limit = 100 ) {
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return array( 'reconciled' => 0 );
		}
		if ( get_transient( 'fundolar_usd_reconcile_lock' ) ) {
			return array( 'reconciled' => 0 );
		}
		set_transient( 'fundolar_usd_reconcile_lock', 1, 300 );

		global $wpdb;
		$table = Fundolar_DB::table();
		$limit = max( 1, min( 200, (int) $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE meta LIKE %s ORDER BY id ASC LIMIT %d",
				'%platform_donation_id%',
				$limit
			)
		);

		$reconciled = 0;
		foreach ( (array) $rows as $row ) {
			$meta = array();
			if ( ! empty( $row->meta ) ) {
				$decoded = json_decode( (string) $row->meta, true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			$donation_id = (int) ( $meta['platform_donation_id'] ?? 0 );
			if ( $donation_id <= 0 ) {
				continue;
			}
			$body = Fundolar_Ledger::central_payment_payload( $row );
			$body['donation_id'] = $donation_id;
			if ( ! empty( $meta['platform_donation_uuid'] ) ) {
				$body['uuid'] = (string) $meta['platform_donation_uuid'];
			}
			$res = self::signed_request( 'POST', '/api/plugin/donations/reconcile-usd', $body );
			if ( ! is_wp_error( $res ) ) {
				++$reconciled;
			}
		}

		delete_transient( 'fundolar_usd_reconcile_lock' );

		return array( 'reconciled' => $reconciled );
	}

	/**
	 * Fetch site dashboard stats from central platform.
	 *
	 * @return array|WP_Error
	 */
	public static function fetch_dashboard_stats() {
		return self::signed_request( 'GET', '/api/plugin/dashboard-stats' );
	}

	/**
	 * Push local donation history to Central with exact stored amounts.
	 *
	 * @param int $batch_size Rows per request cycle.
	 * @return array{synced:int,failed:int,remaining:int}|WP_Error
	 */
	public static function sync_historical_donations( $batch_size = 50 ) {
		$s   = Fundolar_Payments::get_settings();
		$api = trim( (string) $s['platform_api_key'] );
		if ( '' === $api ) {
			return new WP_Error( 'fundolar_platform_not_connected', __( 'This site is not connected yet. Use your site key to connect.', 'fundolar' ) );
		}

		$batch_size = max( 1, min( 100, (int) $batch_size ) );
		$rows       = Fundolar_DB::unsynced_transactions( $batch_size );
		$synced     = 0;
		$failed     = 0;

		foreach ( $rows as $row ) {
			$local_id = (int) $row->id;
			$meta     = array();
			if ( ! empty( $row->meta ) ) {
				$decoded = json_decode( (string) $row->meta, true );
				if ( is_array( $decoded ) ) {
					$meta = $decoded;
				}
			}
			if ( ! empty( $meta['platform_donation_id'] ) ) {
				continue;
			}

			$payload = array_merge(
				array(
					'donor_name'             => (string) $row->donor_name,
					'donor_email'              => (string) $row->donor_email,
					'payment_gateway'          => (string) $row->gateway,
					'gateway_reference'        => (string) $row->gateway_ref,
					'processor_fee_amount'     => 0,
					'source_channel'           => 'wordpress_plugin',
					'local_plugin_record_id'   => $local_id,
					'idempotency_key'          => 'fundolar_local_' . $local_id,
					'gateway_status'           => (string) $row->status,
				),
				Fundolar_Ledger::central_payment_payload( $row )
			);

			$res = self::signed_request( 'POST', '/api/plugin/donations/create', $payload );
			if ( is_wp_error( $res ) || ! is_array( $res ) ) {
				++$failed;
				continue;
			}

			if ( isset( $res['id'] ) ) {
				$meta['platform_donation_id'] = (int) $res['id'];
			}
			if ( isset( $res['uuid'] ) ) {
				$meta['platform_donation_uuid'] = (string) $res['uuid'];
			}
			Fundolar_DB::update( $local_id, array( 'meta' => $meta ) );

			$status = sanitize_key( (string) $row->status );
			if ( in_array( $status, array( 'completed', 'failed', 'refunded', 'pending' ), true ) && 'pending' !== $status ) {
				self::report_donation_status( $local_id, $status, $status . '_import', array( 'imported' => true ) );
			}

			++$synced;
		}

		$remaining = Fundolar_DB::count_unsynced_transactions();
		if ( $remaining < 1 ) {
			Fundolar_Migration::mark_central_sync_done();
		}

		$result = array(
			'synced'    => $synced,
			'failed'    => $failed,
			'remaining' => $remaining,
		);
		if ( $synced > 0 ) {
			set_transient( 'fundolar_last_historical_sync', $result, 60 );
		}

		return $result;
	}

	/**
	 * Report donation creation to central platform.
	 *
	 * @param int   $local_id Local transaction id.
	 * @param array $payload  Donation payload.
	 * @return void
	 */
	public static function report_donation_created( $local_id, array $payload ) {
		$body = wp_parse_args(
			$payload,
			array(
				'local_plugin_record_id' => (int) $local_id,
			)
		);
		$res = self::signed_request( 'POST', '/api/plugin/donations/create', $body );
		if ( is_wp_error( $res ) ) {
			if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( 'Fundolar donations/create: ' . $res->get_error_message() );
			}
			return;
		}
		if ( ! is_array( $res ) ) {
			return;
		}
		$row = Fundolar_DB::get( (int) $local_id );
		if ( ! $row ) {
			return;
		}
		$meta = array();
		if ( ! empty( $row->meta ) ) {
			$decoded = json_decode( (string) $row->meta, true );
			if ( is_array( $decoded ) ) {
				$meta = $decoded;
			}
		}
		if ( isset( $res['id'] ) ) {
			$meta['platform_donation_id'] = (int) $res['id'];
		}
		if ( isset( $res['uuid'] ) ) {
			$meta['platform_donation_uuid'] = (string) $res['uuid'];
		}
		Fundolar_DB::update( (int) $local_id, array( 'meta' => $meta ) );
	}

	/**
	 * Report donation status to central platform.
	 *
	 * @param int    $local_id Local transaction id.
	 * @param string $status   Payment status.
	 * @param string $gateway_status Gateway status.
	 * @param array  $raw Raw gateway payload.
	 * @return void
	 */
	public static function report_donation_status( $local_id, $status, $gateway_status = '', array $raw = array() ) {
		$donation_id = null;
		$uuid        = '';
		$row         = Fundolar_DB::get( (int) $local_id );
		if ( $row && ! empty( $row->meta ) ) {
			$decoded = json_decode( (string) $row->meta, true );
			if ( is_array( $decoded ) ) {
				if ( ! empty( $decoded['platform_donation_id'] ) ) {
					$donation_id = (int) $decoded['platform_donation_id'];
				}
				if ( ! empty( $decoded['platform_donation_uuid'] ) ) {
					$uuid = (string) $decoded['platform_donation_uuid'];
				}
			}
		}

		$payload = array(
			'payment_status' => (string) $status,
			'gateway_status' => (string) $gateway_status,
			'raw_response'   => $raw,
		);
		if ( null !== $donation_id && $donation_id > 0 ) {
			$payload['donation_id'] = $donation_id;
		} elseif ( '' !== $uuid ) {
			$payload['uuid'] = $uuid;
		} else {
			return;
		}

		self::signed_request(
			'POST',
			'/api/plugin/donations/update-status',
			$payload
		);
	}

	/**
	 * Signed request to central plugin API endpoints.
	 *
	 * @param string $method HTTP method.
	 * @param string $path API path.
	 * @param array  $body Optional body.
	 * @return array|WP_Error
	 */
	public static function signed_request( $method, $path, array $body = array() ) {
		$s      = Fundolar_Payments::get_settings();
		$base   = self::base_url();
		$api    = trim( (string) $s['platform_api_key'] );
		$secret = Fundolar_Payments::decrypt_secret( isset( $s['platform_signing_secret'] ) ? $s['platform_signing_secret'] : '' );
		if ( '' === $base ) {
			return new WP_Error( 'fundolar_platform_no_base_url', self::missing_base_url_message() );
		}
		if ( '' === $api || '' === $secret ) {
			return new WP_Error( 'fundolar_platform_not_connected', __( 'This site is not connected yet. Use your site key to connect.', 'fundolar' ) );
		}

		$method = strtoupper( (string) $method );
		$path   = self::normalize_api_path( $path );
		$nonce  = wp_generate_password( 20, false, false );
		$timestamp = (string) time();
		$json_body = ( 'GET' === $method ) ? '' : wp_json_encode( $body );
		$hash      = hash( 'sha256', (string) $json_body );
		$canonical = $method . "\n" . $path . "\n" . $timestamp . "\n" . $nonce . "\n" . $hash;
		$signature = hash_hmac( 'sha256', $canonical, $secret );

		$args = self::platform_http_defaults();
		$args['headers']['X-Fundolar-Api-Key']     = $api;
		$args['headers']['X-Fundolar-Timestamp']   = $timestamp;
		$args['headers']['X-Fundolar-Nonce']       = $nonce;
		$args['headers']['X-Fundolar-Signature']   = $signature;
		if ( 'GET' !== $method ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = $json_body;
		}

		$url      = $base . $path;
		$response = ( 'GET' === $method ) ? wp_remote_get( $url, $args ) : wp_remote_post( $url, $args );
		if ( is_wp_error( $response ) ) {
			return self::enrich_http_error( $response );
		}
		$code     = (int) wp_remote_retrieve_response_code( $response );
		$raw_body = (string) wp_remote_retrieve_body( $response );
		$data     = json_decode( $raw_body, true );
		if ( $code < 200 || $code >= 300 || ! is_array( $data ) ) {
			$msg = self::parse_platform_error_message( $code, $raw_body, is_array( $data ) ? $data : array() );
			return new WP_Error( 'fundolar_platform_request', $msg );
		}
		return $data;
	}
}
