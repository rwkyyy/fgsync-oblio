<?php
/**
 * Action Scheduler job: sync one page of Oblio products into WooCommerce.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue\Jobs;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Stock\LocationAggregator;
use FGSyncOblio\Stock\ProductUpdater;
use FGSyncOblio\Stock\ReservationUnavailableException;
use FGSyncOblio\Stock\StockReservations;
use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Support\AtomicLock;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use Throwable;
final class StockSyncBatch {

	private const PAGE_SIZE              = 250;
	private const MAX_ATTEMPTS           = 5;
	private const TOKEN_RECHECK_INTERVAL = 25;

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

		if ( ! $this->token_current( $token ) ) {
			return;
		}

		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			$this->logger->warning( 'Stock sync: credentials no longer configured mid-run, aborting' );
			$this->abort( $token );
			return;
		}

		try {
			$products = $this->fetch_page( $offset );
		} catch ( ApiException $exception ) {
			$this->handle_failure( $offset, $attempt, $token, $exception->status_message(), $exception->is_retryable() );
			return;
		} catch ( Throwable $exception ) {
			$this->handle_failure( $offset, $attempt, $token, $exception->getMessage(), true );
			return;
		}

		if ( ! $this->token_current( $token ) ) {
			return;
		}

		try {
			$updated = $this->process_page( $products, $token );
		} catch ( Throwable $exception ) {
			// A filter, product load, or $product->save() can throw too - any of
			// them escaping here would otherwise abandon the run without a
			// retry and without ever scheduling the next page.
			$this->handle_failure( $offset, $attempt, $token, $exception->getMessage(), true );
			return;
		}
		$totals = $this->record_progress( count( $products ), $updated, $token );
		$this->log_page( $offset, count( $products ), $updated, $totals );

		if ( ! $this->token_current( $token ) ) {
			return;
		}

		if ( count( $products ) >= self::PAGE_SIZE ) {
			if ( ! $this->scheduler->enqueue_stock_batch( $offset + self::PAGE_SIZE, 1, 0, $token ) ) {
				$this->logger->error( sprintf( 'Stock sync: could not schedule the next page from offset %d, aborting the run', $offset + self::PAGE_SIZE ) );
				$this->abort( $token );
			}
		} else {
			$this->finalize( $token );
		}
	}

	private function fetch_page( int $offset ): array {
		return $this->factory->create()->nomenclature(
			'products',
			(string) $this->settings->get( 'cif' ),
			array( 'offset' => $offset )
		);
	}

	/**
	 * Fetches reservations before touching any product, so a failed
	 * reservation query aborts the whole page instead of writing quantities
	 * that fail to subtract what's actually reserved.
	 *
	 * @param array<int,mixed> $products Page of Oblio products to process.
	 * @param string           $token    Run token, forwarded to token_current() checks.
	 * @throws ReservationUnavailableException If reservations are enabled but
	 *                                          the query fails.
	 */
	private function process_page( array $products, string $token ): int {
		$selected     = array_values( (array) $this->settings->get( 'stock_locations' ) );
		$update_price = $this->settings->is_enabled( 'stock_update_price' );
		$reservations = $this->settings->is_enabled( 'stock_reserve_orders' ) ? $this->reservations->map() : array();

		$sku_map = $this->resolve_skus( $products );

		$updated = 0;
		$checked = 0;
		foreach ( $products as $product ) {
			if ( 0 === $checked % self::TOKEN_RECHECK_INTERVAL && ! $this->token_current( $token ) ) {
				break;
			}
			++$checked;

			$agg = $this->aggregator->aggregate( (array) $product, $selected );

			$agg = apply_filters( 'oblio_fgwoo_stock_aggregate', $agg, (array) $product, $selected );
			if ( null === $agg ) {
				continue;
			}
			if ( $this->updater->update( (array) $product, $agg, $update_price, $reservations, $sku_map ) ) {
				++$updated;
			}
		}
		return $updated;
	}

	private function log_page( int $offset, int $count, int $updated, array $totals ): void {
		$this->logger->info(
			sprintf(
				'Stock sync: page from offset %d - %d updated out of %d products (total %d/%d)',
				$offset,
				$updated,
				$count,
				$totals['updated'],
				$totals['scanned']
			)
		);
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

	/**
	 * Empty tokens never match: only jobs carrying the current run's real token
	 * may touch its state. Bypasses the options cache so a change another
	 * process just made is actually seen, not a stale copy from earlier in
	 * this same request.
	 *
	 * @param string $token Run token to compare against the currently stored one.
	 * @phpstan-impure
	 */
	private function token_current( string $token ): bool {
		if ( '' === $token ) {
			return false;
		}
		wp_cache_delete( StockSyncCoordinator::RUN_TOKEN_OPTION, 'options' );
		return (string) get_option( StockSyncCoordinator::RUN_TOKEN_OPTION, '' ) === $token;
	}

	private function handle_failure( int $offset, int $attempt, string $token, string $reason, bool $retryable ): void {
		if ( ! $this->token_current( $token ) ) {
			return;
		}

		if ( $retryable && $attempt < self::MAX_ATTEMPTS ) {
			$delay = $this->scheduler->backoff( $attempt );
			if ( $this->scheduler->enqueue_stock_batch( $offset, $attempt + 1, $delay, $token ) ) {
				$this->logger->warning( sprintf( 'Stock sync: page from offset %d failed (attempt %d): %s, retrying in %ds', $offset, $attempt, $reason, $delay ) );
				return;
			}
			$this->logger->error( sprintf( 'Stock sync: page from offset %d could not be rescheduled for retry', $offset ) );
		}

		$this->logger->error( sprintf( 'Stock sync: page from offset %d abandoned (attempt %d): %s', $offset, $attempt, $reason ) );
		$this->abort( $token );
	}

	/**
	 * CAS-deletes the run token as the ownership gate, then cleans up, and
	 * only releases the lock last - while we still hold it, nobody else's
	 * acquire() can succeed, so a stale worker's teardown can no longer wipe
	 * a newer run's state out from under it.
	 *
	 * @param string $token Expected run owner.
	 */
	private function abort( string $token ): void {
		if ( ! AtomicLock::delete_if_matches( StockSyncCoordinator::RUN_TOKEN_OPTION, $token ) ) {
			return;
		}

		delete_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		AtomicLock::release( StockSyncCoordinator::RUN_LOCK, $token );
	}

	private function record_progress( int $scanned, int $updated, string $token ): array {
		$progress = get_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		$progress = is_array( $progress ) ? $progress : array(
			'scanned' => 0,
			'updated' => 0,
		);

		if ( ! AtomicLock::renew( StockSyncCoordinator::RUN_LOCK, $token, StockSyncCoordinator::RUN_LOCK_TTL ) ) {
			return $progress;
		}

		$progress['scanned'] = (int) ( $progress['scanned'] ?? 0 ) + $scanned;
		$progress['updated'] = (int) ( $progress['updated'] ?? 0 ) + $updated;

		set_transient( StockSyncCoordinator::PROGRESS_TRANSIENT, $progress, DAY_IN_SECONDS );

		return $progress;
	}

	/**
	 * @param string $token Expected run owner.
	 */
	private function finalize( string $token ): void {
		if ( ! AtomicLock::delete_if_matches( StockSyncCoordinator::RUN_TOKEN_OPTION, $token ) ) {
			return;
		}

		$progress = get_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		$scanned  = is_array( $progress ) ? (int) ( $progress['scanned'] ?? 0 ) : 0;
		$updated  = is_array( $progress ) ? (int) ( $progress['updated'] ?? 0 ) : 0;

		update_option( StockSyncCoordinator::LAST_SYNC_OPTION, time(), false );
		$this->logger->info( sprintf( 'Stock sync finished: %d out of %d products updated', $updated, $scanned ) );

		delete_transient( StockSyncCoordinator::PROGRESS_TRANSIENT );
		AtomicLock::release( StockSyncCoordinator::RUN_LOCK, $token );
	}
}
