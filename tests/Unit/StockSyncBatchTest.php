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
			new ProductUpdater( new Logger() ),
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
}
