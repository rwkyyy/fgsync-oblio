<?php
/**
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Tests\Unit;

use OblioWoo\Support\Encryption;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \OblioWoo\Support\Encryption::class )]
final class EncryptionTest extends TestCase {

	private Encryption $encryption;

	protected function setUp(): void {
		$this->encryption = new Encryption();
	}

	public function test_round_trip_including_utf8(): void {
		$plaintext  = 'oblio-secret-Ω-ăîșț-123';
		$ciphertext = $this->encryption->encrypt( $plaintext );

		$this->assertNotSame( $plaintext, $ciphertext );
		$this->assertTrue( $this->encryption->is_encrypted( $ciphertext ) );
		$this->assertSame( $plaintext, $this->encryption->decrypt( $ciphertext ) );
	}

	public function test_plaintext_passes_through_on_decrypt(): void {
		$this->assertSame( 'legacy-plain', $this->encryption->decrypt( 'legacy-plain' ) );
	}

	public function test_empty_stays_empty(): void {
		$this->assertSame( '', $this->encryption->encrypt( '' ) );
	}

	public function test_decrypts_legacy_cbc_values(): void {
		$key_method = new \ReflectionMethod( $this->encryption, 'key' );
		$key_method->setAccessible( true );
		$key = $key_method->invoke( $this->encryption );

		$plaintext = 'legacy-cbc-secret-ăîșț';
		$iv        = random_bytes( (int) openssl_cipher_iv_length( 'aes-256-cbc' ) );
		$cipher    = openssl_encrypt( $plaintext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
		$legacy    = 'oblio_fgwoo$1$' . base64_encode( $iv . $cipher );

		$this->assertTrue( $this->encryption->is_encrypted( $legacy ) );
		$this->assertSame( $plaintext, $this->encryption->decrypt( $legacy ) );
	}

	public function test_tampered_gcm_value_fails_closed(): void {
		$ciphertext = $this->encryption->encrypt( 'authentic' );

		$tampered = substr( $ciphertext, 0, -2 ) . ( 'A' === substr( $ciphertext, -2, 1 ) ? 'B' : 'A' ) . substr( $ciphertext, -1 );
		$this->assertSame( '', $this->encryption->decrypt( $tampered ) );
	}
}
