<?php
/**
 * Issues, deletes and cancels Oblio documents for orders.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\OrderLock;
use FGSyncOblio\Support\Settings;
use RuntimeException;
use WC_Order;
final class DocumentService implements DocumentIssuer {

	private Settings $settings;

	private ClientFactory $factory;

	private InvoiceBuilder $builder;

	private LifecyclePolicy $policy;

	private InvoiceEmailer $emailer;

	private Logger $logger;

	public function __construct(
		Settings $settings,
		ClientFactory $factory,
		InvoiceBuilder $builder,
		LifecyclePolicy $policy,
		InvoiceEmailer $emailer,
		Logger $logger
	) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->builder  = $builder;
		$this->policy   = $policy;
		$this->emailer  = $emailer;
		$this->logger   = $logger;
	}

	public function issue( WC_Order $order, string $doc_type, array $options = array() ): DocumentResult {
		$existing = OrderMeta::get( $order, $doc_type );
		if ( null !== $existing ) {
			return new DocumentResult( $doc_type, $existing['series'], $existing['number'], $existing['link'] );
		}

		$owner = OrderLock::acquire( $order->get_id() );
		if ( null === $owner ) {
			throw new RuntimeException(
				esc_html__( 'Un alt proces emite deja un document pentru comandă; se va reîncerca automat.', 'fgsync-oblio' )
			);
		}

		try {
			$refreshed = wc_get_order( $order->get_id() );
			if ( $refreshed instanceof WC_Order ) {
				$order = $refreshed;
			}

			$existing = OrderMeta::get( $order, $doc_type );
			if ( null !== $existing ) {
				return new DocumentResult( $doc_type, $existing['series'], $existing['number'], $existing['link'] );
			}

			$this->policy->assert_can_issue( $order, $doc_type );
			$this->maybe_drop_proforma( $order, $doc_type );

			$payload = $this->builder->build( $order, $doc_type, $options );

			OrderLock::renew( $order->get_id(), $owner );
			$data   = $this->factory->create()->create_document( $doc_type, $payload );
			$result = DocumentResult::from_api( $doc_type, $data );

			if ( '' === $result->series_name || '' === $result->number || '' === $result->link ) {
				throw new ApiException( esc_html__( 'Răspuns incomplet de la Oblio; se reîncearcă automat pentru a evita un document duplicat.', 'fgsync-oblio' ) );
			}

			if ( OrderMeta::TYPE_INVOICE === $doc_type ) {

				OrderMeta::record_invoice_stock_usage( $order, ! empty( $options['use_stock'] ) );
			}
			OrderMeta::save( $order, $result );

			do_action( 'oblio_fgwoo_document_issued', $order, $result, $options );

			$this->emailer->maybe_send( $order, $result );
			$this->logger->info( sprintf( 'Order #%d: %s %s %s issued', $order->get_id(), $doc_type, $result->series_name, $result->number ) );

			return $result;
		} finally {
			OrderLock::release( $order->get_id(), $owner );
		}
	}

	public function delete( WC_Order $order, string $doc_type ): bool {
		$owner = OrderLock::acquire( $order->get_id() );
		if ( null === $owner ) {
			throw new RuntimeException(
				esc_html__( 'Un alt proces emite deja un document pentru comandă; se va reîncerca automat.', 'fgsync-oblio' )
			);
		}

		try {
			return $this->delete_unlocked( $order, $doc_type );
		} finally {
			OrderLock::release( $order->get_id(), $owner );
		}
	}

	private function delete_unlocked( WC_Order $order, string $doc_type ): bool {
		$doc = OrderMeta::get( $order, $doc_type );
		if ( null === $doc ) {
			return false;
		}

		$this->factory->create()->delete_document(
			$doc_type,
			(string) $this->settings->get( 'cif' ),
			$doc['series'],
			$doc['number'],
			array( 'deleteCollect' => 1 )
		);

		OrderMeta::clear( $order, $doc_type );

		do_action( 'oblio_fgwoo_document_deleted', $order, $doc_type, $doc );
		$this->logger->info( sprintf( 'Order #%d: %s %s %s deleted', $order->get_id(), $doc_type, $doc['series'], $doc['number'] ) );

		return true;
	}

	private function maybe_drop_proforma( WC_Order $order, string $doc_type ): void {
		if ( OrderMeta::TYPE_INVOICE !== $doc_type ) {
			return;
		}
		if ( null === OrderMeta::get( $order, OrderMeta::TYPE_PROFORMA ) ) {
			return;
		}
		if ( LifecyclePolicy::ON_INVOICE_DELETE !== $this->policy->proforma_on_invoice() ) {
			return;
		}
		try {
			$this->delete_unlocked( $order, OrderMeta::TYPE_PROFORMA );
		} catch ( \Throwable $exception ) {

			$this->logger->warning( 'Could not delete the proforma before the invoice: ' . $exception->getMessage() );
			throw $exception;
		}
	}
}
