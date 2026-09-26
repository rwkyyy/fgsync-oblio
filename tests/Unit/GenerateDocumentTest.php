<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentException;
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

	private function run_job( WC_Order $order, int $attempt = 1 ): void {
		$GLOBALS['oblio_test_orders'][ $order->get_id() ] = $order;
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
	 * A stolen/expired-and-reclaimed marker must not crash the job or stop it
	 * from completing - it's logged so the gap is visible, but the document
	 * still gets issued.
	 */
	public function test_a_stolen_pending_marker_logs_a_warning_but_the_job_still_proceeds(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ), 'old reason' );
		$GLOBALS['oblio_test_options']['oblio_fgwoo_pending_doc_invoice_1'] = ( time() + 3600 ) . '|someone-else';

		$this->run_job( $order );

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$matches  = array_filter(
			$messages,
			static fn ( string $message ): bool => false !== strpos( $message, 'could not renew the pending marker' )
		);
		$this->assertNotEmpty( $matches );
		$this->assertSame( 'warning', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
	}
}
