<?php
/**
 * Donation notification emails and support contact.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Emails
 */
class Fundolar_Emails {

	const SUPPORT_INBOX = 'info@fundolar.com';

	/**
	 * Send configured emails once per completed transaction (deduplicated).
	 *
	 * @param int $transaction_id Row ID.
	 */
	public static function notify_donation_completed( $transaction_id ) {
		$transaction_id = (int) $transaction_id;
		if ( $transaction_id <= 0 ) {
			return;
		}
		$lock_key = 'fundolar_mail_done_' . $transaction_id;
		if ( get_transient( $lock_key ) ) {
			return;
		}

		$row = Fundolar_DB::get( $transaction_id );
		if ( ! $row || 'completed' !== $row->status ) {
			return;
		}

		$s = Fundolar_Payments::get_settings();

		if ( ! empty( $s['notify_admin_on_success'] ) ) {
			self::send_admin_notice( $row, $s );
		}
		if ( ! empty( $s['donor_receipt_enabled'] ) ) {
			self::send_donor_receipt( $row, $s );
		}

		set_transient( $lock_key, 1, HOUR_IN_SECONDS );
	}

	/**
	 * @param object $row DB row.
	 * @param array  $s   Settings.
	 */
	private static function send_admin_notice( $row, array $s ) {
		$list = self::parse_recipient_list( isset( $s['notify_admin_recipients'] ) ? $s['notify_admin_recipients'] : '' );
		if ( empty( $list ) ) {
			$a = get_option( 'admin_email' );
			if ( is_email( $a ) ) {
				$list = array( $a );
			}
		}
		if ( empty( $list ) ) {
			return;
		}
		$blog = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		/* translators: %s: site title */
		$subject = sprintf( __( '[%s] New donation received', 'fundolar' ), $blog );
		$body    = self::build_admin_body( $row );
		$headers = array( 'Content-Type: text/plain; charset=UTF-8' );
		foreach ( $list as $to ) {
			wp_mail( $to, $subject, $body, $headers );
		}
	}

	/**
	 * @param string $raw Comma / newline separated emails.
	 * @return string[]
	 */
	private static function parse_recipient_list( $raw ) {
		$raw = is_string( $raw ) ? $raw : '';
		$parts = preg_split( '/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY );
		$out   = array();
		foreach ( $parts as $p ) {
			$e = sanitize_email( $p );
			if ( is_email( $e ) ) {
				$out[] = $e;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * @param object $row DB row.
	 * @return string
	 */
	private static function build_admin_body( $row ) {
		$lines = array(
			__( 'A new donation was completed.', 'fundolar' ),
			'',
			__( 'Donor:', 'fundolar' ) . ' ' . $row->donor_name,
			__( 'Email:', 'fundolar' ) . ' ' . $row->donor_email,
			__( 'Amount:', 'fundolar' ) . ' ' . $row->currency . ' ' . $row->receipt_amount_display,
			__( 'Gateway:', 'fundolar' ) . ' ' . $row->gateway,
			__( 'Record ID:', 'fundolar' ) . ' ' . (string) $row->id,
		);
		return implode( "\n", $lines );
	}

	/**
	 * @param object $row DB row.
	 * @param array  $s   Settings.
	 */
	private static function send_donor_receipt( $row, array $s ) {
		if ( ! is_email( $row->donor_email ) ) {
			return;
		}
		$from_email = ! empty( $s['donor_receipt_from_email'] ) ? sanitize_email( $s['donor_receipt_from_email'] ) : '';
		if ( ! is_email( $from_email ) ) {
			$from_email = get_option( 'admin_email' );
		}
		if ( ! is_email( $from_email ) ) {
			return;
		}
		$from_name = ! empty( $s['donor_receipt_from_name'] ) ? $s['donor_receipt_from_name'] : get_bloginfo( 'name' );
		$from_name = wp_specialchars_decode( sanitize_text_field( $from_name ), ENT_QUOTES );

		$subj_tpl = ! empty( $s['donor_email_subject'] ) ? $s['donor_email_subject'] : __( 'Thank you for your donation', 'fundolar' );
		$subject  = self::replace_tokens_plain( $subj_tpl, $row );

		$template = ! empty( $s['donor_email_template'] ) ? $s['donor_email_template'] : self::default_donor_template();
		$body     = self::replace_tokens_html( $template, $row );

		$headers = array(
			'Content-Type: text/html; charset=UTF-8',
		);
		$headers[] = 'From: ' . sprintf( '%s <%s>', self::encode_rfc2047_name( $from_name ), $from_email );

		wp_mail( $row->donor_email, $subject, $body, $headers );
	}

	/**
	 * @param string $name Display name.
	 * @return string
	 */
	private static function encode_rfc2047_name( $name ) {
		$name = trim( $name );
		if ( '' === $name ) {
			$name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		}
		if ( ! preg_match( '/[^\x20-\x7E]/', $name ) ) {
			return $name;
		}
		return sprintf( '=?UTF-8?B?%s?=', base64_encode( $name ) );
	}

	/**
	 * @param string $text Template.
	 * @param object $row  DB row.
	 * @return string
	 */
	private static function replace_tokens_plain( $text, $row ) {
		$map = self::token_map_raw( $row );
		$out = str_replace( array_keys( $map ), array_values( $map ), $text );
		return sanitize_text_field( $out );
	}

	/**
	 * @param string $text Template (HTML allowed, already saved with kses).
	 * @param object $row  DB row.
	 * @return string
	 */
	private static function replace_tokens_html( $text, $row ) {
		$map = self::token_map_escaped( $row );
		return str_replace( array_keys( $map ), array_values( $map ), $text );
	}

	/**
	 * @param object $row DB row.
	 * @return array<string,string>
	 */
	private static function token_map_raw( $row ) {
		return array(
			'{{name}}'     => $row->donor_name,
			'{{email}}'    => $row->donor_email,
			'{{amount}}'   => number_format_i18n( (float) $row->receipt_amount_display, 2 ),
			'{{currency}}' => $row->currency,
			'{{site}}'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'{{gateway}}'  => $row->gateway,
		);
	}

	/**
	 * @param object $row DB row.
	 * @return array<string,string>
	 */
	private static function token_map_escaped( $row ) {
		$raw = self::token_map_raw( $row );
		$out = array();
		foreach ( $raw as $k => $v ) {
			$out[ $k ] = esc_html( (string) $v );
		}
		return $out;
	}

	/**
	 * Default HTML body when template is empty.
	 *
	 * @return string
	 */
	private static function default_donor_template() {
		return '<p>Dear {{name}},</p><p>Thank you for your donation of {{currency}} {{amount}}.</p><p>With gratitude,<br />{{site}}</p>';
	}
}
