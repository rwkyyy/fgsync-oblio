<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\OrderListColumn;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Order\OrderMeta;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Admin\OrderListColumn::class )]
final class OrderListColumnTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_the_failure_tooltip_shows_oblio_markup_as_plain_text(): void {
		$order = new WC_Order( 80911 );
		$order->update_meta_data(
			OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ),
			esc_html( 'Produsele urmatoare nu au stoc suficient: <b>Natural statine forte, 60cps, Hypericum</b>.' )
		);

		ob_start();
		( new OrderListColumn( new OrderStore() ) )->render( 'oblio_fgwoo', $order );
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'title="Produsele urmatoare nu au stoc suficient: Natural statine forte, 60cps, Hypericum."', $output );
	}
}
