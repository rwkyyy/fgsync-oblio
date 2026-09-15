<?php
/**
 * Action Scheduler job: issue a document for an order.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Queue\Jobs;

use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Compat\OrderStore;
use OblioWoo\Document\DocumentException;
use OblioWoo\Document\DocumentIssuer;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Queue\Scheduler;
use OblioWoo\Support\Logger;
use OblioWoo\Support\RateLimiter;
use Throwable;
final class GenerateDocument {

	private const MAX_ATTEMPTS = 5;

	private const DEFER_ABOVE = 15;

	private DocumentIssuer $documents;

	private OrderStore $orders;

	private Scheduler $scheduler;

	private Logger $logger;

	private RateLimiter $rate_limiter;

	public function __construct( DocumentIssuer $documents, OrderStore $orders, Scheduler $scheduler, Logger $logger, RateLimiter $rate_limiter ) {
		$this->documents    = $documents;
		$this->orders       = $orders;
		$this->scheduler    = $scheduler;
		$this->logger       = $logger;
		$this->rate_limiter = $rate_limiter;
	}

	public function register(): void {
		add_action( Scheduler::HOOK_GENERATE, array( $this, 'run' ) );
	}

	public function run( $payload ): void {
		$payload  = is_array( $payload ) ? $payload : array();
		$order_id = (int) ( $payload['order_id'] ?? 0 );
		$doc_type = (string) ( $payload['doc_type'] ?? OrderMeta::TYPE_INVOICE );
		$options  = (array) ( $payload['options'] ?? array() );
		$attempt  = (int) ( $payload['attempt'] ?? 1 );

		$wait = $this->rate_limiter->peek();
		if ( $wait > self::DEFER_ABOVE ) {
			$this->scheduler->reschedule_document( $order_id, $doc_type, $options, $attempt, $wait );
			return;
		}

		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			$this->logger->warning( sprintf( 'Queue: order #%d not found, skipping %s', $order_id, $doc_type ) );
			return;
		}

		try {
			$this->documents->issue( $order, $doc_type, $options );
			$order->delete_meta_data( OrderMeta::key( $doc_type, 'failed' ) );
			$order->save();
		} catch ( DocumentException $exception ) {
			$this->fail( $order, $doc_type, $exception->getMessage(), false );
		} catch ( ApiException $exception ) {

			if ( $exception->is_retryable() ) {
				$this->maybe_retry( $order, $doc_type, $options, $attempt, $exception->status_message() );
			} else {
				$this->fail( $order, $doc_type, $exception->status_message(), false );
			}
		} catch ( Throwable $exception ) {
			$this->maybe_retry( $order, $doc_type, $options, $attempt, $exception->getMessage() );
		}
	}

	private function maybe_retry( \WC_Order $order, string $doc_type, array $options, int $attempt, string $reason ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->fail( $order, $doc_type, $reason, true );
			return;
		}

		$delay = $this->scheduler->backoff( $attempt );
		$this->scheduler->enqueue_document( $order->get_id(), $doc_type, $options, $attempt + 1, $delay );
		$this->logger->warning(
			sprintf( 'Queue: %s for order #%d failed (attempt %d/%d): %s, retrying in %ds', $doc_type, $order->get_id(), $attempt, self::MAX_ATTEMPTS, $reason, $delay )
		);
	}

	private function fail( \WC_Order $order, string $doc_type, string $reason, bool $exhausted ): void {
		$order->update_meta_data( OrderMeta::key( $doc_type, 'failed' ), $reason );
		$order->save();
		$this->logger->error(
			sprintf( 'Queue: %s for order #%d %s: %s', $doc_type, $order->get_id(), $exhausted ? 'abandoned after retries' : 'permanent failure', $reason )
		);
	}
}
