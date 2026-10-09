<?php
/**
 * Refunds already on an order when a document is built from its lines.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Document\Mapper\LineVat;
use WC_Order;
use WC_Order_Item_Fee;
use WC_Order_Item_Product;
use WC_Order_Item_Shipping;
use WC_Order_Refund;
final class PriorRefunds {

	/** @var array<int,int> */
	public readonly array $refund_ids;

	public readonly float $amount;

	/** @var array<int,array{quantity:float,total:float,total_tax:float,taxes:array<int|string,float>}> */
	private array $items;

	/**
	 * @param array<int,int>                                                                             $refund_ids Refund IDs.
	 * @param float                                                                                      $amount     Total refunded.
	 * @param array<int,array{quantity:float,total:float,total_tax:float,taxes:array<int|string,float>}> $items      Refunded per original item ID.
	 */
	private function __construct( array $refund_ids, float $amount, array $items ) {
		$this->refund_ids = $refund_ids;
		$this->amount     = $amount;
		$this->items      = $items;
	}

	public static function none(): self {
		return new self( array(), 0.0, array() );
	}

	/**
	 * One pass over the refunds, summing what each original item (product,
	 * shipping, fee) had refunded, as positive amounts.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function for_order( WC_Order $order ): self {
		$ids    = array();
		$amount = 0.0;
		$items  = array();
		foreach ( $order->get_refunds() as $refund ) {
			if ( ! $refund instanceof WC_Order_Refund ) {
				continue;
			}
			$ids[]   = (int) $refund->get_id();
			$amount += abs( (float) $refund->get_amount() );
			foreach ( array( 'line_item', 'shipping', 'fee' ) as $type ) {
				foreach ( $refund->get_items( $type ) as $item ) {
					if ( ! $item instanceof WC_Order_Item_Product && ! $item instanceof WC_Order_Item_Shipping && ! $item instanceof WC_Order_Item_Fee ) {
						continue;
					}
					$original = (int) $item->get_meta( '_refunded_item_id' );
					if ( $original <= 0 ) {
						continue;
					}
					$entry               = $items[ $original ] ?? array(
						'quantity'  => 0.0,
						'total'     => 0.0,
						'total_tax' => 0.0,
						'taxes'     => array(),
					);
					$entry['quantity']  += $item instanceof WC_Order_Item_Product ? abs( (float) $item->get_quantity() ) : 0.0;
					$entry['total']     += abs( (float) $item->get_total() );
					$entry['total_tax'] += abs( (float) $item->get_total_tax() );
					foreach ( array_filter( LineVat::item_taxes( $item ), 'is_numeric' ) as $rate_id => $tax ) {
						$entry['taxes'][ $rate_id ] = ( $entry['taxes'][ $rate_id ] ?? 0.0 ) + abs( (float) $tax );
					}
					$items[ $original ] = $entry;
				}
			}
		}
		return new self( $ids, $amount, $items );
	}

	/**
	 * @param int $item_id Original order item ID.
	 * @return array{quantity:float,total:float,total_tax:float,taxes:array<int|string,float>}
	 */
	public function for_item( int $item_id ): array {
		return $this->items[ $item_id ] ?? array(
			'quantity'  => 0.0,
			'total'     => 0.0,
			'total_tax' => 0.0,
			'taxes'     => array(),
		);
	}

	public function is_empty(): bool {
		return array() === $this->refund_ids;
	}

	public function covers( WC_Order $order ): bool {
		return ! $this->is_empty() && round( (float) $order->get_total() - $this->amount, 2 ) <= 0.0;
	}
}
