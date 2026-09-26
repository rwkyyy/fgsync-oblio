<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Stock\StockReservations;
use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Support\AtomicLock;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Stock\StockSyncCoordinator::class )]
final class StockSyncCoordinatorTest extends TestCase {

	private StockSyncCoordinator $coordinator;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );

		$this->coordinator = new StockSyncCoordinator(
			$this->settings,
			new Scheduler(),
			new StockReservations( new OrderStore(), new Logger() ),
			new Logger()
		);
	}

	public function test_is_run_stale_is_false_when_not_locked(): void {
		$this->assertFalse( StockSyncCoordinator::is_run_stale() );
	}

	public function test_is_run_stale_is_false_right_after_the_lock_is_acquired(): void {
		AtomicLock::acquire( StockSyncCoordinator::RUN_LOCK, StockSyncCoordinator::RUN_LOCK_TTL );

		$this->assertFalse( StockSyncCoordinator::is_run_stale() );
	}

	/**
	 * A healthy run renews RUN_LOCK on every processed batch, so its
	 * remaining TTL stays close to RUN_LOCK_TTL. Once remaining drops well
	 * below that (no renewal in a while), the run is presumed dead.
	 */
	public function test_is_run_stale_is_true_once_remaining_ttl_drops_low(): void {
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, 'some-owner' );
		$GLOBALS['oblio_test_options'][ StockSyncCoordinator::RUN_LOCK ] = ( time() + 100 ) . '|some-owner';

		$this->assertTrue( StockSyncCoordinator::is_run_stale() );
	}

	/**
	 * OblioClient routes every request through RateLimiter::throttle(),
	 * which can block a single page fetch for up to RateLimiter::MAX_WAIT
	 * (600s) under a busy shared rate-limit slot - a batch that hit that
	 * worst case and is about to renew must not already read as stale.
	 */
	public function test_is_run_stale_stays_false_after_a_worst_case_rate_limiter_block(): void {
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, 'some-owner' );
		$remaining = StockSyncCoordinator::RUN_LOCK_TTL - 600;
		$GLOBALS['oblio_test_options'][ StockSyncCoordinator::RUN_LOCK ] = ( time() + $remaining ) . '|some-owner';

		$this->assertFalse( StockSyncCoordinator::is_run_stale() );
	}

	/**
	 * A crashed worker lets RUN_LOCK expire on its own (no explicit
	 * release) - by then it's unconditionally stale. Checking only the
	 * lock's remaining TTL would flip back to "not stale" once fully
	 * expired, hiding the unlock action exactly when it's needed most.
	 */
	public function test_is_run_stale_is_true_once_the_lock_has_fully_expired(): void {
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, 'some-owner' );
		$GLOBALS['oblio_test_options'][ StockSyncCoordinator::RUN_LOCK ] = ( time() - 10 ) . '|some-owner';

		$this->assertTrue( StockSyncCoordinator::is_run_stale() );
	}

	public function test_progress_is_not_done_while_the_run_token_is_still_set_even_if_the_lock_expired(): void {
		update_option( StockSyncCoordinator::RUN_TOKEN_OPTION, 'some-owner' );
		$GLOBALS['oblio_test_options'][ StockSyncCoordinator::RUN_LOCK ] = ( time() - 10 ) . '|some-owner';

		$progress = $this->coordinator->progress();

		$this->assertFalse( $progress['done'] );
	}

	public function test_progress_is_done_once_the_run_token_is_cleared(): void {
		$this->assertSame( '', (string) get_option( StockSyncCoordinator::RUN_TOKEN_OPTION, '' ) );

		$progress = $this->coordinator->progress();

		$this->assertTrue( $progress['done'] );
	}

	public function test_cancel_run_does_not_touch_scheduled_batches_without_a_matching_token(): void {
		$this->coordinator->cancel_run();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_unschedule_calls'] );
	}

	public function test_cancel_run_cancels_batches_and_releases_the_lock_when_the_token_matches(): void {
		$result = $this->coordinator->begin_full_sync();
		$this->assertTrue( $result['ok'] );

		$this->coordinator->cancel_run();

		$this->assertCount( 1, $GLOBALS['oblio_test_as_unschedule_calls'] );
		$this->assertFalse( $this->coordinator->is_run_locked() );
	}

	public function test_unlock_is_a_no_op_while_the_run_is_healthy(): void {
		$this->coordinator->begin_full_sync();

		$this->assertFalse( $this->coordinator->unlock() );
		$this->assertTrue( $this->coordinator->is_run_locked() );
	}

	public function test_unlock_clears_a_genuinely_stale_run(): void {
		$this->coordinator->begin_full_sync();
		$owner = (string) get_option( StockSyncCoordinator::RUN_TOKEN_OPTION, '' );
		$GLOBALS['oblio_test_options'][ StockSyncCoordinator::RUN_LOCK ] = ( time() + 100 ) . '|' . $owner;

		$this->assertTrue( $this->coordinator->unlock() );
		$this->assertFalse( $this->coordinator->is_run_locked() );
	}
}
