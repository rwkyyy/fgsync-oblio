<?php
/**
 * Action Scheduler job: sync one page of Oblio products into WooCommerce.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Queue\Jobs;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Queue\Scheduler;
use OblioWoo\Stock\LocationAggregator;
use OblioWoo\Stock\ProductUpdater;
use OblioWoo\Stock\StockReservations;
use OblioWoo\Stock\StockSyncCoordinator;
use OblioWoo\Support\AtomicLock;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
use Throwable;
final class StockSyncBatch {

	private const PAGE_SIZE    = 250;
	private const MAX_ATTEMPTS = 5;

	private Settings $settings;

	private ClientFactory $factory;

	private Scheduler $scheduler;

	private LocationAggregator $aggregator;

	private ProductUpdater $updater;

	private StockReservations $reservations;

	private Logger $logger;

	public function __construct(
		Settings $settings,
		ClientFactory $factory,
		Scheduler $scheduler,
		LocationAggregator $aggregator,
		ProductUpdater $updater,
		StockReservations $reservations,
		Logger $logger
	) {
		$this->settings     = $settings;
		$this->factory      = $factory;
		$this->scheduler    = $scheduler;
		$this->aggregator   = $aggregator;
		$this->updater      = $updater;
		$this->reservations = $reservations;
		$this->logger       = $logger;
	}

	public function register(): void {
		add_action( Scheduler::HOOK_STOCK_BATCH, array( $this, 'run' ) );
	}

	public function run( $payload ): void {
		$payload = is_array( $payload ) ? $payload : array();
		$offset  = (int) ( $payload['offset'] ?? 0 );
		$attempt = (int) ( $payload['attempt'] ?? 1 );
		$token   = (string) ( $payload['token'] ?? '' );

		$current = (string) get_option( StockSyncCoordinator::RUN_TOKEN_OPTION, '' );
		if ( '' !== $token && $token !== $current ) {
			return;
		}

		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			return;
		}

		try {
			$products = $this->factory->create()->nomenclature(
				'products',
				(string) $this->settings->get( 'cif' ),
				array( 'offset' => $offset )
			);
		} catch ( ApiException $exception ) {
			$this->handle_failure( $offset, $attempt, $token, $exception->status_message(), $exception->is_retryable() );
			return;
		} catch ( Throwable $exception ) {
			$this->handle_failure( $offset, $attempt, $token, $exception->getMessage(), true );
			return;
		}

		$selected     = array_values( (array) $this->settings->get( 'stock_locations' ) );
		$update_price = $this->settings->is_enabled( 'stock_update_price' );
		$reservations = $this->settings->is_enabled( 'stock_reserve_orders' ) ? $this->reservations->map() : array();

		$sku_map = $this->resolve_skus( $products );

		$updated = 0;
		foreach ( $products as $product ) {
			$agg = $this->aggregator->aggregate( (array) $product, $selected );

			$agg = apply_filters( 'oblio_fgwoo_stock_aggregate', $agg, (array) $product, $selected );
			if ( null === $agg ) {
				continue;
			}
			if ( $this->updater->update( (array) $product, $agg, $update_price, $reservations, $sku_map ) ) {
				++$updated;
			}
		}

		$this->record_progress( count( $products ), $updated );

		if ( count( $products ) >= self::PAGE_SIZE ) {
			$this->scheduler->enqueue_stock_batch( $offset + self::PAGE_SIZE, 1, 0, $token );
		} else {
			$this->finalize();
		}
	}

	private function resolve_skus( array $products ): array {
		global $wpdb;

		$codes = array();
		foreach ( $products as $product ) {
			$code = (string) ( ( (array) $product )['code'] ?? '' );
			if ( '' !== $code ) {
				$codes[ $code ] = true;
			}
		}
		if ( empty( $codes ) ) {
			return array();
		}
		$codes        = array_keys( $codes );
		$placeholders = implode( ',', array_fill( 0, count( $codes ), '%s' ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT sku, product_id FROM {$wpdb->prefix}wc_product_meta_lookup WHERE sku IN ($placeholders)",
				$codes
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$map = array();
		foreach ( (array) $rows as $row ) {
			$sku = (string) ( $row['sku'] ?? '' );
			if ( '' !== $sku && ! isset( $map[ $sku ] ) ) {
				$map[ $sku ] = (int) ( $row['product_id'] ?? 0 );
			}
		}
		return $map;
	}

	private function handle_failure( int $offset, int $attempt, string $token, string $reason, bool $retryable ): void {
		if ( $retryable && $attempt < self::MAX_ATTEMPTS ) {
			$delay = $this->scheduler->backoff( $attempt );
			$this->scheduler->enqueue_stock_batch( $offset, $attempt + 1, $delay, $token );
			$this->logger->warning( sprintf( 'Stock sync: page at offset %d failed (attempt %d): %s, retry in %ds', $offset, $attempt, $reason, $delay ) );
			return;
		}

		$this->logger->error( sprintf( 'Stock sync: page at offset %d aborted (attempt %d): %s', $offset, $attempt, $reason ) );
		delete_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		delete_option( StockSyncCoordinator::RUN_TOKEN_OPTION );
		AtomicLock::release( StockSyncCoordinator::RUN_LOCK );
		$this->reservations->reset();
	}

	private function record_progress( int $scanned, int $updated ): void {
		$progress = get_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		$progress = is_array( $progress ) ? $progress : array(
			'scanned' => 0,
			'updated' => 0,
		);

		$progress['scanned'] = (int) ( $progress['scanned'] ?? 0 ) + $scanned;
		$progress['updated'] = (int) ( $progress['updated'] ?? 0 ) + $updated;

		set_transient( StockSyncCoordinator::PROGRESS_TRANSIENT, $progress, DAY_IN_SECONDS );

		AtomicLock::renew( StockSyncCoordinator::RUN_LOCK, StockSyncCoordinator::RUN_LOCK_TTL );
	}

	private function finalize(): void {

		$progress = get_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		$scanned  = is_array( $progress ) ? (int) ( $progress['scanned'] ?? 0 ) : 0;
		$updated  = is_array( $progress ) ? (int) ( $progress['updated'] ?? 0 ) : 0;

		update_option( StockSyncCoordinator::LAST_SYNC_OPTION, time(), false );
		$this->logger->info( sprintf( 'Stock sync complete: updated %d of %d products', $updated, $scanned ) );

		delete_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		delete_option( StockSyncCoordinator::RUN_TOKEN_OPTION );
		AtomicLock::release( StockSyncCoordinator::RUN_LOCK );
		$this->reservations->reset();
	}
}
