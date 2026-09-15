<?php
/**
 * Builds an Oblio document payload from a WooCommerce order.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document;

use OblioWoo\Document\Mapper\ClientMapper;
use OblioWoo\Document\Mapper\CollectMapper;
use OblioWoo\Document\Mapper\LineItemMapper;
use OblioWoo\Document\Mapper\ShippingFeeMapper;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Settings;
use WC_Order;
final class InvoiceBuilder {

	private Settings $settings;

	private ClientMapper $client_mapper;

	private LineItemMapper $line_mapper;

	private ShippingFeeMapper $shipping_mapper;

	private CollectMapper $collect_mapper;

	public function __construct(
		Settings $settings,
		ClientMapper $client_mapper,
		LineItemMapper $line_mapper,
		ShippingFeeMapper $shipping_mapper,
		CollectMapper $collect_mapper
	) {
		$this->settings        = $settings;
		$this->client_mapper   = $client_mapper;
		$this->line_mapper     = $line_mapper;
		$this->shipping_mapper = $shipping_mapper;
		$this->collect_mapper  = $collect_mapper;
	}

	public function build( WC_Order $order, string $doc_type, array $options = array() ): array {
		$cif    = (string) $this->settings->get( 'cif' );
		$series = $this->series_name( $doc_type );

		if ( '' === $cif || '' === $series ) {
			throw new DocumentException( esc_html__( 'Configurare incompletă: verifică Oblio → Setări.', 'oblio-fgwoo' ) );
		}

		$currency  = $this->currency( $order );
		$ctx       = BuildContext::from_settings( $this->settings, $currency, $this->document_language( $order ) );
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
			$data['products'] = $this->products( $order, $ctx );
		}

		if ( OrderMeta::TYPE_INVOICE === $doc_type ) {
			$collect = $this->collect_mapper->map( $order );
			if ( ! empty( $collect ) ) {
				$data['collect'] = $collect;
			}
		}

		return (array) apply_filters( 'oblio_fgwoo_document_data', $data, $order, $doc_type );
	}

	private function products( WC_Order $order, BuildContext $ctx ): array {
		$lines    = $this->line_mapper->map( $order, $ctx );
		$shipping = $this->shipping_mapper->map( $order, $ctx );

		$products = array_merge( $lines['products'], $shipping['products'] );
		$total    = $lines['total'] + $shipping['total'];

		$order_total = (float) $order->get_total();
		if ( number_format( $total, 2, '.', '' ) !== number_format( $order_total, 2, '.', '' ) ) {
			$difference = $order_total - $total;
			$products[] = array(
				'name'                     => $difference > 0
					? __( 'Alte taxe', 'oblio-fgwoo' )
					: __( 'Discount', 'oblio-fgwoo' ),
				'code'                     => '',
				'description'              => '',
				'price'                    => (float) number_format( $difference, 2, '.', '' ),
				'measuringUnit'            => $ctx->measuring_unit,
				'measuringUnitTranslation' => $ctx->measuring_unit_translation,
				'currency'                 => $ctx->currency,
				'vatName'                  => '',
				'vatPercentage'            => null,
				'vatIncluded'              => true,
				'quantity'                 => 1,
				'productType'              => 'Serviciu',
			);
		}

		if ( '0.00' === number_format( $total, 2, '.', '' ) ) {
			throw new DocumentException( esc_html__( 'Comanda are valoare 0.00.', 'oblio-fgwoo' ) );
		}

		return $products;
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

	private function document_language( WC_Order $order ): string {
		$order_lang = (string) $order->get_meta( 'wpml_language' );
		if ( '' !== $order_lang ) {
			$code = strtoupper( substr( $order_lang, 0, 2 ) );
			$code = 'ES' === $code ? 'SP' : $code;
		} else {
			$code = (string) $this->settings->get( 'language', 'RO' );
		}

		return (string) apply_filters( 'oblio_fgwoo_document_language', $code, $order );
	}

	private function currency( WC_Order $order ): string {
		$currency = substr( (string) $order->get_currency(), 0, 3 );
		return 'lei' === strtolower( $currency ) ? 'RON' : $currency;
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
		if ( OrderMeta::TYPE_INVOICE !== $doc_type ) {
			return array();
		}
		$proforma = OrderMeta::get( $order, OrderMeta::TYPE_PROFORMA );
		if ( null === $proforma ) {
			return array();
		}
		return array(
			'type'       => 'Proforma',
			'seriesName' => $proforma['series'],
			'number'     => $proforma['number'],
		);
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
		$key = sprintf( 'woocommerce-%s', str_pad( (string) $order->get_id(), 15, '0', STR_PAD_LEFT ) );
		if ( OrderMeta::TYPE_INVOICE !== $doc_type ) {
			$key .= '-' . $doc_type;
		}
		return $key;
	}
}
