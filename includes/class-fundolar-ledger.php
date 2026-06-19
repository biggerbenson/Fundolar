<?php
/**
 * USD ledger conversion for stored transactions (aligned with Fundolar Central).
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Ledger
 */
class Fundolar_Ledger {

	/**
	 * USD value of one unit of currency.
	 *
	 * @param string $currency ISO currency code.
	 * @return float
	 */
	public static function rate_to_usd( $currency ) {
		$code = strtoupper( substr( preg_replace( '/[^A-Za-z]/', '', (string) $currency ), 0, 3 ) );
		if ( 'USD' === $code || '' === $code ) {
			return 1.0;
		}
		$rates = Fundolar_Payments::fx_rates_usd_base();
		$usd_to_target = isset( $rates[ $code ] ) ? (float) $rates[ $code ] : 0.0;
		if ( $usd_to_target <= 0 ) {
			return 1.0;
		}
		return 1.0 / $usd_to_target;
	}

	/**
	 * Convert a payment-currency amount to USD.
	 *
	 * @param float  $amount   Payment amount.
	 * @param string $currency Payment currency.
	 * @return float
	 */
	public static function to_usd( $amount, $currency ) {
		return round( (float) $amount * self::rate_to_usd( $currency ), 4 );
	}

	/**
	 * Build DB row fields from a checkout split (payment currency).
	 *
	 * @param array{gross:float,fee:float,net:float,currency:string} $split Checkout split.
	 * @return array<string,mixed>
	 */
	public static function row_from_checkout_split( array $split ) {
		$payment_currency = strtoupper( substr( sanitize_text_field( (string) $split['currency'] ), 0, 3 ) );
		$payment_gross    = round( (float) $split['gross'], 4 );
		$payment_fee      = round( (float) $split['fee'], 4 );
		$payment_net      = round( (float) $split['net'], 4 );

		return array(
			'currency'               => 'USD',
			'payment_currency'       => $payment_currency,
			'amount_gross'           => self::to_usd( $payment_gross, $payment_currency ),
			'amount_platform_fee'    => self::to_usd( $payment_fee, $payment_currency ),
			'amount_net'             => self::to_usd( $payment_net, $payment_currency ),
			'receipt_amount_display' => $payment_gross,
		);
	}

	/**
	 * Payment amounts to send when creating/updating Central donations.
	 *
	 * @param object $row Local transaction row.
	 * @return array{currency:string,gross_amount:float,platform_fee_amount:float,net_to_site_amount:float}
	 */
	public static function central_payment_payload( $row ) {
		$payment_currency = '';
		if ( isset( $row->payment_currency ) && '' !== (string) $row->payment_currency ) {
			$payment_currency = strtoupper( (string) $row->payment_currency );
		} elseif ( ! empty( $row->currency ) && 'USD' !== strtoupper( (string) $row->currency ) ) {
			$payment_currency = strtoupper( (string) $row->currency );
		} else {
			$payment_currency = 'USD';
		}

		$payment_gross = (float) ( $row->receipt_amount_display ?? 0 );
		if ( $payment_gross <= 0 ) {
			if ( 'USD' !== $payment_currency ) {
				$payment_gross = (float) ( $row->amount_gross ?? 0 );
			} else {
				$payment_gross = (float) ( $row->amount_gross ?? 0 );
			}
		}

		$rate = self::rate_to_usd( $payment_currency );
		$ledger_fee = (float) ( $row->amount_platform_fee ?? 0 );
		$ledger_net = (float) ( $row->amount_net ?? 0 );
		if ( 'USD' !== $payment_currency && $rate > 0 && $rate < 1 ) {
			$payment_fee = round( $ledger_fee / $rate, 4 );
			$payment_net = round( $ledger_net / $rate, 4 );
		} else {
			$payment_fee = $ledger_fee;
			$payment_net = $ledger_net;
		}

		return array(
			'currency'             => $payment_currency,
			'gross_amount'         => $payment_gross,
			'platform_fee_amount'  => $payment_fee,
			'net_to_site_amount'   => $payment_net,
			'preserve_amounts'     => true,
		);
	}

	/**
	 * Convert a legacy row to USD ledger storage.
	 *
	 * @param object $row Transaction row.
	 * @return array<string,mixed>|null
	 */
	public static function backfill_row( $row ) {
		if ( ! $row ) {
			return null;
		}

		$ledger_currency = strtoupper( (string) ( $row->currency ?? 'USD' ) );
		$payment_currency = '';
		if ( isset( $row->payment_currency ) && '' !== (string) $row->payment_currency ) {
			$payment_currency = strtoupper( (string) $row->payment_currency );
		} else {
			$payment_currency = $ledger_currency;
		}

		$payment_gross = (float) ( $row->receipt_amount_display ?? 0 );
		if ( $payment_gross <= 0 ) {
			$payment_gross = (float) ( $row->amount_gross ?? 0 );
		}
		if ( $payment_gross <= 0 ) {
			return null;
		}

		if ( 'USD' === $ledger_currency && ! empty( $row->payment_currency ) ) {
			return null;
		}

		if ( 'USD' !== $ledger_currency ) {
			$payment_currency = $ledger_currency;
			$payment_gross    = (float) ( $row->amount_gross ?? $payment_gross );
			if ( (float) ( $row->receipt_amount_display ?? 0 ) <= 0 ) {
				$receipt = $payment_gross;
			} else {
				$receipt = (float) $row->receipt_amount_display;
			}
		} else {
			$receipt = $payment_gross;
		}

		$payment_fee = (float) ( $row->amount_platform_fee ?? 0 );
		$payment_net = (float) ( $row->amount_net ?? 0 );
		if ( 'USD' !== $ledger_currency ) {
			$payment_fee = (float) ( $row->amount_platform_fee ?? 0 );
			$payment_net = (float) ( $row->amount_net ?? 0 );
		}

		return array(
			'currency'               => 'USD',
			'payment_currency'       => $payment_currency,
			'amount_gross'           => self::to_usd( $payment_gross, $payment_currency ),
			'amount_platform_fee'    => self::to_usd( $payment_fee, $payment_currency ),
			'amount_net'             => self::to_usd( $payment_net, $payment_currency ),
			'receipt_amount_display' => $receipt,
		);
	}

	/**
	 * Receipt label for admin UI.
	 *
	 * @param array<string,mixed>|object $row Transaction row.
	 * @return string
	 */
	public static function receipt_label( $row ) {
		$payment_currency = '';
		$receipt          = 0.0;
		if ( is_array( $row ) ) {
			$payment_currency = isset( $row['payment_currency'] ) ? (string) $row['payment_currency'] : '';
			$receipt          = (float) ( $row['receipt_amount_display'] ?? 0 );
			if ( '' === $payment_currency ) {
				$payment_currency = (string) ( $row['currency'] ?? 'USD' );
			}
		} else {
			$payment_currency = isset( $row->payment_currency ) ? (string) $row->payment_currency : '';
			$receipt          = (float) ( $row->receipt_amount_display ?? 0 );
			if ( '' === $payment_currency ) {
				$payment_currency = (string) ( $row->currency ?? 'USD' );
			}
		}
		return trim( $payment_currency ) . ' ' . number_format_i18n( $receipt, 2 );
	}
}
