<?php
/**
 * Defers the recurring reconcile/stock-sync scheduling check until Action
 * Scheduler is actually ready.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue;

use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;

final class ScheduleGuard {

	private Settings $settings;

	private Scheduler $scheduler;

	private Logger $logger;

	public function __construct( Settings $settings, Scheduler $scheduler, Logger $logger ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
	}

	/**
	 * Plugin::boot() runs on plugins_loaded, but Action Scheduler (loaded by
	 * WooCommerce at plugins_loaded priority 1) defers its own data-store
	 * init to the init hook - as_schedule_recurring_action() called any
	 * earlier than that always short-circuits to 0, silently failing every
	 * time. action_scheduler_init is the hook Action Scheduler itself fires
	 * once it's actually safe to use the scheduling functions.
	 */
	public function register(): void {
		add_action( 'action_scheduler_init', array( $this, 'maybe_schedule' ) );
	}

	public function maybe_schedule(): void {
		if ( false !== get_transient( Scheduler::SCHEDULE_CHECK ) ) {
			return;
		}

		if ( ! $this->scheduler->ensure_reconcile_scheduled(
			$this->settings->reconcile_watchdog_enabled(),
			'batch' === (string) $this->settings->get( 'invoice_generation', 'event' )
				? (string) $this->settings->get( 'invoice_batch_interval', 'hourly' )
				: 'hourly'
		) ) {
			$this->logger->error( 'Could not (re)schedule the reconciliation watchdog; will retry within the hour' );
		}

		if ( ! $this->scheduler->ensure_stock_scheduled(
			$this->settings->stock_schedule_enabled(),
			(string) $this->settings->get( 'stock_interval', 'hourly' )
		) ) {
			$this->logger->error( 'Could not (re)schedule stock sync; will retry within the hour' );
		}

		set_transient( Scheduler::SCHEDULE_CHECK, 1, HOUR_IN_SECONDS );
	}
}
