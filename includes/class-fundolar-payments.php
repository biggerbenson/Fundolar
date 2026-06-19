<?php
/**
 * Payment gateway helpers (initiate sessions, webhooks).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Payments
 */
class Fundolar_Payments {

	const OPTION = 'fundolar_settings';

	const MODE_OWN_KEYS = 'own_keys';

	const MODE_CENTRAL = 'central';

	/**
	 * Built-in gateway slugs shipped with the plugin.
	 *
	 * @return string[]
	 */
	public static function builtin_gateway_slugs() {
		return array( 'stripe', 'paypal', 'mobile_money_ug', 'pesapal', 'flutterwave', 'paystack' );
	}

	/**
	 * All gateways (Central mode), including any slugs synced from Fundolar Central.
	 *
	 * @return string[]
	 */
	public static function gateways() {
		$slugs = self::builtin_gateway_slugs();
		$s     = self::get_settings();
		foreach ( (array) ( $s['enabled_gateways'] ?? array() ) as $gateway ) {
			$gateway = sanitize_key( (string) $gateway );
			if ( '' !== $gateway ) {
				$slugs[] = $gateway;
			}
		}
		if ( ! empty( $s['platform_gateway_meta'] ) && is_array( $s['platform_gateway_meta'] ) ) {
			foreach ( array_keys( $s['platform_gateway_meta'] ) as $gateway ) {
				$gateway = sanitize_key( (string) $gateway );
				if ( '' !== $gateway ) {
					$slugs[] = $gateway;
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Gateways available in own-keys mode (WordPress.org–friendly).
	 *
	 * @return string[]
	 */
	public static function own_keys_gateways() {
		return array( 'stripe', 'paypal', 'mobile_money_ug' );
	}

	/**
	 * Human-readable gateway labels.
	 *
	 * @return array<string,string>
	 */
	public static function gateway_labels() {
		return array(
			'stripe'          => __( 'Stripe', 'fundolar' ),
			'paypal'          => __( 'PayPal', 'fundolar' ),
			'mobile_money_ug' => __( 'Mobile Money (UG)', 'fundolar' ),
			'paystack'        => __( 'Paystack', 'fundolar' ),
			'flutterwave'     => __( 'Flutterwave', 'fundolar' ),
			'pesapal'         => __( 'Pesapal', 'fundolar' ),
		);
	}

	/**
	 * @param string $gateway Gateway slug.
	 * @return string
	 */
	/**
	 * Public URL for a gateway checkout logo (SVG preferred, then PNG/WebP).
	 *
	 * @param string $gateway Gateway slug.
	 * @return string Empty when no bundled logo exists.
	 */
	public static function gateway_logo_url( $gateway ) {
		$gateway = sanitize_key( (string) $gateway );
		if ( '' === $gateway ) {
			return '';
		}
		$dir  = FUNDOLAR_PLUGIN_DIR . 'resources/images/logos/';
		$base = FUNDOLAR_PLUGIN_URL . 'resources/images/logos/' . $gateway;
		$ext  = '';
		foreach ( array( 'svg', 'png', 'webp' ) as $candidate ) {
			if ( is_file( $dir . $gateway . '.' . $candidate ) ) {
				$ext = $candidate;
				break;
			}
		}
		if ( '' === $ext ) {
			return '';
		}
		$url = $base . '.' . $ext;
		if ( defined( 'FUNDOLAR_VERSION' ) && FUNDOLAR_VERSION ) {
			$url = add_query_arg( 'v', rawurlencode( FUNDOLAR_VERSION ), $url );
		}
		return $url;
	}

	public static function gateway_label( $gateway ) {
		$s    = self::get_settings();
		$meta = isset( $s['platform_gateway_meta'] ) && is_array( $s['platform_gateway_meta'] ) ? $s['platform_gateway_meta'] : array();
		$gateway = sanitize_key( (string) $gateway );
		if ( isset( $meta[ $gateway ]['label'] ) && is_string( $meta[ $gateway ]['label'] ) && '' !== trim( $meta[ $gateway ]['label'] ) ) {
			return $meta[ $gateway ]['label'];
		}
		$labels = self::gateway_labels();
		return isset( $labels[ $gateway ] ) ? $labels[ $gateway ] : ucfirst( str_replace( '_', ' ', $gateway ) );
	}

	/**
	 * Supported checkout currencies for a gateway (empty = any).
	 *
	 * @param string $gateway Gateway slug.
	 * @return string[]
	 */
	public static function gateway_currencies( $gateway ) {
		$s       = self::get_settings();
		$gateway = sanitize_key( (string) $gateway );
		$meta    = isset( $s['platform_gateway_meta'] ) && is_array( $s['platform_gateway_meta'] ) ? $s['platform_gateway_meta'] : array();
		if ( isset( $meta[ $gateway ]['currencies'] ) && is_array( $meta[ $gateway ]['currencies'] ) ) {
			$list = array_map(
				static function ( $c ) {
					return strtoupper( substr( sanitize_text_field( (string) $c ), 0, 3 ) );
				},
				$meta[ $gateway ]['currencies']
			);
			return array_values( array_unique( array_filter( $list ) ) );
		}
		if ( 'paystack' === $gateway ) {
			return array( 'KES' );
		}
		if ( 'pesapal' === $gateway ) {
			return self::pesapal_supported_currencies();
		}
		if ( 'mobile_money_ug' === $gateway ) {
			return array( 'UGX' );
		}
		return array();
	}

	/**
	 * Gateway catalog synced from Central (label, currencies, etc.).
	 *
	 * @return array<string,array<string,mixed>>
	 */
	public static function synced_gateway_meta() {
		$s = self::get_settings();
		$meta = isset( $s['platform_gateway_meta'] ) && is_array( $s['platform_gateway_meta'] ) ? $s['platform_gateway_meta'] : array();
		return $meta;
	}

	/**
	 * Whether local gateway cache should be refreshed from Central.
	 *
	 * @return bool
	 */
	public static function platform_sync_is_stale() {
		if ( ! self::is_central_connected() ) {
			return false;
		}
		$s = self::get_settings();
		$at = isset( $s['platform_last_sync_at'] ) ? strtotime( (string) $s['platform_last_sync_at'] ) : false;
		if ( false === $at || $at < 1 ) {
			return true;
		}
		$stale_after = (int) apply_filters( 'fundolar_platform_sync_stale_seconds', Fundolar_Platform::SYNC_STALE_SECONDS );
		return ( time() - $at ) >= max( 300, $stale_after );
	}

	/**
	 * Active payment mode slug.
	 *
	 * @return string
	 */
	public static function payment_mode() {
		return self::MODE_CENTRAL;
	}

	/**
	 * @return bool
	 */
	public static function is_own_keys_mode() {
		return false;
	}

	/**
	 * @return bool
	 */
	public static function is_central_mode() {
		return self::MODE_CENTRAL === self::payment_mode();
	}

	/**
	 * Central mode with an active platform connection.
	 *
	 * @return bool
	 */
	public static function is_central_connected() {
		if ( ! self::is_central_mode() ) {
			return false;
		}
		$s = self::get_settings();
		return '' !== trim( (string) ( $s['platform_api_key'] ?? '' ) );
	}

	/**
	 * Gateways for the current payment mode.
	 *
	 * @return string[]
	 */
	public static function gateways_for_mode() {
		return self::gateways();
	}

	/**
	 * Human label for payment mode.
	 *
	 * @return string
	 */
	public static function payment_mode_label() {
		return __( 'Fundolar Central', 'fundolar' );
	}

	/**
	 * Public form layout options (slug => label).
	 *
	 * @return array<string,string>
	 */
	public static function form_layouts() {
		return array(
			'portrait'  => __( 'Portrait (default)', 'fundolar' ),
			'landscape' => __( 'Landscape (wide)', 'fundolar' ),
			'inline'    => __( 'Inline (full width)', 'fundolar' ),
			'compact'   => __( 'Compact (small)', 'fundolar' ),
			'split'     => __( 'Split (hero + form)', 'fundolar' ),
		);
	}

	/**
	 * FX rates expressed as 1 USD -> target currency.
	 * Used for client-side display conversion only.
	 *
	 * @return array<string,float>
	 */
	public static function fx_rates_usd_base() {
		$rates = array(
			'USD' => 1.0,
			'EUR' => 0.92,
			'GBP' => 0.79,
			'NGN' => 1550.0,
			'KES' => 130.0,
			'GHS' => 15.5,
			'ZAR' => 18.8,
			'UGX' => 3800.0,
			'TZS' => 2580.0,
			'RWF' => 1320.0,
			'ZMW' => 27.0,
			'MWK' => 1730.0,
			'BIF' => 2880.0,
		);
		/**
		 * Filter display conversion rates (USD base).
		 *
		 * @param array<string,float> $rates Rates map.
		 */
		$rates = apply_filters( 'fundolar_fx_rates_usd_base', $rates );
		if ( ! is_array( $rates ) ) {
			$rates = array( 'USD' => 1.0 );
		}
		$out = array();
		foreach ( $rates as $code => $value ) {
			$key = strtoupper( substr( sanitize_text_field( (string) $code ), 0, 3 ) );
			$val = (float) $value;
			if ( '' === $key || $val <= 0 ) {
				continue;
			}
			$out[ $key ] = $val;
		}
		if ( empty( $out['USD'] ) ) {
			$out['USD'] = 1.0;
		}
		return $out;
	}

	/**
	 * Pesapal currencies allowed in checkout UI/API.
	 *
	 * @return string[]
	 */
	public static function pesapal_supported_currencies() {
		$list = array( 'UGX', 'KES', 'TZS', 'NGN', 'GHS', 'RWF', 'ZMW', 'MWK', 'BIF' );
		/**
		 * Filter Pesapal supported currencies.
		 *
		 * @param string[] $list ISO currency codes.
		 */
		$list = apply_filters( 'fundolar_pesapal_supported_currencies', $list );
		$list = is_array( $list ) ? $list : array();
		$list = array_map(
			static function ( $c ) {
				return strtoupper( substr( sanitize_text_field( (string) $c ), 0, 3 ) );
			},
			$list
		);
		return array_values( array_unique( array_filter( $list ) ) );
	}

	/**
	 * Get merged settings.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = array(
			'payment_mode'              => self::MODE_CENTRAL,
			'enabled_gateways'          => array(),
			'preset_amounts'            => array( 10, 20, 50, 100, 200 ),
			'default_currency'          => 'USD',
			'form_layout'               => 'portrait',
			'color_primary'             => '#2b88b1',
			'color_accent'              => '#28a745',
			'notify_admin_on_success'   => '0',
			'notify_admin_recipients'   => '',
			'donor_receipt_enabled'     => '0',
			'donor_receipt_from_email'  => '',
			'donor_receipt_from_name'   => '',
			'donor_email_subject'       => '',
			'donor_email_template'      => '',
			'stripe_publishable'        => '',
			'stripe_secret'             => '',
			'paypal_client_id'          => '',
			'paypal_secret'             => '',
			'pesapal_consumer_key'      => '',
			'pesapal_consumer_secret'   => '',
			'flutterwave_public'        => '',
			'flutterwave_secret'        => '',
			'paystack_public'           => '',
			'paystack_secret'           => '',
			'marzpay_api_key'           => '',
			'marzpay_api_secret'        => '',
			'stripe_webhook_secret'     => '',
			'stripe_connect_account_id' => '',
			'platform_base_url'         => '',
			'platform_site_key'         => '',
			'platform_api_key'          => '',
			'platform_signing_secret'   => '',
			'platform_site_id'          => 0,
			'platform_account_status'   => '',
			'platform_sync_error'       => '',
			'platform_last_sync_at'     => '',
			'platform_sync_revision'    => '',
			'platform_gateway_meta'     => array(),
			'platform_fee_rules'        => array(),
			'platform_payments_enabled' => '1',
			'platform_withdraw_threshold' => 100,
		);
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}
		$merged = wp_parse_args( $stored, $defaults );
		if ( empty( $stored['payment_mode'] ) && ! empty( $merged['platform_api_key'] ) ) {
			$merged['payment_mode'] = self::MODE_CENTRAL;
		}
		return $merged;
	}

	/**
	 * Decrypted secret from site settings only.
	 *
	 * @param string $option_key Settings key.
	 * @return string
	 */
	public static function get_credential_secret( $option_key ) {
		$s = self::get_settings();
		return self::decrypt_secret( isset( $s[ $option_key ] ) ? $s[ $option_key ] : '' );
	}

	/**
	 * Gateways that are both enabled in settings and have valid site-owner credentials (for donor UI).
	 *
	 * @return string[]
	 */
	public static function gateways_ready_for_front() {
		if ( ! self::is_central_connected() ) {
			return array();
		}
		$s = self::get_settings();
		if ( isset( $s['platform_payments_enabled'] ) && '1' !== (string) $s['platform_payments_enabled'] ) {
			return array();
		}
		$list = array_values( array_intersect( self::gateways(), (array) $s['enabled_gateways'] ) );
		return array_values( array_filter( $list, array( __CLASS__, 'gateway_ready' ) ) );
	}

	/**
	 * Save settings (encrypt secrets).
	 *
	 * @param array $input Raw input.
	 * @return array Sanitized stored array.
	 */
	public static function save_settings( array $input ) {
		$prev   = self::get_settings();
		$out    = $prev;
		$map    = array(
			'default_currency'  => 'text',
			'color_primary'     => 'hex',
			'color_accent'      => 'hex',
			'platform_site_key' => 'text',
		);
		foreach ( $map as $key => $type ) {
			if ( ! array_key_exists( $key, $input ) ) {
				continue;
			}
			$val = $input[ $key ];
			if ( 'hex' === $type ) {
				$val = sanitize_hex_color( $val );
				if ( $val ) {
					$out[ $key ] = $val;
				}
			} elseif ( 'secret' === $type ) {
				$val = is_string( $val ) ? trim( $val ) : '';
				if ( '' !== $val ) {
					$out[ $key ] = Fundolar_Crypto::encrypt( $val );
				}
			} else {
				$out[ $key ] = sanitize_text_field( wp_unslash( $val ) );
			}
		}
		if ( isset( $input['preset_amounts'] ) && is_array( $input['preset_amounts'] ) ) {
			$amounts = array();
			foreach ( $input['preset_amounts'] as $a ) {
				$a = round( floatval( $a ), 2 );
				if ( $a > 0 && $a < 1000000 ) {
					$amounts[] = $a;
				}
			}
			$amounts = array_values( array_unique( $amounts ) );
			sort( $amounts );
			if ( count( $amounts ) > 0 ) {
				$out['preset_amounts'] = array_slice( $amounts, 0, 10 );
			}
		}
		$out['payment_mode'] = self::MODE_CENTRAL;
		if ( isset( $input['form_layout'] ) ) {
			$fl = sanitize_key( wp_unslash( $input['form_layout'] ) );
			if ( array_key_exists( $fl, self::form_layouts() ) ) {
				$out['form_layout'] = $fl;
			}
		}
		$out['notify_admin_on_success'] = ! empty( $input['notify_admin_on_success'] ) ? '1' : '0';
		$out['donor_receipt_enabled']   = ! empty( $input['donor_receipt_enabled'] ) ? '1' : '0';
		if ( isset( $input['notify_admin_recipients'] ) ) {
			$out['notify_admin_recipients'] = sanitize_textarea_field( wp_unslash( $input['notify_admin_recipients'] ) );
		}
		if ( isset( $input['donor_receipt_from_name'] ) ) {
			$out['donor_receipt_from_name'] = sanitize_text_field( wp_unslash( $input['donor_receipt_from_name'] ) );
		}
		if ( isset( $input['donor_receipt_from_email'] ) ) {
			$e = sanitize_email( wp_unslash( $input['donor_receipt_from_email'] ) );
			$out['donor_receipt_from_email'] = is_email( $e ) ? $e : '';
		}
		if ( isset( $input['donor_email_subject'] ) ) {
			$out['donor_email_subject'] = sanitize_text_field( wp_unslash( $input['donor_email_subject'] ) );
		}
		if ( isset( $input['donor_email_template'] ) ) {
			$out['donor_email_template'] = wp_kses_post( wp_unslash( $input['donor_email_template'] ) );
		}
		if ( array_key_exists( 'platform_base_url', $input ) ) {
			$raw = trim( (string) wp_unslash( $input['platform_base_url'] ) );
			if ( '' === $raw ) {
				$out['platform_base_url'] = '';
			} else {
				$u = esc_url_raw( $raw );
				$p = wp_parse_url( $u );
				if ( is_array( $p ) && ! empty( $p['scheme'] ) && ! empty( $p['host'] )
					&& in_array( strtolower( (string) $p['scheme'] ), array( 'http', 'https' ), true ) ) {
					$out['platform_base_url'] = rtrim( $u, '/' );
				}
			}
		}
		update_option( self::OPTION, $out );
		return $out;
	}

	/**
	 * Decrypt secret for runtime use.
	 *
	 * @param string $stored Stored ciphertext.
	 * @return string
	 */
	public static function decrypt_secret( $stored ) {
		return Fundolar_Crypto::decrypt( $stored );
	}

	/**
	 * Settings for display (mask secrets).
	 *
	 * @return array
	 */
	public static function get_settings_for_display() {
		$s = self::get_settings();
		foreach ( array( 'stripe_secret', 'paypal_secret', 'pesapal_consumer_secret', 'flutterwave_secret', 'paystack_secret', 'marzpay_api_secret', 'stripe_webhook_secret', 'platform_signing_secret' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				$s[ $k ] = '********';
			}
		}
		if ( ! empty( $s['platform_api_key'] ) ) {
			$s['platform_api_key'] = substr( (string) $s['platform_api_key'], 0, 10 ) . '...';
		}
		return $s;
	}

	/**
	 * Save central platform credentials and synced gateway keys.
	 *
	 * @param array $payload Central API payload.
	 * @return void
	 */
	public static function save_remote_credentials( array $payload ) {
		$out                   = self::get_settings();
		$out['payment_mode'] = self::MODE_CENTRAL;
		if ( isset( $payload['api_key'] ) ) {
			$out['platform_api_key'] = sanitize_text_field( (string) $payload['api_key'] );
		}
		if ( isset( $payload['signing_secret'] ) ) {
			$secret = trim( (string) $payload['signing_secret'] );
			if ( '' !== $secret ) {
				$out['platform_signing_secret'] = Fundolar_Crypto::encrypt( $secret );
			}
		}
		if ( isset( $payload['site_id'] ) ) {
			$out['platform_site_id'] = (int) $payload['site_id'];
		}
		if ( isset( $payload['account_status'] ) ) {
			$out['platform_account_status'] = sanitize_key( (string) $payload['account_status'] );
		}
		if ( isset( $payload['withdrawal_threshold'] ) ) {
			$out['platform_withdraw_threshold'] = max( 100, (float) $payload['withdrawal_threshold'] );
		}
		if ( array_key_exists( 'payments_enabled', $payload ) ) {
			$out['platform_payments_enabled'] = ! empty( $payload['payments_enabled'] ) ? '1' : '0';
		}
		if ( isset( $payload['sync_revision'] ) ) {
			$out['platform_sync_revision'] = sanitize_text_field( (string) $payload['sync_revision'] );
		}
		if ( isset( $payload['gateways'] ) && is_array( $payload['gateways'] ) ) {
			$meta = array();
			foreach ( $payload['gateways'] as $gateway => $info ) {
				$gateway = sanitize_key( (string) $gateway );
				if ( ! in_array( $gateway, self::gateways(), true ) || ! is_array( $info ) ) {
					continue;
				}
				$currencies = array();
				if ( ! empty( $info['currencies'] ) && is_array( $info['currencies'] ) ) {
					foreach ( $info['currencies'] as $code ) {
						$code = strtoupper( substr( sanitize_text_field( (string) $code ), 0, 3 ) );
						if ( '' !== $code ) {
							$currencies[] = $code;
						}
					}
				}
				$meta[ $gateway ] = array(
					'label'      => sanitize_text_field( (string) ( $info['label'] ?? '' ) ),
					'tagline'    => sanitize_text_field( (string) ( $info['tagline'] ?? '' ) ),
					'accent'     => sanitize_hex_color( (string) ( $info['accent'] ?? '' ) ) ?: '',
					'currencies' => array_values( array_unique( $currencies ) ),
				);
			}
			$out['platform_gateway_meta'] = $meta;
		}
		if ( isset( $payload['fee_rules'] ) && is_array( $payload['fee_rules'] ) ) {
			$rules = $payload['fee_rules'];
			$out['platform_fee_rules'] = array(
				'percentage' => isset( $rules['percentage'] ) ? (float) $rules['percentage'] : self::default_fee_rate(),
				'fixed'      => isset( $rules['fixed'] ) ? (float) $rules['fixed'] : 0,
				'min'        => isset( $rules['min'] ) ? (float) $rules['min'] : 0,
				'max'        => isset( $rules['max'] ) ? (float) $rules['max'] : 0,
				'revision'   => isset( $rules['revision'] ) ? (int) $rules['revision'] : 0,
			);
		}

		$enabled = array();
		$allowed = self::builtin_gateway_slugs();
		if ( isset( $payload['gateways'] ) && is_array( $payload['gateways'] ) ) {
			foreach ( array_keys( $payload['gateways'] ) as $gateway ) {
				$gateway = sanitize_key( (string) $gateway );
				if ( '' !== $gateway ) {
					$allowed[] = $gateway;
				}
			}
		}
		$allowed = array_values( array_unique( $allowed ) );
		if ( isset( $payload['enabled'] ) && is_array( $payload['enabled'] ) ) {
			foreach ( $payload['enabled'] as $gateway ) {
				$gateway = sanitize_key( (string) $gateway );
				if ( '' !== $gateway && in_array( $gateway, $allowed, true ) ) {
					$enabled[] = $gateway;
				}
			}
		}
		$enabled = array_values( array_unique( $enabled ) );
		$out['enabled_gateways'] = $enabled;

		$credentials = isset( $payload['credentials'] ) && is_array( $payload['credentials'] ) ? $payload['credentials'] : array();
		$gateway_credentials = array(
			'stripe'          => array( 'stripe_publishable', 'stripe_secret', 'stripe_webhook_secret' ),
			'paypal'          => array( 'paypal_client_id', 'paypal_secret' ),
			'paystack'        => array( 'paystack_public', 'paystack_secret' ),
			'flutterwave'     => array( 'flutterwave_public', 'flutterwave_secret' ),
			'pesapal'         => array( 'pesapal_consumer_key', 'pesapal_consumer_secret' ),
			'mobile_money_ug' => array( 'marzpay_api_key', 'marzpay_api_secret' ),
		);
		$field_to_gateway = array();
		foreach ( $gateway_credentials as $gateway => $fields ) {
			foreach ( $fields as $field ) {
				$field_to_gateway[ $field ] = $gateway;
			}
		}
		$remote_map = array(
			'stripe_publishable'        => 'stripe_publishable',
			'stripe_secret'             => 'stripe_secret',
			'stripe_webhook_secret'     => 'stripe_webhook_secret',
			'paypal_client_id'          => 'paypal_client_id',
			'paypal_secret'             => 'paypal_secret',
			'paystack_public'           => 'paystack_public',
			'paystack_secret'           => 'paystack_secret',
			'flutterwave_public'        => 'flutterwave_public',
			'flutterwave_secret'        => 'flutterwave_secret',
			'pesapal_consumer_key'      => 'pesapal_consumer_key',
			'pesapal_consumer_secret'   => 'pesapal_consumer_secret',
			'marzpay_api_key'           => 'marzpay_api_key',
			'marzpay_api_secret'        => 'marzpay_api_secret',
		);
		foreach ( $remote_map as $remote => $local ) {
			$gateway = isset( $field_to_gateway[ $local ] ) ? $field_to_gateway[ $local ] : '';
			$active  = '' !== $gateway && in_array( $gateway, $enabled, true );
			$val     = isset( $credentials[ $remote ] ) ? trim( (string) $credentials[ $remote ] ) : '';
			if ( ! $active || '' === $val ) {
				$out[ $local ] = '';
				continue;
			}
			if ( false !== strpos( $local, 'secret' ) ) {
				$out[ $local ] = Fundolar_Crypto::encrypt( $val );
			} else {
				$out[ $local ] = sanitize_text_field( $val );
			}
		}

		$out['platform_last_sync_at'] = gmdate( 'c' );
		$out['platform_sync_error']   = '';
		update_option( self::OPTION, $out );
	}

	/**
	 * Default platform fee rate when Central rules are not synced yet.
	 *
	 * @return float
	 */
	private static function default_fee_rate() {
		return defined( 'FUNDOLAR_PLATFORM_FEE_RATE' ) ? (float) FUNDOLAR_PLATFORM_FEE_RATE : 0.035;
	}

	/**
	 * Store a sync error message from central API.
	 *
	 * @param string $message Error message.
	 * @return void
	 */
	public static function set_platform_sync_error( $message ) {
		$out                        = self::get_settings();
		$out['platform_sync_error'] = sanitize_text_field( (string) $message );
		update_option( self::OPTION, $out );
	}

	/**
	 * Is gateway enabled and configured (minimal check).
	 *
	 * @param string $gateway Gateway.
	 * @return bool
	 */
	public static function gateway_ready( $gateway ) {
		$s = self::get_settings();
		if ( ! in_array( $gateway, $s['enabled_gateways'], true ) ) {
			return false;
		}
		switch ( $gateway ) {
			case 'stripe':
				return '' !== trim( $s['stripe_publishable'] ) && '' !== self::get_credential_secret( 'stripe_secret' );
			case 'paypal':
				return '' !== trim( $s['paypal_client_id'] ) && '' !== self::get_credential_secret( 'paypal_secret' );
			case 'pesapal':
				return '' !== trim( $s['pesapal_consumer_key'] ) && '' !== self::get_credential_secret( 'pesapal_consumer_secret' );
			case 'flutterwave':
				return '' !== trim( $s['flutterwave_public'] ) && '' !== self::get_credential_secret( 'flutterwave_secret' );
			case 'paystack':
				return '' !== trim( $s['paystack_public'] ) && '' !== self::get_credential_secret( 'paystack_secret' );
			case 'mobile_money_ug':
				return '' !== trim( $s['marzpay_api_key'] ) && '' !== self::get_credential_secret( 'marzpay_api_secret' );
		}
		return false;
	}

	/**
	 * MarzPay API credentials from site settings.
	 *
	 * @return array{key:string,secret:string}
	 */
	public static function marzpay_credentials() {
		$s = self::get_settings();
		return array(
			'key'    => trim( (string) ( $s['marzpay_api_key'] ?? '' ) ),
			'secret' => self::get_credential_secret( 'marzpay_api_secret' ),
		);
	}

	/**
	 * Initiate Uganda mobile money collection via MarzPay.
	 *
	 * @param array<string,mixed> $payload name, email, amount, currency, phone_number, callback_url.
	 * @return array|WP_Error
	 */
	public static function marzpay_collect( array $payload ) {
		$creds = self::marzpay_credentials();
		if ( '' === $creds['key'] || '' === $creds['secret'] ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Mobile Money (UG) is not configured.', 'fundolar' ) );
		}

		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		if ( 'UGX' !== strtoupper( $split['currency'] ) ) {
			return new WP_Error(
				'fundolar_marzpay_currency',
				__( 'Mobile Money (UG) checkout is available only for UGX.', 'fundolar' )
			);
		}

		$gross_ugx = (int) round( (float) $split['gross'] );
		$reference = Fundolar_Marzpay::generate_reference();
		$desc      = sprintf(
			/* translators: %s: donor name */
			__( 'Donation from %s', 'fundolar' ),
			sanitize_text_field( (string) ( $payload['name'] ?? '' ) )
		);

		$res = Fundolar_Marzpay::collect_money(
			$creds['key'],
			$creds['secret'],
			array(
				'amount'        => $gross_ugx,
				'phone_number'  => isset( $payload['phone_number'] ) ? $payload['phone_number'] : '',
				'reference'     => $reference,
				'description'   => $desc,
				'callback_url'  => isset( $payload['callback_url'] ) ? $payload['callback_url'] : '',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( '' === $res['uuid'] ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Mobile Money provider did not return a transaction id.', 'fundolar' ) );
		}

		$res['split']     = $split;
		$res['gross_ugx'] = $gross_ugx;
		return $res;
	}

	/**
	 * Poll MarzPay and update local transaction row.
	 *
	 * @param int $transaction_id Local Fundolar transaction id.
	 * @return array|WP_Error Status payload.
	 */
	public static function marzpay_sync_transaction( $transaction_id ) {
		$row = Fundolar_DB::get( (int) $transaction_id );
		if ( ! $row || 'mobile_money_ug' !== $row->gateway ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Transaction not found.', 'fundolar' ) );
		}

		$creds = self::marzpay_credentials();
		if ( '' === $creds['key'] || '' === $creds['secret'] ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Mobile Money (UG) is not configured.', 'fundolar' ) );
		}

		$uuid = (string) $row->gateway_ref;
		$res  = Fundolar_Marzpay::get_collection( $creds['key'], $creds['secret'], $uuid );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		return self::marzpay_apply_status( (int) $row->id, $res['status'], isset( $res['raw'] ) ? $res['raw'] : array() );
	}

	/**
	 * Apply MarzPay status to a local transaction.
	 *
	 * @param int                  $transaction_id Local row id.
	 * @param string               $status         Provider status.
	 * @param array<string,mixed>  $provider_meta  Raw provider payload.
	 * @return array{status:string,completed:bool}
	 */
	public static function marzpay_apply_status( $transaction_id, $status, array $provider_meta = array() ) {
		$row = Fundolar_DB::get( (int) $transaction_id );
		if ( ! $row ) {
			return array( 'status' => 'unknown', 'completed' => false );
		}

		$meta = array();
		if ( ! empty( $row->meta ) ) {
			$decoded = json_decode( (string) $row->meta, true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}
		$meta['marzpay'] = $provider_meta;

		if ( Fundolar_Marzpay::is_success_status( $status ) ) {
			if ( 'completed' !== $row->status ) {
				Fundolar_DB::update(
					(int) $row->id,
					array(
						'status' => 'completed',
						'meta'   => $meta,
					)
				);
				Fundolar_Emails::notify_donation_completed( (int) $row->id );
				Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'marzpay_' . sanitize_key( $status ), $provider_meta );
			} else {
				Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
			}
			return array( 'status' => 'completed', 'completed' => true );
		}

		if ( Fundolar_Marzpay::is_failed_status( $status ) && 'completed' !== $row->status ) {
			Fundolar_DB::update(
				(int) $row->id,
				array(
					'status' => 'failed',
					'meta'   => $meta,
				)
			);
			Fundolar_Platform::report_donation_status( (int) $row->id, 'failed', 'marzpay_' . sanitize_key( $status ), $provider_meta );
			return array( 'status' => 'failed', 'completed' => false );
		}

		if ( 'completed' !== $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
		}

		return array(
			'status'    => Fundolar_Marzpay::is_pending_status( $status ) ? 'pending' : sanitize_key( (string) $status ),
			'completed' => false,
		);
	}

	/**
	 * Handle MarzPay webhook / callback by gateway reference (uuid or reference).
	 *
	 * @param array<string,mixed> $payload Decoded JSON.
	 * @return bool Whether a row was updated.
	 */
	public static function marzpay_handle_webhook( array $payload ) {
		$parsed = Fundolar_Marzpay::parse_callback_payload( $payload );
		if ( '' === $parsed['uuid'] && '' === $parsed['reference'] ) {
			return false;
		}

		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = null;
		if ( '' !== $parsed['uuid'] ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'mobile_money_ug', $parsed['uuid'] ) );
		}
		if ( ! $row && '' !== $parsed['reference'] ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id, meta FROM {$table} WHERE gateway = %s AND meta LIKE %s LIMIT 1", 'mobile_money_ug', '%' . $wpdb->esc_like( $parsed['reference'] ) . '%' ) );
		}
		if ( ! $row ) {
			return false;
		}

		self::marzpay_apply_status( (int) $row->id, $parsed['status'], $payload );
		return true;
	}

	/**
	 * Create Stripe PaymentIntent (Connect application fee when configured).
	 *
	 * @param array $payload Payload from REST.
	 * @return array|WP_Error
	 */
	public static function stripe_create_intent( array $payload ) {
		$secret = self::get_credential_secret( 'stripe_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'fundolar_stripe', __( 'Stripe is not configured.', 'fundolar' ) );
		}
		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		$gross_minor = Fundolar_Fees::to_minor_units( $split['gross'], $split['currency'] );
		$fee_minor   = Fundolar_Fees::to_minor_units( $split['fee'], $split['currency'] );
		$net_minor   = Fundolar_Fees::to_minor_units( $split['net'], $split['currency'] );
		if ( $gross_minor < 1 ) {
			return new WP_Error( 'fundolar_amount', __( 'Amount is too small for this currency.', 'fundolar' ) );
		}

		$author_connect = self::author_stripe_destination_account_id();

		$body = array(
			'amount'                    => $gross_minor,
			'currency'                  => strtolower( $split['currency'] ),
			// Stripe form-encoded booleans must be "true"/"false" strings (not 1/0).
			'automatic_payment_methods' => array( 'enabled' => 'true' ),
			'metadata'                  => array(
				'fundolar_receipt_amount' => (string) $split['gross'],
				'fundolar_platform_fee'   => (string) $split['fee'],
				'fundolar_net'            => (string) $split['net'],
				'fundolar_donor_email'    => sanitize_email( $payload['email'] ),
				'fundolar_donor_name'     => sanitize_text_field( $payload['name'] ),
			),
			'description'               => sprintf(
				/* translators: %s: donor name */
				__( 'Donation from %s', 'fundolar' ),
				sanitize_text_field( $payload['name'] )
			),
		);

		// Stripe Connect routing:
		// - Destination receives: amount - application_fee_amount
		// - We want the author to receive the "platform fee" portion, so:
		//   destination gets (gross - application_fee_amount) = fee
		//   => application_fee_amount = net
		$used_connect = false;
		if ( '' !== $author_connect && $fee_minor > 0 && $net_minor >= 0 && $net_minor <= $gross_minor ) {
			$body['application_fee_amount'] = (int) $net_minor;
			$body['transfer_data']          = array(
				'destination' => $author_connect,
			);
			$used_connect                    = true;
		}

		$response = wp_remote_post(
			'https://api.stripe.com/v1/payment_intents',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/x-www-form-urlencoded',
				),
				'body'    => http_build_query( $body, '', '&' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			if ( $used_connect ) {
				$msg = isset( $json['error']['message'] ) ? (string) $json['error']['message'] : __( 'Stripe Connect error.', 'fundolar' );
				return new WP_Error(
					'fundolar_stripe_connect',
					sprintf(
						/* translators: %s: gateway error message */
						__( 'Platform fee routing failed (Stripe Connect). %s', 'fundolar' ),
						$msg
					)
				);
			}
			$msg = isset( $json['error']['message'] ) ? $json['error']['message'] : __( 'Stripe error.', 'fundolar' );
			return new WP_Error( 'fundolar_stripe_api', $msg );
		}
		$cid = isset( $json['id'] ) ? (string) $json['id'] : '';
		$cs  = isset( $json['client_secret'] ) ? (string) $json['client_secret'] : '';
		if ( '' === $cid || '' === $cs || 0 !== strpos( $cid, 'pi_' ) ) {
			return new WP_Error( 'fundolar_stripe', __( 'Stripe did not return a valid payment session. Please try again.', 'fundolar' ) );
		}
		return array(
			'client_secret' => $cs,
			'id'            => $cid,
		);
	}

	/**
	 * Paystack initialize transaction.
	 *
	 * @param array $payload Payload.
	 * @return array|WP_Error
	 */
	public static function paystack_initialize( array $payload ) {
		$secret = self::get_credential_secret( 'paystack_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'fundolar_paystack', __( 'Paystack is not configured.', 'fundolar' ) );
		}
		$split    = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		$gross_minor = Fundolar_Fees::to_minor_units( $split['gross'], $split['currency'] );
		$fee_minor   = Fundolar_Fees::to_minor_units( $split['fee'], $split['currency'] );
		$net_minor   = Fundolar_Fees::to_minor_units( $split['net'], $split['currency'] );
		if ( $gross_minor < 1 ) {
			return new WP_Error( 'fundolar_amount', __( 'Amount is too small for this currency.', 'fundolar' ) );
		}
		$email    = sanitize_email( $payload['email'] );
		$reference = 'fundolar_' . wp_generate_password( 12, false, false );

		$author_subaccount = self::author_paystack_subaccount_code();

		$body = array(
			'email'     => $email,
			'amount'    => $gross_minor,
			'currency'  => strtoupper( $split['currency'] ),
			'reference' => $reference,
			'metadata'  => array(
				'donor_name'     => sanitize_text_field( $payload['name'] ),
				'receipt_amount' => (string) $split['gross'],
				'platform_fee'   => (string) $split['fee'],
				'net_to_site'    => (string) $split['net'],
			),
		);

		// Paystack split:
		// - With a subaccount set, we want the subaccount to receive the "platform fee" portion.
		// - transaction_charge is the amount that goes to the main account; subaccount gets the rest.
		// - So: main gets net, subaccount gets fee.
		$used_split = false;
		if ( '' !== $author_subaccount && $fee_minor > 0 && $net_minor >= 0 && $net_minor <= $gross_minor ) {
			$body['subaccount']         = $author_subaccount;
			$body['transaction_charge'] = (int) $net_minor;
			$used_split                 = true;
		}

		if ( ! empty( $payload['callback_url'] ) ) {
			$body['callback_url'] = esc_url_raw( $payload['callback_url'] );
		}

		// Optional: extend $body with split or subaccount fields when configured in your dashboard.

		$response = wp_remote_post(
			'https://api.paystack.co/transaction/initialize',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['status'] ) ) {
			if ( $used_split ) {
				return new WP_Error(
					'fundolar_paystack_split',
					isset( $json['message'] ) ? (string) $json['message'] : __( 'Paystack platform fee split failed.', 'fundolar' )
				);
			}
			return new WP_Error( 'fundolar_paystack', isset( $json['message'] ) ? $json['message'] : __( 'Paystack error.', 'fundolar' ) );
		}
		return array(
			'authorization_url' => $json['data']['authorization_url'] ?? '',
			'reference'         => $json['data']['reference'] ?? $reference,
			'access_code'       => $json['data']['access_code'] ?? '',
		);
	}

	/**
	 * Flutterwave initiate payment.
	 *
	 * @param array $payload Payload.
	 * @return array|WP_Error
	 */
	public static function flutterwave_init( array $payload ) {
		$secret = self::get_credential_secret( 'flutterwave_secret' );
		if ( '' === $secret ) {
			return new WP_Error( 'fundolar_fw', __( 'Flutterwave is not configured.', 'fundolar' ) );
		}
		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		$minor = Fundolar_Fees::to_minor_units( $split['gross'], $split['currency'] );
		if ( $minor < 1 ) {
			return new WP_Error( 'fundolar_amount', __( 'Amount is too small for this currency.', 'fundolar' ) );
		}
		$tx_ref = 'fundolar_' . wp_generate_password( 14, false, false );

		$author_subaccount_id = self::author_flutterwave_subaccount_id();

		$body = array(
			'tx_ref'       => $tx_ref,
			'amount'       => (string) $split['gross'],
			'currency'     => strtoupper( $split['currency'] ),
			'redirect_url' => esc_url_raw( $payload['redirect_url'] ),
			'customer'     => array(
				'email'       => sanitize_email( $payload['email'] ),
				'name'        => sanitize_text_field( $payload['name'] ),
				'phone_number' => '',
			),
			'customizations' => array(
				'title' => __( 'Donation', 'fundolar' ),
			),
			'meta' => array(
				'receipt_amount' => (string) $split['gross'],
				'platform_fee'   => (string) $split['fee'],
				'net_to_site'    => (string) $split['net'],
			),
		);

		// Flutterwave split via subaccounts (author receives the platform-fee portion).
		// We use flat_subaccount so the subaccount gets a fixed "fee" amount, while the main account gets the remainder.
		$used_split = false;
		if ( '' !== $author_subaccount_id && (float) $split['fee'] > 0 ) {
			$body['subaccounts'] = array(
				array(
					'id'                     => $author_subaccount_id,
					'transaction_charge_type' => 'flat_subaccount',
					'transaction_charge'       => (string) round( (float) $split['fee'], 2 ),
				),
			);
			$used_split           = true;
		}

		$response = wp_remote_post(
			'https://api.flutterwave.com/v3/payments',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['status'] ) || 'success' !== $json['status'] ) {
			if ( $used_split ) {
				return new WP_Error(
					'fundolar_fw_split',
					isset( $json['message'] ) ? (string) $json['message'] : __( 'Flutterwave platform fee split failed.', 'fundolar' )
				);
			}
			return new WP_Error(
				'fundolar_fw',
				isset( $json['message'] ) ? $json['message'] : __( 'Flutterwave error.', 'fundolar' )
			);
		}
		return array(
			'link'   => $json['data']['link'] ?? '',
			'tx_ref' => $tx_ref,
		);
	}

	/**
	 * PayPal: return order creation URL pattern (client-side JS uses client id).
	 * Server creates order via REST for security.
	 *
	 * @param array $payload Payload.
	 * @return array|WP_Error
	 */
	public static function paypal_create_order( array $payload ) {
		$s        = self::get_settings();
		$client   = trim( $s['paypal_client_id'] );
		$secret   = self::get_credential_secret( 'paypal_secret' );
		if ( '' === $client || '' === $secret ) {
			return new WP_Error( 'fundolar_paypal', __( 'PayPal is not configured.', 'fundolar' ) );
		}
		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		$minor = Fundolar_Fees::to_minor_units( $split['gross'], $split['currency'] );
		if ( $minor < 1 ) {
			return new WP_Error( 'fundolar_amount', __( 'Amount is too small for this currency.', 'fundolar' ) );
		}
		$fee_payee = self::author_paypal_fee_payee();
		/**
		 * Whether to create a multiparty PayPal order (fee payee + net to merchant). Standard Smart Checkout
		 * often fails in the payer popup unless PayPal enabled parallel/multiparty for your integration.
		 *
		 * @param bool  $enabled   Default from FUNDOLAR_PAYPAL_ENABLE_FEE_SPLIT.
		 * @param array $fee_payee Payee array or empty.
		 * @param array $split     gross/fee/net/currency.
		 */
		$fee_split_enabled = (bool) apply_filters(
			'fundolar_paypal_enable_fee_split',
			! empty( $fee_payee ) || ( defined( 'FUNDOLAR_PAYPAL_ENABLE_FEE_SPLIT' ) && FUNDOLAR_PAYPAL_ENABLE_FEE_SPLIT ),
			$fee_payee,
			$split
		);
		$token = self::paypal_access_token( $client, $secret );
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$base = self::paypal_api_base();

		$custom = array(
			'receipt' => $split['gross'],
			'fee'     => $split['fee'],
			'net'     => $split['net'],
			'name'    => sanitize_text_field( $payload['name'] ),
			'email'   => sanitize_email( $payload['email'] ),
		);

		$use_split = $fee_split_enabled && ! empty( $fee_payee ) && (float) $split['fee'] > 0 && (float) $split['net'] >= 0;
		if ( $use_split ) {
			$fee_unit = array(
				'amount'      => array(
					'currency_code' => strtoupper( $split['currency'] ),
					'value'         => number_format( (float) $split['fee'], 2, '.', '' ),
				),
				'description' => __( 'Donation fee', 'fundolar' ),
				'custom_id'   => wp_json_encode( $custom ),
				'payee'       => $fee_payee,
			);

			$net_unit = array(
				'amount'      => array(
					'currency_code' => strtoupper( $split['currency'] ),
					'value'         => number_format( (float) $split['net'], 2, '.', '' ),
				),
				'description' => __( 'Donation to cause', 'fundolar' ),
				'custom_id'   => wp_json_encode( $custom ),
			);

			$body = array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array( $fee_unit, $net_unit ),
			);
		} else {
			// No valid fee payee configured: keep the original single purchase unit flow.
			$purchase_unit = array(
				'amount'      => array(
					'currency_code' => strtoupper( $split['currency'] ),
					'value'         => number_format( $split['gross'], 2, '.', '' ),
				),
				'description' => __( 'Donation', 'fundolar' ),
				'custom_id'   => wp_json_encode( $custom ),
			);

			$body = array(
				'intent'         => 'CAPTURE',
				'purchase_units' => array( $purchase_unit ),
			);
		}
		$pp_headers = array(
			'Authorization' => 'Bearer ' . $token,
			'Content-Type'  => 'application/json',
		);
		if ( defined( 'FUNDOLAR_AUTHOR_PAYPAL_BN_CODE' ) && '' !== (string) FUNDOLAR_AUTHOR_PAYPAL_BN_CODE ) {
			$pp_headers['PayPal-Partner-Attribution-Id'] = sanitize_text_field( (string) FUNDOLAR_AUTHOR_PAYPAL_BN_CODE );
		}
		$response = wp_remote_post(
			$base . '/v2/checkout/orders',
			array(
				'timeout' => 20,
				'headers' => $pp_headers,
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 ) {
			$detail = '';
			if ( is_array( $json ) ) {
				if ( ! empty( $json['message'] ) ) {
					$detail = ' ' . sanitize_text_field( (string) $json['message'] );
				}
				if ( ! empty( $json['details'] ) && is_array( $json['details'] ) ) {
					foreach ( $json['details'] as $d ) {
						if ( is_array( $d ) && ! empty( $d['description'] ) ) {
							$detail .= ' ' . sanitize_text_field( (string) $d['description'] );
							break;
						}
					}
				}
			}
			if ( $use_split ) {
				return new WP_Error(
					'fundolar_paypal_split',
					trim( __( 'PayPal split order was rejected.', 'fundolar' ) . $detail )
				);
			}
			return new WP_Error( 'fundolar_paypal', trim( __( 'PayPal order error.', 'fundolar' ) . $detail ) );
		}
		return array(
			'id' => $json['id'] ?? '',
		);
	}

	/**
	 * PayPal OAuth token.
	 *
	 * @param string $client_id Client ID.
	 * @param string $client_secret Secret.
	 * @return string|WP_Error
	 */
	private static function paypal_access_token( $client_id, $client_secret ) {
		$base     = self::paypal_api_base();
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
	 * PayPal API host (sandbox vs live) — use live when keys look live (heuristic: client id ends with typical pattern). Default live.
	 *
	 * @return string
	 */
	private static function paypal_api_base() {
		/**
		 * Filter PayPal API base URL.
		 *
		 * @param string $url URL.
		 */
		return apply_filters( 'fundolar_paypal_api_base', 'https://api-m.paypal.com' );
	}

	/**
	 * Pesapal initialize checkout via API v3.
	 *
	 * @param array $payload Payload.
	 * @return array|WP_Error
	 */
	public static function pesapal_init( array $payload ) {
		$s   = self::get_settings();
		$key = trim( $s['pesapal_consumer_key'] );
		$sec = self::get_credential_secret( 'pesapal_consumer_secret' );
		if ( '' === $key || '' === $sec ) {
			return new WP_Error( 'fundolar_pesapal', __( 'Pesapal is not configured.', 'fundolar' ) );
		}
		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		if ( ! in_array( strtoupper( $split['currency'] ), self::pesapal_supported_currencies(), true ) ) {
			return new WP_Error( 'fundolar_pesapal_currency', __( 'Pesapal checkout is available only for mobile-money enabled currencies.', 'fundolar' ) );
		}

		$api_base = self::pesapal_api_base();
		$token    = self::pesapal_access_token( $key, $sec, $api_base );
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$return_url = ! empty( $payload['redirect_url'] ) ? esc_url_raw( (string) $payload['redirect_url'] ) : home_url( '/' );
		$return_url = add_query_arg( 'fundolar_gateway', 'pesapal', $return_url );
		$ipn_url    = add_query_arg( 'fundolar_gateway', 'pesapal', home_url( '/' ) );
		$ipn_id     = self::pesapal_register_ipn( $token, $api_base, $ipn_url );
		if ( is_wp_error( $ipn_id ) ) {
			return $ipn_id;
		}

		$order_reference = 'fundolar_' . wp_generate_password( 14, false, false );
		$customer_name   = trim( sanitize_text_field( (string) $payload['name'] ) );
		$parts           = preg_split( '/\s+/', $customer_name );
		$first_name      = isset( $parts[0] ) ? $parts[0] : 'Donor';
		$last_name       = ( count( $parts ) > 1 ) ? implode( ' ', array_slice( $parts, 1 ) ) : 'Fundolar';

		$request = array(
			'id'              => $order_reference,
			'currency'        => strtoupper( $split['currency'] ),
			'amount'          => (float) $split['gross'],
			'description'     => sprintf(
				/* translators: %s: donor name */
				__( 'Donation from %s', 'fundolar' ),
				$customer_name !== '' ? $customer_name : __( 'Donor', 'fundolar' )
			),
			'callback_url'    => $return_url,
			'notification_id' => $ipn_id,
			'billing_address' => array(
				'email_address' => sanitize_email( (string) $payload['email'] ),
				'phone_number'  => '',
				'country_code'  => '',
				'first_name'    => sanitize_text_field( $first_name ),
				'middle_name'   => '',
				'last_name'     => sanitize_text_field( $last_name ),
				'line_1'        => '',
				'line_2'        => '',
				'city'          => '',
				'state'         => '',
				'postal_code'   => '',
				'zip_code'      => '',
			),
		);

		$response = wp_remote_post(
			$api_base . '/Transactions/SubmitOrderRequest',
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $request ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $json ) ) {
			return new WP_Error( 'fundolar_pesapal', __( 'Pesapal request failed.', 'fundolar' ) );
		}

		$redirect = isset( $json['redirect_url'] ) ? esc_url_raw( (string) $json['redirect_url'] ) : '';
		if ( '' === $redirect ) {
			$msg = isset( $json['error'] ) ? (string) $json['error'] : __( 'Pesapal did not return a checkout URL.', 'fundolar' );
			return new WP_Error( 'fundolar_pesapal', $msg );
		}

		return array(
			'authorization_url' => $redirect,
			'reference'         => $order_reference,
			'order_tracking_id' => isset( $json['order_tracking_id'] ) ? sanitize_text_field( (string) $json['order_tracking_id'] ) : '',
		);
	}

	/**
	 * Verify Pesapal transaction status and update local row.
	 *
	 * @param string $order_tracking_id Tracking id from Pesapal callback/IPN.
	 * @param string $merchant_reference Merchant reference/order id.
	 * @return bool
	 */
	public static function pesapal_verify_and_update( $order_tracking_id, $merchant_reference = '' ) {
		$order_tracking_id  = sanitize_text_field( (string) $order_tracking_id );
		$merchant_reference = sanitize_text_field( (string) $merchant_reference );
		if ( '' === $order_tracking_id ) {
			return false;
		}

		$s   = self::get_settings();
		$key = trim( $s['pesapal_consumer_key'] );
		$sec = self::get_credential_secret( 'pesapal_consumer_secret' );
		if ( '' === $key || '' === $sec ) {
			return false;
		}

		$api_base = self::pesapal_api_base();
		$token    = self::pesapal_access_token( $key, $sec, $api_base );
		if ( is_wp_error( $token ) ) {
			return false;
		}

		$url = add_query_arg(
			array(
				'orderTrackingId' => $order_tracking_id,
			),
			$api_base . '/Transactions/GetTransactionStatus'
		);
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 25,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			return false;
		}

		$status_code = isset( $json['status_code'] ) ? (int) $json['status_code'] : 0;
		$pay_status  = isset( $json['payment_status_description'] ) ? strtolower( (string) $json['payment_status_description'] ) : '';
		$ok          = ( 1 === $status_code ) || false !== strpos( $pay_status, 'completed' );

		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = null;
		if ( '' !== $merchant_reference ) {
			$row = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'pesapal', $merchant_reference ) );
		}
		if ( ! $row ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, meta FROM {$table} WHERE gateway = %s ORDER BY id DESC LIMIT 50", 'pesapal' ) );
			if ( $rows ) {
				foreach ( $rows as $candidate ) {
					if ( empty( $candidate->meta ) ) {
						continue;
					}
					$meta = json_decode( (string) $candidate->meta, true );
					if ( is_array( $meta ) && ! empty( $meta['order_tracking_id'] ) && $order_tracking_id === (string) $meta['order_tracking_id'] ) {
						$row = $candidate;
						break;
					}
				}
			}
		}
		if ( ! $row ) {
			return false;
		}

