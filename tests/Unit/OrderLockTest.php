<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Support\OrderLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Support\OrderLock::class )]
final class OrderLockTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_acquire_on_a_free_order_succeeds(): void {
		$owner = OrderLock::acquire( 42 );
		$this->assertIsString( $owner );
		$this->assertNotSame( '', $owner );
	}

	public function test_fail_fast_returns_null_immediately_on_contention(): void {
		OrderLock::acquire( 42 );

		$start = microtime( true );
		$result = OrderLock::acquire( 42, OrderLock::FAIL_FAST );
		$elapsed = microtime( true ) - $start;

		$this->assertNull( $result );
		// The retry loop sleeps 200ms per attempt - a fail-fast call that took
		// anywhere near that long would mean it looped instead of trying once.
		$this->assertLessThan( 0.1, $elapsed );
	}

	public function test_fail_fast_constant_is_zero(): void {
		$this->assertSame( 0, OrderLock::FAIL_FAST );
	}

	public function test_default_wait_matches_the_wait_constant(): void {
		$this->assertSame( 20, OrderLock::WAIT );
	}

	public function test_two_different_orders_lock_independently(): void {
		$owner_a = OrderLock::acquire( 1 );
		$owner_b = OrderLock::acquire( 2 );

		$this->assertNotNull( $owner_a );
		$this->assertNotNull( $owner_b );
	}

	public function test_renew_extends_the_lock_for_its_owner(): void {
		$owner = OrderLock::acquire( 42 );

		// A wrong owner can't renew, so a second acquire still fails afterward.
		OrderLock::renew( 42, 'someone-else' );
		$this->assertNull( OrderLock::acquire( 42, OrderLock::FAIL_FAST ) );

		OrderLock::renew( 42, $owner );
		$this->assertNull( OrderLock::acquire( 42, OrderLock::FAIL_FAST ) );
	}

	public function test_release_frees_the_order_for_a_new_acquire(): void {
		$owner = OrderLock::acquire( 42 );
		OrderLock::release( 42, $owner );

		$this->assertNotNull( OrderLock::acquire( 42, OrderLock::FAIL_FAST ) );
	}

	public function test_release_with_the_wrong_owner_does_not_free_the_lock(): void {
		OrderLock::acquire( 42 );
		OrderLock::release( 42, 'someone-else' );

		$this->assertNull( OrderLock::acquire( 42, OrderLock::FAIL_FAST ) );
	}
}
