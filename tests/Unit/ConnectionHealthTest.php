<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Support\ConnectionHealth;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Support\ConnectionHealth::class )]
final class ConnectionHealthTest extends TestCase {

	private ConnectionHealth $health;

	protected function setUp(): void {
		oblio_test_reset();
		$this->health = new ConnectionHealth();
	}

	public function test_no_recorded_state_reads_as_healthy(): void {
		$this->assertTrue( $this->health->is_healthy() );
		$this->assertSame( '', $this->health->last_error() );
	}

	public function test_record_success_marks_healthy(): void {
		$this->health->record_success();

		$this->assertTrue( $this->health->is_healthy() );
	}

	public function test_record_failure_marks_unhealthy_with_a_reason(): void {
		$this->health->record_failure( 'timeout' );

		$this->assertFalse( $this->health->is_healthy() );
		$this->assertSame( 'timeout', $this->health->last_error() );
	}

	public function test_repeated_success_within_the_rewrite_interval_skips_the_write(): void {
		$this->health->record_success();
		$first = get_option( 'oblio_fgwoo_connection_health' );

		$this->health->record_success();
		$second = get_option( 'oblio_fgwoo_connection_health' );

		$this->assertSame( $first['at'], $second['at'] );
	}

	public function test_a_repeated_identical_failure_within_the_rewrite_interval_skips_the_write(): void {
		$this->health->record_failure( 'timeout' );
		$first = get_option( 'oblio_fgwoo_connection_health' );

		$this->health->record_failure( 'timeout' );
		$second = get_option( 'oblio_fgwoo_connection_health' );

		$this->assertSame( $first['at'], $second['at'] );
	}

	public function test_a_changed_failure_reason_writes_immediately(): void {
		$this->health->record_failure( 'timeout' );
		$this->health->record_failure( 'auth failed' );

		$this->assertSame( 'auth failed', $this->health->last_error() );
	}

	public function test_recovering_from_failure_to_success_always_writes(): void {
		$this->health->record_failure( 'timeout' );
		$this->health->record_success();

		$this->assertTrue( $this->health->is_healthy() );
		$this->assertSame( '', $this->health->last_error() );
	}

	public function test_a_stale_failure_outside_the_rewrite_interval_writes_again(): void {
		$this->health->record_failure( 'timeout' );

		$state       = get_option( 'oblio_fgwoo_connection_health' );
		$state['at'] = time() - 6 * MINUTE_IN_SECONDS;
		update_option( 'oblio_fgwoo_connection_health', $state );

		$this->health->record_failure( 'timeout' );

		$updated = get_option( 'oblio_fgwoo_connection_health' );
		$this->assertGreaterThan( $state['at'], $updated['at'] );
	}

	public function test_last_checked_reflects_the_stored_timestamp(): void {
		$this->assertSame( 0, $this->health->last_checked() );

		$this->health->record_success();

		$this->assertGreaterThan( 0, $this->health->last_checked() );
	}
}
