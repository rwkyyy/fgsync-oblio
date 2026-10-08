<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Order\RegularPriceSnapshot;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order_Item_Product;
use WC_Product;

#[CoversClass( RegularPriceSnapshot::class )]
final class RegularPriceSnapshotTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_register_hooks_checkout_line_creation_and_hides_the_keys(): void {
		$snapshot = new RegularPriceSnapshot();

		$snapshot->register();

		$this->assertSame(
			array(
				array( 'woocommerce_checkout_create_order_line_item', 'capture', 10, 3 ),
				array( 'woocommerce_hidden_order_itemmeta', 'hide', 10, 1 ),
			),
			array_map(
				static fn ( array $call ): array => array( $call['hook'], $call['callback'][1], $call['priority'], $call['accepted_args'] ),
				$GLOBALS['oblio_test_add_action_calls']
			)
		);
	}

	public function test_capture_records_the_cart_products_regular_and_active_price(): void {
		$item = new WC_Order_Item_Product();

		( new RegularPriceSnapshot() )->capture( $item, 'key', array( 'data' => new WC_Product( array( 'regular_price' => '99.90', 'price' => '79.90' ) ) ) );

		$this->assertSame( '99.90', $item->get_meta( RegularPriceSnapshot::META_KEY ) );
		$this->assertSame( '79.90', $item->get_meta( RegularPriceSnapshot::META_PRICE_KEY ) );
	}

	public function test_capture_skips_products_without_a_regular_price(): void {
		$item = new WC_Order_Item_Product();

		( new RegularPriceSnapshot() )->capture( $item, 'key', array( 'data' => new WC_Product( array( 'price' => '10' ) ) ) );

		$this->assertSame( '', $item->get_meta( RegularPriceSnapshot::META_KEY ) );
		$this->assertSame( '', $item->get_meta( RegularPriceSnapshot::META_PRICE_KEY ) );
	}

	public function test_capture_ignores_cart_items_without_a_product(): void {
		$item = new WC_Order_Item_Product();

		( new RegularPriceSnapshot() )->capture( $item, 'key', array() );

		$this->assertSame( '', $item->get_meta( RegularPriceSnapshot::META_KEY ) );
	}

	public function test_both_keys_are_hidden_from_order_item_display(): void {
		$keys = ( new RegularPriceSnapshot() )->hide( array( '_qty' ) );

		$this->assertSame( array( '_qty', RegularPriceSnapshot::META_KEY, RegularPriceSnapshot::META_PRICE_KEY ), $keys );
	}
}
