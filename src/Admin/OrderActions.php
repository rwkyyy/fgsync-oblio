<?php
/**
 * AJAX handler for manual document actions on the order screen.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Compat\OrderStore;
use OblioWoo\Document\DocumentException;
use OblioWoo\Document\DocumentService;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Refund\RefundService;
use OblioWoo\Support\Logger;
use Throwable;
final class OrderActions {

	public const NONCE_ACTION = 'oblio_fgwoo_order';

	private DocumentService $documents;

	private RefundService $refunds;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( DocumentService $documents, RefundService $refunds, OrderStore $orders, Logger $logger ) {
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
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$task      = isset( $_POST['task'] ) ? sanitize_key( wp_unslash( $_POST['task'] ) ) : '';
		$doc_type  = isset( $_POST['doc_type'] ) ? sanitize_key( wp_unslash( $_POST['doc_type'] ) ) : OrderMeta::TYPE_INVOICE;
		$use_stock = ! empty( $_POST['use_stock'] );

		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			wp_send_json_error( array( 'message' => __( 'Comandă inexistentă.', 'oblio-fgwoo' ) ) );
		}

		if ( ! current_user_can( 'edit_shop_order', $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată pentru această comandă.', 'oblio-fgwoo' ) ), 403 );
		}

		if ( ! in_array( $doc_type, array( OrderMeta::TYPE_INVOICE, OrderMeta::TYPE_PROFORMA, OrderMeta::TYPE_NOTICE ), true ) ) {
			$doc_type = OrderMeta::TYPE_INVOICE;
		}

		try {
			switch ( $task ) {
				case 'issue':
					$result = $this->documents->issue( $order, $doc_type, array( 'use_stock' => $use_stock ) );
					$this->logger->info( sprintf( 'Manual action: order #%d %s %s %s issued', $order->get_id(), $doc_type, $result->series_name, $result->number ) );
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
					$this->logger->info( sprintf( 'Manual action: order #%d storno %s %s issued', $order->get_id(), $result->series_name, $result->number ) );
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
						wp_send_json_error( array( 'message' => __( 'Se poate șterge doar ultimul document din serie. Emite un storno în schimb.', 'oblio-fgwoo' ) ) );
					}
					$this->documents->delete( $order, $doc_type );
					$this->logger->info( sprintf( 'Manual action: order #%d %s deleted', $order->get_id(), $doc_type ) );
					wp_send_json_success( array( 'deleted' => true ) );
					break;

				default:
					wp_send_json_error( array( 'message' => __( 'Acțiune necunoscută.', 'oblio-fgwoo' ) ) );
			}
		} catch ( DocumentException $exception ) {
			$this->logger->error( sprintf( 'Manual action: order #%d %s %s failed: %s', $order_id, $doc_type, $task, $exception->getMessage() ) );
			wp_send_json_error( array( 'message' => $exception->getMessage() ) );
		} catch ( Throwable $exception ) {
			$this->logger->error( sprintf( 'Manual action: order #%d %s %s failed: %s', $order_id, $doc_type, $task, $exception->getMessage() ) );
			wp_send_json_error( array( 'message' => $exception->getMessage() ) );
		}
	}
}
