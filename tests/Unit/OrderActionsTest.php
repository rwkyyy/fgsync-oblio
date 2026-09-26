<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\OrderActions;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Refund\RefundIssuer;
use FGSyncOblio\Support\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

/**
 * Mimics DocumentService::issue()'s own "... issued" log line, so a test can
 * detect OrderActions ALSO logging one for the same action (the double-count
 * bug) - the real DocumentService can't be constructed here (it depends on
 * concrete, final API/mapper classes that need a much fuller WC_Order than
 * this suite's minimal stub provides), so this stands in for "some
 * DocumentIssuer that logs on success" rather than DocumentService itself.
 */
final class LoggingDocumentIssuer implements DocumentIssuer {

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public function issue( WC_Order $order, string $doc_type, array $options = array(), bool $fail_fast = false ): DocumentResult {
		$result = new DocumentResult( $doc_type, 'S', '1', 'https://example.test/doc' );
		$this->logger->info( sprintf( 'Order #%d: %s %s %s issued', $order->get_id(), $doc_type, $result->series_name, $result->number ) );
		return $result;
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		return true;
	}
}

final class LoggingRefundIssuer implements RefundIssuer {

	private Logger $logger;

	public function __construct( Logger $logger ) {
		$this->logger = $logger;
	}

	public function issue_for_refund( int $order_id, int $refund_id, bool $fail_fast = false ): ?DocumentResult {
		return new DocumentResult( OrderMeta::TYPE_STORNO, 'S', '1', 'https://example.test/doc' );
	}

	public function issue_full_storno( WC_Order $order, bool $fail_fast = false ): DocumentResult {
		$result = new DocumentResult( OrderMeta::TYPE_STORNO, 'S', '1', 'https://example.test/doc' );
		$this->logger->info( sprintf( 'Order #%d: manual full storno %s %s issued', $order->get_id(), $result->series_name, $result->number ) );
		return $result;
	}
}

#[CoversClass( \FGSyncOblio\Admin\OrderActions::class )]
final class OrderActionsTest extends TestCase {

	private OrderActions $actions;

	protected function setUp(): void {
		oblio_test_reset();
		$logger       = new Logger();
		$this->actions = new OrderActions(
			new LoggingDocumentIssuer( $logger ),
			new LoggingRefundIssuer( $logger ),
			new OrderStore(),
			$logger
		);
	}

	private function post( array $data ): void {
		$_POST = $data;
		$this->actions->handle();
	}

	private function issued_log_lines(): array {
		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		return array_values( array_filter( $messages, static fn ( string $message ): bool => false !== strpos( $message, ' issued' ) ) );
	}

	/**
	 * The underlying DocumentIssuer already logs its own "... issued" line for
	 * every issuance - OrderActions must not log a second one for the same
	 * manual action (the fix for the "documents issued today" double count).
	 */
	public function test_a_manual_issue_produces_exactly_one_issued_log_line(): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->post(
			array(
				'order_id' => '1',
				'task'     => 'issue',
				'doc_type' => 'invoice',
				'nonce'    => 'test-nonce',
			)
		);

		$this->assertCount( 1, $this->issued_log_lines() );
		$this->assertTrue( $GLOBALS['oblio_test_json_response']['success'] );
	}

	public function test_a_manual_storno_produces_exactly_one_issued_log_line(): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );

		$this->post(
			array(
				'order_id' => '1',
				'task'     => 'storno',
				'nonce'    => 'test-nonce',
			)
		);

		$this->assertCount( 1, $this->issued_log_lines() );
		$this->assertTrue( $GLOBALS['oblio_test_json_response']['success'] );
	}
}
