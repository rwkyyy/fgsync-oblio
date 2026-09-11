<?php
/**
 * At-rest encryption for secrets, keyed off WordPress salts.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class Encryption {

	private const PREFIX     = 'oblio_fgwoo$2$';
	private const PREFIX_CBC = 'oblio_fgwoo$1$';
	private const CIPHER     = 'aes-256-gcm';
	private const CIPHER_CBC = 'aes-256-cbc';
	private const TAG_LEN    = 16;

	public function is_available(): bool {
		return function_exists( 'openssl_encrypt' ) && in_array( self::CIPHER, openssl_get_cipher_methods(), true );
	}

	public function encrypt( string $plaintext ): string {
		if ( '' === $plaintext || ! $this->is_available() ) {
			return $plaintext;
		}

		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_len ) {
			return $plaintext;
		}
		$iv     = random_bytes( $iv_len );
		$tag    = '';
		$cipher = openssl_encrypt( $plaintext, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag, '', self::TAG_LEN );
		if ( false === $cipher ) {
			return $plaintext;
		}
		return self::PREFIX . base64_encode( $iv . $tag . $cipher );
	}

	public function decrypt( string $value ): string {
		if ( 0 === strpos( $value, self::PREFIX ) ) {
			return $this->decrypt_gcm( substr( $value, strlen( self::PREFIX ) ) );
		}
		if ( 0 === strpos( $value, self::PREFIX_CBC ) ) {
			return $this->decrypt_cbc( substr( $value, strlen( self::PREFIX_CBC ) ) );
		}
		return $value;
	}

	private function decrypt_gcm( string $encoded ): string {
		if ( ! $this->is_available() ) {
			return '';
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER );
		if ( false === $iv_len || strlen( $raw ) <= $iv_len + self::TAG_LEN ) {
			return '';
		}
		$iv        = substr( $raw, 0, $iv_len );
		$tag       = substr( $raw, $iv_len, self::TAG_LEN );
		$cipher    = substr( $raw, $iv_len + self::TAG_LEN );
		$plaintext = openssl_decrypt( $cipher, self::CIPHER, $this->key(), OPENSSL_RAW_DATA, $iv, $tag );

		return false === $plaintext ? '' : $plaintext;
	}

	private function decrypt_cbc( string $encoded ): string {
		if ( ! function_exists( 'openssl_decrypt' ) || ! in_array( self::CIPHER_CBC, openssl_get_cipher_methods(), true ) ) {
			return '';
		}
		$raw = base64_decode( $encoded, true );
		if ( false === $raw ) {
			return '';
		}
		$iv_len = openssl_cipher_iv_length( self::CIPHER_CBC );
		if ( false === $iv_len || strlen( $raw ) <= $iv_len ) {
			return '';
		}
		$iv        = substr( $raw, 0, $iv_len );
		$cipher    = substr( $raw, $iv_len );
		$plaintext = openssl_decrypt( $cipher, self::CIPHER_CBC, $this->key(), OPENSSL_RAW_DATA, $iv );

		return false === $plaintext ? '' : $plaintext;
	}

	public function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX ) || 0 === strpos( $value, self::PREFIX_CBC );
	}

	private function key(): string {
		$salt = defined( 'AUTH_KEY' ) && '' !== AUTH_KEY ? AUTH_KEY : wp_salt( 'auth' );
		return hash( 'sha256', 'oblio_fgwoo|' . $salt, true );
	}
}