		$status = $ok ? 'completed' : 'failed';
		$meta   = array(
			'pesapal'           => $json,
			'order_tracking_id' => $order_tracking_id,
		);
		Fundolar_DB::update(
			(int) $row->id,
			array(
				'status' => $status,
				'meta'   => $meta,
			)
		);
		if ( $ok ) {
			Fundolar_Emails::notify_donation_completed( (int) $row->id );
			Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'pesapal_completed', $json );
			return true;
		}
		Fundolar_Platform::report_donation_status( (int) $row->id, 'failed', 'pesapal_failed', $json );
		return false;
	}

	/**
	 * Pesapal API base URL.
	 *
	 * @return string
	 */
	private static function pesapal_api_base() {
		/**
		 * Filter Pesapal API base URL.
		 *
		 * @param string $url API base URL.
		 */
		$base = apply_filters( 'fundolar_pesapal_api_base', 'https://pay.pesapal.com/v3/api' );
		return rtrim( (string) $base, '/' );
	}

	/**
	 * Pesapal access token.
	 *
	 * @param string $consumer_key Consumer key.
	 * @param string $consumer_secret Consumer secret.
	 * @param string $api_base API base URL.
	 * @return string|WP_Error
	 */
	private static function pesapal_access_token( $consumer_key, $consumer_secret, $api_base ) {
		$response = wp_remote_post(
			$api_base . '/Auth/RequestToken',
			array(
				'timeout' => 20,
				'headers' => array(
					'Accept'       => 'application/json',
					'Content-Type' => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'consumer_key'    => $consumer_key,
						'consumer_secret' => $consumer_secret,
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $json ) || empty( $json['token'] ) ) {
			$msg = is_array( $json ) && ! empty( $json['error'] ) ? (string) $json['error'] : __( 'Pesapal authentication failed.', 'fundolar' );
			return new WP_Error( 'fundolar_pesapal_auth', $msg );
		}
		return (string) $json['token'];
	}

	/**
	 * Register a Pesapal IPN callback URL and return notification id.
	 *
	 * @param string $token Access token.
	 * @param string $api_base API base URL.
	 * @param string $ipn_url Callback URL.
	 * @return string|WP_Error
	 */
	private static function pesapal_register_ipn( $token, $api_base, $ipn_url ) {
		$response = wp_remote_post(
			$api_base . '/URLSetup/RegisterIPN',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $token,
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode(
					array(
						'url'                   => esc_url_raw( $ipn_url ),
						'ipn_notification_type' => 'GET',
					)
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $json ) ) {
			return new WP_Error( 'fundolar_pesapal_ipn', __( 'Could not register Pesapal callback URL.', 'fundolar' ) );
		}
		if ( ! empty( $json['ipn_id'] ) ) {
			return sanitize_text_field( (string) $json['ipn_id'] );
		}
		if ( ! empty( $json['ipnId'] ) ) {
			return sanitize_text_field( (string) $json['ipnId'] );
		}
		return new WP_Error( 'fundolar_pesapal_ipn', __( 'Pesapal did not return an IPN id.', 'fundolar' ) );
	}

	/**
	 * Verify Paystack transaction and update local row.
	 *
	 * @param string $reference Reference.
	 * @return bool
	 */
	public static function paystack_verify_and_update( $reference ) {
		$reference = sanitize_text_field( $reference );
		if ( '' === $reference ) {
			return false;
		}
		$secret = self::get_credential_secret( 'paystack_secret' );
		if ( '' === $secret ) {
			return false;
		}
		$url      = 'https://api.paystack.co/transaction/verify/' . rawurlencode( $reference );
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
			return false;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['status'] ) || empty( $json['data'] ) ) {
			return false;
		}
		$data   = $json['data'];
		$status = isset( $data['status'] ) ? $data['status'] : '';
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'paystack', $reference ) );
		if ( ! $row ) {
			return false;
		}
		if ( 'success' === $status ) {
			Fundolar_DB::update( (int) $row->id, array( 'status' => 'completed', 'meta' => array( 'paystack' => $data ) ) );
			Fundolar_Emails::notify_donation_completed( (int) $row->id );
			Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'paystack_success', is_array( $data ) ? $data : array() );
			return true;
		}
		Fundolar_DB::update( (int) $row->id, array( 'status' => 'failed', 'meta' => array( 'paystack' => $data ) ) );
		Fundolar_Platform::report_donation_status( (int) $row->id, 'failed', 'paystack_failed', is_array( $data ) ? $data : array() );
		return false;
	}

	/**
	 * Verify Flutterwave transaction by id and update local row.
	 *
	 * @param string $tx_ref         Local tx ref.
	 * @param string $transaction_id Gateway transaction id.
	 * @return bool
	 */
	public static function flutterwave_verify_and_update( $tx_ref, $transaction_id ) {
		$tx_ref         = sanitize_text_field( $tx_ref );
		$transaction_id = sanitize_text_field( $transaction_id );
		if ( '' === $tx_ref || '' === $transaction_id ) {
			return false;
		}
		$secret = self::get_credential_secret( 'flutterwave_secret' );
		if ( '' === $secret ) {
			return false;
		}
		$url      = 'https://api.flutterwave.com/v3/transactions/' . rawurlencode( $transaction_id ) . '/verify';
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
			return false;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $json['status'] ) || 'success' !== $json['status'] || empty( $json['data'] ) ) {
			return false;
		}
		$data = $json['data'];
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'flutterwave', $tx_ref ) );
		if ( ! $row ) {
			return false;
		}
		$ok = isset( $data['status'] ) && 'successful' === $data['status'];
		Fundolar_DB::update(
			(int) $row->id,
			array(
				'status' => $ok ? 'completed' : 'failed',
				'meta'   => array( 'flutterwave' => $data ),
			)
		);
		if ( $ok ) {
			Fundolar_Emails::notify_donation_completed( (int) $row->id );
			Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'flutterwave_successful', is_array( $data ) ? $data : array() );
			return $ok;
		}
		Fundolar_Platform::report_donation_status( (int) $row->id, 'failed', 'flutterwave_failed', is_array( $data ) ? $data : array() );
		return $ok;
	}

	/**
	 * Stripe Connect destination account id (acct_…) that receives the platform fee.
	 * Optional: set FUNDOLAR_AUTHOR_STRIPE_CONNECT_ACCOUNT in wp-config. Otherwise uses
	 * author Stripe secret (optional constant) to resolve the account when needed.
	 *
	 * @return string Connected account id (acct_...).
	 */
	private static function author_stripe_destination_account_id() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$platform = Fundolar_Gateway_Connect::platform_stripe_account_id();
		if ( '' !== $platform ) {
			$cached = $platform;
			return $cached;
		}

		$from_const = defined( 'FUNDOLAR_AUTHOR_STRIPE_CONNECT_ACCOUNT' ) ? (string) FUNDOLAR_AUTHOR_STRIPE_CONNECT_ACCOUNT : '';
		$from_const = sanitize_text_field( $from_const );
		if ( '' !== $from_const ) {
			$cached = $from_const;
			return $cached;
		}

		$secret = Fundolar_Author_Credentials::get( 'stripe_secret' );
		if ( '' === $secret ) {
			$cached = '';
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.stripe.com/v1/account',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$cached = '';
			return $cached;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		$dest = isset( $json['id'] ) ? sanitize_text_field( (string) $json['id'] ) : '';
		$cached = $dest;
		return $cached;
	}

	/**
	 * Derive Paystack subaccount code for author.
	 *
	 * @return string Paystack subaccount code (e.g. ACCT_...).
	 */
	private static function author_paystack_subaccount_code() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$from_const = defined( 'FUNDOLAR_AUTHOR_PAYSTACK_SUBACCOUNT' ) ? (string) FUNDOLAR_AUTHOR_PAYSTACK_SUBACCOUNT : '';
		$from_const = sanitize_text_field( $from_const );
		if ( '' !== $from_const ) {
			$cached = $from_const;
			return $cached;
		}

		$secret = Fundolar_Author_Credentials::get( 'paystack_secret' );
		if ( '' === $secret ) {
			$cached = '';
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.paystack.co/subaccount?perPage=50&page=1',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$cached = '';
			return $cached;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) || empty( $json['data'] ) || ! is_array( $json['data'] ) ) {
			$cached = '';
			return $cached;
		}

		$code = '';
		foreach ( $json['data'] as $sub ) {
			if ( ! is_array( $sub ) ) {
				continue;
			}
			$active = isset( $sub['active'] ) ? (bool) $sub['active'] : false;
			if ( ! $active ) {
				continue;
			}
			if ( ! empty( $sub['subaccount_code'] ) ) {
				$code = sanitize_text_field( (string) $sub['subaccount_code'] );
				break;
			}
		}
		if ( '' === $code ) {
			// Fallback: take the first subaccount_code returned (if any).
			foreach ( $json['data'] as $sub ) {
				if ( is_array( $sub ) && ! empty( $sub['subaccount_code'] ) ) {
					$code = sanitize_text_field( (string) $sub['subaccount_code'] );
					break;
				}
			}
		}
		$cached = $code;
		return $cached;
	}

	/**
	 * Derive Flutterwave subaccount_id for author.
	 *
	 * @return string Flutterwave subaccount_id (RS_... or similar).
	 */
	private static function author_flutterwave_subaccount_id() {
		static $cached = null;
		if ( null !== $cached ) {
			return $cached;
		}

		$from_const = defined( 'FUNDOLAR_AUTHOR_FLUTTERWAVE_SUBACCOUNT_ID' ) ? (string) FUNDOLAR_AUTHOR_FLUTTERWAVE_SUBACCOUNT_ID : '';
		$from_const = sanitize_text_field( $from_const );
		if ( '' !== $from_const ) {
			$cached = $from_const;
			return $cached;
		}

		$secret = Fundolar_Author_Credentials::get( 'flutterwave_secret' );
		if ( '' === $secret ) {
			$cached = '';
			return $cached;
		}

		$response = wp_remote_get(
			'https://api.flutterwave.com/v3/subaccounts',
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Bearer ' . $secret,
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$cached = '';
			return $cached;
		}
		$json = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			$cached = '';
			return $cached;
		}
		$data = isset( $json['data'] ) ? $json['data'] : array();
		if ( ! is_array( $data ) || empty( $data ) ) {
			$cached = '';
			return $cached;
		}

		// Prefer subaccounts that actually include subaccount_id.
		$first = array_shift( $data );
		if ( is_array( $first ) ) {
			$sub_id = '';
			if ( ! empty( $first['subaccount_id'] ) ) {
				$sub_id = sanitize_text_field( (string) $first['subaccount_id'] );
			} elseif ( ! empty( $first['id'] ) ) {
				$sub_id = sanitize_text_field( (string) $first['id'] );
			}
			$cached = $sub_id;
			return $cached;
		}
		$cached = '';
		return $cached;
	}

	/**
	 * Get PayPal payee for the author fee portion.
	 *
	 * @return array<string,string> Either ['email_address'=>...] or ['merchant_id'=>...] or empty array.
	 */
	private static function author_paypal_fee_payee() {
		$platform = Fundolar_Gateway_Connect::platform_paypal_payee();
		if ( ! empty( $platform ) ) {
			return $platform;
		}

		$from_const = defined( 'FUNDOLAR_AUTHOR_PAYPAL_IDENTIFIER' ) ? (string) FUNDOLAR_AUTHOR_PAYPAL_IDENTIFIER : '';
		$from_const = sanitize_text_field( $from_const );
		if ( '' !== $from_const ) {
			if ( false !== strpos( $from_const, '@' ) ) {
				$em = sanitize_email( $from_const );
				return ( is_email( $em ) ) ? array( 'email_address' => $em ) : array();
			}
			return array( 'merchant_id' => sanitize_text_field( $from_const ) );
		}

		return array();
	}
}
