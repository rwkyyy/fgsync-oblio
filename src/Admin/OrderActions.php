<?php
/**
 * AJAX handler for manual document actions on the order screen.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\DocumentIssuer;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Refund\RefundIssuer;
use FGSyncOblio\Support\Logger;
use Throwable;
use WC_Order;
final class OrderActions {

	public const NONCE_ACTION = 'oblio_fgwoo_order';

	private DocumentIssuer $documents;

	private RefundIssuer $refunds;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( DocumentIssuer $documents, RefundIssuer $refunds, OrderStore $orders, Logger $logger ) {
		$this->documents = $documents;
		$this->refunds   = $refunds;
		$this->orders    = $orders;
		$this->logger    = $logger;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_order_action', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'fgsync-oblio' ) ), 403 );
		}

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$task      = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$doc_type  = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : OrderMeta::TYPE_INVOICE;
		$use_stock = ! empty( $_POST['use_stock'] );

		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'Comandă inexistentă.', 'fgsync-oblio' ) ) );
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată pentru această comandă.', 'fgsync-oblio' ) ), 403 );
		}

		if ( ! in_array( $doc_type, array( OrderMeta::TYPE_INVOICE, OrderMeta::TYPE_PROFORMA, OrderMeta::TYPE_NOTICE ), true ) ) {
			$doc_type = OrderMeta::TYPE_INVOICE;
		}

		try {
			switch ( $task ) {
				case 'issue':
					$result = $this->documents->issue( $order, $doc_type, array( 'use_stock' => $use_stock ) );
					$order->delete_meta_data( OrderMeta::key( $doc_type, 'failed' ) );
					$order->save();
					// DocumentService::issue() already logs an "... issued" line for
					// every issuance regardless of caller - logging it again here
					// double-counts this order on StatusPanel's "documents issued
					// today" tile.
					wp_send_json_success(
						array(
							'series' => $result->series_name,
							'number' => $result->number,
							'link'   => $result->link,
						)
					);
					break;

				case 'storno':
					$result = $this->refunds->issue_full_storno( $order );
					// RefundService::issue_full_storno() already logs the issuance -
					// see the note in the 'issue' case above.
					wp_send_json_success(
						array(
							'series' => $result->series_name,
							'number' => $result->number,
							'link'   => $result->link,
						)
					);
					break;

				case 'delete':
					if ( ! OrderMeta::is_last_document( $order, $doc_type ) ) {
						wp_send_json_error( array( 'message' => __( 'Se poate șterge doar ultimul document din serie. Emite un storno în schimb.', 'fgsync-oblio' ) ) );
					}
					$this->documents->delete( $order, $doc_type );
					$this->logger->info( sprintf( 'Manual action: order #%d %s deleted', $order->get_id(), $doc_type ) );
					wp_send_json_success( array( 'deleted' => true ) );
					break;

				default:
					wp_send_json_error( array( 'message' => __( 'Acțiune necunoscută.', 'fgsync-oblio' ) ) );
			}
		} catch ( DocumentException $exception ) {
			$this->logger->error( sprintf( 'Manual action: order #%d %s %s failed: %s', $order_id, $doc_type, $task, $exception->getMessage() ) );
			$this->record_issue_failure( $order, $doc_type, $task, $exception->getMessage() );
			wp_send_json_error( array( 'message' => $exception->getMessage() ) );
		} catch ( Throwable $exception ) {
			$this->logger->error( sprintf( 'Manual action: order #%d %s %s failed: %s', $order_id, $doc_type, $task, $exception->getMessage() ) );
			$this->record_issue_failure( $order, $doc_type, $task, $exception->getMessage() );
			wp_send_json_error( array( 'message' => $exception->getMessage() ) );
		}
	}

	private function record_issue_failure( WC_Order $order, string $doc_type, string $task, string $reason ): void {
		if ( 'issue' !== $task ) {
			return;
		}
		$order->update_meta_data( OrderMeta::key( $doc_type, 'failed' ), $reason );
		$order->save();
	}
}
