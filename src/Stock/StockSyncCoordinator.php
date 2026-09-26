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

	/**
	 * A single page fetch can legitimately block for a while: OblioClient
	 * routes every request through RateLimiter::throttle(), whose reserve()
	 * can make a caller sleep up to RateLimiter::MAX_WAIT (600s) under a
	 * busy shared rate-limit slot. STALE_AFTER has to clear that worst case
	 * with real margin, or a healthy, merely-queued-behind-other-jobs batch
	 * would get misclassified as dead.
	 */
	private const STALE_AFTER = 20 * MINUTE_IN_SECONDS;

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

		if ( ! $this->scheduler->enqueue_stock_batch( 0, 1, 0, $token ) ) {
			$this->logger->error( 'Stock sync: could not schedule the first batch, aborting the run' );
			$this->cancel_run();
			return false;
		}
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

		if ( ! $this->scheduler->enqueue_stock_batch( 0, 1, 0, $token ) ) {
			$this->logger->error( 'Stock sync: could not schedule the first batch, aborting the run' );
			$this->cancel_run();
			return array(
				'ok'     => false,
				'reason' => __( 'Nu s-a putut programa sincronizarea în coadă.', 'fgsync-oblio' ),
			);
		}
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
	 *
	 * "done" is keyed off the run token, not the raw lock: a crashed worker
	 * lets RUN_LOCK expire on its own (TTL, no explicit release), which would
	 * otherwise read as "done" even though cancel_run()/finalize() never ran
	 * and the run token (and possibly a still-queued batch action) is left
	 * behind.
	 */
	public function progress(): array {
		$progress = get_transient( self::PROGRESS_TRANSIENT );
		$scanned  = is_array( $progress ) ? (int) ( $progress['scanned'] ?? 0 ) : 0;
		$updated  = is_array( $progress ) ? (int) ( $progress['updated'] ?? 0 ) : 0;

		return array(
			'ok'      => true,
			'done'    => '' === (string) get_option( self::RUN_TOKEN_OPTION, '' ),
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

	/**
	 * Confirms ownership of the current run token before touching anything
	 * else - cancelling the scheduled batches unconditionally, before this
	 * check, could kill a newer run's work if one started in the instant
	 * between this call being triggered and actually running.
	 */
	public function cancel_run(): void {
		$this->cancel_run_for( (string) get_option( self::RUN_TOKEN_OPTION, '' ) );
	}

	/**
	 * Token-gated variant used by unlock() - takes the token the caller
	 * already inspected instead of re-reading RUN_TOKEN_OPTION itself, so a
	 * run that finishes (or a new one that starts) between the caller's
	 * staleness check and this call can't be cancelled out from under it: the
	 * CAS-delete simply no-ops if the option no longer holds that exact token.
	 *
	 * @param string $token Expected current run token.
	 * @return bool True if this call actually cancelled the run identified by $token.
	 */
	private function cancel_run_for( string $token ): bool {
		if ( '' === $token || ! AtomicLock::delete_if_matches( self::RUN_TOKEN_OPTION, $token ) ) {
			return false;
		}

		$this->scheduler->cancel_stock_batches();
		delete_transient( self::PROGRESS_TRANSIENT );
		AtomicLock::release( self::RUN_LOCK, $token );
		return true;
	}

	public function is_run_locked(): bool {
		return AtomicLock::is_locked( self::RUN_LOCK );
	}

	/**
	 * A healthy run renews RUN_LOCK on every processed batch (see
	 * StockSyncBatch::record_progress()), so its remaining TTL stays close to
	 * RUN_LOCK_TTL. If nothing has renewed it in STALE_AFTER seconds, the run
	 * is presumed dead (crashed worker, dropped Action Scheduler job) rather
	 * than merely slow - that's the only case "unlock" should act on.
	 *
	 * The run token, not the lock, is the primary signal: RUN_LOCK expires on
	 * its own (TTL, no explicit release) once a crashed run stops renewing
	 * it, and by then it's unconditionally stale - checking remaining TTL
	 * alone would flip back to "not stale" once fully expired, hiding the
	 * unlock action exactly when it's needed most.
	 */
	public static function is_run_stale(): bool {
		if ( '' === (string) get_option( self::RUN_TOKEN_OPTION, '' ) ) {
			return false;
		}
		$remaining = AtomicLock::seconds_remaining( self::RUN_LOCK );
		if ( null === $remaining ) {
			return true;
		}
		return $remaining <= self::RUN_LOCK_TTL - self::STALE_AFTER;
	}

	/**
	 * Force-releasing unconditionally would risk clobbering a run that started
	 * in the instant between the honest cancel_run() and this call, so only
	 * override when cancel_run()'s token-gated release genuinely didn't clear it.
	 *
	 * Snapshots the run token up front and threads that same value through
	 * cancel_run_for() - re-reading the token separately at cancel time (as a
	 * plain cancel_run() call would) leaves a window where a stale run finishes
	 * and a new healthy one starts in between, and this call would then cancel
	 * or force-unlock the NEW run instead of the one actually inspected.
	 *
	 * @return bool False (no-op) if the current run isn't actually stale, or if
	 *              the token changed before the cancel could take effect.
	 */
	public function unlock(): bool {
		$token = (string) get_option( self::RUN_TOKEN_OPTION, '' );
		if ( '' === $token || ! self::is_run_stale() ) {
			return false;
		}

		$this->logger->warning( 'Stock sync: lock released manually' );

		if ( ! $this->cancel_run_for( $token ) ) {
			return false;
		}
		if ( AtomicLock::is_locked( self::RUN_LOCK ) ) {
			AtomicLock::force_release( self::RUN_LOCK );
		}
		return true;
	}

	private function is_configured(): bool {
		return $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
	}
}
