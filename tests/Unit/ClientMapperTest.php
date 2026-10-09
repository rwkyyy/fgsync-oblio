<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Document\Mapper\ClientMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Document\Mapper\ClientMapper::class )]
final class ClientMapperTest extends TestCase {

	private ClientMapper $mapper;

	protected function setUp(): void {
		oblio_test_reset();
		$this->mapper = new ClientMapper();
	}

	public function test_reads_cif_from_av_facturare_cui(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cui' => 'RO123456' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO123456', $client['cif'] );
	}

	public function test_reads_cif_from_av_facturare_cnp_when_cui_absent(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cnp' => '1234567890123' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame( '1234567890123', $client['cif'] );
	}

	public function test_reads_rc_from_av_facturare_nr_reg_com(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'nr_reg_com' => 'J40/1234/2020' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'J40/1234/2020', $client['rc'] );
	}

	public function test_falls_back_to_curiero_when_av_facturare_absent(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data(
			'curiero_pf_pj_option',
			array(
				'cui'        => 'RO999',
				'nr_reg_com' => 'J40/999/2020',
			)
		);

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO999', $client['cif'] );
		$this->assertSame( 'J40/999/2020', $client['rc'] );
	}

	public function test_av_facturare_takes_priority_over_curiero(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cui' => 'RO-AV' ) );
		$order->update_meta_data( 'curiero_pf_pj_option', array( 'cui' => 'RO-CURIERO' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO-AV', $client['cif'] );
	}

	public function test_falls_back_to_regex_scan_when_no_legacy_fields_present(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( '_billing_cif', 'RO555' );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO555', $client['cif'] );
	}

	public function test_client_field_filter_still_overrides_legacy_fields(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cui' => 'RO123456' ) );
		$GLOBALS['oblio_test_filter_overrides']['oblio_fgwoo_client_field'] = 'FORCED';

		$client = $this->mapper->map( $order );

		$this->assertSame( 'FORCED', $client['cif'] );
	}

	public function test_iban_and_bank_unaffected_by_av_facturare_presence(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cui' => 'RO123456' ) );
		$order->update_meta_data( 'billing_iban', 'RO49AAAA1B31007593840000' );
		$order->update_meta_data( 'billing_bank', 'Test Bank' );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO49AAAA1B31007593840000', $client['iban'] );
		$this->assertSame( 'Test Bank', $client['bank'] );
	}

	public function test_empty_av_facturare_cui_falls_through_to_the_cnp(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cui' => ' ', 'cnp' => '1800101123456' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame( '1800101123456', $client['cif'] );
	}

	public function test_empty_legacy_fields_fall_through_to_the_regex_scan(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'av_facturare', array( 'cui' => '', 'nr_reg_com' => '' ) );
		$order->update_meta_data( '_billing_cif', 'RO555' );
		$order->update_meta_data( '_billing_rc', 'J40/1/2020' );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO555', $client['cif'] );
		$this->assertSame( 'J40/1/2020', $client['rc'] );
	}

	public function test_regex_scan_skips_empty_matches(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( '_billing_cif', '' );
		$order->update_meta_data( '_billing_cui', 'RO777' );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'RO777', $client['cif'] );
	}

	public function test_rc_and_bank_suffixes_ignore_unrelated_meta(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( '_tracking_src', 'newsletter' );
		$order->update_meta_data( '_piggybank', 'yes' );

		$client = $this->mapper->map( $order );

		$this->assertSame( '', $client['rc'] );
		$this->assertSame( '', $client['bank'] );
	}

	public function test_rc_and_bank_keys_still_match(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( 'rc', 'J40/2/2021' );
		$order->update_meta_data( '_billing_bank_name', 'Test Bank' );

		$client = $this->mapper->map( $order );

		$this->assertSame( 'J40/2/2021', $client['rc'] );
		$this->assertSame( 'Test Bank', $client['bank'] );
	}

	public function test_persoana_juridica_is_invoiced_as_the_company(): void {
		$order = new WC_Order( 1 );
		$order->set_billing( array( 'first_name' => 'Ion', 'last_name' => 'Pop', 'company' => 'ACME SRL' ) );
		$order->update_meta_data( 'av_facturare', array( 'tip_facturare' => 'pers-jur', 'cui' => 'RO40663762', 'nr_reg_com' => 'J03/1/2019', 'nume_banca' => 'ING', 'iban' => 'RO49INGB0000999900000000' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame(
			array( 'RO40663762', 'ACME SRL', 'J03/1/2019', 'ING', 'RO49INGB0000999900000000', 'Ion Pop' ),
			array( $client['cif'], $client['name'], $client['rc'], $client['bank'], $client['iban'], $client['contact'] )
		);
	}

	public function test_persoana_fizica_is_invoiced_as_the_person_even_with_a_company_filled(): void {
		$order = new WC_Order( 1 );
		$order->set_billing( array( 'first_name' => 'Ion', 'last_name' => 'Pop', 'company' => 'ACME SRL' ) );
		$order->update_meta_data( 'av_facturare', array( 'tip_facturare' => 'pers-fiz', 'cnp' => '1800101123456', 'cui' => 'RO40663762' ) );

		$client = $this->mapper->map( $order );

		$this->assertSame( array( '1800101123456', 'Ion Pop', '' ), array( $client['cif'], $client['name'], $client['rc'] ) );
	}
}
