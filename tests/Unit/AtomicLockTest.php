<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Support\AtomicLock;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Support\AtomicLock::class )]
final class AtomicLockTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_fresh_key_is_acquired(): void {
		$owner = AtomicLock::acquire( 'lock_a', 100 );
		$this->assertIsString( $owner );
		$this->assertNotSame( '', $owner );
	}

	public function test_second_acquire_on_a_held_key_fails(): void {
		AtomicLock::acquire( 'lock_a', 100 );
		$this->assertNull( AtomicLock::acquire( 'lock_a', 100 ) );
	}

	public function test_two_acquires_get_different_owner_tokens(): void {
		$first = AtomicLock::acquire( 'lock_a', 100 );
		AtomicLock::release( 'lock_a', $first );
		$second = AtomicLock::acquire( 'lock_a', 100 );

		$this->assertNotSame( $first, $second );
	}

	public function test_is_locked_reflects_current_state(): void {
		$this->assertFalse( AtomicLock::is_locked( 'lock_a' ) );

		$owner = AtomicLock::acquire( 'lock_a', 100 );
		$this->assertTrue( AtomicLock::is_locked( 'lock_a' ) );

		AtomicLock::release( 'lock_a', $owner );
		$this->assertFalse( AtomicLock::is_locked( 'lock_a' ) );
	}

	public function test_an_expired_lock_can_be_stolen_by_a_new_acquire(): void {
		$GLOBALS['oblio_test_options']['lock_a'] = ( time() - 10 ) . '|stale-owner';

		$owner = AtomicLock::acquire( 'lock_a', 100 );

		$this->assertNotNull( $owner );
		$this->assertNotSame( 'stale-owner', $owner );
	}

	public function test_an_unexpired_lock_cannot_be_stolen(): void {
		$GLOBALS['oblio_test_options']['lock_a'] = ( time() + 100 ) . '|live-owner';

		$this->assertNull( AtomicLock::acquire( 'lock_a', 100 ) );
	}

	public function test_renew_succeeds_only_for_the_current_owner(): void {
		$owner = AtomicLock::acquire( 'lock_a', 100 );

		$this->assertFalse( AtomicLock::renew( 'lock_a', 'someone-else', 200 ) );
		$this->assertTrue( AtomicLock::renew( 'lock_a', $owner, 200 ) );
	}

	public function test_renew_on_a_missing_key_fails(): void {
		$this->assertFalse( AtomicLock::renew( 'no_such_key', 'owner', 100 ) );
	}

	public function test_release_succeeds_only_for_the_current_owner_and_clears_the_key(): void {
		$owner = AtomicLock::acquire( 'lock_a', 100 );

		$this->assertFalse( AtomicLock::release( 'lock_a', 'someone-else' ) );
		$this->assertTrue( AtomicLock::is_locked( 'lock_a' ) );

		$this->assertTrue( AtomicLock::release( 'lock_a', $owner ) );
		$this->assertFalse( AtomicLock::is_locked( 'lock_a' ) );
	}

	public function test_release_on_a_missing_key_fails(): void {
		$this->assertFalse( AtomicLock::release( 'no_such_key', 'owner' ) );
	}

	public function test_force_release_clears_the_key_regardless_of_owner(): void {
		AtomicLock::acquire( 'lock_a', 100 );

		AtomicLock::force_release( 'lock_a' );

		$this->assertFalse( AtomicLock::is_locked( 'lock_a' ) );
	}

	public function test_delete_if_matches_only_deletes_the_expected_value(): void {
		update_option( 'plain_key', 'expected-value' );

		$this->assertFalse( AtomicLock::delete_if_matches( 'plain_key', 'wrong-value' ) );
		$this->assertSame( 'expected-value', get_option( 'plain_key' ) );

		$this->assertTrue( AtomicLock::delete_if_matches( 'plain_key', 'expected-value' ) );
		$this->assertFalse( get_option( 'plain_key', false ) );
	}

	public function test_a_released_key_can_be_reacquired_by_someone_else(): void {
		$owner = AtomicLock::acquire( 'lock_a', 100 );
		AtomicLock::release( 'lock_a', $owner );

		$second = AtomicLock::acquire( 'lock_a', 100 );

		$this->assertNotNull( $second );
	}

	public function test_seconds_remaining_is_null_when_not_locked(): void {
		$this->assertNull( AtomicLock::seconds_remaining( 'lock_a' ) );
	}

	public function test_seconds_remaining_reflects_the_current_ttl(): void {
		AtomicLock::acquire( 'lock_a', 100 );

		$remaining = AtomicLock::seconds_remaining( 'lock_a' );

		$this->assertNotNull( $remaining );
		$this->assertGreaterThan( 90, $remaining );
		$this->assertLessThanOrEqual( 100, $remaining );
	}

	public function test_seconds_remaining_is_null_for_an_expired_lock(): void {
		$GLOBALS['oblio_test_options']['lock_a'] = ( time() - 10 ) . '|stale-owner';

		$this->assertNull( AtomicLock::seconds_remaining( 'lock_a' ) );
	}
}
