<?php
/**
 * Builds an Oblio document payload from a WooCommerce order.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Document\Mapper\ClientMapper;
use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Document\Mapper\LineItemMapper;
use FGSyncOblio\Document\Mapper\LineVat;
use FGSyncOblio\Document\Mapper\ShippingFeeMapper;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Settings;
use WC_Order;
final class InvoiceBuilder {

	private Settings $settings;

	private ClientMapper $client_mapper;

	private LineItemMapper $line_mapper;

	private ShippingFeeMapper $shipping_mapper;

	private CollectMapper $collect_mapper;

	private VatCategories $vat_categories;

	public function __construct(
		Settings $settings,
		ClientMapper $client_mapper,
		LineItemMapper $line_mapper,
		ShippingFeeMapper $shipping_mapper,
		CollectMapper $collect_mapper,
		VatCategories $vat_categories
	) {
		$this->settings        = $settings;
		$this->client_mapper   = $client_mapper;
		$this->line_mapper     = $line_mapper;
		$this->shipping_mapper = $shipping_mapper;
		$this->collect_mapper  = $collect_mapper;
		$this->vat_categories  = $vat_categories;
	}

	public function build( WC_Order $order, string $doc_type, array $options = array(), ?PriorRefunds $refunds = null ): array {
		$cif    = (string) $this->settings->get( 'cif' );
		$series = $this->series_name( $doc_type );

		if ( '' === $cif || '' === $series ) {
			throw new DocumentException( esc_html__( 'Configurare incompletă: verifică Oblio → Setări.', 'fgsync-oblio' ) );
		}

		$ctx       = BuildContext::for_order( $this->settings, $order );
		$currency  = $ctx->currency;
		$issue     = $this->issue_date( $order, $options );
		$reference = $this->reference_document( $order, $doc_type, $options );

		$data = array(
			'cif'                => $cif,
			'client'             => $this->client_mapper->map(
				$order,
				array( 'autocomplete' => (int) $this->settings->get( 'autocomplete_company', 0 ) )
			),
			'issueDate'          => $issue,
			'dueDate'            => $this->due_date( $issue ),
			'seriesName'         => $series,
			'language'           => $ctx->language,
			'precision'          => $ctx->precision,
			'currency'           => $currency,
			'products'           => array(),
			'issuerName'         => (string) $this->settings->get( 'invoice_issuer_name', '' ),
			'issuerId'           => (string) $this->settings->get( 'invoice_issuer_id', '' ),
			'deputyName'         => (string) $this->settings->get( 'invoice_deputy_name', '' ),
			'deputyIdentityCard' => (string) $this->settings->get( 'invoice_deputy_identity_card', '' ),
			'deputyAuto'         => (string) $this->settings->get( 'invoice_deputy_auto', '' ),
			'selesAgent'         => (string) $this->settings->get( 'invoice_seles_agent', '' ),
			'mentions'           => $this->mentions( $order ),
			'workStation'        => (string) $this->settings->get( 'workstation', '' ),
			'idempotencyKey'     => $this->idempotency_key( $order, $doc_type ),
		);

		if ( OrderMeta::TYPE_INVOICE === $doc_type ) {
			$data['useStock'] = empty( $options['use_stock'] ) ? 0 : 1;
		}

		if ( ! empty( $reference ) ) {
			$data['referenceDocument'] = $reference;
		} else {
			$data['products'] = $this->products( $order, $ctx, $refunds ?? PriorRefunds::for_order( $order ) );
		}

		if ( OrderMeta::TYPE_INVOICE === $doc_type ) {
			$collect = $this->collect_mapper->map( $order );
			if ( ! empty( $collect ) ) {
				$data['collect'] = $collect;
			}
		}

		return (array) apply_filters( 'oblio_fgwoo_document_data', $data, $order, $doc_type );
	}

	/**
	 * Refunds the document built from this order takes in, so they get no
	 * storno of their own: those on the order now, or, for an invoice built
	 * on an aviz or proforma, the ones that document took in.
	 *
	 * @param WC_Order            $order    Order.
	 * @param string              $doc_type Document type.
	 * @param array<string,mixed> $options  Issue options.
	 * @param PriorRefunds        $refunds  Refunds the lines were built with.
	 * @return array<int,int>
	 */
	public function netted_refund_ids( WC_Order $order, string $doc_type, array $options, PriorRefunds $refunds ): array {
		if ( isset( $options['reference'] ) && is_array( $options['reference'] ) ) {
			return array();
		}
		$referenced = $this->referenced_type( $order, $doc_type );
		if ( null !== $referenced ) {
			return OrderMeta::netted_refunds( $order, $referenced );
		}
		return $refunds->refund_ids;
	}

	private function products( WC_Order $order, BuildContext $ctx, PriorRefunds $refunds ): array {
		if ( $refunds->covers( $order ) ) {
			throw new FullyRefundedException( esc_html__( 'Comanda a fost rambursată integral, nu mai este nimic de facturat.', 'fgsync-oblio' ) );
		}

		$lines    = $this->line_mapper->map( $order, $ctx, $refunds );
		$shipping = $this->shipping_mapper->map( $order, $ctx, $refunds );

		$products = array_merge( $lines['products'], $shipping['products'] );
		$total    = $lines['total'] + $shipping['total'];

		$order_total = (float) $order->get_total() - $refunds->amount;
		if ( number_format( $total, 2, '.', '' ) !== number_format( $order_total, 2, '.', '' ) ) {
			$difference = $order_total - $total;
			$products[] = array(
				'name'                     => $difference > 0
					? __( 'Alte taxe', 'fgsync-oblio' )
					: __( 'Discount', 'fgsync-oblio' ),
				'code'                     => '',
				'description'              => '',
				'price'                    => (float) number_format( $difference, 2, '.', '' ),
				'measuringUnit'            => $ctx->measuring_unit,
				'measuringUnitTranslation' => $ctx->measuring_unit_translation,
				'currency'                 => $ctx->currency,
			)
				+ LineVat::document_fields( $ctx )
				+ array(
					'quantity'    => 1,
					'productType' => 'Serviciu',
				);
		}

		if ( '0.00' === number_format( $total, 2, '.', '' ) ) {
			throw new DocumentException( esc_html__( 'Comanda are valoare 0.00.', 'fgsync-oblio' ) );
		}

		return $this->vat_categories->apply( $products );
	}

	private function series_name( string $doc_type ): string {
		switch ( $doc_type ) {
			case OrderMeta::TYPE_PROFORMA:
				return (string) $this->settings->get( 'series_proforma' );
			case OrderMeta::TYPE_NOTICE:
				return (string) $this->settings->get( 'series_notice', '' );
			default:
				return (string) $this->settings->get( 'series_invoice' );
		}
	}

	private function issue_date( WC_Order $order, array $options ): string {
		if ( ! empty( $options['date'] ) ) {
			return (string) $options['date'];
		}
		if ( 'order' === (string) $this->settings->get( 'issue_date_basis' ) ) {
			$created = $order->get_date_created();
			if ( $created ) {
				return $created->format( 'Y-m-d' );
			}
		}
		return current_time( 'Y-m-d' );
	}

	private function due_date( string $issue_date ): string {
		$days = (int) $this->settings->get( 'invoice_due', 0 );
		if ( $days <= 0 ) {
			return '';
		}
		return gmdate( 'Y-m-d', (int) strtotime( $issue_date ) + $days * DAY_IN_SECONDS );
	}

	private function reference_document( WC_Order $order, string $doc_type, array $options ): array {
		if ( isset( $options['reference'] ) && is_array( $options['reference'] ) ) {
			return $options['reference'];
		}
		$type = $this->referenced_type( $order, $doc_type );
		if ( null === $type ) {
			return array();
		}
		$document = (array) OrderMeta::get( $order, $type );
		return array(
			'type'       => OrderMeta::TYPE_NOTICE === $type ? 'Aviz' : 'Proforma',
			'seriesName' => $document['series'],
			'number'     => $document['number'],
		);
	}

	/**
	 * An aviz already took the goods out of stock, so the invoice is built on
	 * it rather than issued again as a new sale; it wins over a proforma.
	 *
	 * @param WC_Order $order    Order.
	 * @param string   $doc_type Document type being issued.
	 */
	private function referenced_type( WC_Order $order, string $doc_type ): ?string {
		if ( OrderMeta::TYPE_INVOICE !== $doc_type ) {
			return null;
		}
		foreach ( array( OrderMeta::TYPE_NOTICE, OrderMeta::TYPE_PROFORMA ) as $type ) {
			if ( null !== OrderMeta::get( $order, $type ) ) {
				return $type;
			}
		}
		return null;
	}

	private function mentions( WC_Order $order ): string {
		$template = (string) $this->settings->get( 'invoice_mentions', '' );
		if ( '' === $template ) {
			return '';
		}
		$created = $order->get_date_created();
		return str_replace(
			array( '[order_id]', '[date]', '[payment]', '[shipping]', '[site]' ),
			array(
				'#' . $order->get_order_number(),
				$created ? $created->date_i18n( 'd.m.Y' ) : '',
				$order->get_payment_method_title(),
				$order->get_shipping_method(),
				get_bloginfo( 'name' ),
			),
			$template
		);
	}

	private function idempotency_key( WC_Order $order, string $doc_type ): string {
		$legacy = sprintf( 'woocommerce-%s', str_pad( (string) $order->get_id(), 15, '0', STR_PAD_LEFT ) );
		if ( OrderMeta::TYPE_INVOICE !== $doc_type ) {
			$legacy .= '-' . $doc_type;
		}

		$fresh = $this->has_prior_oblio_activity( $order )
			? $legacy
			: $this->install_namespace() . '-' . $legacy;

		return OrderMeta::persisted_idempotency_key( $order, OrderMeta::key( $doc_type, 'idempotency' ), $fresh );
	}

	private function has_prior_oblio_activity( WC_Order $order ): bool {
		foreach ( array( OrderMeta::TYPE_INVOICE, OrderMeta::TYPE_PROFORMA, OrderMeta::TYPE_NOTICE, OrderMeta::TYPE_STORNO ) as $type ) {
			if ( OrderMeta::has( $order, $type ) ) {
				return true;
			}
		}
		return '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) );
	}

	private function install_namespace(): string {
		return substr( md5( site_url() . '|' . (string) $this->settings->get( 'cif' ) ), 0, 12 );
	}
}
