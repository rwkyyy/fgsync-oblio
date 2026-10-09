<?php
/**
 * Proforma / invoice lifecycle rules.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Settings;
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
				esc_html__( 'Nu se poate emite proformă după ce a fost emisă factura.', 'fgsync-oblio' )
			);
		}
		if ( OrderMeta::TYPE_PROFORMA === $doc_type && self::paid_online( $order ) ) {
			throw new DocumentException(
				esc_html__( 'Comanda a fost deja plătită online, deci nu are nevoie de proformă (proforma este o cerere de plată).', 'fgsync-oblio' )
			);
		}
	}

	/**
	 * Paid through an online gateway. WooCommerce also records a payment date
	 * when a cash-on-delivery or bank-transfer order moves to "processing",
	 * so the date alone doesn't mean the money arrived.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function paid_online( WC_Order $order ): bool {
		return null !== $order->get_date_paid() && ! in_array( $order->get_payment_method(), CollectMapper::OFFLINE_GATEWAYS, true );
	}

	public function proforma_on_invoice(): string {
		$value = (string) $this->settings->get( 'proforma_on_invoice' );
		return self::ON_INVOICE_DELETE === $value ? self::ON_INVOICE_DELETE : self::ON_INVOICE_TRANSFORM;
	}
}
