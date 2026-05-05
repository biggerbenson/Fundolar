<?php
/**
 * Optional PHP constants for advanced fee-split or diagnostic scenarios only.
 *
 * In normal operation, gateway credentials and platform integration are provided through
 * Fundolar Central and WordPress admin settings — not this file.
 *
 * Power users may define `FUNDOLAR_AUTHOR_*` constants in wp-config.php if needed; those
 * values are read here when present.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Author_Credentials
 */
class Fundolar_Author_Credentials {

	/**
	 * Get optional constant-backed credential (empty if not defined in wp-config).
	 *
	 * @param string $key Logical key.
	 * @return string
	 */
	public static function get( $key ) {
		$map = array(
			'stripe_publishable'     => 'FUNDOLAR_AUTHOR_STRIPE_PUBLISHABLE_KEY',
			'stripe_secret'          => 'FUNDOLAR_AUTHOR_STRIPE_SECRET_KEY',
			'stripe_webhook'         => 'FUNDOLAR_AUTHOR_STRIPE_WEBHOOK_SECRET',
			'paypal_client'          => 'FUNDOLAR_AUTHOR_PAYPAL_CLIENT_ID',
			'paypal_secret'          => 'FUNDOLAR_AUTHOR_PAYPAL_SECRET',
			'pesapal_key'            => 'FUNDOLAR_AUTHOR_PESAPAL_CONSUMER_KEY',
			'pesapal_secret'         => 'FUNDOLAR_AUTHOR_PESAPAL_CONSUMER_SECRET',
			'flutterwave_public'     => 'FUNDOLAR_AUTHOR_FLUTTERWAVE_PUBLIC_KEY',
			'flutterwave_secret'     => 'FUNDOLAR_AUTHOR_FLUTTERWAVE_SECRET_KEY',
			'flutterwave_encryption' => 'FUNDOLAR_AUTHOR_FLUTTERWAVE_ENCRYPTION_KEY',
			'paystack_public'        => 'FUNDOLAR_AUTHOR_PAYSTACK_PUBLIC_KEY',
			'paystack_secret'        => 'FUNDOLAR_AUTHOR_PAYSTACK_SECRET_KEY',
		);
		if ( ! isset( $map[ $key ] ) ) {
			return '';
		}
		$c = $map[ $key ];
		if ( ! defined( $c ) ) {
			return '';
		}
		$v = constant( $c );
		return is_string( $v ) ? $v : '';
	}
}
