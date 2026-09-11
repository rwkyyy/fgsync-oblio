<?php
/**
 * Decides whether/how to mark an invoice as collected (paid).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document\Mapper;

use OblioWoo\Support\Settings;
use WC_Order;
final class CollectMapper {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function map( WC_Order $order ): array {
		if ( ! $this->should_collect( $order ) ) {
			return array();
		}

		return array(
			'type'           => $this->collect_type( $order ),
			'documentNumber' => '#' . $order->get_id(),
		);
	}

	public function should_collect( WC_Order $order ): bool {
		$gateway    = $order->get_payment_method();
		$mode       = (string) $this->settings->get( 'collect_mode' );
		$exceptions = (array) $this->settings->get( 'collect_exceptions' );
		$selected   = (array) $this->settings->get( 'collect_gateways' );

		$decision = false;
		switch ( $mode ) {
			case 'all':
				$decision = ! in_array( $gateway, $exceptions, true );
				break;
			case 'selected':
				$decision = in_array( $gateway, $selected, true ) && ! in_array( $gateway, $exceptions, true );
				break;
			case 'card':
				$decision = ! in_array( $gateway, array( 'bacs', 'cod' ), true );
				break;
			case 'off':
			default:
				$decision = false;
		}

		return (bool) apply_filters( 'oblio_fgwoo_collection_is_paid', $decision, $order, $gateway );
	}

	public function collect_type( WC_Order $order ): string {
		$gateway = $order->get_payment_method();
		switch ( $gateway ) {
			case 'cod':
				$type = 'Ramburs';
				break;
			case 'bacs':
				$type = 'Ordin de plata';
				break;
			default:
				$type = 'Card';
		}

		return (string) apply_filters( 'oblio_fgwoo_collect_type', $type, $order, $gateway );
	}
}
