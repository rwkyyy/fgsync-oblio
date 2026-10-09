<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\FullyRefundedException;
use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Queue\Jobs\GenerateDocument;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;
use WC_Order;

final class ConfigurableDocumentIssuer implements DocumentIssuer {

	private ?Throwable $throw = null;

	public function fail_with( Throwable $exception ): void {
		$this->throw = $exception;
	}

	public function issue( WC_Order $order, string $doc_type, array $options = array(), bool $fail_fast = false ): DocumentResult {
		if ( null !== $this->throw ) {
			throw $this->throw;
		}
		return new DocumentResult( $doc_type, 'S', '1', 'https://example.test/doc' );
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		return true;
	}
}

#[CoversClass( \FGSyncOblio\Queue\Jobs\GenerateDocument::class )]
final class GenerateDocumentTest extends TestCase {

	private ConfigurableDocumentIssuer $documents;

	private GenerateDocument $job;

	protected function setUp(): void {
		oblio_test_reset();
		$this->documents = new ConfigurableDocumentIssuer();
		$this->job       = new GenerateDocument(
			$this->documents,
			new OrderStore(),
			new Scheduler(),
			new Logger(),
			new RateLimiter( new InMemorySlotStore() )
		);
	}

	/**
	 * 'owner-a' stands in for a marker genuinely claimed at enqueue time - only
	 * seeded when a test hasn't already set up its own marker state (the
	 * stolen/missing-marker tests deliberately set the option themselves
	 * before calling this, so it must not clobber that).
	 */
	private function run_job( WC_Order $order, int $attempt = 1 ): void {
		$GLOBALS['oblio_test_orders'][ $order->get_id() ] = $order;
		$key = 'oblio_fgwoo_pending_doc_' . OrderMeta::TYPE_INVOICE . '_' . $order->get_id();
		if ( ! array_key_exists( $key, $GLOBALS['oblio_test_options'] ) ) {
			$GLOBALS['oblio_test_options'][ $key ] = ( time() + 3600 ) . '|owner-a';
		}
		$this->job->run(
			array(
				'order_id'      => $order->get_id(),
				'doc_type'      => OrderMeta::TYPE_INVOICE,
				'attempt'       => $attempt,
				'pending_owner' => 'owner-a',
			)
		);
	}

	/**
	 * A non-retryable ApiException (e.g. a validation error) is a permanent,
	 * business-rule failure - reconciliation must not keep re-trying it.
	 */
	public function test_a_non_retryable_api_failure_is_recorded_as_permanent(): void {
		$order = new WC_Order( 1 );
		$this->documents->fail_with( new ApiException( 'bad request', 422 ) );

		$this->run_job( $order );

		$this->assertNotSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
		$this->assertSame( '1', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ) ) );
	}

	public function test_a_document_exception_is_recorded_as_permanent(): void {
		$order = new WC_Order( 1 );
		$this->documents->fail_with( new DocumentException( 'invalid CIF' ) );

		$this->run_job( $order );

		$this->assertSame( '1', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ) ) );
	}

	/**
	 * A retryable failure that exhausts MAX_ATTEMPTS is transient (e.g. an
	 * Oblio outage) - it must NOT be flagged permanent, so Reconciler can
	 * still pick it up later.
	 */
	public function test_an_exhausted_retryable_failure_is_not_recorded_as_permanent(): void {
		$order = new WC_Order( 1 );
		$this->documents->fail_with( new ApiException( 'server error', 500 ) );

		$this->run_job( $order, 5 );

		$this->assertNotSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ) ) );
	}

	public function test_a_fully_refunded_order_is_skipped_without_a_failure(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ), 'old' );
		$this->documents->fail_with( new FullyRefundedException( 'refunded' ) );

		$this->run_job( $order );

		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ) ) );
		$this->assertArrayNotHasKey( 'oblio_fgwoo_pending_doc_' . OrderMeta::TYPE_INVOICE . '_1', $GLOBALS['oblio_test_options'] );
	}

	public function test_a_successful_issue_clears_both_failure_flags(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ), 'old reason' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ), '1' );

		$this->run_job( $order );

		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ) ) );
	}

	/**
	 * An Action Scheduler pickup delay past the marker's 1h TTL must not
	 * silently go unrenewed - the job renews it as soon as the attempt
	 * actually starts, not only on a rate-limiter defer.
	 */
	public function test_a_valid_pending_marker_is_renewed_at_job_start_without_a_warning(): void {
		$scheduler = new Scheduler();
		$scheduler->enqueue_document( 1, OrderMeta::TYPE_INVOICE, array(), 1, 0 );
		$owner = $GLOBALS['oblio_test_as_calls'][0]['args'][0]['pending_owner'];

		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;
		$this->job->run(
			array(
				'order_id'      => 1,
				'doc_type'      => OrderMeta::TYPE_INVOICE,
				'attempt'       => 1,
				'pending_owner' => $owner,
			)
		);

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$warnings = array_filter( $messages, static fn ( string $message ): bool => false !== strpos( $message, 'could not renew the pending marker' ) );
		$this->assertSame( array(), array_values( $warnings ) );
	}

	/**
	 * A stolen/expired-and-reclaimed marker means a newer chain now owns it -
	 * this stale chain must abort instead of racing it with outdated options,
	 * even though the newer chain's own eventual run will still issue it.
	 */
	public function test_a_stolen_pending_marker_logs_a_warning_and_aborts_the_job(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ), 'old reason' );
		$GLOBALS['oblio_test_options']['oblio_fgwoo_pending_doc_invoice_1'] = ( time() + 3600 ) . '|someone-else';

		$this->run_job( $order );

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$matches  = array_filter(
			$messages,
			static fn ( string $message ): bool => false !== strpos( $message, 'owned by a newer chain' )
		);
		$this->assertNotEmpty( $matches );
		$this->assertSame( 'warning', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		// Aborted before touching the order at all - the stale reason is untouched.
		$this->assertSame( 'old reason', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
	}

	/**
	 * A marker that's simply gone (already released, or wiped by an external
	 * force-release) is just as stale as one reclaimed by someone else - this
	 * chain must not proceed on the assumption it's still the sole owner.
	 */
	public function test_a_missing_pending_marker_logs_a_warning_and_aborts_the_job(): void {
		$order = new WC_Order( 1 );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->job->run(
			array(
				'order_id'      => 1,
				'doc_type'      => OrderMeta::TYPE_INVOICE,
				'attempt'       => 1,
				'pending_owner' => 'owner-a',
			)
		);

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$matches  = array_filter(
			$messages,
			static fn ( string $message ): bool => false !== strpos( $message, 'is gone, aborting this stale job' )
		);
		$this->assertNotEmpty( $matches );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ) ) );
	}
}
