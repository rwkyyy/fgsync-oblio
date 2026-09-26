<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Jobs\GenerateRefund;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Refund\RefundIssuer;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;
use WC_Order;

final class RecordingRefundIssuer implements RefundIssuer {

	/** @var array<int,array{0:int,1:int}> */
	public array $calls = array();

	private ?Throwable $throw = null;

	public function fail_with( Throwable $exception ): void {
		$this->throw = $exception;
	}

	public function issue_for_refund( int $order_id, int $refund_id, bool $fail_fast = false ): ?DocumentResult {
		$this->calls[] = array( $order_id, $refund_id );
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		return new DocumentResult( OrderMeta::TYPE_STORNO, 'S', '1', 'https://example.test/doc' );
	}

	public function issue_full_storno( WC_Order $order, bool $fail_fast = false ): DocumentResult {
		$this->calls[] = array( $order->get_id(), 0 );
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		return new DocumentResult( OrderMeta::TYPE_STORNO, 'S', '1', 'https://example.test/doc' );
	}
}

#[CoversClass( \FGSyncOblio\Queue\Jobs\GenerateRefund::class )]
final class GenerateRefundTest extends TestCase {

	private RecordingRefundIssuer $refunds;

	private GenerateRefund $job;

	protected function setUp(): void {
		oblio_test_reset();
		$this->refunds = new RecordingRefundIssuer();
		$this->job     = new GenerateRefund(
			$this->refunds,
			new OrderStore(),
			new Scheduler(),
			new Logger(),
			new RateLimiter( new InMemorySlotStore() )
		);
	}

	/**
	 * A refund can be created while its invoice is still queued - the job
	 * must retry with backoff instead of permanently failing or, worse,
	 * silently doing nothing (RefundAutoIssue no longer pre-checks this).
	 * This uses a separate invoice_wait_attempt counter, not the API-retry
	 * attempt counter, so it doesn't inherit the API retries' short budget.
	 */
	public function test_a_missing_invoice_is_retried_instead_of_failing_permanently(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 1,
				'pending_owner' => 'owner-a',
			)
		);

		$this->assertCount( 0, $this->refunds->calls );
		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$payload = $GLOBALS['oblio_test_as_calls'][0]['args'][0];
		$this->assertSame( 1, $payload['attempt'] );
		$this->assertSame( 1, $payload['invoice_wait_attempt'] );
		$this->assertSame( 'owner-a', $payload['pending_owner'] );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_5' ) );
	}

	/**
	 * A regular API-retry attempt count exhausting MAX_ATTEMPTS(5) must NOT
	 * be conflated with invoice_wait_attempt's own, much longer budget.
	 */
	public function test_a_high_api_retry_attempt_does_not_shorten_the_invoice_wait_budget(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 5,
				'pending_owner' => 'owner-a',
			)
		);

		$this->assertCount( 0, $this->refunds->calls );
		$this->assertCount( 1, $GLOBALS['oblio_test_as_calls'] );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_5' ) );
	}

	public function test_a_missing_invoice_is_recorded_as_a_failure_once_the_wait_budget_is_exhausted(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'             => 1,
				'refund_id'            => 5,
				'attempt'              => 1,
				'pending_owner'        => 'owner-a',
				'invoice_wait_attempt' => 30,
			)
		);

		$this->assertCount( 0, $this->refunds->calls );
		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );
		$this->assertNotSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_5' ) );
	}

	/**
	 * A permanent business-rule failure (e.g. already has a storno) must be
	 * flagged so a refund reconciliation pass doesn't keep retrying it.
	 */
	public function test_a_document_exception_is_recorded_as_permanent(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->refunds->fail_with( new \FGSyncOblio\Document\DocumentException( 'already has a storno' ) );

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 1,
				'pending_owner' => 'owner-a',
			)
		);

		$this->assertSame( '1', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_permanent_5' ) );
	}

	/**
	 * A retryable failure that exhausts MAX_ATTEMPTS is transient (e.g. an
	 * Oblio outage) - it must NOT be flagged permanent.
	 */
	public function test_an_exhausted_retryable_failure_is_not_recorded_as_permanent(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->refunds->fail_with( new \FGSyncOblio\Api\Exception\ApiException( 'server error', 500 ) );

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 5,
				'pending_owner' => 'owner-a',
			)
		);

		$this->assertNotSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_5' ) );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_permanent_5' ) );
	}

	public function test_a_successful_storno_clears_both_failure_flags(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_5', 'old reason' );
		$order->update_meta_data( 'oblio_fgwoo_storno_failed_permanent_5', '1' );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 1,
				'pending_owner' => 'owner-a',
			)
		);

		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_5' ) );
		$this->assertSame( '', (string) $order->get_meta( 'oblio_fgwoo_storno_failed_permanent_5' ) );
	}

	public function test_an_existing_invoice_lets_the_storno_proceed(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 1,
				'pending_owner' => 'owner-a',
			)
		);

		$this->assertCount( 1, $this->refunds->calls );
		$this->assertSame( array( 1, 5 ), $this->refunds->calls[0] );
	}

	/**
	 * An Action Scheduler pickup delay past the marker's 1h TTL must not
	 * silently go unrenewed - the job renews it as soon as the attempt
	 * actually starts, not only on a rate-limiter defer.
	 */
	public function test_a_valid_pending_marker_is_renewed_at_job_start_without_a_warning(): void {
		$scheduler = new Scheduler();
		$scheduler->enqueue_refund( 1, 5, 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 1,
				'pending_owner' => $owner,
			)
		);

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$warnings = array_filter( $messages, static fn ( string $message ): bool => false !== strpos( $message, 'could not renew the pending marker' ) );
		$this->assertSame( array(), array_values( $warnings ) );
	}

	/**
	 * A stolen/expired-and-reclaimed marker must not crash the job or stop it
	 * from completing - it's logged so the gap is visible, but the storno
	 * still gets issued.
	 */
	public function test_a_stolen_pending_marker_logs_a_warning_but_the_job_still_proceeds(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ), 'https://example.test/invoice' );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$GLOBALS['oblio_test_options']['oblio_fgwoo_pending_refund_1_5'] = ( time() + 3600 ) . '|someone-else';

		$this->job->run(
			array(
				'order_id'      => 1,
				'refund_id'     => 5,
				'attempt'       => 1,
				'pending_owner' => 'owner-a',
			)
		);

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$matches  = array_filter(
			$messages,
			static fn ( string $message ): bool => false !== strpos( $message, 'could not renew the pending marker' )
		);
		$this->assertNotEmpty( $matches );
		$this->assertCount( 1, $this->refunds->calls );
	}
}
