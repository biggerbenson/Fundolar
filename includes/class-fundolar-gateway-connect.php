<?php
/**
 * Stripe Connect & PayPal Partner onboarding helpers (own-keys mode).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Gateway_Connect
 */
class Fundolar_Gateway_Connect {

	const OAUTH_TRANSIENT_PREFIX = 'fundolar_oauth_state_';

	/**
	 * Register admin OAuth return handler.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'handle_oauth_return' ) );
	}

	/**
	 * Stripe Connect OAuth start URL (Fundolar platform or filter override).
	 *
	 * @return string
	 */
	public static function stripe_connect_url() {
		$state = wp_generate_password( 24, false, false );
		set_transient( self::OAUTH_TRANSIENT_PREFIX . 'stripe_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );

		$return = add_query_arg(
			array(
				'page'           => 'fundolar-settings',
				'fundolar_oauth' => 'stripe',
				'state'          => $state,
			),
			admin_url( 'admin.php' )
		);

		$base = Fundolar_Platform::base_url();
		$url  = $base . '/owner/connect/stripe?' . http_build_query(
			array(
				'return_url' => $return,
				'site_url'   => home_url( '/' ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		/**
		 * Filter Stripe Connect authorization URL for own-keys mode.
		 *
		 * @param string $url       Authorization URL.
		 * @param string $return_url Admin return URL after OAuth.
		 */
		return (string) apply_filters( 'fundolar_stripe_connect_url', $url, $return );
	}

	/**
	 * PayPal Partner onboarding URL.
	 *
	 * @return string
	 */
	public static function paypal_connect_url() {
		$state = wp_generate_password( 24, false, false );
		set_transient( self::OAUTH_TRANSIENT_PREFIX . 'paypal_' . $state, get_current_user_id(), 15 * MINUTE_IN_SECONDS );

		$return = add_query_arg(
			array(
				'page'           => 'fundolar-settings',
				'fundolar_oauth' => 'paypal',
				'state'          => $state,
			),
			admin_url( 'admin.php' )
		);

		$base = Fundolar_Platform::base_url();
		$url  = $base . '/owner/connect/paypal?' . http_build_query(
			array(
				'return_url' => $return,
				'site_url'   => home_url( '/' ),
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);

		/**
		 * Filter PayPal Partner connect URL for own-keys mode.
		 *
		 * @param string $url       Onboarding URL.
		 * @param string $return_url Admin return URL.
		 */
		return (string) apply_filters( 'fundolar_paypal_connect_url', $url, $return );
	}

	/**
	 * Platform Stripe Connect account that receives the 3.5% fee (acct_…).
	 *
	 * @return string
	 */
	public static function platform_stripe_account_id() {
		if ( defined( 'FUNDOLAR_PLATFORM_STRIPE_CONNECT_ACCOUNT' ) && '' !== (string) FUNDOLAR_PLATFORM_STRIPE_CONNECT_ACCOUNT ) {
			return sanitize_text_field( (string) FUNDOLAR_PLATFORM_STRIPE_CONNECT_ACCOUNT );
		}
		if ( defined( 'FUNDOLAR_AUTHOR_STRIPE_CONNECT_ACCOUNT' ) && '' !== (string) FUNDOLAR_AUTHOR_STRIPE_CONNECT_ACCOUNT ) {
			return sanitize_text_field( (string) FUNDOLAR_AUTHOR_STRIPE_CONNECT_ACCOUNT );
		}
		/**
		 * Filter platform Stripe Connect destination for application fees.
		 *
		 * @param string $account_id Stripe account id or empty.
		 */
		return sanitize_text_field( (string) apply_filters( 'fundolar_platform_stripe_connect_account', '' ) );
	}

	/**
	 * Platform PayPal partner payee for the 3.5% fee portion.
	 *
	 * @return array<string,string>
	 */
	public static function platform_paypal_payee() {
		if ( defined( 'FUNDOLAR_PLATFORM_PAYPAL_PARTNER_ID' ) && '' !== (string) FUNDOLAR_PLATFORM_PAYPAL_PARTNER_ID ) {
			$id = sanitize_text_field( (string) FUNDOLAR_PLATFORM_PAYPAL_PARTNER_ID );
			if ( false !== strpos( $id, '@' ) ) {
				$em = sanitize_email( $id );
				return is_email( $em ) ? array( 'email_address' => $em ) : array();
			}
			return array( 'merchant_id' => $id );
		}
		/**
		 * Filter platform PayPal payee for fee split orders.
		 *
		 * @param array<string,string> $payee Payee array or empty.
		 */
		$payee = apply_filters( 'fundolar_platform_paypal_payee', array() );
		return is_array( $payee ) ? $payee : array();
	}

	/**
	 * Handle OAuth return from Fundolar platform (stores credentials when provided).
	 */
	public static function handle_oauth_return() {
		if ( ! is_admin() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( empty( $_GET['fundolar_oauth'] ) || empty( $_GET['state'] ) ) {
			return;
		}

		$gateway = sanitize_key( wp_unslash( $_GET['fundolar_oauth'] ) );
		$state   = sanitize_text_field( wp_unslash( $_GET['state'] ) );
		if ( ! in_array( $gateway, array( 'stripe', 'paypal' ), true ) ) {
			return;
		}

		$uid = get_transient( self::OAUTH_TRANSIENT_PREFIX . $gateway . '_' . $state );
		delete_transient( self::OAUTH_TRANSIENT_PREFIX . $gateway . '_' . $state );
		if ( false === $uid || (int) $uid !== get_current_user_id() ) {
			add_settings_error( 'fundolar', 'oauth-state', __( 'Connection session expired. Please try again.', 'fundolar' ), 'error' );
			return;
		}

		$out = Fundolar_Payments::get_settings();
		$out['payment_mode'] = Fundolar_Payments::MODE_CENTRAL;

		if ( 'stripe' === $gateway ) {
			$pk = isset( $_GET['stripe_publishable_key'] ) ? sanitize_text_field( wp_unslash( $_GET['stripe_publishable_key'] ) ) : '';
			$sk = isset( $_GET['stripe_secret_key'] ) ? sanitize_text_field( wp_unslash( $_GET['stripe_secret_key'] ) ) : '';
			if ( '' !== $pk ) {
				$out['stripe_publishable'] = $pk;
			}
			if ( '' !== $sk ) {
				$out['stripe_secret'] = Fundolar_Crypto::encrypt( $sk );
			}
			if ( ! empty( $_GET['stripe_user_id'] ) ) {
				$out['stripe_connect_account_id'] = sanitize_text_field( wp_unslash( $_GET['stripe_user_id'] ) );
			}
			$en = (array) $out['enabled_gateways'];
			if ( ! in_array( 'stripe', $en, true ) ) {
				$en[] = 'stripe';
			}
			$out['enabled_gateways'] = array_values( array_unique( $en ) );
		}

		if ( 'paypal' === $gateway ) {
			$cid = isset( $_GET['paypal_client_id'] ) ? sanitize_text_field( wp_unslash( $_GET['paypal_client_id'] ) ) : '';
			$sec = isset( $_GET['paypal_secret'] ) ? sanitize_text_field( wp_unslash( $_GET['paypal_secret'] ) ) : '';
			if ( '' !== $cid ) {
				$out['paypal_client_id'] = $cid;
			}
			if ( '' !== $sec ) {
				$out['paypal_secret'] = Fundolar_Crypto::encrypt( $sec );
			}
			$en = (array) $out['enabled_gateways'];
			if ( ! in_array( 'paypal', $en, true ) ) {
				$en[] = 'paypal';
			}
			$out['enabled_gateways'] = array_values( array_unique( $en ) );
		}

		update_option( Fundolar_Payments::OPTION, $out, false );
		wp_safe_redirect( admin_url( 'admin.php?page=fundolar-settings&fundolar_oauth_ok=' . rawurlencode( $gateway ) . '#payments' ) );
		exit;
	}
}
