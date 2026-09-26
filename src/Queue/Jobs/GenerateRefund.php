<?php
/**
 * Action Scheduler job: issue a storno for a refund.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue\Jobs;

use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Refund\RefundIssuer;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use Throwable;
use WC_Order;
final class GenerateRefund {

	private const MAX_ATTEMPTS = 5;

	private const DEFER_ABOVE = 5;

	/**
	 * Batch invoicing or a deep queue can issue the invoice long after the
	 * refund's own MAX_ATTEMPTS/backoff window (a few minutes) would have
	 * given up - this is a separate, much longer budget just for "is the
	 * invoice there yet", reusing the same backoff() curve (which reaches its
	 * 1h ceiling after a handful of attempts), for roughly half a day of
	 * patience before finally giving up.
	 */
	private const MAX_INVOICE_WAIT_ATTEMPTS = 30;

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
		$payload      = is_array( $payload ) ? $payload : array();
		$order_id     = (int) ( $payload['order_id'] ?? 0 );
		$refund_id    = (int) ( $payload['refund_id'] ?? 0 );
		$attempt      = (int) ( $payload['attempt'] ?? 1 );
		$owner        = (string) ( $payload['pending_owner'] ?? '' );
		$invoice_wait = (int) ( $payload['invoice_wait_attempt'] ?? 0 );

		// Refresh the pending-refund marker as soon as this attempt actually
		// starts - see GenerateDocument::run() for why this can't wait for a
		// rate-limiter defer alone.
		if ( '' !== $owner && ! $this->scheduler->renew_pending_refund( $order_id, $refund_id, $owner ) ) {
			$this->logger->warning( sprintf( 'Queue: could not renew the pending marker for order #%d refund #%d at job start', $order_id, $refund_id ) );
		}

		$wait = $this->rate_limiter->peek();
		if ( $wait > self::DEFER_ABOVE ) {
			if ( ! $this->scheduler->reschedule_refund( $order_id, $refund_id, $attempt, $wait, $owner, $invoice_wait ) ) {
				$this->fail_or_release( $order_id, $refund_id, $owner, esc_html__( 'Nu s-a putut reprograma reîncercarea în coadă.', 'fgsync-oblio' ) );
			}
			return;
		}

		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			$this->scheduler->release_pending_refund( $order_id, $refund_id, $owner );
			$this->logger->warning( sprintf( 'Queue: order #%d not found, skipping storno for refund #%d', $order_id, $refund_id ) );
			return;
		}

		// The refund can be created while its invoice is still queued - retry
		// on a separate, much longer budget until it appears instead of never
		// revisiting this refund (see MAX_INVOICE_WAIT_ATTEMPTS).
		if ( ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			$this->wait_for_invoice( $order, $refund_id, $attempt, $invoice_wait, $owner );
			return;
		}

		try {
			if ( 0 === $refund_id ) {
				$this->refunds->issue_full_storno( $order, true );
			} else {
				$this->refunds->issue_for_refund( $order_id, $refund_id, true );
			}
			$order->delete_meta_data( 'oblio_fgwoo_storno_failed_' . $refund_id );
			$order->delete_meta_data( 'oblio_fgwoo_storno_failed_permanent_' . $refund_id );
			$order->save();
			$this->scheduler->release_pending_refund( $order_id, $refund_id, $owner );
		} catch ( DocumentException $exception ) {
			$this->fail( $order, $refund_id, $owner, $exception->getMessage(), false );
		} catch ( ApiException $exception ) {

			if ( $exception->is_retryable() ) {
				$this->maybe_retry( $order, $refund_id, $attempt, $owner, $exception->status_message() );
			} else {
				$this->fail( $order, $refund_id, $owner, $exception->status_message(), false );
			}
		} catch ( Throwable $exception ) {
			$this->maybe_retry( $order, $refund_id, $attempt, $owner, $exception->getMessage() );
		}
	}

	private function wait_for_invoice( WC_Order $order, int $refund_id, int $attempt, int $invoice_wait, string $owner ): void {
		if ( $invoice_wait >= self::MAX_INVOICE_WAIT_ATTEMPTS ) {
			$this->fail( $order, $refund_id, $owner, esc_html__( 'Factura nu a fost emisă în timp util.', 'fgsync-oblio' ), true );
			return;
		}
		$delay = $this->scheduler->backoff( $invoice_wait + 1 );
		if ( ! $this->scheduler->reschedule_refund( $order->get_id(), $refund_id, $attempt, $delay, $owner, $invoice_wait + 1 ) ) {
			$this->fail( $order, $refund_id, $owner, esc_html__( 'Nu s-a putut reprograma reîncercarea în coadă.', 'fgsync-oblio' ), true );
			return;
		}
		$this->logger->info(
			sprintf( 'Queue: storno for order #%d refund #%d waiting on its invoice (check %d/%d), retrying in %ds', $order->get_id(), $refund_id, $invoice_wait + 1, self::MAX_INVOICE_WAIT_ATTEMPTS, $delay )
		);
	}

	private function maybe_retry( WC_Order $order, int $refund_id, int $attempt, string $owner, string $reason ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->fail( $order, $refund_id, $owner, $reason, true );
			return;
		}
		$delay = $this->scheduler->backoff( $attempt );
		if ( ! $this->scheduler->enqueue_refund( $order->get_id(), $refund_id, $attempt + 1, $delay, $owner ) ) {
			$this->fail( $order, $refund_id, $owner, esc_html__( 'Nu s-a putut reprograma reîncercarea în coadă.', 'fgsync-oblio' ), true );
			return;
		}
		$this->logger->warning( sprintf( 'Queue: storno for order #%d refund #%d failed (attempt %d/%d): %s, retrying in %ds', $order->get_id(), $refund_id, $attempt, self::MAX_ATTEMPTS, $reason, $delay ) );
	}

	/**
	 * $exhausted distinguishes a permanent, business-rule failure (already
	 * has a storno, non-retryable API rejection) from one that merely ran
	 * out of retries or invoice-wait attempts - only the permanent case is
	 * recorded as failed_permanent, so a refund reconciliation pass can keep
	 * retrying the other one after a cooldown instead of abandoning it.
	 *
	 * @param WC_Order $order     Order the refund belongs to.
	 * @param int      $refund_id Refund ID (0 = full storno).
	 * @param string   $owner     Pending-marker owner token.
	 * @param string   $reason    Failure reason to record.
	 * @param bool     $exhausted Whether this is a transient failure that ran out of retries.
	 */
	private function fail( WC_Order $order, int $refund_id, string $owner, string $reason, bool $exhausted ): void {
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_' . $refund_id, $reason );
		if ( ! $exhausted ) {
			$order->update_meta_data( 'oblio_fgwoo_storno_failed_permanent_' . $refund_id, '1' );
		}
		$order->save();
		$this->scheduler->release_pending_refund( $order->get_id(), $refund_id, $owner );
		$this->logger->error( sprintf( 'Queue: storno for order #%d refund #%d %s: %s', $order->get_id(), $refund_id, $exhausted ? 'abandoned' : 'permanent failure', $reason ) );
	}

	/**
	 * Reschedule failed with no order loaded yet - fetch it now only to
	 * record the failure, or fall back to just releasing the marker if the
	 * order is gone.
	 *
	 * @param int    $order_id  Order ID.
	 * @param int    $refund_id Refund ID (0 = full storno).
	 * @param string $owner     Pending-marker owner token.
	 * @param string $reason    Failure reason to record.
	 */
	private function fail_or_release( int $order_id, int $refund_id, string $owner, string $reason ): void {
		$order = $this->orders->get_order( $order_id );
		if ( null !== $order ) {
			$this->fail( $order, $refund_id, $owner, $reason, true );
			return;
		}
		$this->scheduler->release_pending_refund( $order_id, $refund_id, $owner );
		$this->logger->error( sprintf( 'Queue: could not reschedule storno for order #%d refund #%d and the order is gone', $order_id, $refund_id ) );
	}
}
