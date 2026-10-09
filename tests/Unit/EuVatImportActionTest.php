<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\EuVatImportAction;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Tax\EuVatRateImporter;
use FGSyncOblio\Tax\EuVatRates;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( EuVatImportAction::class )]
final class EuVatImportActionTest extends TestCase {

	private InMemoryTaxRateStore $store;

	protected function setUp(): void {
		oblio_test_reset();
		$_GET        = array();
		$this->store = new InMemoryTaxRateStore();
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';
	}

	protected function tearDown(): void {
		$_GET = array();
	}

	private function render(): string {
		ob_start();
		( new EuVatImportAction( new EuVatRateImporter( $this->store ), new Logger() ) )->render_field( array() );
		return (string) ob_get_clean();
	}

	public function test_an_empty_store_previews_every_country_and_offers_the_button(): void {
		$html = $this->render();

		$this->assertStringContainsString( '<span class="oblio-fgwoo-chip is-new">FI 25.5%</span>', $html );
		$this->assertStringContainsString( '<span class="oblio-fgwoo-chip is-new">restul lumii 21%</span>', $html );
		$this->assertStringContainsString( 'Cote noi ce pot fi importate: (28)', $html );
		$this->assertStringContainsString( 'action=' . EuVatImportAction::ACTION, $html );
		$this->assertStringContainsString( EuVatRates::bundled()['fetched_at'], $html );
		$this->assertStringContainsString( 'page=wc-settings&amp;tab=tax&amp;section=standard', $html );
	}

	public function test_the_button_comes_after_the_explanation_and_the_planned_changes(): void {
		$html = $this->render();

		$this->assertLessThan( strpos( $html, 'action=' . EuVatImportAction::ACTION ), strpos( $html, 'Ce face importul' ) );
		$this->assertLessThan( strpos( $html, 'action=' . EuVatImportAction::ACTION ), strpos( $html, 'Cote noi' ) );
	}

	public function test_nothing_to_import_hides_the_button(): void {
		( new EuVatRateImporter( $this->store ) )->import( EuVatRates::bundled(), 'RO', 'TVA' );

		$html = $this->render();

		$this->assertStringContainsString( 'nu este nimic de importat', $html );
		$this->assertStringNotContainsString( 'action=' . EuVatImportAction::ACTION, $html );
	}

	public function test_the_shops_own_rows_are_listed_as_kept(): void {
		$this->store->add_row( 'RO', 19.0 );

		$html = $this->render();

		$this->assertStringContainsString( 'Rândurile tale, neschimbate (1)', $html );
		$this->assertStringContainsString( '<span class="oblio-fgwoo-chip ">RO</span>', $html );
	}

	public function test_shows_the_result_after_an_import(): void {
		$_GET['oblio_fgwoo_eu_vat'] = '28-0-0';

		$this->assertStringContainsString( 'Cote TVA importate: 28 adăugate, 0 actualizate, 0 păstrate.', $this->render() );
	}

	public function test_shows_a_failed_import(): void {
		$_GET['oblio_fgwoo_eu_vat'] = 'error';

		$this->assertStringContainsString( 'Importul cotelor TVA a eșuat', $this->render() );
	}

	private function notice(): string {
		ob_start();
		( new EuVatImportAction( new EuVatRateImporter( $this->store ), new Logger() ) )->update_notice();
		return (string) ob_get_clean();
	}

	private function import_then_drift(): void {
		( new EuVatRateImporter( $this->store ) )->import( EuVatRates::bundled(), 'RO', 'TVA' );
		foreach ( $this->store->rows as $id => $row ) {
			if ( 'FI' === $row['country'] ) {
				$this->store->update_rate( $id, 24.0 );
			}
		}
	}

	public function test_update_notice_on_fgsync_and_woocommerce_tax_screens(): void {
		$this->import_then_drift();

		$_GET = array( 'page' => 'fgsync-oblio' );
		$this->assertStringContainsString( 'cote TVA UE noi', $this->notice() );
		$this->assertStringContainsString( 'adaugă și o categorie în <a href="https://www.oblio.eu/account/cote_tva"', $this->notice() );

		$_GET = array(
			'page' => 'wc-settings',
			'tab'  => 'tax',
		);
		$this->assertStringContainsString( 'cote TVA UE noi', $this->notice() );
	}

	public function test_no_update_notice_elsewhere_or_when_up_to_date(): void {
		$_GET = array( 'page' => 'fgsync-oblio' );
		$this->assertSame( '', $this->notice() );

		$this->import_then_drift();
		$_GET = array( 'page' => 'wc-orders' );
		$this->assertSame( '', $this->notice() );
	}

	public function test_the_preview_lists_rate_changes_to_imported_rows(): void {
		$this->import_then_drift();

		$html = $this->render();

		$this->assertStringContainsString( '<span class="oblio-fgwoo-chip is-changed">FI 24% → 25.5%</span>', $html );
		$this->assertStringContainsString( 'Cote lipsă în Oblio', $html );
	}
}
