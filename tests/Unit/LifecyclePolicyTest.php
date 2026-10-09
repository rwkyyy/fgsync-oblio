<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\LifecyclePolicy;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( LifecyclePolicy::class )]
final class LifecyclePolicyTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	private function order( string $gateway, bool $paid ): WC_Order {
		$order = new WC_Order( 1 );
		$order->set_payment_method( $gateway );
		$order->date_paid = $paid ? new \DateTimeImmutable( '2026-10-08' ) : null;
		return $order;
	}

	/**
	 * @return array<string,array{0:string,1:bool,2:bool}>
	 */
	public static function payments(): array {
		return array(
			'card, paid'               => array( 'stripe', true, true ),
			'card, not paid yet'       => array( 'stripe', false, false ),
			'cash on delivery, paid'   => array( 'cod', true, false ),
			'bank transfer, paid'      => array( 'bacs', true, false ),
			'cheque, paid'             => array( 'cheque', true, false ),
			'no gateway (admin order)' => array( '', true, false ),
		);
	}

	#[DataProvider( 'payments' )]
	public function test_paid_online( string $gateway, bool $paid, bool $expected ): void {
		$this->assertSame( $expected, LifecyclePolicy::paid_online( $this->order( $gateway, $paid ) ) );
	}

	public function test_refuses_a_proforma_for_an_order_paid_online(): void {
		$this->expectException( DocumentException::class );
		$this->expectExceptionMessage( 'deja plătită online' );

		( new LifecyclePolicy( new Settings() ) )->assert_can_issue( $this->order( 'stripe', true ), OrderMeta::TYPE_PROFORMA );
	}

	public function test_allows_a_proforma_for_cash_on_delivery_and_an_invoice_for_a_paid_order(): void {
		$policy = new LifecyclePolicy( new Settings() );

		$policy->assert_can_issue( $this->order( 'cod', true ), OrderMeta::TYPE_PROFORMA );
		$policy->assert_can_issue( $this->order( 'stripe', true ), OrderMeta::TYPE_INVOICE );

		$this->addToAssertionCount( 2 );
	}
}
