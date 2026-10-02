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
	 * Self-hosted distribution uses Fundolar Central only (no local API key fields).
	 *
	 * @return bool
	 */
	public static function is_central_only_distribution() {
		return defined( 'FUNDOLAR_CENTRAL_ONLY' ) && FUNDOLAR_CENTRAL_ONLY;
	}

	/**
	 * Create a return nonce for gateway redirect callbacks.
	 *
	 * @param string $gateway   Gateway slug.
	 * @param string $reference Checkout / order reference.
	 * @return string
	 */
	public static function create_gateway_return_nonce( $gateway, $reference ) {
		return wp_create_nonce( 'fundolar_gw_return_' . sanitize_key( (string) $gateway ) . '_' . sanitize_text_field( (string) $reference ) );
	}

	/**
	 * Verify a gateway return nonce.
	 *
	 * @param string $gateway   Gateway slug.
	 * @param string $reference Checkout / order reference.
	 * @param string $nonce     Nonce from the return URL.
	 * @return bool
	 */
	public static function verify_gateway_return_nonce( $gateway, $reference, $nonce ) {
		$nonce = sanitize_text_field( (string) $nonce );
		if ( '' === $nonce || '' === (string) $reference ) {
			return false;
		}
		return (bool) wp_verify_nonce( $nonce, 'fundolar_gw_return_' . sanitize_key( (string) $gateway ) . '_' . sanitize_text_field( (string) $reference ) );
	}

	/**
	 * Append fundolar_gateway + fundolar_rtn to a return URL.
	 *
	 * @param string $url       Base return URL.
	 * @param string $gateway   Gateway slug.
	 * @param string $reference Checkout reference.
	 * @return string
	 */
	public static function with_gateway_return_args( $url, $gateway, $reference ) {
		return add_query_arg(
			array(
				'fundolar_gateway' => sanitize_key( (string) $gateway ),
				'fundolar_rtn'     => self::create_gateway_return_nonce( $gateway, $reference ),
			),
			esc_url_raw( (string) $url )
		);
	}

	/**
	 * Built-in gateway slugs shipped with the plugin.
	 *
	 * @return string[]
	 */
	public static function builtin_gateway_slugs() {
		return array( 'stripe', 'payoneer', 'paypal', 'mobile_money_ug', 'mpesa', 'pesapal', 'flutterwave', 'paystack' );
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
		return array( 'stripe', 'payoneer', 'paypal', 'mobile_money_ug', 'mpesa', 'pesapal', 'flutterwave', 'paystack' );
	}

	/**
	 * Human-readable gateway labels.
	 *
	 * @return array<string,string>
	 */
	public static function gateway_labels() {
		return array(
			'stripe'          => __( 'Stripe', 'fundolar' ),
			'payoneer'        => __( 'Payoneer Cards', 'fundolar' ),
			'paypal'          => __( 'PayPal', 'fundolar' ),
			'mobile_money_ug' => __( 'Mobile Money (UG)', 'fundolar' ),
			'mpesa'           => __( 'Mpesa', 'fundolar' ),
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
		if ( 'mpesa' === $gateway ) {
			return array( 'KES' );
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
	public static function platform_sync_is_stale( $stale_after = null ) {
		if ( ! self::is_central_connected() ) {
			return false;
		}
		$s = self::get_settings();
		$at = isset( $s['platform_last_sync_at'] ) ? strtotime( (string) $s['platform_last_sync_at'] ) : false;
		if ( false === $at || $at < 1 ) {
			return true;
		}
		if ( null === $stale_after ) {
			$stale_after = (int) apply_filters( 'fundolar_platform_sync_stale_seconds', Fundolar_Platform::SYNC_STALE_SECONDS );
		} else {
			$stale_after = (int) $stale_after;
		}
		return ( time() - $at ) >= max( 60, $stale_after );
	}

	/**
	 * Active payment mode slug.
	 *
	 * @return string
	 */
	public static function payment_mode() {
		if ( self::is_central_only_distribution() ) {
			return self::MODE_CENTRAL;
		}
		$s    = self::get_settings();
		$mode = isset( $s['payment_mode'] ) ? sanitize_key( (string) $s['payment_mode'] ) : self::MODE_CENTRAL;
		if ( ! in_array( $mode, array( self::MODE_OWN_KEYS, self::MODE_CENTRAL ), true ) ) {
			$mode = self::MODE_CENTRAL;
		}
		return $mode;
	}

	/**
	 * @return bool
	 */
	public static function is_own_keys_mode() {
		if ( self::is_central_only_distribution() ) {
			return false;
		}
		return self::MODE_OWN_KEYS === self::payment_mode();
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
		return self::has_complete_platform_credentials();
	}

	/**
	 * Whether the site has both Central API key and signing secret required for sync.
	 *
	 * @return bool
	 */
	public static function has_complete_platform_credentials() {
		$api = self::get_platform_api_key();
		if ( '' === $api ) {
			return false;
		}
		$s      = self::get_settings();
		$secret = self::decrypt_secret( isset( $s['platform_signing_secret'] ) ? $s['platform_signing_secret'] : '' );

		return '' !== $secret;
	}

	/**
	 * Decrypted Fundolar Central API key (supports legacy plaintext until next save).
	 *
	 * @return string
	 */
	public static function get_platform_api_key() {
		$s      = self::get_settings();
		$stored = trim( (string) ( $s['platform_api_key'] ?? '' ) );
		if ( '' === $stored ) {
			return '';
		}
		if ( Fundolar_Crypto::looks_encrypted( $stored ) ) {
			return self::decrypt_secret( $stored );
		}
		// Legacy plaintext — still usable; re-encrypted on next Central sync/save.
		return $stored;
	}

	/**
	 * Shared token embedded in MarzPay callback URLs.
	 *
	 * @return string
	 */
	public static function marzpay_webhook_token() {
		$s      = self::get_settings();
		$site   = isset( $s['platform_site_id'] ) ? (string) (int) $s['platform_site_id'] : '0';
		$secret = self::decrypt_secret( isset( $s['platform_signing_secret'] ) ? $s['platform_signing_secret'] : '' );
		$seed   = '' !== $secret ? $secret : ( wp_salt( 'auth' ) . '|' . $site );
		return hash_hmac( 'sha256', 'fundolar_marzpay_webhook_v1', $seed );
	}

	/**
	 * MarzPay REST webhook URL including authentication token.
	 *
	 * @return string
	 */
	public static function marzpay_webhook_url() {
		return add_query_arg(
			'fundolar_wh',
			self::marzpay_webhook_token(),
			rest_url( 'fundolar/v1/webhooks/marzpay' )
		);
	}

	/**
	 * Gateways for the current payment mode.
	 *
	 * @return string[]
	 */
	public static function gateways_for_mode() {
		if ( self::is_own_keys_mode() ) {
			return self::own_keys_gateways();
		}
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
			'payment_mode'              => self::is_central_only_distribution() ? self::MODE_CENTRAL : self::MODE_OWN_KEYS,
			'enabled_gateways'          => array( 'stripe' ),
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
			'payoneer_checkout_store_code' => '',
			'payoneer_checkout_token'   => '',
			'payoneer_checkout_environment' => 'live',
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
			'mpesa_api_key'             => '',
			'mpesa_api_secret'          => '',
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
		if ( self::is_central_only_distribution() ) {
			$merged['payment_mode'] = self::MODE_CENTRAL;
		} elseif ( empty( $stored['payment_mode'] ) && ! empty( $merged['platform_api_key'] ) ) {
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
		$s = self::get_settings();
		if ( self::is_central_mode() ) {
			if ( ! self::is_central_connected() ) {
				return array();
			}
			if ( isset( $s['platform_payments_enabled'] ) && '1' !== (string) $s['platform_payments_enabled'] ) {
				return array();
			}
			$list = array_values( array_intersect( self::gateways(), (array) $s['enabled_gateways'] ) );
		} else {
			$list = array_values( array_intersect( self::gateways_for_mode(), (array) $s['enabled_gateways'] ) );
		}
		return array_values( array_filter( $list, array( __CLASS__, 'gateway_ready' ) ) );
	}

	/**
	 * Central-synced gateways that are enabled but missing local credentials.
	 *
	 * @return string[]
	 */
	public static function synced_gateways_missing_credentials() {
		if ( ! self::is_central_mode() || ! self::is_central_connected() ) {
			return array();
		}
		$s       = self::get_settings();
		$enabled = array_values(
			array_unique(
				array_map(
					'sanitize_key',
					(array) ( $s['enabled_gateways'] ?? array() )
				)
			)
		);
		$ready   = self::gateways_ready_for_front();

		return array_values( array_diff( $enabled, $ready ) );
	}

	/**
	 * Labels and logo URLs for gateways exposed to the donation form JS.
	 *
	 * @param string[]|null $gateways Optional gateway slugs; defaults to gateways ready for front.
	 * @return array<string,array{label:string,logo:string}>
	 */
	public static function gateway_assets_for_js( $gateways = null ) {
		if ( null === $gateways ) {
			$gateways = self::gateways_ready_for_front();
		}
		$out = array();
		foreach ( (array) $gateways as $gateway ) {
			$gateway = sanitize_key( (string) $gateway );
			if ( '' === $gateway ) {
				continue;
			}
			$out[ $gateway ] = array(
				'label' => self::gateway_label( $gateway ),
				'logo'  => self::gateway_logo_url( $gateway ),
			);
		}
		return $out;
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
		if ( self::is_central_only_distribution() ) {
			$out['payment_mode'] = self::MODE_CENTRAL;
		} elseif ( isset( $input['payment_mode'] ) ) {
			$mode = sanitize_key( wp_unslash( $input['payment_mode'] ) );
			if ( in_array( $mode, array( self::MODE_OWN_KEYS, self::MODE_CENTRAL ), true ) ) {
				$out['payment_mode'] = $mode;
			}
		}
		if ( ! self::is_central_only_distribution() && self::MODE_OWN_KEYS === $out['payment_mode'] ) {
			$cred_map = array(
				'stripe_publishable'      => 'text',
				'stripe_secret'           => 'secret',
				'stripe_webhook_secret'   => 'secret',
				'payoneer_checkout_store_code' => 'text',
				'payoneer_checkout_token' => 'secret',
				'payoneer_checkout_environment' => 'text',
				'paypal_client_id'        => 'text',
				'paypal_secret'           => 'secret',
				'pesapal_consumer_key'    => 'text',
				'pesapal_consumer_secret' => 'secret',
				'flutterwave_public'      => 'text',
				'flutterwave_secret'      => 'secret',
				'paystack_public'         => 'text',
				'paystack_secret'         => 'secret',
				'marzpay_api_key'         => 'text',
				'marzpay_api_secret'      => 'secret',
				'mpesa_api_key'           => 'text',
				'mpesa_api_secret'        => 'secret',
			);
			foreach ( $cred_map as $key => $type ) {
				if ( ! array_key_exists( $key, $input ) ) {
					continue;
				}
				$val = $input[ $key ];
				if ( 'secret' === $type ) {
					$val = is_string( $val ) ? trim( $val ) : '';
					if ( '' !== $val ) {
						$out[ $key ] = Fundolar_Crypto::encrypt( $val );
					}
				} else {
					$out[ $key ] = sanitize_text_field( wp_unslash( $val ) );
				}
			}
			if ( isset( $input['enabled_gateways'] ) && is_array( $input['enabled_gateways'] ) ) {
				$en = array();
				foreach ( $input['enabled_gateways'] as $g ) {
					$g = sanitize_key( $g );
					if ( in_array( $g, self::own_keys_gateways(), true ) ) {
						$en[] = $g;
					}
				}
				$out['enabled_gateways'] = array_values( array_unique( $en ) );
			} elseif ( array_key_exists( 'enabled_gateways', $input ) ) {
				$out['enabled_gateways'] = array();
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
		if ( ! empty( $input['fundolar_connect_platform'] ) || ! empty( $input['fundolar_sync_platform'] ) ) {
			$out['payment_mode'] = self::MODE_CENTRAL;
		}
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
				$allowed = self::sanitize_platform_base_url( $raw );
				if ( '' !== $allowed ) {
					$out['platform_base_url'] = $allowed;
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
		foreach ( array( 'stripe_secret', 'payoneer_checkout_token', 'paypal_secret', 'pesapal_consumer_secret', 'flutterwave_secret', 'paystack_secret', 'marzpay_api_secret', 'mpesa_api_secret', 'stripe_webhook_secret', 'platform_signing_secret' ) as $k ) {
			if ( ! empty( $s[ $k ] ) ) {
				$s[ $k ] = '********';
			}
		}
		if ( ! empty( $s['platform_api_key'] ) ) {
			$plain = self::get_platform_api_key();
			$s['platform_api_key'] = '' !== $plain
				? substr( $plain, 0, 6 ) . '…'
				: '********';
		}
		return $s;
	}

	/**
	 * Allowlist Fundolar Central hostnames (blocks credential exfil via arbitrary base URL).
	 *
	 * @param string $url Candidate URL.
	 * @return string Sanitized base URL or empty.
	 */
	public static function sanitize_platform_base_url( $url ) {
		$url = esc_url_raw( trim( (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		$p = wp_parse_url( $url );
		if ( ! is_array( $p ) || empty( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		if ( 'https' !== strtolower( (string) $p['scheme'] ) ) {
			return '';
		}
		$host = strtolower( (string) $p['host'] );
		$allowed_hosts = array(
			'app.fundolar.com',
			'fundolar.com',
			'www.fundolar.com',
		);
		/**
		 * Filter allowed Fundolar Central hostnames.
		 *
		 * @param string[] $allowed_hosts Hostnames.
		 */
		$allowed_hosts = (array) apply_filters( 'fundolar_allowed_platform_hosts', $allowed_hosts );
		$allowed_hosts = array_map( 'strtolower', array_map( 'strval', $allowed_hosts ) );
		if ( ! in_array( $host, $allowed_hosts, true ) ) {
			return '';
		}
		$path = isset( $p['path'] ) ? (string) $p['path'] : '';
		$path = ( '/' === $path ) ? '' : rtrim( $path, '/' );
		return 'https://' . $host . $path;
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
			$api_key = sanitize_text_field( (string) $payload['api_key'] );
			if ( '' !== $api_key ) {
				$encrypted = Fundolar_Crypto::encrypt( $api_key );
				$out['platform_api_key'] = '' !== $encrypted ? $encrypted : $api_key;
			}
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
			$meta            = array();
			$known_gateways  = self::builtin_gateway_slugs();
			foreach ( array_keys( $payload['gateways'] ) as $gateway_key ) {
				$gateway_key = sanitize_key( (string) $gateway_key );
				if ( '' !== $gateway_key ) {
					$known_gateways[] = $gateway_key;
				}
			}
			$known_gateways = array_values( array_unique( $known_gateways ) );
			foreach ( $payload['gateways'] as $gateway => $info ) {
				$gateway = sanitize_key( (string) $gateway );
				if ( ! in_array( $gateway, $known_gateways, true ) || ! is_array( $info ) ) {
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
			'payoneer'        => array( 'payoneer_checkout_store_code', 'payoneer_checkout_token', 'payoneer_checkout_environment' ),
			'paypal'          => array( 'paypal_client_id', 'paypal_secret' ),
			'paystack'        => array( 'paystack_public', 'paystack_secret' ),
			'flutterwave'     => array( 'flutterwave_public', 'flutterwave_secret' ),
			'pesapal'         => array( 'pesapal_consumer_key', 'pesapal_consumer_secret' ),
			'mobile_money_ug' => array( 'marzpay_api_key', 'marzpay_api_secret' ),
			'mpesa'           => array( 'mpesa_api_key', 'mpesa_api_secret' ),
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
			'payoneer_checkout_store_code' => 'payoneer_checkout_store_code',
			'payoneer_checkout_token'   => 'payoneer_checkout_token',
			'payoneer_checkout_environment' => 'payoneer_checkout_environment',
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
			'mpesa_api_key'             => 'mpesa_api_key',
			'mpesa_api_secret'          => 'mpesa_api_secret',
		);
		foreach ( $remote_map as $remote => $local ) {
			$gateway = isset( $field_to_gateway[ $local ] ) ? $field_to_gateway[ $local ] : '';
			$active  = '' !== $gateway && in_array( $gateway, $enabled, true );
			$val     = isset( $credentials[ $remote ] ) ? trim( (string) $credentials[ $remote ] ) : '';
			if ( ! $active ) {
				$out[ $local ] = '';
				continue;
			}
			if ( '' === $val ) {
				// Keep existing stored credentials when Central omits unchanged secrets.
				if ( isset( $out[ $local ] ) && '' !== trim( (string) $out[ $local ] ) ) {
					continue;
				}
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
			case 'payoneer':
				return '' !== trim( (string) ( $s['payoneer_checkout_store_code'] ?? '' ) ) && '' !== self::get_credential_secret( 'payoneer_checkout_token' );
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
			case 'mpesa':
				$creds = self::mpesa_credentials();
				return '' !== trim( $creds['key'] ) && '' !== $creds['secret'];
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
				'country'       => 'UG',
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
	 * Mpesa collection credentials from site settings.
	 *
	 * @return array{key:string,secret:string}
	 */
	public static function mpesa_credentials() {
		$s   = self::get_settings();
		$key = trim( (string) ( $s['mpesa_api_key'] ?? '' ) );
		$sec = self::get_credential_secret( 'mpesa_api_secret' );
		if ( '' === $key || '' === $sec ) {
			$key = trim( (string) ( $s['marzpay_api_key'] ?? '' ) );
			$sec = self::get_credential_secret( 'marzpay_api_secret' );
		}
		return array(
			'key'    => $key,
			'secret' => $sec,
		);
	}

	/**
	 * Initiate Kenya Mpesa collection.
	 *
	 * @param array<string,mixed> $payload name, email, amount, currency, phone_number, callback_url.
	 * @return array|WP_Error
	 */
	public static function mpesa_collect( array $payload ) {
		$creds = self::mpesa_credentials();
		if ( '' === $creds['key'] || '' === $creds['secret'] ) {
			return new WP_Error( 'fundolar_mpesa', __( 'Mpesa is not configured.', 'fundolar' ) );
		}

		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		if ( 'KES' !== strtoupper( $split['currency'] ) ) {
			return new WP_Error(
				'fundolar_mpesa_currency',
				__( 'Mpesa checkout is available only for KES.', 'fundolar' )
			);
		}

		$gross_kes = (int) round( (float) $split['gross'] );
		$reference = Fundolar_Marzpay::generate_reference();
		$email     = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$desc      = sprintf(
			/* translators: %s: donor name */
			__( 'Donation from %s', 'fundolar' ),
			sanitize_text_field( (string) ( $payload['name'] ?? '' ) )
		);

		$res = Fundolar_Marzpay::collect_money(
			$creds['key'],
			$creds['secret'],
			array(
				'amount'       => $gross_kes,
				'phone_number' => isset( $payload['phone_number'] ) ? $payload['phone_number'] : '',
				'country'      => 'KE',
				'reference'    => $reference,
				'description'  => $desc,
				'callback_url' => isset( $payload['callback_url'] ) ? $payload['callback_url'] : '',
				'metadata'     => array(
					array( 'orderId' => 'fundolar-' . $reference ),
					array(
						'customerId' => '' !== $email ? $email : sanitize_text_field( (string) ( $payload['name'] ?? '' ) ),
						'isPII'      => true,
					),
				),
			)
		);
		if ( is_wp_error( $res ) ) {
			$msg = $res->get_error_message();
			if ( false !== stripos( $msg, 'No collection services available for country KE' ) ) {
				return new WP_Error(
					'fundolar_mpesa',
					__( 'Kenya M-Pesa is not active on the payment account. Subscribe to Kenya M-Pesa Collection in your MarzPay dashboard, then sync gateways in Fundolar Central.', 'fundolar' )
				);
			}
			return $res;
		}
		if ( '' === $res['uuid'] ) {
			return new WP_Error( 'fundolar_mpesa', __( 'Mpesa provider did not return a transaction id.', 'fundolar' ) );
		}

		$res['split']     = $split;
		$res['gross_kes'] = $gross_kes;
		return $res;
	}

	/**
	 * Poll mobile money provider and update local transaction row.
	 *
	 * @param int $transaction_id Local Fundolar transaction id.
	 * @return array|WP_Error Status payload.
	 */
	public static function marzpay_sync_transaction( $transaction_id ) {
		$row = Fundolar_DB::get( (int) $transaction_id );
		if ( ! $row || ! in_array( (string) $row->gateway, array( 'mobile_money_ug', 'mpesa' ), true ) ) {
			return new WP_Error( 'fundolar_marzpay', __( 'Transaction not found.', 'fundolar' ) );
		}

		$is_mpesa = 'mpesa' === (string) $row->gateway;
		$creds    = $is_mpesa ? self::mpesa_credentials() : self::marzpay_credentials();
		if ( '' === $creds['key'] || '' === $creds['secret'] ) {
			return new WP_Error(
				'fundolar_marzpay',
				$is_mpesa ? __( 'Mpesa is not configured.', 'fundolar' ) : __( 'Mobile Money (UG) is not configured.', 'fundolar' )
			);
		}

		$uuid = (string) $row->gateway_ref;
		$res  = Fundolar_Marzpay::get_collection( $creds['key'], $creds['secret'], $uuid );
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		$provider_status = isset( $res['status'] ) ? (string) $res['status'] : '';
		$created_ts      = ! empty( $row->created_at ) ? strtotime( (string) $row->created_at ) : 0;
		$grace_seconds   = 'mpesa' === (string) $row->gateway ? 180 : 120;
		if (
			$created_ts > 0
			&& Fundolar_Marzpay::is_failed_status( $provider_status )
			&& ( time() - $created_ts ) < $grace_seconds
		) {
			return array(
				'status'    => 'pending',
				'completed' => false,
				'message'   => '',
			);
		}

		return self::marzpay_apply_status( (int) $row->id, $provider_status, isset( $res['raw'] ) ? $res['raw'] : array() );
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
			return array(
				'status'    => 'unknown',
				'completed' => false,
				'message'   => '',
			);
		}

		$meta = array();
		if ( ! empty( $row->meta ) ) {
			$decoded = json_decode( (string) $row->meta, true );
			$meta    = is_array( $decoded ) ? $decoded : array();
		}
		$meta['marzpay'] = $provider_meta;
		$message         = self::marzpay_failure_message( $provider_meta );

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
			return array(
				'status'    => 'completed',
				'completed' => true,
				'message'   => '',
			);
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
			return array(
				'status'    => 'failed',
				'completed' => false,
				'message'   => $message,
			);
		}

		if ( 'completed' !== $row->status ) {
			Fundolar_DB::update( (int) $row->id, array( 'meta' => $meta ) );
		}

		return array(
			'status'    => Fundolar_Marzpay::is_pending_status( $status ) ? 'pending' : sanitize_key( (string) $status ),
			'completed' => false,
			'message'   => '',
		);
	}

	/**
	 * Extract a donor-facing failure reason from MarzPay payload.
	 *
	 * @param array<string,mixed> $provider_meta Raw provider payload.
	 * @return string
	 */
	private static function marzpay_failure_message( array $provider_meta ) {
		$candidates = array();
		if ( isset( $provider_meta['message'] ) && is_string( $provider_meta['message'] ) ) {
			$candidates[] = $provider_meta['message'];
		}
		if ( isset( $provider_meta['data'] ) && is_array( $provider_meta['data'] ) ) {
			$data = $provider_meta['data'];
			if ( isset( $data['message'] ) && is_string( $data['message'] ) ) {
				$candidates[] = $data['message'];
			}
			if ( isset( $data['transaction'] ) && is_array( $data['transaction'] ) ) {
				$tx = $data['transaction'];
				foreach ( array( 'failure_reason', 'status_message', 'message', 'provider_message' ) as $key ) {
					if ( ! empty( $tx[ $key ] ) && is_string( $tx[ $key ] ) ) {
						$candidates[] = $tx[ $key ];
					}
				}
			}
		}
		foreach ( $candidates as $candidate ) {
			$candidate = sanitize_text_field( (string) $candidate );
			if ( '' !== $candidate ) {
				return $candidate;
			}
		}
		return '';
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
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id FROM {$table} WHERE gateway IN (%s, %s) AND gateway_ref = %s LIMIT 1",
					'mobile_money_ug',
					'mpesa',
					$parsed['uuid']
				)
			);
		}
		if ( ! $row && '' !== $parsed['reference'] ) {
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, meta FROM {$table} WHERE gateway IN (%s, %s) AND meta LIKE %s LIMIT 1",
					'mobile_money_ug',
					'mpesa',
					'%' . $wpdb->esc_like( $parsed['reference'] ) . '%'
				)
			);
		}
		if ( ! $row ) {
			return false;
		}

		// Re-verify status with MarzPay API — do not trust the webhook body alone.
		$res = self::marzpay_sync_transaction( (int) $row->id );
		return ! is_wp_error( $res );
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
		if ( $gross_minor < 1 ) {
			return new WP_Error( 'fundolar_amount', __( 'Amount is too small for this currency.', 'fundolar' ) );
		}

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
	 * Create a Payoneer Checkout LIST session for card payments.
	 *
	 * @param array $payload Payload from REST.
	 * @return array|WP_Error
	 */
	public static function payoneer_create_list( array $payload ) {
		$s     = self::get_settings();
		$code  = trim( (string) ( $s['payoneer_checkout_store_code'] ?? '' ) );
		$token = self::get_credential_secret( 'payoneer_checkout_token' );
		if ( '' === $code || '' === $token ) {
			return new WP_Error( 'fundolar_payoneer', __( 'Payoneer Checkout is not configured.', 'fundolar' ) );
		}
		$env = strtolower( trim( (string) ( $s['payoneer_checkout_environment'] ?? 'live' ) ) );
		if ( in_array( $env, array( 'sandbox', 'test' ), true ) ) {
			$env = 'sandbox';
		} else {
			$env = 'live';
		}
		$api_base = 'sandbox' === $env ? 'https://api.sandbox.oscato.com' : 'https://api.live.oscato.com';
		$res_base = 'sandbox' === $env ? 'https://resources.sandbox.oscato.com' : 'https://resources.live.oscato.com';

		$split = Fundolar_Fees::split_for_checkout( (float) $payload['amount'], $payload['currency'] );
		$tx_id = 'FD-PO-' . strtoupper( wp_generate_password( 12, false, false ) );
		$name  = sanitize_text_field( (string) ( $payload['name'] ?? 'Donor' ) );
		$email = sanitize_email( (string) ( $payload['email'] ?? '' ) );
		$return_url = ! empty( $payload['redirect_url'] ) ? esc_url_raw( (string) $payload['redirect_url'] ) : home_url( '/' );
		$return_url = self::with_gateway_return_args( $return_url, 'payoneer', $tx_id );
		$parts = preg_split( '/\s+/', $name );
		$first = is_array( $parts ) && isset( $parts[0] ) ? substr( (string) $parts[0], 0, 50 ) : 'Donor';
		$last  = ( is_array( $parts ) && count( $parts ) > 1 ) ? substr( implode( ' ', array_slice( $parts, 1 ) ), 0, 50 ) : 'Guest';

		$body = array(
			'transactionId' => $tx_id,
			'country'       => 'US',
			'customer'      => array_filter(
				array(
					'number' => substr( preg_replace( '/[^a-zA-Z0-9_-]/', '', $tx_id ), 0, 32 ),
					'email'  => is_email( $email ) ? $email : null,
					'name'   => array(
						'firstName' => $first ?: 'Donor',
						'lastName'  => $last ?: 'Guest',
					),
				)
			),
			'payment'       => array(
				'amount'    => round( (float) $split['gross'], 2 ),
				'currency'  => strtoupper( (string) $split['currency'] ),
				'reference' => sprintf(
					/* translators: %s: donor name */
					__( 'Donation from %s', 'fundolar' ),
					$name
				),
			),
			'style'         => array( 'hostedVersion' => 'v4' ),
			'callback'      => array(
				'returnUrl' => $return_url,
				'cancelUrl' => $return_url,
			),
		);

		$response = wp_remote_post(
			$api_base . '/api/lists',
			array(
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $code . ':' . $token ),
					'Accept'        => 'application/vnd.optile.payment.enterprise-v1-extensible+json',
					'Content-Type'  => 'application/vnd.optile.payment.enterprise-v1-extensible+json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code_http = wp_remote_retrieve_response_code( $response );
		$json      = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code_http >= 400 || ! is_array( $json ) ) {
			$msg = isset( $json['resultInfo'] ) ? (string) $json['resultInfo'] : __( 'Payoneer Checkout error.', 'fundolar' );
			return new WP_Error( 'fundolar_payoneer_api', $msg );
		}
		$long_id  = isset( $json['identification']['longId'] ) ? (string) $json['identification']['longId'] : '';
		$list_url = isset( $json['links']['self'] ) ? (string) $json['links']['self'] : '';
		if ( '' === $long_id && '' !== $list_url ) {
			$chunks  = explode( '/', rtrim( $list_url, '/' ) );
			$long_id = (string) end( $chunks );
		}
		if ( '' === $list_url && '' !== $long_id ) {
			$list_url = $api_base . '/pci/v1/' . rawurlencode( $long_id );
		}
		if ( '' === $long_id || '' === $list_url ) {
			return new WP_Error( 'fundolar_payoneer', __( 'Payoneer did not return a payment session.', 'fundolar' ) );
		}
		$hosted = $res_base . '/paymentpage/v4/responsive.html?listUrl=' . rawurlencode( $list_url );

		return array(
			'transaction_id' => $tx_id,
			'long_id'        => $long_id,
			'list_url'       => $list_url,
			'hosted_url'     => $hosted,
			'env'            => 'sandbox' === $env ? 'test' : 'live',
			'split'          => $split,
		);
	}

	/**
	 * Verify a Payoneer LIST was charged.
	 *
	 * @param string $long_id Payoneer longId.
	 * @return bool
	 */
	public static function payoneer_list_charged( $long_id ) {
		$long_id = trim( (string) $long_id );
		if ( '' === $long_id ) {
			return false;
		}
		$s     = self::get_settings();
		$code  = trim( (string) ( $s['payoneer_checkout_store_code'] ?? '' ) );
		$token = self::get_credential_secret( 'payoneer_checkout_token' );
		if ( '' === $code || '' === $token ) {
			return false;
		}
		$env = strtolower( trim( (string) ( $s['payoneer_checkout_environment'] ?? 'live' ) ) );
		$api_base = in_array( $env, array( 'sandbox', 'test' ), true )
			? 'https://api.sandbox.oscato.com'
			: 'https://api.live.oscato.com';
		$response = wp_remote_get(
			$api_base . '/api/lists/' . rawurlencode( $long_id ),
			array(
				'timeout' => 20,
				'headers' => array(
					'Authorization' => 'Basic ' . base64_encode( $code . ':' . $token ),
					'Accept'        => 'application/vnd.optile.payment.enterprise-v1-extensible+json',
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
		$status = strtolower( (string) ( $json['status']['code'] ?? '' ) );
		if ( in_array( $status, array( 'charged', 'paid', 'authorized' ), true ) ) {
			return true;
		}
		$blob = strtolower( wp_json_encode( $json ) ?: '' );
		return false !== strpos( $blob, '"code":"charged"' );
	}

	/**
	 * Mark local checkout complete after Payoneer return if LIST is charged.
	 *
	 * @param string $reference  Our transactionId.
	 * @param string $long_id    Optional Payoneer longId.
	 * @return bool
	 */
	public static function payoneer_verify_and_update( $reference, $long_id = '' ) {
		$reference = sanitize_text_field( (string) $reference );
		$long_id   = sanitize_text_field( (string) $long_id );
		if ( '' === $reference ) {
			return false;
		}
		global $wpdb;
		$table = Fundolar_DB::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT id, status, meta FROM {$table} WHERE gateway = %s AND gateway_ref = %s LIMIT 1", 'payoneer', $reference ) );
		if ( ! $row ) {
			return false;
		}
		if ( 'completed' === (string) ( $row->status ?? '' ) ) {
			return true;
		}
		if ( '' === $long_id && ! empty( $row->meta ) ) {
			$meta = json_decode( (string) $row->meta, true );
			if ( is_array( $meta ) && ! empty( $meta['long_id'] ) ) {
				$long_id = (string) $meta['long_id'];
			}
		}
		if ( '' === $long_id || ! self::payoneer_list_charged( $long_id ) ) {
			return false;
		}
		Fundolar_DB::update( (int) $row->id, array( 'status' => 'completed', 'meta' => array( 'payoneer_long_id' => $long_id ) ) );
		Fundolar_Emails::notify_donation_completed( (int) $row->id );
		Fundolar_Platform::report_donation_status( (int) $row->id, 'completed', 'payoneer_charged', array( 'long_id' => $long_id ) );
		return true;
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
		if ( $gross_minor < 1 ) {
			return new WP_Error( 'fundolar_amount', __( 'Amount is too small for this currency.', 'fundolar' ) );
		}
		$email    = sanitize_email( $payload['email'] );
		$reference = 'fundolar_' . wp_generate_password( 12, false, false );

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

		if ( ! empty( $payload['callback_url'] ) ) {
			$body['callback_url'] = self::with_gateway_return_args( $payload['callback_url'], 'paystack', $reference );
		}

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
		$redirect = ! empty( $payload['redirect_url'] ) ? (string) $payload['redirect_url'] : home_url( '/' );

		$body = array(
			'tx_ref'       => $tx_ref,
			'amount'       => (string) $split['gross'],
			'currency'     => strtoupper( $split['currency'] ),
			'redirect_url' => self::with_gateway_return_args( $redirect, 'flutterwave', $tx_ref ),
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

		$body = array(
			'intent'         => 'CAPTURE',
			'purchase_units' => array(
				array(
					'amount'      => array(
						'currency_code' => strtoupper( $split['currency'] ),
						'value'         => number_format( $split['gross'], 2, '.', '' ),
					),
					'description' => __( 'Donation', 'fundolar' ),
					'custom_id'   => wp_json_encode( $custom ),
				),
			),
		);
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
		$ipn_url    = add_query_arg( 'fundolar_gateway', 'pesapal', home_url( '/' ) );
		$ipn_id     = self::pesapal_register_ipn( $token, $api_base, $ipn_url );
		if ( is_wp_error( $ipn_id ) ) {
			return $ipn_id;
		}

		$order_reference = 'fundolar_' . wp_generate_password( 14, false, false );
		$return_url      = self::with_gateway_return_args( $return_url, 'pesapal', $order_reference );
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
}
