<?php
/**
 * Action Scheduler job: issue a storno for a refund.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Queue\Jobs;

use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Compat\OrderStore;
use OblioWoo\Document\DocumentException;
use OblioWoo\Queue\Scheduler;
use OblioWoo\Refund\RefundIssuer;
use OblioWoo\Support\Logger;
use OblioWoo\Support\RateLimiter;
use Throwable;
use WC_Order;
final class GenerateRefund {

	private const MAX_ATTEMPTS = 5;

	private const DEFER_ABOVE = 15;

	private RefundIssuer $refunds;

	private OrderStore $orders;

	private Scheduler $scheduler;

	private Logger $logger;

	private RateLimiter $rate_limiter;

	public function __construct( RefundIssuer $refunds, OrderStore $orders, Scheduler $scheduler, Logger $logger, RateLimiter $rate_limiter ) {
		$this->refunds      = $refunds;
		$this->orders       = $orders;
		$this->scheduler    = $scheduler;
		$this->logger       = $logger;
		$this->rate_limiter = $rate_limiter;
	}

	public function register(): void {
		add_action( Scheduler::HOOK_REFUND, array( $this, 'run' ) );
	}

	public function run( $payload ): void {
		$payload   = is_array( $payload ) ? $payload : array();
		$order_id  = (int) ( $payload['order_id'] ?? 0 );
		$refund_id = (int) ( $payload['refund_id'] ?? 0 );
		$attempt   = (int) ( $payload['attempt'] ?? 1 );

		$wait = $this->rate_limiter->peek();
		if ( $wait > self::DEFER_ABOVE ) {
			$this->scheduler->reschedule_refund( $order_id, $refund_id, $attempt, $wait );
			return;
		}

		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			$this->logger->warning( sprintf( 'Queue: order #%d not found, skipping storno for refund #%d', $order_id, $refund_id ) );
			return;
		}

		try {
			if ( 0 === $refund_id ) {
				$this->refunds->issue_full_storno( $order );
			} else {
				$this->refunds->issue_for_refund( $order_id, $refund_id );
			}
			$order->delete_meta_data( 'oblio_fgwoo_storno_failed_' . $refund_id );
			$order->save();
		} catch ( DocumentException $exception ) {
			$this->fail( $order, $refund_id, $exception->getMessage(), false );
		} catch ( ApiException $exception ) {

			if ( $exception->is_retryable() ) {
				$this->maybe_retry( $order, $refund_id, $attempt, $exception->status_message() );
			} else {
				$this->fail( $order, $refund_id, $exception->status_message(), false );
			}
		} catch ( Throwable $exception ) {
			$this->maybe_retry( $order, $refund_id, $attempt, $exception->getMessage() );
		}
	}

	private function maybe_retry( WC_Order $order, int $refund_id, int $attempt, string $reason ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->fail( $order, $refund_id, $reason, true );
			return;
		}
		$delay = $this->scheduler->backoff( $attempt );
		$this->scheduler->enqueue_refund( $order->get_id(), $refund_id, $attempt + 1, $delay );
		$this->logger->warning( sprintf( 'Queue: storno for order #%d refund #%d failed (attempt %d/%d): %s, retrying in %ds', $order->get_id(), $refund_id, $attempt, self::MAX_ATTEMPTS, $reason, $delay ) );
	}

	private function fail( WC_Order $order, int $refund_id, string $reason, bool $exhausted ): void {
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_' . $refund_id, $reason );
		$order->save();
		$this->logger->error( sprintf( 'Queue: storno for order #%d refund #%d %s: %s', $order->get_id(), $refund_id, $exhausted ? 'abandoned' : 'permanent failure', $reason ) );
	}
}
