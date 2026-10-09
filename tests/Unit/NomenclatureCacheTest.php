<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( NomenclatureCache::class )]
final class NomenclatureCacheTest extends TestCase {

	private NomenclatureCache $cache;

	protected function setUp(): void {
		oblio_test_reset();
		$settings = new Settings();
		$settings->set( 'email', 'shop@example.test' );
		$settings->set( 'secret', 'token' );
		$settings->set( 'cif', 'RO123' );
		$logger      = new Logger();
		$factory     = new ClientFactory( $settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		$this->cache = new NomenclatureCache( $factory, $settings, $logger, new Scheduler() );
	}

	/**
	 * @param array<int|string,mixed> $data
	 */
	private function respond( array $data ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $data ),
		);
	}

	public function test_prime_also_caches_the_vat_categories(): void {
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array( array( 'name' => 'SDD', 'percent' => 0, 'default' => false ) ) ) );

		$this->assertTrue( $this->cache->prime() );

		$this->assertSame( array( array( 'name' => 'SDD', 'percent' => 0, 'default' => false ) ), $this->cache->vat_categories() );
	}

	private function respond_error( int $code, string $message ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( array( 'statusMessage' => $message ) ),
		);
	}

	public function test_an_account_without_warehouses_still_loads_the_vat_categories(): void {
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( array( 'name' => 'FCT', 'type' => 'Factura' ) ) ) );
		$this->respond_error( 400, 'Nu aveti acces la nici o gestiune sau nu ati adaugat nici o gestiune in cont' );
		$this->respond( array( 'data' => array( array( 'name' => 'SFDD', 'percent' => 0, 'default' => false ) ) ) );

		$this->assertTrue( $this->cache->prime() );

		$this->assertSame( array( 'FCT' => 'FCT' ), $this->cache->series( 'Factura' ) );
		$this->assertSame( array(), $this->cache->managements() );
		$this->assertSame( 'SFDD', $this->cache->vat_categories()[0]['name'] );
		$this->assertTrue( $this->cache->is_complete() );
		$this->assertTrue( NomenclatureCache::has_no_warehouses() );
		$this->assertSame( array(), array_filter( $GLOBALS['oblio_test_wc_logs'], static fn ( array $log ): bool => 'error' === $log['level'] ) );
	}

	public function test_a_company_without_stock_skips_the_warehouse_call(): void {
		update_option( 'oblio_fgwoo_companies_use_stock', array( 'RO123' => false ) );
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array( array( 'name' => 'SDD', 'percent' => 0, 'default' => false ) ) ) );

		$this->assertTrue( $this->cache->prime() );

		$this->assertNotContains( true, array_map( static fn ( array $call ): bool => str_contains( (string) $call['url'], 'management' ), $GLOBALS['oblio_test_http_calls'] ) );
		$this->assertSame( 'SDD', $this->cache->vat_categories()[0]['name'] );
		$this->assertTrue( NomenclatureCache::has_no_warehouses() );
	}

	public function test_a_company_with_stock_is_not_treated_as_stockless(): void {
		update_option( 'oblio_fgwoo_companies_use_stock', array( 'RO123' => true ) );

		$this->assertTrue( NomenclatureCache::uses_stock() );
		$this->assertFalse( NomenclatureCache::has_no_warehouses() );
	}

	public function test_one_failing_list_does_not_block_the_others(): void {
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond_error( 500, 'Server error' );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array( array( 'name' => 'SDD', 'percent' => 0, 'default' => false ) ) ) );

		$this->assertFalse( $this->cache->prime() );

		$this->assertSame( 'SDD', $this->cache->vat_categories()[0]['name'] );
		$this->assertFalse( $this->cache->is_complete() );
	}

	public function test_refresh_clears_the_vat_categories(): void {
		oblio_test_seed_vat_categories();

		$this->cache->refresh();

		$this->assertSame( array(), $this->cache->vat_categories() );
		$this->assertArrayNotHasKey( VatCategories::TRANSIENT, $GLOBALS['oblio_test_transients'] );
	}
}
