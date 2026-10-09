<?php
/**
 * Issues storno (refund) documents for WooCommerce refunds.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Refund;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Document\BuildContext;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\LineVat;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\OrderLock;
use FGSyncOblio\Support\Settings;
use RuntimeException;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Refund;
final class RefundService implements RefundIssuer {

	private Settings $settings;

	private ClientFactory $factory;

	private OrderStore $orders;

	private Logger $logger;

	private LineItemMapper $line_mapper;

	private ShippingFeeMapper $shipping_mapper;

	private VatCategories $vat_categories;

	public function __construct( Settings $settings, ClientFactory $factory, OrderStore $orders, Logger $logger, LineItemMapper $line_mapper, ShippingFeeMapper $shipping_mapper, VatCategories $vat_categories ) {
		$this->settings        = $settings;
		$this->factory         = $factory;
		$this->orders          = $orders;
		$this->logger          = $logger;
		$this->line_mapper     = $line_mapper;
		$this->shipping_mapper = $shipping_mapper;
		$this->vat_categories  = $vat_categories;
	}

	public function issue_for_refund( int $order_id, int $refund_id, bool $fail_fast = false ): ?DocumentResult {
		$order = $this->orders->get_order( $order_id );
		if ( null === $order ) {
			return null;
		}

		$refund = wc_get_order( $refund_id );
		if ( ! $refund instanceof WC_Order_Refund ) {
			return null;
		}
		// Every other caller derives $order_id and $refund_id consistently by
		// construction (Reconciler gets $order from $refund->get_parent_id()
		// itself; queue payloads are built the same way) - this guard protects
		// the one exception, ReturnsIntegration's independent order/refund
		// resolution from an external WC Returns event, from ever building a
		// storno against a mismatched order/refund pair.
		if ( $refund->get_parent_id() !== $order_id ) {
			$this->logger->error( sprintf( 'Refund #%d does not belong to order #%d, refusing to issue a storno', $refund_id, $order_id ) );
			return null;
		}

		$owner = OrderLock::acquire( $order_id, $fail_fast ? OrderLock::FAIL_FAST : OrderLock::WAIT );
		if ( null === $owner ) {
			throw new RuntimeException( esc_html__( 'Un alt proces emite deja un document pentru comandă; se va reîncerca automat.', 'fgsync-oblio' ) );
		}

		try {
			$refreshed = $this->orders->get_order( $order_id );
			if ( null !== $refreshed ) {
				$order = $refreshed;
			}

			$guard_key = 'oblio_fgwoo_storno_refund_' . $refund_id;
			if ( '' !== (string) $order->get_meta( $guard_key ) ) {
				return null;
			}

			$invoice = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
			if ( null === $invoice ) {
				throw new DocumentException( esc_html__( 'Nu există factură pentru care să se emită storno.', 'fgsync-oblio' ) );
			}

			if ( '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' ) ) ) {
				return null;
			}

			if ( in_array( $refund_id, OrderMeta::netted_refunds( $order, OrderMeta::TYPE_INVOICE ), true ) ) {
				$this->logger->info( sprintf( 'Order #%d refund #%d: already taken off invoice %s %s, no storno needed', $order_id, $refund_id, $invoice['series'], $invoice['number'] ) );
				return null;
			}

			$is_full = $this->is_full_refund( $order, $refund );
			$payload = $this->build_storno( $order, $invoice, $refund, $is_full, $refund_id );

			OrderLock::renew( $order_id, $owner );
			$data   = $this->factory->create()->create_document( OrderMeta::TYPE_INVOICE, $payload );
			$result = DocumentResult::from_api( OrderMeta::TYPE_STORNO, $data );

			if ( '' === $result->series_name || '' === $result->number || '' === $result->link ) {
				throw new ApiException( esc_html__( 'Răspuns incomplet de la Oblio; se reîncearcă automat pentru a evita un document duplicat.', 'fgsync-oblio' ) );
			}

			$order->update_meta_data(
				$guard_key,
				(string) wp_json_encode(
					array(
						'series' => $result->series_name,
						'number' => $result->number,
						'link'   => $result->link,
					)
				)
			);
			if ( $is_full ) {
				$order->update_meta_data( OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' ), current_time( 'mysql' ) );
			}
			$this->record_storno( $order, $result, $is_full );
			OrderMeta::save( $order, $result );
		} finally {
			OrderLock::release( $order_id, $owner );
		}

		// Runs after the lock is released, same reasoning as DocumentService::issue():
		// the storno is already persisted, so a hook exception here doesn't leave a
		// retry silently skipping it forever, and a slow callback doesn't extend
		// lock contention.
		try {
			do_action( 'oblio_fgwoo_storno_issued', $order, $result, $refund_id, $is_full );
		} catch ( \Throwable $exception ) {
			$this->logger->error( sprintf( 'Order #%d refund #%d: post-issue hook failed for storno %s %s: %s', $order_id, $refund_id, $result->series_name, $result->number, $exception->getMessage() ) );
		}

		$this->logger->info( sprintf( 'Order #%d refund #%d: storno %s %s (%s) issued', $order_id, $refund_id, $result->series_name, $result->number, $is_full ? 'full' : 'partial' ) );

		return $result;
	}

	public function issue_full_storno( WC_Order $order, bool $fail_fast = false ): DocumentResult {
		$order_id = $order->get_id();
		$owner    = OrderLock::acquire( $order_id, $fail_fast ? OrderLock::FAIL_FAST : OrderLock::WAIT );
		if ( null === $owner ) {
			throw new RuntimeException( esc_html__( 'Un alt proces emite deja un document pentru comandă; se va reîncerca automat.', 'fgsync-oblio' ) );
		}

		try {
			$refreshed = $this->orders->get_order( $order_id );
			if ( null !== $refreshed ) {
				$order = $refreshed;
			}

			$invoice = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
			if ( null === $invoice ) {
				throw new DocumentException( esc_html__( 'Nu există factură pentru care să se emită storno.', 'fgsync-oblio' ) );
			}

			$full_key = OrderMeta::key( OrderMeta::TYPE_STORNO, 'full' );

			if ( '' !== (string) $order->get_meta( $full_key ) ) {
				$existing = OrderMeta::get( $order, OrderMeta::TYPE_STORNO );
				if ( null !== $existing ) {
					return new DocumentResult( OrderMeta::TYPE_STORNO, $existing['series'], $existing['number'], $existing['link'] );
				}
			}

			if ( null !== OrderMeta::get( $order, OrderMeta::TYPE_STORNO ) ) {
				throw new DocumentException( esc_html__( 'Există deja un storno pentru această factură. Storneaz restul printr-o rambursare WooCommerce.', 'fgsync-oblio' ) );
			}

			$payload = array(
				'cif'               => (string) $this->settings->get( 'cif' ),
				'seriesName'        => (string) $this->settings->get( 'series_invoice' ),
				'referenceDocument' => array(
					'type'       => 'Factura',
					'refund'     => 1,
					'seriesName' => $invoice['series'],
					'number'     => $invoice['number'],
				),
				'idempotencyKey'    => $this->storno_idempotency_key( $order, 0 ),
			);

			$payload = (array) apply_filters( 'oblio_fgwoo_storno_data', $payload, $order, null, true );

			OrderLock::renew( $order_id, $owner );
			$data   = $this->factory->create()->create_document( OrderMeta::TYPE_INVOICE, $payload );
			$result = DocumentResult::from_api( OrderMeta::TYPE_STORNO, $data );

			if ( '' === $result->series_name || '' === $result->number || '' === $result->link ) {
				throw new ApiException( esc_html__( 'Răspuns incomplet de la Oblio; se reîncearcă automat pentru a evita un document duplicat.', 'fgsync-oblio' ) );
			}

			$order->update_meta_data( $full_key, current_time( 'mysql' ) );
			$this->record_storno( $order, $result, true );
			OrderMeta::save( $order, $result );
		} finally {
			OrderLock::release( $order_id, $owner );
		}

		try {
			do_action( 'oblio_fgwoo_storno_issued', $order, $result, 0, true );
		} catch ( \Throwable $exception ) {
			$this->logger->error( sprintf( 'Order #%d: post-issue hook failed for full storno %s %s: %s', $order->get_id(), $result->series_name, $result->number, $exception->getMessage() ) );
		}

		$this->logger->info( sprintf( 'Order #%d: manual full storno %s %s issued', $order->get_id(), $result->series_name, $result->number ) );

		return $result;
	}

	private function record_storno( WC_Order $order, DocumentResult $result, bool $is_full ): void {
		$list   = $order->get_meta( OrderMeta::STORNO_LIST );
		$list   = is_array( $list ) ? $list : array();
		$list[] = array(
			'series' => $result->series_name,
			'number' => $result->number,
			'link'   => $result->link,
			'full'   => $is_full,
			'date'   => current_time( 'mysql' ),
		);
		$order->update_meta_data( OrderMeta::STORNO_LIST, $list );
	}

	/**
	 * Measured against the invoice total, which leaves out refunds it netted.
	 *
	 * @param WC_Order        $order  Order.
	 * @param WC_Order_Refund $refund Refund.
	 */
	private function is_full_refund( WC_Order $order, WC_Order_Refund $refund ): bool {
		$invoiced = (float) $order->get_total();
		$netted   = OrderMeta::netted_refunds( $order, OrderMeta::TYPE_INVOICE );
		foreach ( $order->get_refunds() as $prior ) {
			if ( $prior instanceof WC_Order_Refund && in_array( (int) $prior->get_id(), $netted, true ) ) {
				$invoiced -= abs( (float) $prior->get_amount() );
			}
		}
		return abs( abs( (float) $refund->get_amount() ) - $invoiced ) < 0.01;
	}

	private function build_storno( WC_Order $order, array $invoice, WC_Order_Refund $refund, bool $is_full, int $refund_id ): array {
		$ctx     = BuildContext::for_order( $this->settings, $order );
		$payload = array(
			'cif'               => (string) $this->settings->get( 'cif' ),
			'seriesName'        => (string) $this->settings->get( 'series_invoice' ),
			'language'          => $ctx->language,
			'referenceDocument' => array(
				'type'       => 'Factura',

				'refund'     => $is_full ? 1 : 0,
				'seriesName' => $invoice['series'],
				'number'     => $invoice['number'],
			),
			'idempotencyKey'    => $this->storno_idempotency_key( $order, $refund_id ),
		);

		if ( ! $is_full ) {

			$products = $this->refund_products( $order, $refund, $ctx );
			if ( empty( $products ) ) {

				$products = $this->amount_only_line( $refund, $ctx );
			}
			$payload['products'] = $this->vat_categories->apply( $this->reconcile_products( $products, $refund, $ctx ) );
		}

		return (array) apply_filters( 'oblio_fgwoo_storno_data', $payload, $order, $refund, $is_full );
	}

	private function storno_idempotency_key( WC_Order $order, int $refund_id ): string {
		$legacy = 0 === $refund_id
			? sprintf( 'woocommerce-%s-storno', str_pad( (string) $order->get_id(), 15, '0', STR_PAD_LEFT ) )
			: sprintf( 'woocommerce-%s-storno-%d', str_pad( (string) $order->get_id(), 15, '0', STR_PAD_LEFT ), $refund_id );

		$fresh = $this->has_prior_oblio_activity( $order )
			? $legacy
			: $this->install_namespace() . '-' . $legacy;

		return OrderMeta::persisted_idempotency_key( $order, OrderMeta::key( OrderMeta::TYPE_STORNO, 'idempotency_' . $refund_id ), $fresh );
	}

	private function has_prior_oblio_activity( WC_Order $order ): bool {
		// A storno's invoice always exists by definition, so inherit ITS key format instead
		// of treating that existence as a signal.
		$invoice_key = (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'idempotency' ) );
		if ( '' !== $invoice_key ) {
			return 0 !== strpos( $invoice_key, $this->install_namespace() . '-' );
		}

		foreach ( array( OrderMeta::TYPE_PROFORMA, OrderMeta::TYPE_NOTICE, OrderMeta::TYPE_STORNO ) as $type ) {
			if ( OrderMeta::has( $order, $type ) ) {
				return true;
			}
		}
		return '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) );
	}

	private function install_namespace(): string {
		return substr( md5( site_url() . '|' . (string) $this->settings->get( 'cif' ) ), 0, 12 );
	}

	private function refund_products( WC_Order $order, WC_Order_Refund $refund, BuildContext $ctx ): array {
		$products = $this->line_mapper->storno_lines( $order, $refund, $ctx );

		foreach ( $refund->get_items( 'fee' ) as $fee ) {
			if ( ! $fee instanceof WC_Order_Item_Fee ) {
				continue;
			}
			$net = abs( (float) $fee->get_total() );
			$tax = abs( (float) $fee->get_total_tax() );
			if ( $net + $tax > 0 ) {
				$products[] = $this->shipping_mapper->service_line( $fee->get_name(), $net + $tax, $net, $tax, $ctx, -1, LineVat::item_taxes( $fee ) );
			}
		}

		foreach ( $this->shipping_mapper->shipping_lines( $refund, $ctx, -1 ) as $line ) {
			$products[] = $line;
		}

		return $products;
	}

	private function amount_only_line( WC_Order_Refund $refund, BuildContext $ctx ): array {
		$amount = abs( (float) $refund->get_amount() );
		if ( $amount <= 0 ) {
			return array();
		}

		$reason = trim( (string) $refund->get_reason() );

		return array( $this->adjustment_line( '' !== $reason ? $reason : __( 'Rambursare', 'fgsync-oblio' ), round( $amount, $ctx->precision + 2 ), -1, $ctx ) );
	}

	private function reconcile_products( array $products, WC_Order_Refund $refund, BuildContext $ctx ): array {
		$target = round( abs( (float) $refund->get_amount() ), 2 );
		if ( $target <= 0 || empty( $products ) ) {
			return $products;
		}

		$magnitude = 0.0;
		foreach ( $products as $line ) {

			$magnitude += (float) $line['price'] * abs( (float) $line['quantity'] );
		}

		$adjustment = self::storno_adjustment( $target, $magnitude );
		if ( null === $adjustment ) {
			return $products;
		}

		$products[] = $this->adjustment_line( __( 'Ajustare storno', 'fgsync-oblio' ), $adjustment['price'], $adjustment['quantity'], $ctx );

		return $products;
	}

	/**
	 * @param string       $name     Line name.
	 * @param float        $price    Gross unit price.
	 * @param int          $quantity Signed quantity.
	 * @param BuildContext $ctx      Document context.
	 * @return array<string,mixed>
	 */
	private function adjustment_line( string $name, float $price, int $quantity, BuildContext $ctx ): array {
		return array(
			'name'                     => $name,
			'code'                     => '',
			'price'                    => $price,
			'measuringUnit'            => $ctx->measuring_unit,
			'measuringUnitTranslation' => $ctx->measuring_unit_translation,
			'currency'                 => $ctx->currency,
		)
			+ LineVat::document_fields( $ctx )
			+ array(
				'quantity'    => $quantity,
				'productType' => 'Serviciu',
			);
	}

	public static function storno_adjustment( float $target, float $magnitude ): ?array {
		$difference = round( $target - round( $magnitude, 2 ), 2 );
		if ( abs( $difference ) < 0.01 ) {
			return null;
		}
		return array(
			'price'    => abs( $difference ),

			'quantity' => $difference > 0 ? -1 : 1,
		);
	}
}
