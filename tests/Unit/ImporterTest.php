<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\ConnectionTest;
use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Legacy\Importer;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Importer::class )]
final class ImporterTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
	}

	private function import(): void {
		$logger = new Logger();
		( new Importer( $this->settings, new ClientFactory( $this->settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) ), $logger ) )->import();
	}

	public function test_old_auto_invoicing_also_turns_on_invoicing_on_payment(): void {
		update_option( 'oblio_invoice_autogen', '1' );

		$this->import();

		$this->assertSame( 'yes', $this->settings->get( 'invoice_autogen' ) );
		$this->assertSame( 'yes', $this->settings->get( 'invoice_on_payment' ) );
	}

	public function test_without_old_auto_invoicing_payment_stays_off(): void {
		update_option( 'oblio_invoice_autogen', '0' );

		$this->import();

		$this->assertSame( 'no', $this->settings->get( 'invoice_on_payment' ) );
	}

	public function test_the_old_plugins_placeholder_is_imported_as_no_selection(): void {
		update_option( 'oblio_series_name', 'FCT' );
		update_option( 'oblio_series_name_proforma', 'Selecteaza' );
		update_option( 'oblio_workstation', 'Selecteaza' );
		update_option( 'oblio_management', 'Selecteaza' );

		$this->import();

		$this->assertSame( 'FCT', get_option( 'oblio_fgwoo_series_invoice' ) );
		$this->assertSame( '', get_option( 'oblio_fgwoo_series_proforma' ) );
		$this->assertSame( '', get_option( 'oblio_fgwoo_workstation' ) );
		$this->assertSame( '', get_option( 'oblio_fgwoo_management' ) );
	}

	public function test_the_old_plugins_stock_flag_is_imported(): void {
		update_option( 'oblio_cui', 'RO123' );
		update_option( 'oblio_use_stock', 0 );

		$this->import();

		$this->assertSame( array( 'RO123' => false ), get_option( ConnectionTest::USE_STOCK_OPTION ) );
		$this->assertFalse( NomenclatureCache::uses_stock() );
	}

	public function test_a_stock_flag_already_loaded_from_oblio_wins(): void {
		update_option( 'oblio_cui', 'RO123' );
		update_option( 'oblio_use_stock', 0 );
		update_option( ConnectionTest::USE_STOCK_OPTION, array( 'RO123' => true ) );

		$this->import();

		$this->assertTrue( NomenclatureCache::uses_stock() );
	}

	public function test_a_missing_old_stock_flag_stays_unknown(): void {
		update_option( 'oblio_cui', 'RO123' );

		$this->import();

		$this->assertNull( NomenclatureCache::uses_stock() );
	}
}
