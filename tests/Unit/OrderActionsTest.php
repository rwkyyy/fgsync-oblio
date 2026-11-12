<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\OrderActions;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Refund\RefundIssuer;
use FGSyncOblio\Support\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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

final class StockShortageDocumentIssuer implements DocumentIssuer {

	public const OBLIO_MESSAGE = "Produsele urmatoare nu au stoc suficient:\n<b>Natural statine forte, 60cps, Hypericum</b>.";

	public function issue( WC_Order $order, string $doc_type, array $options = array(), bool $fail_fast = false ): DocumentResult {
		throw new ApiException( esc_html( self::OBLIO_MESSAGE ), 499, esc_html( self::OBLIO_MESSAGE ) );
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		return true;
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

	/**
	 * A prior permanent failure must not linger after a manual issue succeeds -
	 * otherwise a later invoice deletion would leave Reconciler permanently
	 * excluding this order (see Reconciler::reconcile_invoices()'s NOT EXISTS
	 * on failed_permanent).
	 */
	public function test_a_manual_issue_clears_a_prior_permanent_failure(): void {
		$order = new WC_Order( 1 );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ), 'old reason' );
		$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ), '1' );
		$GLOBALS['oblio_test_orders'][1] = $order;

		$this->post(
			array(
				'order_id' => '1',
				'task'     => 'issue',
				'doc_type' => 'invoice',
				'nonce'    => 'test-nonce',
			)
		);

		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) );
		$this->assertSame( '', (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed_permanent' ) ) );
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

	public function test_a_failed_issue_returns_oblio_markup_as_plain_text(): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		$logger  = new Logger();
		$actions = new OrderActions( new StockShortageDocumentIssuer(), new LoggingRefundIssuer( $logger ), new OrderStore(), $logger );

		$_POST = array(
			'order_id' => '1',
			'task'     => 'issue',
			'doc_type' => 'invoice',
			'nonce'    => 'test-nonce',
		);
		$actions->handle();

		$this->assertFalse( $GLOBALS['oblio_test_json_response']['success'] );
		$this->assertSame(
			"Produsele urmatoare nu au stoc suficient:\nNatural statine forte, 60cps, Hypericum.",
			$GLOBALS['oblio_test_json_response']['data']['message']
		);
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function failed_issue_log_lines(): array {
		return array(
			'invoice with stock'    => array( array( 'doc_type' => 'invoice', 'use_stock' => '1' ), 'Manual action: order #1 invoice issue with stock failed:' ),
			'invoice without stock' => array( array( 'doc_type' => 'invoice' ), 'Manual action: order #1 invoice issue without stock failed:' ),
			'proforma'              => array( array( 'doc_type' => 'proforma' ), 'Manual action: order #1 proforma issue failed:' ),
		);
	}

	/**
	 * @param array<string,string> $request Extra POST fields.
	 * @param string               $line    Expected start of the log line.
	 */
	#[DataProvider( 'failed_issue_log_lines' )]
	public function test_a_failed_issue_logs_whether_stock_was_requested( array $request, string $line ): void {
		$GLOBALS['oblio_test_orders'][1] = new WC_Order( 1 );
		$logger  = new Logger();
		$actions = new OrderActions( new StockShortageDocumentIssuer(), new LoggingRefundIssuer( $logger ), new OrderStore(), $logger );

		$_POST = array(
			'order_id' => '1',
			'task'     => 'issue',
			'nonce'    => 'test-nonce',
		) + $request;
		$actions->handle();

		$messages = array_column( $GLOBALS['oblio_test_wc_logs'], 'message' );
		$this->assertNotEmpty( array_filter( $messages, static fn ( string $message ): bool => str_starts_with( $message, $line ) ) );
	}
}
