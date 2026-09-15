<?php
/**
 * Webhook handler: a collection was recorded in Oblio → mark the order paid.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Webhook\Handler;

use OblioWoo\Compat\OrderStore;
use OblioWoo\Document\DocumentResult;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Logger;
use OblioWoo\Webhook\WebhookHandler;
final class CollectInsertedHandler implements WebhookHandler {

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( OrderStore $orders, Logger $logger ) {
		$this->orders = $orders;
		$this->logger = $logger;
	}

	public function handle( array $data ): void {
		$series   = (string) ( $data['seriesName'] ?? '' );
		$number   = (string) ( $data['number'] ?? '' );
		$doc_type = 'Proforma' === ( $data['documentType'] ?? 'Factura' ) ? OrderMeta::TYPE_PROFORMA : OrderMeta::TYPE_INVOICE;

		$order = $this->orders->find_by_document( $doc_type, $series, $number );
		if ( null === $order ) {
			$this->logger->warning( sprintf( 'Webhook Collect/Inserted: no order found for %s %s %s', $doc_type, $series, $number ) );
			return;
		}

		$invoiced_by = $data['invoicedBy'] ?? null;
		if ( is_array( $invoiced_by ) && ! empty( $invoiced_by['number'] ) && ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			OrderMeta::save(
				$order,
				new DocumentResult(
					OrderMeta::TYPE_INVOICE,
					(string) ( $invoiced_by['seriesName'] ?? '' ),
					(string) ( $invoiced_by['number'] ?? '' ),
					(string) ( $invoiced_by['link'] ?? '' )
				)
			);
		}

		$complete = (bool) apply_filters( 'oblio_fgwoo_collect_complete_order', true, $order, $data );

		$order->update_meta_data( 'oblio_fgwoo_collected', current_time( 'mysql' ) );

		if ( $complete ) {
			if ( 'completed' !== $order->get_status() ) {
				$order->update_status( 'completed', __( 'Oblio: încasare confirmată.', 'oblio-fgwoo' ) );
			} else {
				$order->save();
			}
			$this->logger->info( sprintf( 'Webhook Collect/Inserted: order #%d completed (%s %s %s)', $order->get_id(), $doc_type, $series, $number ) );
			return;
		}

		$order->save();
		$this->logger->info( sprintf( 'Webhook Collect/Inserted: payment recorded for order #%d, not completed (%s %s %s)', $order->get_id(), $doc_type, $series, $number ) );
	}
}
