<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Refund\RefundService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Refund\RefundService::class )]
final class RefundServiceTest extends TestCase {

	public function test_no_adjustment_when_lines_match_the_refund(): void {
		$this->assertNull( RefundService::storno_adjustment( 100.0, 100.0 ) );
	}

	public function test_no_adjustment_for_sub_bani_rounding(): void {
		$this->assertNull( RefundService::storno_adjustment( 100.0, 99.996 ) );
	}

	public function test_shortfall_adds_a_minus_line(): void {
		$adjustment = RefundService::storno_adjustment( 100.0, 90.0 );
		$this->assertSame( array( 'price' => 10.0, 'quantity' => -1 ), $adjustment );
	}

	public function test_overshoot_trims_with_a_plus_line(): void {
		$adjustment = RefundService::storno_adjustment( 90.0, 100.0 );
		$this->assertSame( array( 'price' => 10.0, 'quantity' => 1 ), $adjustment );
	}

	public function test_adjustment_makes_the_total_exact(): void {
		$target     = 123.45;
		$magnitude  = 120.00;
		$adjustment = RefundService::storno_adjustment( $target, $magnitude );
		$this->assertNotNull( $adjustment );

		$reconciled = $magnitude + $adjustment['price'] * ( $adjustment['quantity'] < 0 ? 1 : -1 );
		$this->assertEqualsWithDelta( $target, $reconciled, 0.001 );
	}
}
