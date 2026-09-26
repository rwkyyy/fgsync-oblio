<?php
/**
 * Action Scheduler job: issue a document for an order.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue\Jobs;

use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use Throwable;
final class GenerateDocument {

	private const MAX_ATTEMPTS = 5;

	private const DEFER_ABOVE = 5;

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
		$owner    = (string) ( $payload['pending_owner'] ?? '' );

		// Refresh the pending-document marker as soon as this attempt actually
		// starts, not just on a rate-limiter defer - an Action Scheduler delay
		// past PENDING_TTL before the first pickup would otherwise let it expire
		// and permit a second, duplicate chain for the same order+doc_type.
		if ( '' !== $owner && ! $this->scheduler->renew_pending_document( $order_id, $doc_type, $owner ) ) {
			$this->logger->warning( sprintf( 'Queue: could not renew the pending marker for order #%d %s at job start', $order_id, $doc_type ) );
		}

		$wait = $this->rate_limiter->peek();
		if ( $wait > self::DEFER_ABOVE ) {
			if ( ! $this->scheduler->reschedule_document( $order_id, $doc_type, $options, $attempt, $wait, $owner ) ) {
				$this->fail_or_release( $order_id, $doc_type, $owner, esc_html__( 'Nu s-a putut reprograma reîncercarea în coadă.', 'fgsync-oblio' ) );
			}
			return;
		}

		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			$this->scheduler->release_pending_document( $order_id, $doc_type, $owner );
			$this->logger->warning( sprintf( 'Queue: order #%d not found, skipping %s', $order_id, $doc_type ) );
			return;
		}

		try {
			$this->documents->issue( $order, $doc_type, $options, true );
			$order->delete_meta_data( OrderMeta::key( $doc_type, 'failed' ) );
			$order->delete_meta_data( OrderMeta::key( $doc_type, 'failed_permanent' ) );
			$order->save();
			$this->scheduler->release_pending_document( $order_id, $doc_type, $owner );
		} catch ( DocumentException $exception ) {
			$this->fail( $order, $doc_type, $owner, $exception->getMessage(), false );
		} catch ( ApiException $exception ) {

			if ( $exception->is_retryable() ) {
				$this->maybe_retry( $order, $doc_type, $options, $attempt, $owner, $exception->status_message() );
			} else {
				$this->fail( $order, $doc_type, $owner, $exception->status_message(), false );
			}
		} catch ( Throwable $exception ) {
			$this->maybe_retry( $order, $doc_type, $options, $attempt, $owner, $exception->getMessage() );
		}
	}

	private function maybe_retry( \WC_Order $order, string $doc_type, array $options, int $attempt, string $owner, string $reason ): void {
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			$this->fail( $order, $doc_type, $owner, $reason, true );
			return;
		}

		$delay = $this->scheduler->backoff( $attempt );
		if ( ! $this->scheduler->enqueue_document( $order->get_id(), $doc_type, $options, $attempt + 1, $delay, $owner ) ) {
			$this->fail( $order, $doc_type, $owner, esc_html__( 'Nu s-a putut reprograma reîncercarea în coadă.', 'fgsync-oblio' ), true );
			return;
		}
		$this->logger->warning(
			sprintf( 'Queue: %s for order #%d failed (attempt %d/%d): %s, retrying in %ds', $doc_type, $order->get_id(), $attempt, self::MAX_ATTEMPTS, $reason, $delay )
		);
	}

	/**
	 * $exhausted distinguishes a permanent, business-rule failure (invalid
	 * data, a non-retryable API rejection - retrying won't help) from a
	 * transient one that simply ran out of retries (e.g. an Oblio outage
	 * that outlasted this job's few-minute budget - it might well succeed
	 * later). Only the permanent case is recorded as failed_permanent, so
	 * Reconciler can keep retrying the transient one after a cooldown
	 * instead of abandoning it forever.
	 *
	 * @param \WC_Order $order    Order the document belongs to.
	 * @param string    $doc_type Document type.
	 * @param string    $owner    Pending-marker owner token.
	 * @param string    $reason   Failure reason to record.
	 * @param bool      $exhausted Whether this is a transient failure that ran out of retries.
	 */
	private function fail( \WC_Order $order, string $doc_type, string $owner, string $reason, bool $exhausted ): void {
		$order->update_meta_data( OrderMeta::key( $doc_type, 'failed' ), $reason );
		if ( ! $exhausted ) {
			$order->update_meta_data( OrderMeta::key( $doc_type, 'failed_permanent' ), '1' );
		}
		$order->save();
		$this->scheduler->release_pending_document( $order->get_id(), $doc_type, $owner );
		$this->logger->error(
			sprintf( 'Queue: %s for order #%d %s: %s', $doc_type, $order->get_id(), $exhausted ? 'abandoned after retries' : 'permanent failure', $reason )
		);
	}

	/**
	 * Reschedule failed with no order loaded yet (the defer path doesn't fetch
	 * one on the normal, successful branch) - fetch it now only to record the
	 * failure, or fall back to just releasing the marker if the order is gone.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $doc_type Document type.
	 * @param string $owner    Pending-marker owner token.
	 * @param string $reason   Failure reason to record.
	 */
	private function fail_or_release( int $order_id, string $doc_type, string $owner, string $reason ): void {
		$order = $this->orders->get_order( $order_id );
		if ( null !== $order ) {
			$this->fail( $order, $doc_type, $owner, $reason, true );
			return;
		}
		$this->scheduler->release_pending_document( $order_id, $doc_type, $owner );
		$this->logger->error( sprintf( 'Queue: could not reschedule order #%d %s and the order is gone', $order_id, $doc_type ) );
	}
}
