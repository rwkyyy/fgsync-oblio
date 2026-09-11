<?php
/**
 * Access-token storage (encrypted, with expiry).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Api;

use OblioWoo\Support\Encryption;
final class TokenStore {

	private const OPTION        = 'oblio_fgwoo_access_token';
	private const SAFETY_MARGIN = 60;

	private Encryption $encryption;

	private bool $persistent;

	private string $memory = '';

	public function __construct( Encryption $encryption, bool $persistent = true ) {
		$this->encryption = $encryption;
		$this->persistent = $persistent;
	}

	public function get(): ?array {
		$stored = $this->persistent ? (string) get_option( self::OPTION, '' ) : $this->memory;
		if ( '' === $stored ) {
			return null;
		}

		$json  = $this->encryption->decrypt( $stored );
		$token = json_decode( $json, true );
		if ( ! is_array( $token ) || empty( $token['access_token'] ) ) {
			return null;
		}

		$expires_at = (int) ( $token['stored_at'] ?? 0 ) + (int) ( $token['expires_in'] ?? 0 );
		if ( $expires_at - self::SAFETY_MARGIN <= time() ) {
			return null;
		}

		return $token;
	}

	public function set( array $payload ): void {
		$token = array(
			'access_token' => (string) ( $payload['access_token'] ?? '' ),
			'token_type'   => (string) ( $payload['token_type'] ?? 'Bearer' ),
			'expires_in'   => (int) ( $payload['expires_in'] ?? 3600 ),
			'stored_at'    => time(),
		);

		$encoded = $this->encryption->encrypt( (string) wp_json_encode( $token ) );
		if ( $this->persistent ) {
			update_option( self::OPTION, $encoded, false );
		} else {
			$this->memory = $encoded;
		}
	}

	public function clear(): void {
		if ( $this->persistent ) {
			delete_option( self::OPTION );
		} else {
			$this->memory = '';
		}
	}
}
