<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Queue\Jobs\StockSyncBatch;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Stock\LocationAggregator;
use FGSyncOblio\Stock\ProductUpdater;
use FGSyncOblio\Stock\ReservationUnavailableException;
use FGSyncOblio\Stock\StockReservations;
use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Support\AtomicLock;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass( \FGSyncOblio\Queue\Jobs\StockSyncBatch::class )]
final class StockSyncBatchTest extends TestCase {

	private StockSyncBatch $batch;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->batch    = new StockSyncBatch(
			$this->settings,
			new ClientFactory( $this->settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter() ),
			new Scheduler(),
			new LocationAggregator(),
			new ProductUpdater( new Logger(), $this->settings ),
			new StockReservations( new OrderStore(), new Logger() ),
			new Logger()
		);
	}

	private function process_page( array $products, string $token ): int {
		return ( new ReflectionMethod( StockSyncBatch::class, 'process_page' ) )->invoke( $this->batch, $products, $token );
	}

	/**
	 * A failed reservation query must abort the page before any product
	 * write, not fail open with an empty (indistinguishable from
	 * legitimately-zero) reservation map.
	 */
	public function test_a_reservation_query_failure_aborts_the_page_before_any_product_is_touched(): void {
		$this->settings->set( 'stock_reserve_orders', 'yes' );
		$GLOBALS['wpdb']->last_error = 'Unknown column';

		$this->expectException( ReservationUnavailableException::class );

		$this->process_page( array( array( 'code' => 'SKU-1' ) ), 'token' );
	}

	public function test_reservations_disabled_never_queries_and_never_throws(): void {
		$this->settings->set( 'stock_reserve_orders', 'no' );
		$GLOBALS['wpdb']->last_error = 'Unknown column';

		$updated = $this->process_page( array(), 'token' );

		$this->assertSame( 0, $updated );
		$this->assertCount( 0, $GLOBALS['wpdb']->get_results_calls );
	}

	/**
	 * process_page() must not swallow an unrelated processing error (a
	 * filter, product load, or $product->save() throwing) - it has to
	 * propagate out uncaught so run()'s try/catch can route it through
	 * handle_failure() instead of the exception silently killing the run.
	 * wc_get_product_id_by_sku() isn't stubbed in this harness, so a
	 * non-empty product list reaching the update step throws a plain \Error
	 * here, standing in for any of those real-world failure sources.
	 */
	public function test_an_unrelated_processing_error_propagates_out_of_process_page_uncaught(): void {
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, 'token' );
		$this->expectException( \Error::class );

		$this->process_page( array( array( 'code' => 'SKU-1', 'price' => '10' ) ), 'token' );
	}

	/**
	 * Two pages, each skipping products for a type mismatch: the run keeps
	 * the total and the first codes, and they outlive the run for the
	 * completion message and the Stare tab.
	 */
	public function test_a_finished_run_keeps_the_products_skipped_for_their_type(): void {
		$owner = AtomicLock::acquire( StockSyncCoordinator::RUN_LOCK, StockSyncCoordinator::RUN_LOCK_TTL );
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, $owner );
		$record = new ReflectionMethod( StockSyncBatch::class, 'record_progress' );

		$codes = array_map( static fn ( int $index ): string => 'TORT-' . $index, range( 1, 12 ) );
		$record->invoke( $this->batch, 250, 3, array_slice( $codes, 0, 8 ), $owner );
		$record->invoke( $this->batch, 40, 1, array_slice( $codes, 8 ), $owner );
		( new ReflectionMethod( StockSyncBatch::class, 'finalize' ) )->invoke( $this->batch, $owner );

		$result = get_option( StockSyncCoordinator::LAST_RESULT_OPTION );
		$this->assertSame( 290, $result['scanned'] );
		$this->assertSame( 4, $result['updated'] );
		$this->assertSame( 12, $result['skipped'] );
		$this->assertSame( array_slice( $codes, 0, StockSyncCoordinator::SKIPPED_CODES_SHOWN ), $result['codes'] );
	}

	public function test_a_page_hands_its_type_skips_to_the_run_counters(): void {
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, 'token' );
		$GLOBALS['oblio_test_products'][7]        = new \WC_Product( array( 'id' => 7 ) );
		$GLOBALS['wpdb']->next_get_results_return = array(
			array(
				'sku'        => 'TORT-05',
				'product_id' => 7,
			),
		);

		$this->process_page(
			array(
				array(
					'code'        => 'TORT-05',
					'productType' => 'Produs finit',
					'price'       => '10',
				),
			),
			'token'
		);

		$this->assertSame( array( 'TORT-05' ), ( new \ReflectionProperty( StockSyncBatch::class, 'type_skips' ) )->getValue( $this->batch ) );
	}
}
