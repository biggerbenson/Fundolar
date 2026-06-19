<?php
/**
 * Platform fee calculations (donor-facing amount vs settlement split).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Fees
 */
class Fundolar_Fees {

	/**
	 * Rate (e.g. 0.035).
	 *
	 * @return float
	 */
	public static function rate() {
		$rules = self::synced_rules();
		if ( null !== $rules && isset( $rules['percentage'] ) ) {
			return (float) $rules['percentage'];
		}
		/**
		 * Filter platform fee rate (decimal). Default 3.5%.
		 *
		 * @param float $rate Rate.
		 */
		return (float) apply_filters( 'fundolar_platform_fee_rate', defined( 'FUNDOLAR_PLATFORM_FEE_RATE' ) ? FUNDOLAR_PLATFORM_FEE_RATE : 0.035 );
	}

	/**
	 * Fee rules synced from Central when connected.
	 *
	 * @return array{percentage:float,fixed:float,min:float,max:float,revision:int}|null
	 */
	private static function synced_rules() {
		if ( ! Fundolar_Payments::is_central_connected() ) {
			return null;
		}
		$s = Fundolar_Payments::get_settings();
		if ( empty( $s['platform_fee_rules'] ) || ! is_array( $s['platform_fee_rules'] ) ) {
			return null;
		}
		$rules = $s['platform_fee_rules'];
		if ( ! isset( $rules['percentage'] ) ) {
			return null;
		}
		return array(
			'percentage' => (float) $rules['percentage'],
			'fixed'      => isset( $rules['fixed'] ) ? (float) $rules['fixed'] : 0,
			'min'        => isset( $rules['min'] ) ? (float) $rules['min'] : 0,
			'max'        => isset( $rules['max'] ) ? (float) $rules['max'] : 0,
			'revision'   => isset( $rules['revision'] ) ? (int) $rules['revision'] : 0,
		);
	}

	/**
	 * Split gross donor amount into platform fee and net to site owner.
	 *
	 * Receipt should always show the gross amount the donor intended to give.
	 *
	 * @param float  $gross    Gross amount in major units (e.g. 5.00).
	 * @param string $currency Currency code.
	 * @return array{gross:float,fee:float,net:float,currency:string}
	 */
	public static function split( $gross, $currency = 'USD' ) {
		$gross = round( (float) $gross, 4 );
		$rate  = self::rate();
		$fee   = round( $gross * $rate, 4 );
		$net   = round( $gross - $fee, 4 );
		if ( $net < 0 ) {
			$net = 0;
		}
		return array(
			'gross'    => $gross,
			'fee'      => $fee,
			'net'      => $net,
			'currency' => strtoupper( substr( sanitize_text_field( $currency ), 0, 3 ) ),
		);
	}

	/**
	 * Platform fee from rules (processor fee subtracted after platform fee when used).
	 *
	 * @param float  $gross         Gross donor amount (major units).
	 * @param string $currency      ISO currency.
	 * @param array  $rules         Keys: percentage, fixed, min, max (max 0 = no cap).
	 * @param float  $processor_fee Processor fee in major units.
	 * @return array{gross:float,platform_fee:float,processor_fee:float,net_to_site:float,currency:string}
	 */
	public static function calculate_from_rules( $gross, $currency, array $rules, $processor_fee = 0.0 ) {
		$curr = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $currency ), 0, 3 ) );
		if ( strlen( $curr ) !== 3 ) {
			$curr = 'USD';
		}
		$pct   = isset( $rules['percentage'] ) ? (float) $rules['percentage'] : self::rate();
		$fixed = isset( $rules['fixed'] ) ? (float) $rules['fixed'] : 0;
		$min   = isset( $rules['min'] ) ? (float) $rules['min'] : 0;
		$max   = isset( $rules['max'] ) ? (float) $rules['max'] : 0;

		$gross          = round( (float) $gross, 4 );
		$platform       = round( $gross * $pct + $fixed, 4 );
		if ( $platform < $min ) {
			$platform = round( $min, 4 );
		}
		if ( $max > 0 && $platform > $max ) {
			$platform = round( $max, 4 );
		}
		$processor = round( (float) $processor_fee, 4 );
		$net       = round( $gross - $platform - $processor, 4 );
		if ( $net < 0 ) {
			$net = 0.0;
		}
		return array(
			'gross'         => $gross,
			'platform_fee'  => $platform,
			'processor_fee' => $processor,
			'net_to_site'   => $net,
			'currency'      => $curr,
		);
	}

	/**
	 * Checkout split: percentage (and optional fixed/min/max via filter on rate or custom logic).
	 *
	 * @param float  $gross    Donor-facing gross amount.
	 * @param string $currency Currency.
	 * @return array{gross:float,fee:float,net:float,currency:string}
	 */
	public static function split_for_checkout( $gross, $currency = 'USD' ) {
		$rules = self::synced_rules();
		if ( null !== $rules ) {
			$calc = self::calculate_from_rules( $gross, $currency, $rules, 0 );
			return array(
				'gross'    => $calc['gross'],
				'fee'      => $calc['platform_fee'],
				'net'      => $calc['net_to_site'],
				'currency' => $calc['currency'],
			);
		}
		return self::split( $gross, $currency );
	}

	/**
	 * Percentage for front-end cover-fees estimate.
	 *
	 * @return float
	 */
	public static function effective_percentage_for_js() {
		return self::rate();
	}

	/**
	 * Convert major units to minor (cents) for gateways that require integers.
	 *
	 * @param float  $amount   Major units.
	 * @param string $currency Currency.
	 * @return int
	 */
	public static function to_minor_units( $amount, $currency = 'USD' ) {
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$curr         = strtoupper( $currency );
		if ( in_array( $curr, $zero_decimal, true ) ) {
			return (int) round( $amount );
		}
		return (int) round( $amount * 100 );
	}

	/**
	 * Convert gateway minor units to major (inverse of to_minor_units).
	 *
	 * @param int    $minor    Integer minor amount.
	 * @param string $currency Currency code.
	 * @return float
	 */
	public static function from_minor_units( $minor, $currency = 'USD' ) {
		$minor = (int) $minor;
		$zero_decimal = array( 'BIF', 'CLP', 'DJF', 'GNF', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF' );
		$curr         = strtoupper( substr( sanitize_text_field( $currency ), 0, 3 ) );
		if ( in_array( $curr, $zero_decimal, true ) ) {
			return (float) $minor;
		}
		return round( $minor / 100, 4 );
	}
}
