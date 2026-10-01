<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Queue\ScheduleGuard;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Queue\ScheduleGuard::class )]
final class ScheduleGuardTest extends TestCase {

	private ScheduleGuard $guard;

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->guard    = new ScheduleGuard( $this->settings, new Scheduler(), new Logger() );
	}

	/**
	 * as_schedule_recurring_action() only works once Action Scheduler's data
	 * store has finished initializing, which happens on the init hook - not
	 * on plugins_loaded, where Plugin::boot() runs. register() must defer
	 * the actual scheduling attempt to action_scheduler_init (the hook
	 * Action Scheduler itself fires once it's safe), not run it immediately.
	 */
	public function test_register_defers_scheduling_to_action_scheduler_init_instead_of_running_immediately(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );

		$this->guard->register();

		$this->assertCount( 0, $GLOBALS['oblio_test_as_calls'] );

		$hooks = array_column( $GLOBALS['oblio_test_add_action_calls'], 'hook' );
		$this->assertContains( 'action_scheduler_init', $hooks );
	}

	public function test_maybe_schedule_logs_an_error_when_action_scheduler_rejects_the_reconcile_watchdog(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );
		$GLOBALS['oblio_test_as_fail'] = true;

		$this->guard->maybe_schedule();

		$this->assertNotEmpty(
			array_filter(
				$GLOBALS['oblio_test_wc_logs'],
				static fn ( array $log ): bool => false !== strpos( $log['message'], 'reconciliation watchdog' )
			)
		);
	}

	public function test_maybe_schedule_succeeds_and_sets_the_schedule_check_transient(): void {
		$this->settings->set( 'invoice_autogen', 'yes' );

		$this->guard->maybe_schedule();

		$this->assertEmpty( $GLOBALS['oblio_test_wc_logs'] );
		$this->assertNotFalse( get_transient( Scheduler::SCHEDULE_CHECK ) );
	}

	public function test_maybe_schedule_is_a_no_op_once_the_schedule_check_transient_is_set(): void {
		set_transient( Scheduler::SCHEDULE_CHECK, 1, HOUR_IN_SECONDS );
		$GLOBALS['oblio_test_as_fail'] = true;

		$this->guard->maybe_schedule();

		$this->assertEmpty( $GLOBALS['oblio_test_wc_logs'] );
	}
}
