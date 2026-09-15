<?php
/**
 * Proforma / invoice lifecycle rules.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document;

use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Settings;
use WC_Order;
final class LifecyclePolicy {

	public const ON_INVOICE_TRANSFORM = 'transform';
	public const ON_INVOICE_DELETE    = 'delete';

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function assert_can_issue( WC_Order $order, string $doc_type ): void {
		if ( OrderMeta::TYPE_PROFORMA === $doc_type && OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			throw new DocumentException(
				esc_html__( 'Nu se poate emite proformă după ce a fost emisă factura.', 'oblio-fgwoo' )
			);
		}
	}

	public function proforma_on_invoice(): string {
		$value = (string) $this->settings->get( 'proforma_on_invoice' );
		return self::ON_INVOICE_DELETE === $value ? self::ON_INVOICE_DELETE : self::ON_INVOICE_TRANSFORM;
	}
}
