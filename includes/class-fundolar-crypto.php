<?php
/**
 * Encrypt/decrypt sensitive option values using WordPress salts.
 *
 * @package Fundolar
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class Fundolar_Crypto
 */
class Fundolar_Crypto {

	/**
	 * Derive encryption key.
	 *
	 * @return string Binary key.
	 */
	private static function key() {
		return hash( 'sha256', wp_salt( 'auth' ) . wp_salt( 'secure_auth' ), true );
	}

	/**
	 * Encrypt plaintext.
	 *
	 * @param string $plain Plain text.
	 * @return string Base64 payload or empty.
	 */
	public static function encrypt( $plain ) {
		if ( '' === (string) $plain ) {
			return '';
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return base64_encode( '::plain::' . $plain );
		}
		$iv = random_bytes( 16 );
		$tag = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		if ( false === $cipher ) {
			return '';
		}
		return base64_encode( $iv . $tag . $cipher );
	}

	/**
	 * Decrypt payload.
	 *
	 * @param string $encoded Encrypted string.
	 * @return string
	 */
	public static function decrypt( $encoded ) {
		if ( '' === (string) $encoded ) {
			return '';
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}
		$decoded_try = base64_decode( $encoded, true );
		if ( is_string( $decoded_try ) && 0 === strpos( $decoded_try, '::plain::' ) ) {
			return substr( $decoded_try, 9 );
		}
		if ( ! function_exists( 'openssl_decrypt' ) || strlen( $raw ) < 33 ) {
			return '';
		}
		$iv   = substr( $raw, 0, 16 );
		$tag  = substr( $raw, 16, 16 );
		$ct   = substr( $raw, 32 );
		$out  = openssl_decrypt( $ct, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $out ? '' : $out;
	}
}
