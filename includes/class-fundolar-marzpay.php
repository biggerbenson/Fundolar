<?php
/**
 * MarzPay (Marspay) Mobile Money API client — Uganda collections.
 *
 * @see https://wallet.wearemarz.com/documentation/api
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Marzpay
 */
class Fundolar_Marzpay {

	const API_BASE = 'https://wallet.wearemarz.com/api/v1';

	const MIN_UGX = 500;

	const MAX_UGX = 10000000;

	/**
	 * @return string
	 */
	public static function api_base() {
		$base = apply_filters( 'fundolar_marzpay_api_base', self::API_BASE );
		return rtrim( (string) $base, '/' );
	}

	/**
	 * @return string UUID v4 reference for collect-money.
	 */
	public static function generate_reference() {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return wp_generate_uuid4();
		}
		$bytes = random_bytes( 16 );
		$bytes[6] = chr( ( ord( $bytes[6] ) & 0x0f ) | 0x40 );
		$bytes[8] = chr( ( ord( $bytes[8] ) & 0x3f ) | 0x80 );
		$hex      = bin2hex( $bytes );
		return sprintf(
			'%s-%s-%s-%s-%s',
			substr( $hex, 0, 8 ),
			substr( $hex, 8, 4 ),
			substr( $hex, 12, 4 ),
			substr( $hex, 16, 4 ),
			substr( $hex, 20, 12 )
		);
	}

	/**
	 * Normalize Uganda mobile number to +256XXXXXXXXX.
	 *
	 * @param string $phone Raw phone input.
	 * @return string|WP_Error
	 */
	public static function normalize_phone( $phone ) {
		$raw = preg_replace( '/\s+/', '', (string) $phone );
		$raw = preg_replace( '/[^0-9+]/', '', $raw );
		if ( '' === $raw ) {
			return new WP_Error( 'fundolar_marzpay_phone', __( 'Enter your mobile money phone number.', 'fundolar' ) );
		}
		if ( '0' === $raw[0] && strlen( $raw ) === 10 ) {
			$raw = '+256' . substr( $raw, 1 );
		} elseif ( preg_match( '/^256[0-9]{9}$/', $raw ) ) {
			$raw = '+' . $raw;
		} elseif ( preg_match( '/^[0-9]{9}$/', $raw ) ) {
			$raw = '+256' . $raw;
		}
		if ( ! preg_match( '/^\+256[0-9]{9}$/', $raw ) ) {
			return new WP_Error(
				'fundolar_marzpay_phone',
				__( 'Enter a valid Uganda mobile number (e.g. 0771234567 or +256771234567).', 'fundolar' )
			);
		}
		return $raw;
	}

	/**
	 * @param string $status MarzPay transaction status.
	 * @return bool
	 */
	public static function is_success_status( $status ) {
		$s = strtolower( sanitize_key( (string) $status ) );
		return in_array( $s, array( 'successful', 'completed', 'sandbox' ), true );
	}

	/**
	 * @param string $status MarzPay transaction status.
	 * @return bool
	 */
	public static function is_failed_status( $status ) {
		$s = strtolower( sanitize_key( (string) $status ) );
		return in_array( $s, array( 'failed', 'cancelled', 'canceled' ), true );
	}

	/**
	 * @param string $status MarzPay transaction status.
	 * @return bool
	 */
	public static function is_pending_status( $status ) {
		$s = strtolower( sanitize_key( (string) $status ) );
		return in_array( $s, array( 'pending', 'processing' ), true );
	}

	/**
	 * @param string               $method      HTTP method.
	 * @param string               $path        Path after /api/v1.
	 * @param string               $api_key     API key.
	 * @param string               $api_secret  API secret.
	 * @param array<string,mixed>|null $body    JSON body for POST/PUT.
	 * @return array|WP_Error
	 */
	public static function request( $method, $path, $api_key, $api_secret, $body = null ) {
		$api_key    = trim( (string) $api_key );
		$api_secret = trim( (string) $api_secret );
		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Mobile Money (UG) is not configured.', 'fundolar' ) );
		}

		$url  = self::api_base() . '/' . ltrim( (string) $path, '/' );
		$args = array(
			'timeout' => 25,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
				'Accept'        => 'application/json',
			),
		);
		$method = strtoupper( (string) $method );
		if ( null !== $body && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
			$args['body'] = wp_json_encode( $body );
		}

		if ( 'GET' === $method ) {
			$response = wp_remote_get( $url, $args );
		} elseif ( 'POST' === $method ) {
			$response = wp_remote_post( $url, $args );
		} else {
			$args['method'] = $method;
			$response       = wp_remote_request( $url, $args );
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Invalid response from Mobile Money provider.', 'fundolar' ), array( 'status' => $code ) );
		}
		if ( $code >= 400 || ( isset( $json['status'] ) && 'error' === $json['status'] ) ) {
			$msg = isset( $json['message'] ) ? (string) $json['message'] : __( 'Mobile Money request failed.', 'fundolar' );
			if ( ! empty( $json['errors'] ) && is_array( $json['errors'] ) ) {
				$parts = array();
				foreach ( $json['errors'] as $field => $messages ) {
					if ( is_array( $messages ) ) {
						$parts = array_merge( $parts, array_map( 'strval', $messages ) );
					}
				}
				if ( ! empty( $parts ) ) {
					$msg = implode( ' ', $parts );
				}
			}
			return new WP_Error( 'fundolar_marzpay', $msg, array( 'status' => $code, 'marzpay' => $json ) );
		}

		return $json;
	}

	/**
	 * Initiate a mobile money collection (MTN / Airtel Uganda).
	 *
	 * @param string               $api_key    API key.
	 * @param string               $api_secret API secret.
	 * @param array<string,mixed>  $args       amount, phone_number, reference, description, callback_url.
	 * @return array|WP_Error Normalized result with uuid, reference, status.
	 */
	public static function collect_money( $api_key, $api_secret, array $args ) {
		$amount = isset( $args['amount'] ) ? (int) round( (float) $args['amount'] ) : 0;
		if ( $amount < self::MIN_UGX || $amount > self::MAX_UGX ) {
			return new WP_Error(
				'fundolar_marzpay_amount',
				sprintf(
					/* translators: 1: minimum UGX, 2: maximum UGX */
					__( 'Amount must be between %1$s and %2$s UGX for Mobile Money.', 'fundolar' ),
					number_format_i18n( self::MIN_UGX ),
					number_format_i18n( self::MAX_UGX )
				)
			);
		}

		$phone = self::normalize_phone( isset( $args['phone_number'] ) ? $args['phone_number'] : '' );
		if ( is_wp_error( $phone ) ) {
			return $phone;
		}

		$reference = isset( $args['reference'] ) ? sanitize_text_field( (string) $args['reference'] ) : '';
		if ( '' === $reference ) {
			$reference = self::generate_reference();
		}
		if ( ! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $reference ) ) {
			return new WP_Error( 'fundolar_marzpay_reference', __( 'Invalid payment reference.', 'fundolar' ) );
		}

		$body = array(
			'amount'       => $amount,
			'phone_number' => $phone,
			'country'      => 'UG',
			'reference'    => $reference,
			'description'  => substr( sanitize_text_field( (string) ( $args['description'] ?? __( 'Donation', 'fundolar' ) ) ), 0, 255 ),
		);
		if ( ! empty( $args['callback_url'] ) ) {
			$body['callback_url'] = esc_url_raw( (string) $args['callback_url'] );
		}

		$res = self::request( 'POST', '/collect-money', $api_key, $api_secret, $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : array();
		$tx   = isset( $data['transaction'] ) && is_array( $data['transaction'] ) ? $data['transaction'] : array();

		return array(
			'uuid'      => isset( $tx['uuid'] ) ? sanitize_text_field( (string) $tx['uuid'] ) : '',
			'reference' => isset( $tx['reference'] ) ? sanitize_text_field( (string) $tx['reference'] ) : $reference,
			'status'    => isset( $tx['status'] ) ? sanitize_key( (string) $tx['status'] ) : 'processing',
			'raw'       => $res,
		);
	}

	/**
	 * @param string $api_key    API key.
	 * @param string $api_secret API secret.
	 * @param string $uuid       MarzPay transaction UUID.
	 * @return array|WP_Error
	 */
	public static function get_collection( $api_key, $api_secret, $uuid ) {
		$uuid = sanitize_text_field( (string) $uuid );
		if ( '' === $uuid ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Missing transaction reference.', 'fundolar' ) );
		}
		$res = self::request( 'GET', '/collect-money/' . rawurlencode( $uuid ), $api_key, $api_secret );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = isset( $res['data'] ) && is_array( $res['data'] ) ? $res['data'] : $res;
		$tx   = isset( $data['transaction'] ) && is_array( $data['transaction'] ) ? $data['transaction'] : array();
		return array(
			'uuid'      => isset( $tx['uuid'] ) ? sanitize_text_field( (string) $tx['uuid'] ) : $uuid,
			'reference' => isset( $tx['reference'] ) ? sanitize_text_field( (string) $tx['reference'] ) : '',
			'status'    => isset( $tx['status'] ) ? sanitize_key( (string) $tx['status'] ) : '',
			'raw'       => $res,
		);
	}

	/**
	 * Parse webhook / callback payload status.
	 *
	 * @param array<string,mixed> $payload Decoded JSON body.
	 * @return array{uuid:string,reference:string,status:string}
	 */
	public static function parse_callback_payload( array $payload ) {
		$tx = array();
		if ( isset( $payload['transaction'] ) && is_array( $payload['transaction'] ) ) {
			$tx = $payload['transaction'];
		} elseif ( isset( $payload['data']['transaction'] ) && is_array( $payload['data']['transaction'] ) ) {
			$tx = $payload['data']['transaction'];
		}
		return array(
			'uuid'      => isset( $tx['uuid'] ) ? sanitize_text_field( (string) $tx['uuid'] ) : '',
			'reference' => isset( $tx['reference'] ) ? sanitize_text_field( (string) $tx['reference'] ) : '',
			'status'    => isset( $tx['status'] ) ? sanitize_key( (string) $tx['status'] ) : '',
		);
	}
}
