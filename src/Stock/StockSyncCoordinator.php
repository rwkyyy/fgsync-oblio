<?php
/**
 * Starts a stock-sync run.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\AtomicLock;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
final class StockSyncCoordinator {

	public const PROGRESS_TRANSIENT = 'oblio_fgwoo_stock_progress';
	public const LAST_SYNC_OPTION   = 'oblio_fgwoo_stock_last_sync';
	public const RUN_LOCK           = 'oblio_fgwoo_stock_run_lock';

	public const RUN_TOKEN_OPTION = 'oblio_fgwoo_stock_run_token';

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
	}

	public function start( bool $force = false ): bool {
		if ( ! $force && ! $this->settings->stock_schedule_enabled() ) {
			return false;
		}
		$token = $this->begin_run();
		if ( null === $token ) {
			return false;
		}

		$this->scheduler->enqueue_stock_batch( 0, 1, 0, $token );
		$this->logger->info( 'Stock sync started (scheduled)' );

		return true;
	}

	public function begin_full_sync(): array {
		$token = $this->begin_run();
		if ( null === $token ) {
			return array(
				'ok'     => false,
				'reason' => $this->is_configured()
					? __( 'O sincronizare este deja în curs.', 'fgsync-oblio' )
					: __( 'Sincronizarea stocului nu este configurată.', 'fgsync-oblio' ),
			);
		}

		$this->scheduler->enqueue_stock_batch( 0, 1, 0, $token );
		$this->logger->info( 'Stock sync started (manual)' );

		return array(
			'ok'    => true,
			'token' => $token,
		);
	}

	/**
	 * Read-only poll for a run started by begin_full_sync() - the browser no
	 * longer drives the sync itself, it just watches the same progress
	 * transient the background batch worker updates.
	 */
	public function progress(): array {
		$progress = get_transient( self::PROGRESS_TRANSIENT );
		$scanned  = is_array( $progress ) ? (int) ( $progress['scanned'] ?? 0 ) : 0;
		$updated  = is_array( $progress ) ? (int) ( $progress['updated'] ?? 0 ) : 0;

		return array(
			'ok'      => true,
			'done'    => ! $this->is_run_locked(),
			'scanned' => $scanned,
			'updated' => $updated,
		);
	}

	private function begin_run(): ?string {
		if ( ! $this->is_configured() ) {
			return null;
		}
		$owner = AtomicLock::acquire( self::RUN_LOCK, self::RUN_LOCK_TTL );
		if ( null === $owner ) {
			$this->logger->info( 'Stock sync skipped: a run is already in progress' );
			return null;
		}

		update_option( self::RUN_TOKEN_OPTION, $owner, false );

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

		return $owner;
	}

	public function cancel_run(): void {
		$this->scheduler->cancel_stock_batches();

		$owner = (string) get_option( self::RUN_TOKEN_OPTION, '' );
		if ( '' === $owner || ! AtomicLock::delete_if_matches( self::RUN_TOKEN_OPTION, $owner ) ) {
			return;
		}

		delete_transient( self::PROGRESS_TRANSIENT );
		$this->reservations->reset();
		AtomicLock::release( self::RUN_LOCK, $owner );
	}

	public function is_run_locked(): bool {
		return AtomicLock::is_locked( self::RUN_LOCK );
	}

	/**
	 * Force-releasing unconditionally would risk clobbering a run that started
	 * in the instant between the honest cancel_run() and this call, so only
	 * override when cancel_run()'s token-gated release genuinely didn't clear it.
	 */
	public function unlock(): void {
		$this->logger->warning( 'Stock sync: lock released manually' );
		$this->cancel_run();
		if ( AtomicLock::is_locked( self::RUN_LOCK ) ) {
			AtomicLock::force_release( self::RUN_LOCK );
		}
	}

	private function is_configured(): bool {
		return $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
	}
}
