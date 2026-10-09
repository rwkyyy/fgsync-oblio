<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Customer\BuyerProfile;
use FGSyncOblio\Customer\BuyerResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( BuyerResolver::class )]
#[CoversClass( BuyerProfile::class )]
final class BuyerResolverTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	private function resolver(): BuyerResolver {
		return new BuyerResolver();
	}

	/**
	 * @param array<string,mixed> $facturare
	 */
	private function order( array $facturare, string $country = 'RO', string $company = 'ACME SRL' ): WC_Order {
		$order = new WC_Order( 1 );
		$order->set_billing(
			array(
				'country' => $country,
				'company' => $company,
			)
		);
		$order->update_meta_data( 'av_facturare', $facturare );
		return $order;
	}

	public function test_persoana_juridica_takes_the_company_data(): void {
		$profile = $this->resolver()->resolve(
			$this->order(
				array(
					'tip_facturare' => 'pers-jur',
					'cui'           => ' RO40663762 ',
					'cnp'           => '1800101123456',
					'nr_reg_com'    => 'J03/1/2019',
					'nume_banca'    => 'ING',
					'iban'          => 'RO49INGB0000999900000000',
				)
			)
		);

		$this->assertTrue( $profile->is_business );
		$this->assertSame(
			array( 'ACME SRL', 'RO40663762', '', 'J03/1/2019', 'ING', 'RO49INGB0000999900000000', BuyerProfile::SOURCE_FACTURARE ),
			array( $profile->company, $profile->tax_id, $profile->cnp, $profile->registration, $profile->bank, $profile->iban, $profile->source )
		);
		$this->assertSame( 'RO40663762', $profile->oblio_cif() );
	}

	public function test_persoana_fizica_ignores_a_stale_cui_and_company(): void {
		$profile = $this->resolver()->resolve(
			$this->order(
				array(
					'tip_facturare' => 'pers-fiz',
					'cui'           => 'RO40663762',
					'cnp'           => '1800101123456',
					'iban'          => 'RO49INGB0000999900000000',
				)
			)
		);

		$this->assertFalse( $profile->is_business );
		$this->assertSame( array( '', '', '1800101123456', '' ), array( $profile->company, $profile->tax_id, $profile->cnp, $profile->iban ) );
		$this->assertSame( '1800101123456', $profile->oblio_cif() );
	}

	public function test_without_a_buyer_type_a_cnp_means_an_individual(): void {
		$profile = $this->resolver()->resolve( $this->order( array( 'cnp' => '1800101123456' ) ) );

		$this->assertFalse( $profile->is_business );
		$this->assertSame( array( '1800101123456', '', BuyerProfile::SOURCE_DETECTED ), array( $profile->cnp, $profile->tax_id, $profile->source ) );
	}

	public function test_without_a_buyer_type_a_tax_id_means_a_business(): void {
		$order = $this->order( array() );
		$order->update_meta_data( '_billing_cif', 'RO40663763' );

		$profile = $this->resolver()->resolve( $order );

		$this->assertTrue( $profile->is_business );
		$this->assertSame( 'RO40663763', $profile->tax_id );
	}

	public function test_bank_and_iban_come_from_facturare_meta_without_a_type_too(): void {
		$profile = $this->resolver()->resolve( $this->order( array( 'cui' => 'RO40663762', 'nume_banca' => 'BT', 'iban' => 'RO09BTRL0000000000000000' ) ) );

		$this->assertSame( array( 'BT', 'RO09BTRL0000000000000000' ), array( $profile->bank, $profile->iban ) );
	}

	public function test_profile_filter_can_replace_the_result(): void {
		$custom = new BuyerProfile( true, 'DE', 'Custom', 'DE123456789', '', '', '', '', 'custom' );
		$GLOBALS['oblio_test_filter_overrides']['oblio_fgwoo_buyer_profile'] = $custom;

		$this->assertSame( $custom, $this->resolver()->resolve( $this->order( array( 'tip_facturare' => 'pers-fiz' ) ) ) );
	}
}
