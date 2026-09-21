<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\SlotStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
final class InMemorySlotStore implements SlotStore {

	private ?int $value = null;

	private int $fail_next = 0;

	public int $cas_calls = 0;

	public function seed( int $value ): void {
		$this->value = $value;
	}

	public function fail_next_cas( int $times ): void {
		$this->fail_next = $times;
	}

	public function read(): ?int {
		return $this->value;
	}

	public function insert( int $value ): bool {
		if ( null !== $this->value ) {
			return false;
		}
		$this->value = $value;
		return true;
	}

	public function compare_and_set( int $expected, int $value ): bool {
		++$this->cas_calls;
		if ( $this->fail_next > 0 ) {
			--$this->fail_next;

			$this->value = ( $this->value ?? 0 ) + 4;
			return false;
		}
		if ( $this->value !== $expected ) {
			return false;
		}
		$this->value = $value;
		return true;
	}
}

#[CoversClass( \FGSyncOblio\Support\RateLimiter::class )]
final class RateLimiterTest extends TestCase {

	public function test_plan_clamps_stale_past_slot_to_now(): void {
		$this->assertSame( array( 2000, 2004 ), RateLimiter::plan( 2000, 1000, 4 ) );
	}

	public function test_plan_honours_a_future_slot(): void {
		$this->assertSame( array( 2010, 2014 ), RateLimiter::plan( 2000, 2010, 4 ) );
	}

	public function test_first_reservation_fires_immediately_and_seeds_floor(): void {
		$store   = new InMemorySlotStore();
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$this->assertSame( 0, $limiter->reserve() );
		$this->assertSame( 2004, $store->read() );
	}

	public function test_burst_reservations_are_spaced(): void {
		$store   = new InMemorySlotStore();
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$waits = array( $limiter->reserve(), $limiter->reserve(), $limiter->reserve() );

		$this->assertSame( array( 0, 4, 8 ), $waits );
		$this->assertSame( 2012, $store->read() );
	}

	public function test_reservation_waits_for_a_future_slot(): void {
		$store = new InMemorySlotStore();
		$store->seed( 2010 );
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$this->assertSame( 10, $limiter->reserve() );
		$this->assertSame( 2014, $store->read() );
	}

	public function test_reservation_retries_until_the_swap_wins(): void {
		$store = new InMemorySlotStore();
		$store->seed( 1000 );
		$store->fail_next_cas( 2 );
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$this->assertSame( 0, $limiter->reserve() );
		$this->assertSame( 3, $store->cas_calls );
		$this->assertSame( 2004, $store->read() );
	}

	public function test_throttle_sleeps_the_full_reserved_wait(): void {
		$store = new InMemorySlotStore();
		$store->seed( 2500 );
		$slept   = null;
		$limiter = new RateLimiter(
			$store,
			4,
			static fn() => 2000,
			static function ( int $seconds ) use ( &$slept ): void {
				$slept = $seconds;
			}
		);

		$limiter->throttle();

		$this->assertSame( 500, $slept );
	}

	public function test_throttle_resets_an_implausibly_far_slot(): void {
		$store = new InMemorySlotStore();
		$store->seed( 100000 );
		$slept   = 'unset';
		$limiter = new RateLimiter(
			$store,
			4,
			static fn() => 2000,
			static function ( int $seconds ) use ( &$slept ): void {
				$slept = $seconds;
			}
		);

		$limiter->throttle();

		$this->assertSame( 'unset', $slept );
		$this->assertSame( 2004, $store->read() );
	}

	public function test_peek_reports_zero_when_idle(): void {
		$store   = new InMemorySlotStore();
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$this->assertSame( 0, $limiter->peek() );
	}

	public function test_peek_reports_the_wait_without_reserving(): void {
		$store = new InMemorySlotStore();
		$store->seed( 2030 );
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$this->assertSame( 30, $limiter->peek() );
		$this->assertSame( 30, $limiter->peek() );
		$this->assertSame( 2030, $store->read() );
	}

	public function test_peek_treats_a_corrupt_far_future_slot_as_free(): void {
		$store = new InMemorySlotStore();
		$store->seed( 100000 );
		$limiter = new RateLimiter( $store, 4, static fn() => 2000 );

		$this->assertSame( 0, $limiter->peek() );
	}

	public function test_throttle_does_not_sleep_when_idle(): void {
		$store   = new InMemorySlotStore();
		$slept   = 'unset';
		$limiter = new RateLimiter(
			$store,
			4,
			static fn() => 2000,
			static function ( int $seconds ) use ( &$slept ): void {
				$slept = $seconds;
			}
		);

		$limiter->throttle();

		$this->assertSame( 'unset', $slept );
	}
}
