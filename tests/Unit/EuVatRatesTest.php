<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Tax\EuVatRates;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

#[CoversClass( EuVatRates::class )]
final class EuVatRatesTest extends TestCase {

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private static function vatcomply(): array {
		$items = array();
		foreach ( array( 'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU', 'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK' ) as $code ) {
			$items[] = array(
				'country_code'       => $code,
				'standard_rate'      => 'FI' === $code ? 25.5 : 20.0,
				'reduced_rates'      => array( 10.0 ),
				'super_reduced_rate' => null,
				'parking_rate'       => null,
				'member_state'       => true,
				'rate_comments'      => 'long text',
			);
		}
		$items[] = array(
			'country_code'  => 'NO',
			'standard_rate' => 25.0,
			'member_state'  => false,
		);
		return $items;
	}

	public function test_builds_the_bundle_from_member_states_with_woocommerce_country_codes(): void {
		$bundle = EuVatRates::from_vatcomply( self::vatcomply(), '2026-10-01' );

		$this->assertCount( 27, $bundle['rates'] );
		$this->assertArrayHasKey( 'GR', $bundle['rates'] );
		$this->assertArrayNotHasKey( 'EL', $bundle['rates'] );
		$this->assertArrayNotHasKey( 'NO', $bundle['rates'] );
		$this->assertSame( array( 'standard' => 25.5, 'reduced' => array( 10.0 ), 'super_reduced' => null, 'parking' => null ), $bundle['rates']['FI'] );
		$this->assertSame( array( EuVatRates::SOURCE, '2026-10-01' ), array( $bundle['source'], $bundle['fetched_at'] ) );

		EuVatRates::validate( $bundle );
	}

	/**
	 * @return array<string,array{0:callable,1:string}>
	 */
	public static function broken_bundles(): array {
		return array(
			'a country missing'    => array( static fn ( array $bundle ): array => array_merge( $bundle, array( 'rates' => array_slice( $bundle['rates'], 1 ) ) ), 'Expected 27' ),
			'Romania missing'      => array(
				static function ( array $bundle ): array {
					unset( $bundle['rates']['RO'] );
					$bundle['rates']['XX'] = $bundle['rates']['DE'];
					return $bundle;
				},
				'Romania is missing',
			),
			'implausible rate'     => array(
				static function ( array $bundle ): array {
					$bundle['rates']['DE']['standard'] = 190.0;
					return $bundle;
				},
				'Implausible VAT rate for DE',
			),
			'zero standard rate'   => array(
				static function ( array $bundle ): array {
					$bundle['rates']['DE']['standard'] = 0.0;
					return $bundle;
				},
				'must be positive',
			),
			'missing rate value'   => array(
				static function ( array $bundle ): array {
					$bundle['rates']['DE']['reduced'] = array( 'n/a' );
					return $bundle;
				},
				'Implausible VAT rate for DE',
			),
			'bad fetch date'       => array( static fn ( array $bundle ): array => array_merge( $bundle, array( 'fetched_at' => 'yesterday' ) ), 'fetched_at' ),
		);
	}

	#[DataProvider( 'broken_bundles' )]
	public function test_validation_rejects_a_bad_bundle( callable $break, string $message ): void {
		$this->expectException( InvalidArgumentException::class );
		$this->expectExceptionMessage( $message );

		EuVatRates::validate( $break( EuVatRates::from_vatcomply( self::vatcomply(), '2026-10-01' ) ) );
	}

	public function test_same_rates_ignores_the_fetch_date(): void {
		$october  = EuVatRates::from_vatcomply( self::vatcomply(), '2026-10-01' );
		$november = EuVatRates::from_vatcomply( self::vatcomply(), '2026-11-01' );
		$changed  = $november;
		$changed['rates']['FI']['standard'] = 26.0;

		$this->assertTrue( EuVatRates::same_rates( $october, $november ) );
		$this->assertFalse( EuVatRates::same_rates( $october, $changed ) );
	}

	public function test_the_bundle_is_read_once_per_request(): void {
		$cache = new ReflectionProperty( EuVatRates::class, 'bundled' );
		$cache->setValue( null, null );

		$first = EuVatRates::bundled();
		$cache->setValue( null, array( 'fetched_at' => 'cached' ) + $first );

		$this->assertSame( 'cached', EuVatRates::bundled()['fetched_at'] );
		$cache->setValue( null, null );
	}

	public function test_the_shipped_bundle_is_valid(): void {
		$bundle = EuVatRates::bundled();

		$this->assertSame( 21.0, $bundle['rates']['RO']['standard'] );
	}
}
