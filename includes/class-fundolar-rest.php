<?php
/**
 * REST API routes.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_REST
 */
class Fundolar_REST {

	const NS = 'fundolar/v1';

	/**
	 * Register routes.
	 */
	public static function register() {
		register_rest_route(
			self::NS,
			'/bootstrap',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'bootstrap' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/init-payment',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'init_payment' ),
				'permission_callback' => array( __CLASS__, 'permission_donate_request' ),
				'args'                => array(
					'nonce'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_nonce_param' ),
					),
					'gateway'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
					'name'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'email'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_email',
					),
					'amount'   => array(
						'type'              => 'number',
						'required'          => true,
					),
					'currency' => array(
						'type'              => 'string',
						'default'           => 'USD',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'return_url' => array(
						'type'              => 'string',
						'sanitize_callback' => 'esc_url_raw',
					),
					'phone_number' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/paypal/capture',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'paypal_capture' ),
				'permission_callback' => array( __CLASS__, 'permission_donate_request' ),
				'args'                => array(
					'nonce'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_nonce_param' ),
					),
					'order_id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/webhooks/stripe',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook_stripe' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/stripe/sync-intent',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'stripe_sync_intent' ),
				'permission_callback' => array( __CLASS__, 'permission_donate_request' ),
				'args'                => array(
					'nonce'           => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_nonce_param' ),
					),
					'payment_intent'  => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			self::NS,
			'/webhooks/marzpay',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'webhook_marzpay' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NS,
			'/marzpay/status',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'marzpay_status' ),
				'permission_callback' => array( __CLASS__, 'permission_donate_request' ),
				'args'                => array(
					'nonce'          => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => array( __CLASS__, 'sanitize_nonce_param' ),
					),
					'transaction_id' => array(
						'type'              => 'integer',
						'required'          => true,
					),
				),
			)
		);

		add_filter( 'rest_authentication_errors', array( __CLASS__, 'rest_skip_cookie_check_for_donate_posts' ), 98 );
	}

	/**
	 * Logged-in donors send a valid X-WP-Nonce (wp_rest) on donation POST routes; without it, core cookie auth fails before the route runs.
	 * Only bypass cookie auth when that header is present and valid — never from the route path alone.
	 *
	 * @param WP_Error|bool|null $result Prior result.
	 * @return WP_Error|bool|null|true
	 */
	public static function rest_skip_cookie_check_for_donate_posts( $result ) {
		if ( is_wp_error( $result ) || true === $result ) {
			return $result;
		}
		if ( ! isset( $_SERVER['REQUEST_METHOD'] ) || 'POST' !== strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) ) {
			return $result;
		}
		if ( ! self::is_fundolar_donate_post_route() ) {
			return $result;
		}
		$x = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( $x && wp_verify_nonce( $x, 'wp_rest' ) ) {
			return true;
		}
		return $result;
	}

	/**
	 * Whether the current request targets a public donation POST route.
	 *
	 * @return bool
	 */
	private static function is_fundolar_donate_post_route() {
		$slug = '(init-payment|paypal/capture|stripe/sync-intent|marzpay/status)';
		if ( ! empty( $_GET['rest_route'] ) ) {
			$route = (string) wp_unslash( $_GET['rest_route'] );
			if ( preg_match( '#^/?fundolar/v1/' . $slug . '$#i', $route ) ) {
				return true;
			}
		}
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		return (bool) ( $path && preg_match( '#/fundolar/v1/' . $slug . '$#i', $path ) );
	}

	/**
	 * Public donation POST endpoints: require a valid fundolar_donate or wp_rest nonce (see verify_donate_nonce).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function permission_donate_request( WP_REST_Request $request ) {
		return self::verify_donate_nonce( $request );
	}

	/**
	 * Sanitize nonce REST arg (always a string for verify).
	 *
	 * @param mixed $value Raw value.
	 * @return string
	 */
	public static function sanitize_nonce_param( $value ) {
		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}
		return '';
	}

	/**
	 * Fresh tokens for the donation form (not cacheable). Fixes stale nonces from full-page cache.
	 *
	 * @return WP_REST_Response
	 */
	public static function bootstrap() {
		Fundolar_Platform::ensure_gateways_synced_for_display();
		$ready_gateways = Fundolar_Payments::gateways_ready_for_front();
		$s              = Fundolar_Payments::get_settings();
		$data = array(
			'nonce'           => wp_create_nonce( 'fundolar_donate' ),
			'rest_nonce'      => wp_create_nonce( 'wp_rest' ),
			'platformFeeRate' => Fundolar_Fees::effective_percentage_for_js(),
			'enabled'         => $ready_gateways,
			'syncedGateways'  => array_values( array_unique( array_map( 'sanitize_key', (array) ( $s['enabled_gateways'] ?? array() ) ) ) ),
			'gatewayMeta'     => Fundolar_Payments::synced_gateway_meta(),
			'gatewayAssets'   => Fundolar_Payments::gateway_assets_for_js( $ready_gateways ),
			'stripePk'        => isset( $s['stripe_publishable'] ) ? (string) $s['stripe_publishable'] : '',
			'paypalClient'    => isset( $s['paypal_client_id'] ) ? (string) $s['paypal_client_id'] : '',
		);
		$response = new WP_REST_Response( $data );
		$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
		$response->header( 'Vary', 'Cookie' );
		return $response;
	}

	/**
	 * Verify donation request: fundolar_donate body nonce, JSON body fallback, or X-WP-Nonce (wp_rest).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	private static function verify_donate_nonce( WP_REST_Request $request ) {
		$x = isset( $_SERVER['HTTP_X_WP_NONCE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) ) : '';
		if ( $x && wp_verify_nonce( $x, 'wp_rest' ) ) {
			return true;
		}

		$n = $request->get_param( 'nonce' );
		if ( ! is_string( $n ) || $n === '' ) {
			$json = $request->get_json_params();
			if ( is_array( $json ) && ! empty( $json['nonce'] ) && is_string( $json['nonce'] ) ) {
				$n = sanitize_text_field( $json['nonce'] );
			}
		}

		if ( is_string( $n ) && $n !== '' && wp_verify_nonce( $n, 'fundolar_donate' ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Init payment session.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function init_payment( WP_REST_Request $request ) {
		Fundolar_Platform::ensure_gateways_synced_for_display( true );
		$gateway = sanitize_key( (string) $request->get_param( 'gateway' ) );
		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		$amount  = round( (float) $request->get_param( 'amount' ), 4 );
		$cur     = strtoupper( substr( sanitize_text_field( (string) $request->get_param( 'currency' ) ), 0, 3 ) );

		if ( ! is_email( $email ) ) {
			return new WP_Error( 'fundolar_email', __( 'Please enter a valid email address.', 'fundolar' ), array( 'status' => 400 ) );
		}
		if ( strlen( $cur ) !== 3 || ! ctype_alpha( $cur ) ) {
			return new WP_Error( 'fundolar_currency', __( 'Invalid currency.', 'fundolar' ), array( 'status' => 400 ) );
		}
		if ( ! is_finite( $amount ) || $amount <= 0 || $amount > 999999 ) {
			return new WP_Error( 'fundolar_amount', __( 'Invalid amount.', 'fundolar' ), array( 'status' => 400 ) );
		}
		if ( ! Fundolar_Payments::gateway_ready( $gateway ) ) {
			return new WP_Error( 'fundolar_gateway', __( 'This payment method is not available.', 'fundolar' ), array( 'status' => 400 ) );
		}
		$allowed_currencies = Fundolar_Payments::gateway_currencies( $gateway );
		if ( ! empty( $allowed_currencies ) && ! in_array( $cur, $allowed_currencies, true ) ) {
			return new WP_Error(
				'fundolar_gateway_currency',
				__( 'This payment method is not available for the selected currency.', 'fundolar' ),
				array( 'status' => 400 )
			);
		}

		$return = $request->get_param( 'return_url' );
		$return = $return ? esc_url_raw( $return ) : home_url( '/' );

		$payload = array(
			'name'         => $name,
			'email'        => $email,
			'amount'       => $amount,
			'currency'     => $cur,
			'redirect_url' => $return,
		);

		$split = Fundolar_Fees::split_for_checkout( $amount, $cur );

		if ( 'stripe' === $gateway ) {
			$res = Fundolar_Payments::stripe_create_intent( $payload );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$tid = Fundolar_DB::insert_checkout_transaction(
				$name,
				$email,
				$split,
				'stripe',
				$res['id'],
				array( 'type' => 'stripe_intent' )
			);
			if ( ! $tid ) {
				return new WP_Error( 'fundolar_db', __( 'Could not start payment. Please try again.', 'fundolar' ), array( 'status' => 500 ) );
			}
			Fundolar_Platform::report_donation_created(
				(int) $tid,
				array(
					'donor_name'             => $name,
					'donor_email'            => $email,
					'payment_gateway'        => 'stripe',
					'gateway_payment_intent' => $res['id'],
					'gateway_reference'      => $res['id'],
					'currency'               => $split['currency'],
					'gross_amount'           => $split['gross'],
					'source_channel'         => 'wordpress_plugin',
				)
			);
			return rest_ensure_response(
				array(
					'ok'             => true,
					'gateway'        => 'stripe',
					'client_secret'  => $res['client_secret'],
					'transaction_id' => $tid,
					'receipt'        => $split['gross'],
				)
			);
		}

		if ( 'paystack' === $gateway ) {
			if ( 'KES' !== $split['currency'] ) {
				return new WP_Error(
					'fundolar_paystack_currency',
					__( 'Paystack checkout is currently available only for KES.', 'fundolar' ),
					array( 'status' => 400 )
				);
			}
			$ps_payload            = $payload;
			$ps_payload['callback_url'] = add_query_arg( 'fundolar_gateway', 'paystack', $return );
			$res                   = Fundolar_Payments::paystack_initialize( $ps_payload );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['authorization_url'] ) ) {
				return new WP_Error( 'fundolar_paystack', __( 'Paystack did not return a checkout URL.', 'fundolar' ), array( 'status' => 502 ) );
			}
			$tid = Fundolar_DB::insert_checkout_transaction( $name, $email, $split, 'paystack', $res['reference'] );
			if ( ! $tid ) {
				return new WP_Error( 'fundolar_db', __( 'Could not start payment. Please try again.', 'fundolar' ), array( 'status' => 500 ) );
			}
			Fundolar_Platform::report_donation_created(
				(int) $tid,
				array(
					'donor_name'        => $name,
					'donor_email'       => $email,
					'payment_gateway'   => 'paystack',
					'gateway_reference' => $res['reference'],
					'currency'          => $split['currency'],
					'gross_amount'      => $split['gross'],
					'source_channel'    => 'wordpress_plugin',
				)
			);
			return rest_ensure_response(
				array(
					'ok'                => true,
					'gateway'           => 'paystack',
					'authorization_url' => $res['authorization_url'],
					'transaction_id'    => $tid,
					'receipt'           => $split['gross'],
				)
			);
		}

		if ( 'flutterwave' === $gateway ) {
			$payload['redirect_url'] = add_query_arg( 'fundolar_gateway', 'flutterwave', $return );
			$res                     = Fundolar_Payments::flutterwave_init( $payload );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['link'] ) ) {
				return new WP_Error( 'fundolar_fw', __( 'Flutterwave did not return a checkout URL.', 'fundolar' ), array( 'status' => 502 ) );
			}
			$tid = Fundolar_DB::insert_checkout_transaction( $name, $email, $split, 'flutterwave', $res['tx_ref'] );
			if ( ! $tid ) {
				return new WP_Error( 'fundolar_db', __( 'Could not start payment. Please try again.', 'fundolar' ), array( 'status' => 500 ) );
			}
			Fundolar_Platform::report_donation_created(
				(int) $tid,
				array(
					'donor_name'        => $name,
					'donor_email'       => $email,
					'payment_gateway'   => 'flutterwave',
					'gateway_reference' => $res['tx_ref'],
					'currency'          => $split['currency'],
					'gross_amount'      => $split['gross'],
					'source_channel'    => 'wordpress_plugin',
				)
			);
			return rest_ensure_response(
				array(
					'ok'                  => true,
					'gateway'             => 'flutterwave',
					'authorization_url'   => $res['link'],
					'transaction_id'      => $tid,
					'receipt'             => $split['gross'],
				)
			);
		}

		if ( 'paypal' === $gateway ) {
			$res = Fundolar_Payments::paypal_create_order( $payload );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['id'] ) ) {
				return new WP_Error( 'fundolar_paypal', __( 'PayPal did not return an order. Please try again.', 'fundolar' ), array( 'status' => 502 ) );
			}
			$tid = Fundolar_DB::insert_checkout_transaction( $name, $email, $split, 'paypal', $res['id'] );
			if ( ! $tid ) {
				return new WP_Error( 'fundolar_db', __( 'Could not start payment. Please try again.', 'fundolar' ), array( 'status' => 500 ) );
			}
			Fundolar_Platform::report_donation_created(
				(int) $tid,
				array(
					'donor_name'        => $name,
					'donor_email'       => $email,
					'payment_gateway'   => 'paypal',
					'gateway_order_id'  => $res['id'],
					'gateway_reference' => $res['id'],
					'currency'          => $split['currency'],
					'gross_amount'      => $split['gross'],
					'source_channel'    => 'wordpress_plugin',
				)
			);
			return rest_ensure_response(
				array(
					'ok'             => true,
					'gateway'        => 'paypal',
					'order_id'       => $res['id'],
					'transaction_id' => $tid,
					'receipt'        => $split['gross'],
				)
			);
		}

		if ( 'mobile_money_ug' === $gateway ) {
			if ( 'UGX' !== $split['currency'] ) {
				return new WP_Error(
					'fundolar_marzpay_currency',
					__( 'Mobile Money (UG) checkout is available only for UGX.', 'fundolar' ),
					array( 'status' => 400 )
				);
			}
			$phone = sanitize_text_field( (string) $request->get_param( 'phone_number' ) );
			$phone = Fundolar_Marzpay::normalize_phone( $phone );
			if ( is_wp_error( $phone ) ) {
				return $phone;
			}
			$mm_payload                 = $payload;
			$mm_payload['phone_number'] = $phone;
			$mm_payload['callback_url'] = rest_url( 'fundolar/v1/webhooks/marzpay' );
			$res                        = Fundolar_Payments::marzpay_collect( $mm_payload );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			$tid = Fundolar_DB::insert_checkout_transaction(
				$name,
				$email,
				$split,
				'mobile_money_ug',
				$res['uuid'],
				array(
					'marzpay_reference' => $res['reference'],
					'phone_number'      => $phone,
				)
			);
			if ( ! $tid ) {
				return new WP_Error( 'fundolar_db', __( 'Could not start payment. Please try again.', 'fundolar' ), array( 'status' => 500 ) );
			}
			Fundolar_Platform::report_donation_created(
				(int) $tid,
				array(
					'donor_name'        => $name,
					'donor_email'       => $email,
					'payment_gateway'   => 'mobile_money_ug',
					'gateway_reference' => $res['uuid'],
					'currency'          => $split['currency'],
					'gross_amount'      => $split['gross'],
					'source_channel'    => 'wordpress_plugin',
				)
			);
			return rest_ensure_response(
				array(
					'ok'             => true,
					'gateway'        => 'mobile_money_ug',
					'transaction_id' => $tid,
					'marzpay_uuid'   => $res['uuid'],
					'status'         => $res['status'],
					'message'        => __( 'Check your phone and approve the Mobile Money prompt.', 'fundolar' ),
					'receipt'        => $split['gross'],
				)
			);
		}

		if ( 'pesapal' === $gateway ) {
			if ( ! in_array( $split['currency'], Fundolar_Payments::pesapal_supported_currencies(), true ) ) {
				return new WP_Error(
					'fundolar_pesapal_currency',
					__( 'Pesapal checkout is available only for mobile-money enabled currencies.', 'fundolar' ),
					array( 'status' => 400 )
				);
			}
			$payload['redirect_url'] = add_query_arg( 'fundolar_gateway', 'pesapal', $return );
			$res                     = Fundolar_Payments::pesapal_init( $payload );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			if ( empty( $res['authorization_url'] ) ) {
				return new WP_Error( 'fundolar_pesapal', __( 'Pesapal did not return a checkout URL.', 'fundolar' ), array( 'status' => 502 ) );
			}
			$tid = Fundolar_DB::insert_checkout_transaction(
				$name,
				$email,
				$split,
				'pesapal',
				$res['reference'],
				array(
					'order_tracking_id' => isset( $res['order_tracking_id'] ) ? (string) $res['order_tracking_id'] : '',
				)
			);
			if ( ! $tid ) {
				return new WP_Error( 'fundolar_db', __( 'Could not start payment. Please try again.', 'fundolar' ), array( 'status' => 500 ) );
			}
			Fundolar_Platform::report_donation_created(
				(int) $tid,
				array(
					'donor_name'        => $name,
					'donor_email'       => $email,
					'payment_gateway'   => 'pesapal',
					'gateway_reference' => $res['reference'],
					'currency'          => $split['currency'],
					'gross_amount'      => $split['gross'],
					'source_channel'    => 'wordpress_plugin',
				)
			);
			return rest_ensure_response(
				array(
					'ok'                => true,
					'gateway'           => 'pesapal',
					'authorization_url' => $res['authorization_url'],
					'transaction_id'    => $tid,
					'receipt'           => $split['gross'],
				)
			);
		}

		return new WP_Error( 'fundolar_gateway', __( 'Unsupported gateway.', 'fundolar' ), array( 'status' => 400 ) );
	}

	/**
	 * Capture PayPal order server-side.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function paypal_capture( WP_REST_Request $request ) {
		$order_id = sanitize_text_field( (string) $request->get_param( 'order_id' ) );
		if ( '' === $order_id ) {
			return new WP_Error( 'fundolar_paypal', __( 'Missing order id.', 'fundolar' ), array( 'status' => 400 ) );
		}
		$s      = Fundolar_Payments::get_settings();
		$secret = Fundolar_Payments::get_credential_secret( 'paypal_secret' );
		$client   = trim( $s['paypal_client_id'] );
		if ( '' === $secret || '' === $client ) {
			return new WP_Error( 'fundolar_paypal', __( 'PayPal not configured.', 'fundolar' ) );
		}
		$token = self::paypal_token_internal( $client, $secret );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$base     = apply_filters( 'fundolar_paypal_api_base', 'https://api-m.paypal.com' );
		$response = wp_remote_post(
			$base . '/v2/checkout/orders/' . rawurlencode( $order_id ) . '/capture',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Content-Type'  => 'application/json',
				),
				'body'    => '{}',
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			self::mark_paypal_tx( $order_id, 'failed', $json );
			return new WP_Error( 'fundolar_paypal', __( 'PayPal capture failed.', 'fundolar' ), array( 'status' => 400 ) );
		}
		$status = $json['status'] ?? '';
		if ( 'COMPLETED' === $status ) {
			self::mark_paypal_tx( $order_id, 'completed', $json );
			return rest_ensure_response( array( 'ok' => true, 'receipt' => self::receipt_from_paypal_custom( $json ) ) );
		}
		self::mark_paypal_tx( $order_id, 'failed', $json );
		return new WP_Error( 'fundolar_paypal', __( 'Payment not completed.', 'fundolar' ), array( 'status' => 400 ) );
	}

	/**
	 * @param string $client_id Client ID.
	 * @param string $client_secret Secret.
	 * @return string|WP_Error
	 */
	private static function paypal_token_internal( $client_id, $client_secret ) {
		$base     = apply_filters( 'fundolar_paypal_api_base', 'https://api-m.paypal.com' );
		$response = wp_remote_post(
			$base . '/v1/oauth2/token',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $client_id . ':' . $client_secret ),
				),
				'body'    => array( 'grant_type' => 'client_credentials' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['access_token'] ) ) {
			return new WP_Error( 'fundolar_paypal_auth', __( 'PayPal authentication failed.', 'fundolar' ) );
		}
		return $json['access_token'];
	}

	/**
	 * Update transaction row for PayPal order.
	 *
	 * @param string $order_id Order ID.
	 * @param string $status Status.
	 * @param array  $json Response.
	 */
	private static function mark_paypal_tx( $order_id, $status, $json ) {
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'paypal', $order_id ) );
		if ( ! $row ) {
			return;
		}
		Fundolar_DB::update(
			(int) $row->id,
			array(
				'status' => $status,
				'meta'   => wp_json_encode( array( 'paypal' => $json ) ),
			)
		);
		if ( 'completed' === $status ) {
			Fundolar_Emails::notify_donation_completed( (int) $row->id );
		}
		Fundolar_Platform::report_donation_status( (int) $row->id, $status, 'paypal_' . $status, is_array( $json ) ? $json : array() );
	}

	/**
	 * Parse receipt from PayPal custom_id if present.
	 *
	 * @param array $json JSON.
	 * @return float|null
	 */
	private static function receipt_from_paypal_custom( $json ) {
		$units = $json['purchase_units'] ?? array();
		if ( ! is_array( $units ) || empty( $units ) ) {
			return null;
		}
		$total = 0.0;
		foreach ( $units as $unit ) {
			if ( empty( $unit['payments']['captures'][0]['amount']['value'] ) ) {
				continue;
			}
			$total += (float) $unit['payments']['captures'][0]['amount']['value'];
		}
		if ( $total > 0 ) {
			return (float) $total;
		}
		return null;
	}

	/**
	 * Stripe webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function webhook_stripe( WP_REST_Request $request ) {
		$wh_secret = Fundolar_Payments::get_credential_secret( 'stripe_webhook_secret' );
		$payload   = $request->get_body();
		$sig_header = isset( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_STRIPE_SIGNATURE'] ) ) : '';

		if ( '' === $wh_secret ) {
			return new WP_REST_Response( array( 'received' => false ), 400 );
		}

		$event = self::stripe_verify_webhook( $payload, $sig_header, $wh_secret );
		if ( is_wp_error( $event ) ) {
			return new WP_REST_Response( array( 'error' => $event->get_error_message() ), 400 );
		}

		$type = $event['type'] ?? '';
		if ( 'payment_intent.succeeded' === $type ) {
			$obj = $event['data']['object'] ?? array();
			$id  = $obj['id'] ?? '';
			if ( $id ) {
				self::stripe_webhook_payment_intent_succeeded( $id, $obj );
			}
		}
		if ( 'payment_intent.payment_failed' === $type ) {
			$obj = $event['data']['object'] ?? array();
			$id  = $obj['id'] ?? '';
			if ( $id ) {
				self::stripe_webhook_payment_intent_failed( $id, $obj );
			}
		}
		if ( 'invoice.payment_succeeded' === $type ) {
			$inv = $event['data']['object'] ?? array();
			if ( is_array( $inv ) ) {
				self::stripe_webhook_invoice_payment_succeeded( $inv );
			}
		}

		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * @param string|null $json Stored meta JSON.
	 * @return array<string,mixed>
	 */
	private static function fundolar_decode_transaction_meta( $json ) {
		if ( ! is_string( $json ) || '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * @param string               $pi_id PaymentIntent id.
	 * @param array<string,mixed> $obj   Stripe object.
	 */
	private static function stripe_webhook_payment_intent_succeeded( $pi_id, array $obj ) {
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, meta FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'stripe', $pi_id ) );
		if ( ! $row ) {
			return;
		}
		$meta = self::fundolar_decode_transaction_meta( $row->meta );
		$meta['stripe'] = $obj;
		if ( 'completed' === $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
			return;
		}
		if ( 'pending' === $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'status' => 'completed', 'meta' => $meta ) );
			Fundolar_Emails::notify_donation_completed( (int) $row->id );
			Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'stripe_succeeded', $obj );
		}
	}

	/**
	 * @param string               $pi_id PaymentIntent id.
	 * @param array<string,mixed> $obj   Stripe object.
	 */
	private static function stripe_webhook_payment_intent_failed( $pi_id, array $obj ) {
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, meta FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'stripe', $pi_id ) );
		if ( ! $row ) {
			return;
		}
		$meta = self::fundolar_decode_transaction_meta( $row->meta );
		$meta['stripe'] = $obj;
		if ( 'completed' === $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
			return;
		}
		Fundolar_DB::update( (int) $row->id, array( 'status' => 'failed', 'meta' => $meta ) );
		Fundolar_Platform::report_donation_status( (int) $row->id, 'failed', 'stripe_failed', $obj );
	}

	/**
	 * Complete a pending row when Stripe confirms the invoice (backup to payment_intent.succeeded).
	 *
	 * @param array<string,mixed> $inv Invoice object.
	 */
	private static function stripe_webhook_invoice_payment_succeeded( array $inv ) {
		$raw_pi = $inv['payment_intent'] ?? null;
		$pi_id  = '';
		if ( is_string( $raw_pi ) ) {
			$pi_id = $raw_pi;
		} elseif ( is_array( $raw_pi ) && ! empty( $raw_pi['id'] ) ) {
			$pi_id = (string) $raw_pi['id'];
		}
		if ( '' === $pi_id ) {
			return;
		}

		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, meta FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'stripe', $pi_id ) );

		if ( ! $row ) {
			return;
		}

		$meta = self::fundolar_decode_transaction_meta( $row->meta );
		$meta['stripe_invoice'] = $inv;
		if ( 'completed' === $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
			return;
		}
		if ( 'pending' === $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'status' => 'completed', 'meta' => $meta ) );
			Fundolar_Emails::notify_donation_completed( (int) $row->id );
			Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'stripe_invoice_paid', $inv );
		}
	}

	/**
	 * Verify Stripe webhook (timestamp + signatures).
	 *
	 * @param string $payload Body.
	 * @param string $sig_header Signature header.
	 * @param string $secret Signing secret.
	 * @return array|WP_Error
	 */
	private static function stripe_verify_webhook( $payload, $sig_header, $secret ) {
		$t     = '';
		$v1s   = array();
		foreach ( explode( ',', $sig_header ) as $part ) {
			$kv = explode( '=', trim( $part ), 2 );
			if ( 2 !== count( $kv ) ) {
				continue;
			}
			$k = $kv[0];
			$v = $kv[1];
			if ( 't' === $k ) {
				$t = $v;
			}
			if ( 'v1' === $k ) {
				$v1s[] = $v;
			}
		}
		if ( '' === $t || empty( $v1s ) ) {
			return new WP_Error( 'stripe_sig', 'Bad signature header' );
		}
		if ( abs( time() - (int) $t ) > 300 ) {
			return new WP_Error( 'stripe_sig', 'Timestamp skew' );
		}
		$signed = $t . '.' . $payload;
		$expect = hash_hmac( 'sha256', $signed, $secret );
		$match  = false;
		foreach ( $v1s as $v1 ) {
			if ( hash_equals( $expect, $v1 ) ) {
				$match = true;
				break;
			}
		}
		if ( ! $match ) {
			return new WP_Error( 'stripe_sig', 'Invalid signature' );
		}
		$json = json_decode( $payload, true );
		return is_array( $json ) ? $json : new WP_Error( 'stripe_sig', 'Invalid JSON' );
	}

	/**
	 * After client confirms payment, verify status with Stripe and update the local row.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function stripe_sync_intent( WP_REST_Request $request ) {
		$id = sanitize_text_field( (string) $request->get_param( 'payment_intent' ) );
		if ( '' === $id || 0 !== strpos( $id, 'pi_' ) ) {
			return new WP_Error( 'fundolar_stripe', __( 'Invalid payment reference.', 'fundolar' ), array( 'status' => 400 ) );
		}
		$secret = Fundolar_Payments::get_credential_secret( 'stripe_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'fundolar_stripe', __( 'Stripe is not configured.', 'fundolar' ) );
		}
		$url      = 'https://api.stripe.com/v1/payment_intents/' . rawurlencode( $id );
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $json ) ) {
			return new WP_Error( 'fundolar_stripe', __( 'Could not verify payment.', 'fundolar' ) );
		}
		$status = isset( $json['status'] ) ? $json['status'] : '';
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, meta FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'stripe', $id ) );
		if ( $row ) {
			$meta = self::fundolar_decode_transaction_meta( $row->meta );
			$meta['stripe'] = $json;
			if ( 'succeeded' === $status ) {
				if ( 'completed' === $row->status ) {
					Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
				} elseif ( 'pending' === $row->status ) {
					Fundolar_DB::update( (int) $row->id, array( 'status' => 'completed', 'meta' => $meta ) );
					Fundolar_Emails::notify_donation_completed( (int) $row->id );
					Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'stripe_succeeded', $json );
				}
			} elseif ( in_array( $status, array( 'canceled', 'requires_payment_method' ), true ) ) {
				if ( 'completed' !== $row->status ) {
					Fundolar_DB::update( (int) $row->id, array( 'status' => 'failed', 'meta' => $meta ) );
					Fundolar_Platform::report_donation_status( (int) $row->id, 'failed', (string) $status, $json );
				} else {
					Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
				}
			}
		}
		return rest_ensure_response( array( 'ok' => true, 'status' => $status ) );
	}

	/**
	 * MarzPay webhook / callback.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function webhook_marzpay( WP_REST_Request $request ) {
		$json = json_decode( $request->get_body(), true );
		if ( is_array( $json ) ) {
			Fundolar_Payments::marzpay_handle_webhook( $json );
		}
		return new WP_REST_Response( array( 'received' => true ), 200 );
	}

	/**
	 * Poll MarzPay collection status for donor UI.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function marzpay_status( WP_REST_Request $request ) {
		$tid = (int) $request->get_param( 'transaction_id' );
		if ( $tid < 1 ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Invalid transaction.', 'fundolar' ), array( 'status' => 400 ) );
		}
		$res = Fundolar_Payments::marzpay_sync_transaction( $tid );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response(
			array(
				'ok'        => true,
				'status'    => $res['status'],
				'completed' => ! empty( $res['completed'] ),
			)
		);
	}
}
