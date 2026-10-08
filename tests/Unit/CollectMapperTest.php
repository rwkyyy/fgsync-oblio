<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Document\Mapper\CollectMapper;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( CollectMapper::class )]
final class CollectMapperTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
	}

	private function order( string $gateway ): WC_Order {
		$order = new WC_Order( 42 );
		$order->set_payment_method( $gateway );
		return $order;
	}

	public static function card_mode_cases(): array {
		return array(
			'cod is not collected'         => array( 'cod', array() ),
			'bacs is not collected'        => array( 'bacs', array() ),
			'card gateway is card'         => array( 'stripe', array( 'type' => 'Card', 'documentNumber' => '#42' ) ),
			'cheque is not collected'      => array( 'cheque', array() ),
			'no gateway is not collected'  => array( '', array() ),
		);
	}

	#[DataProvider( 'card_mode_cases' )]
	public function test_card_mode( string $gateway, array $expected ): void {
		$this->settings->set( 'collect_mode', 'card' );

		$this->assertSame( $expected, ( new CollectMapper( $this->settings ) )->map( $this->order( $gateway ) ) );
	}

	public function test_document_number_is_the_customer_facing_order_number(): void {
		$this->settings->set( 'collect_mode', 'card' );
		$order = $this->order( 'stripe' );
		$order->set_order_number( 'WEB-1042' );

		$this->assertSame( '#WEB-1042', ( new CollectMapper( $this->settings ) )->map( $order )['documentNumber'] );
	}

	public function test_off_mode_never_collects(): void {
		$this->settings->set( 'collect_mode', 'off' );

		$this->assertSame( array(), ( new CollectMapper( $this->settings ) )->map( $this->order( 'stripe' ) ) );
	}

	public function test_all_mode_uses_gateway_specific_types_and_honours_exceptions(): void {
		$this->settings->set( 'collect_mode', 'all' );
		$this->settings->set( 'collect_exceptions', array( 'cheque' ) );
		$mapper = new CollectMapper( $this->settings );

		$this->assertSame( 'Ramburs', $mapper->map( $this->order( 'cod' ) )['type'] );
		$this->assertSame( 'Ordin de plata', $mapper->map( $this->order( 'bacs' ) )['type'] );
		$this->assertSame( array(), $mapper->map( $this->order( 'cheque' ) ) );
	}

	public function test_selected_mode_only_collects_listed_gateways(): void {
		$this->settings->set( 'collect_mode', 'selected' );
		$this->settings->set( 'collect_gateways', array( 'stripe' ) );
		$mapper = new CollectMapper( $this->settings );

		$this->assertNotSame( array(), $mapper->map( $this->order( 'stripe' ) ) );
		$this->assertSame( array(), $mapper->map( $this->order( 'paypal' ) ) );
	}
}
