<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Admin\VatCategoryCheck;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( VatCategoryCheck::class )]
final class VatCategoryCheckTest extends TestCase {

	private InMemoryTaxRateStore $store;

	protected function setUp(): void {
		oblio_test_reset();
		$this->store = new InMemoryTaxRateStore();
	}

	private function render(): string {
		$settings     = new Settings();
		$logger       = new Logger();
		$factory      = new ClientFactory( $settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		$nomenclature = new NomenclatureCache( $factory, $settings, $logger, new Scheduler() );

		ob_start();
		( new VatCategoryCheck( $nomenclature, $this->store ) )->render_field( array() );
		return (string) ob_get_clean();
	}

	public function test_lists_missing_rates_with_the_recommended_name_and_countries(): void {
		oblio_test_seed_vat_categories();
		$this->store->add_row( 'RO', 21.0 );
		$this->store->add_row( 'FR', 20.0 );
		$this->store->add_row( 'AT', 20.0 );
		$this->store->add_row( '', 27.0 );
		$this->store->other_classes[] = array( 'id' => 900, 'country' => 'BE', 'state' => '', 'rate' => 6.0 );

		$html = $this->render();

		$this->assertStringContainsString( 'Cote lipsă în Oblio', $html );
		$this->assertStringContainsString( '<a href="https://www.oblio.eu/account/cote_tva" target="_blank" rel="noopener">Oblio → Setări → Cote TVA</a>', $html );
		$this->assertStringContainsString( '<span class="rate">20%</span> <code>20</code> <span>FR, AT</span>', $html );
		$this->assertStringContainsString( '<code>27</code> <span>restul lumii</span>', $html );
		$this->assertStringContainsString( '<code>6</code> <span>BE</span>', $html );
		$this->assertStringNotContainsString( '<code>21</code>', $html );
	}

	public function test_renders_nothing_when_every_rate_has_a_category(): void {
		oblio_test_seed_vat_categories();
		$this->store->add_row( 'RO', 21.0 );
		$this->store->add_row( 'FI', 25.5 );

		$this->assertSame( '', $this->render() );
	}

	public function test_renders_nothing_before_the_categories_are_fetched(): void {
		$this->store->add_row( 'FR', 20.0 );

		$this->assertSame( '', $this->render() );
	}
}
