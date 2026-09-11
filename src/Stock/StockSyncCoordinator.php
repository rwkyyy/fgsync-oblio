<?php
/**
 * Starts a stock-sync run.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Stock;

use OblioWoo\Queue\Scheduler;
use OblioWoo\Support\AtomicLock;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
final class StockSyncCoordinator {

	public const PROGRESS_TRANSIENT = 'oblio_fgwoo_stock_progress';
	public const LAST_SYNC_OPTION   = 'oblio_fgwoo_stock_last_sync';
	public const RUN_LOCK           = 'oblio_fgwoo_stock_run_lock';

	public const RUN_TOKEN_OPTION = 'oblio_fgwoo_stock_run_token';

	public const LAST_PING_OPTION = 'oblio_fgwoo_stock_webhook_last';

	public const RUN_LOCK_TTL = 1800;

	private Settings $settings;

	private Scheduler $scheduler;

	private StockReservations $reservations;

	private Logger $logger;

	public function __construct( Settings $settings, Scheduler $scheduler, StockReservations $reservations, Logger $logger ) {
		$this->settings     = $settings;
		$this->scheduler    = $scheduler;
		$this->reservations = $reservations;
		$this->logger       = $logger;
	}

	public function register(): void {
		add_action( Scheduler::HOOK_STOCK_SYNC, array( $this, 'start' ) );
		add_action( Scheduler::HOOK_STOCK_SETTLE, array( $this, 'run_settle' ) );
	}

	public function start( bool $force = false ): bool {
		if ( ! $force && ! $this->settings->stock_schedule_enabled() ) {
			return false;
		}
		if ( ! $this->is_configured() ) {
			return false;
		}

		if ( ! AtomicLock::acquire( self::RUN_LOCK, self::RUN_LOCK_TTL ) ) {
			$this->logger->info( 'Stock sync skipped: a run is already in progress' );
			return false;
		}

		$token = wp_generate_password( 12, false );
		update_option( self::RUN_TOKEN_OPTION, $token, false );

		delete_transient( self::PROGRESS_TRANSIENT );
		set_transient(
			self::PROGRESS_TRANSIENT,
			array(
				'scanned' => 0,
				'updated' => 0,
			),
			DAY_IN_SECONDS
		);
		$this->reservations->reset();

		$this->scheduler->enqueue_stock_batch( 0, 1, 0, $token );
		$this->logger->info( 'Stock sync started' );

		return true;
	}

	public function request_webhook_sync(): void {
		update_option( self::LAST_PING_OPTION, time(), false );
		if ( ! $this->scheduler->settle_pending() ) {
			$this->scheduler->schedule_settle( $this->settings->webhook_stock_delay() );
		}
		$this->logger->info( 'Webhook stock: sync requested (debounced)' );
	}

	public function run_settle(): void {
		$last = (int) get_option( self::LAST_PING_OPTION, 0 );
		if ( $last <= 0 ) {
			return;
		}

		$now   = time();
		$delay = $this->settings->webhook_stock_delay();
		if ( $now - $last < $delay ) {

			$this->scheduler->schedule_settle( ( $last + $delay ) - $now );
			return;
		}

		delete_option( self::LAST_PING_OPTION );
		$this->cancel_run();
		$this->start( true );
	}

	public function cancel_run(): void {
		$this->scheduler->cancel_stock_batches();
		delete_option( self::RUN_TOKEN_OPTION );
		AtomicLock::release( self::RUN_LOCK );
		delete_transient( self::PROGRESS_TRANSIENT );
		$this->reservations->reset();
	}

	private function is_configured(): bool {
		return $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
	}
}
