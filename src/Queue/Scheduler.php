<?php
/**
 * Action Scheduler wrapper for the plugin's background jobs.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue;

use FGSyncOblio\Support\AtomicLock;
final class Scheduler {

	public const GROUP             = 'oblio_fgwoo';
	private const OLD_GROUP        = 'oblio';
	public const HOOK_GENERATE     = 'oblio_fgwoo_generate_document';
	public const HOOK_REFUND       = 'oblio_fgwoo_generate_refund';
	public const HOOK_RECONCILE    = 'oblio_fgwoo_reconcile';
	public const HOOK_STOCK_SYNC   = 'oblio_fgwoo_stock_sync';
	public const HOOK_STOCK_BATCH  = 'oblio_fgwoo_stock_batch';
	public const HOOK_NOMENCLATURE = 'oblio_fgwoo_refresh_nomenclature';

	public const SCHEDULE_CHECK = 'oblio_fgwoo_schedule_check';

	/**
	 * TTL for a pending-document/refund marker - generous headroom above the
	 * worst-case retry span so a marker self-heals if a crashed job never
	 * reaches a terminal state to release it. A rate-limiter defer renews the
	 * marker for a fresh PENDING_TTL each time (see reschedule_document()/
	 * reschedule_refund()), so what matters isn't the total retry span but
	 * MAX_DEFER_DELAY staying well under this value - otherwise the marker
	 * could expire before the deferred job runs again to renew it.
	 */
	private const PENDING_TTL = HOUR_IN_SECONDS;

	/**
	 * Upper bound on a single rate-limiter defer delay. RateLimiter::peek()
	 * is unbounded (it grows with queue depth), but reschedule_document()/
	 * reschedule_refund() renew the marker for PENDING_TTL right before
	 * scheduling - if the delay itself could exceed PENDING_TTL, the marker
	 * would lapse while the job is still legitimately waiting. Capping it well
	 * under PENDING_TTL just means a very deep backlog re-checks the rate
	 * limiter sooner and defers again (harmless), instead of the marker
	 * expiring mid-flight.
	 */
	private const MAX_DEFER_DELAY = 30 * MINUTE_IN_SECONDS;

	public function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	public function enqueue_document( int $order_id, string $doc_type, array $options = array(), int $attempt = 1, int $delay = 0, string $owner = '' ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		if ( 1 === $attempt ) {
			$claimed = $this->claim_pending_document( $order_id, $doc_type );
			if ( null === $claimed ) {
				return false;
			}
			$owner = $claimed;
		}

		$payload = array(
			'order_id'      => $order_id,
			'doc_type'      => $doc_type,
			'options'       => $options,
			'attempt'       => $attempt,
			'pending_owner' => $owner,
		);

		if ( $this->schedule_action( self::HOOK_GENERATE, $payload, $delay ) ) {
			return true;
		}

		if ( '' !== $owner ) {
			$this->release_pending_document( $order_id, $doc_type, $owner );
		}
		return false;
	}

	/**
	 * @param int    $order_id Order ID.
	 * @param string $doc_type Document type.
	 * @param array  $options  Document build options, carried through to the job.
	 * @param int    $attempt  Attempt number, unchanged from the attempt being deferred.
	 * @param int    $delay    Seconds to push the run back by.
	 * @param string $owner    Pending-marker owner token from the original claim; renewed so
	 *                         it doesn't expire while this job keeps bouncing through defers.
	 */
	public function reschedule_document( int $order_id, string $doc_type, array $options, int $attempt, int $delay, string $owner ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		if ( '' !== $owner ) {
			AtomicLock::renew( self::pending_document_key( $order_id, $doc_type ), $owner, self::PENDING_TTL );
		}

		$payload = array(
			'order_id'      => $order_id,
			'doc_type'      => $doc_type,
			'options'       => $options,
			'attempt'       => $attempt,
			'pending_owner' => $owner,
		);

		return $this->schedule_action( self::HOOK_GENERATE, $payload, min( max( 1, $delay ), self::MAX_DEFER_DELAY ) );
	}

	/**
	 * @param int    $order_id             Order ID.
	 * @param int    $refund_id            Refund ID (0 = full storno).
	 * @param int    $attempt              Attempt number, unchanged from the attempt being deferred.
	 * @param int    $delay                Seconds to push the run back by.
	 * @param string $owner                Pending-marker owner token; renewed so it survives the defer.
	 * @param int    $invoice_wait_attempt Carried through unchanged - how many times this refund has
	 *                                     already been deferred waiting for its invoice to be issued.
	 */
	public function reschedule_refund( int $order_id, int $refund_id, int $attempt, int $delay, string $owner, int $invoice_wait_attempt = 0 ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		if ( '' !== $owner ) {
			AtomicLock::renew( self::pending_refund_key( $order_id, $refund_id ), $owner, self::PENDING_TTL );
		}

		$payload = array(
			'order_id'             => $order_id,
			'refund_id'            => $refund_id,
			'attempt'              => $attempt,
			'pending_owner'        => $owner,
			'invoice_wait_attempt' => $invoice_wait_attempt,
		);

		return $this->schedule_action( self::HOOK_REFUND, $payload, min( max( 1, $delay ), self::MAX_DEFER_DELAY ) );
	}

	public function enqueue_refund( int $order_id, int $refund_id, int $attempt = 1, int $delay = 0, string $owner = '' ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		if ( 1 === $attempt ) {
			$claimed = $this->claim_pending_refund( $order_id, $refund_id );
			if ( null === $claimed ) {
				return false;
			}
			$owner = $claimed;
		}

		$payload = array(
			'order_id'      => $order_id,
			'refund_id'     => $refund_id,
			'attempt'       => $attempt,
			'pending_owner' => $owner,
		);

		if ( $this->schedule_action( self::HOOK_REFUND, $payload, $delay ) ) {
			return true;
		}

		if ( '' !== $owner ) {
			$this->release_pending_refund( $order_id, $refund_id, $owner );
		}
		return false;
	}

	/**
	 * Releases a document's pending marker - called by the job runner once
	 * the order+doc_type reaches a terminal state (issued, permanently
	 * failed, or retries exhausted), so a later first-attempt enqueue for the
	 * same order+doc_type isn't blocked forever. Not called between retries:
	 * the marker deliberately stays held for the whole retry chain.
	 *
	 * CAS-released against the owner from the original claim (carried in the
	 * job payload), not force-released: an unconditional delete could clobber
	 * a DIFFERENT, newer marker that was legitimately claimed after this
	 * job's own marker expired mid-flight (e.g. a very deep queue backlog
	 * outlasting the marker's TTL).
	 *
	 * @param int    $order_id Order ID.
	 * @param string $doc_type Document type.
	 * @param string $owner    Owner token from the original claim.
	 */
	public function release_pending_document( int $order_id, string $doc_type, string $owner ): void {
		if ( '' === $owner ) {
			return;
		}
		AtomicLock::release( self::pending_document_key( $order_id, $doc_type ), $owner );
	}

	public function release_pending_refund( int $order_id, int $refund_id, string $owner ): void {
		if ( '' === $owner ) {
			return;
		}
		AtomicLock::release( self::pending_refund_key( $order_id, $refund_id ), $owner );
	}

	/**
	 * Renews a pending-document marker for a fresh PENDING_TTL - called by the
	 * job runner as soon as an attempt actually starts executing (not just on
	 * a rate-limiter defer), so a marker claimed at enqueue time but only
	 * picked up by Action Scheduler close to (or past) an hour later still
	 * gets refreshed before the retry chain continues, instead of relying
	 * solely on reschedule_document() to renew it later in that chain.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $doc_type Document type.
	 * @param string $owner    Pending-marker owner token from the original claim.
	 * @return bool False if the marker already expired and was reclaimed by a
	 *              different owner in the meantime (nothing to renew).
	 */
	public function renew_pending_document( int $order_id, string $doc_type, string $owner ): bool {
		if ( '' === $owner ) {
			return false;
		}
		return AtomicLock::renew( self::pending_document_key( $order_id, $doc_type ), $owner, self::PENDING_TTL );
	}

	/**
	 * @param int    $order_id  Order ID.
	 * @param int    $refund_id Refund ID (0 = full storno).
	 * @param string $owner     Pending-marker owner token from the original claim.
	 * @return bool False if the marker already expired and was reclaimed by a
	 *              different owner in the meantime (nothing to renew).
	 */
	public function renew_pending_refund( int $order_id, int $refund_id, string $owner ): bool {
		if ( '' === $owner ) {
			return false;
		}
		return AtomicLock::renew( self::pending_refund_key( $order_id, $refund_id ), $owner, self::PENDING_TTL );
	}

	public function has_pending_document( int $order_id, string $doc_type ): bool {
		return AtomicLock::is_locked( self::pending_document_key( $order_id, $doc_type ) );
	}

	public function has_pending_refund( int $order_id, int $refund_id ): bool {
		return AtomicLock::is_locked( self::pending_refund_key( $order_id, $refund_id ) );
	}

	/**
	 * Atomic claim: whichever of two near-simultaneous first-attempt
	 * enqueues for the same order+doc_type (e.g. an auto-issue hook and a
	 * manual admin click) acquires the marker first wins; the other no-ops.
	 * A single indexed wp_options lookup, not a scan of the Action Scheduler
	 * backlog. Returns the owner token so the caller can carry it through
	 * the job payload and release it precisely later.
	 *
	 * @param int    $order_id Order ID.
	 * @param string $doc_type Document type.
	 */
	private function claim_pending_document( int $order_id, string $doc_type ): ?string {
		return AtomicLock::acquire( self::pending_document_key( $order_id, $doc_type ), self::PENDING_TTL );
	}

	private function claim_pending_refund( int $order_id, int $refund_id ): ?string {
		return AtomicLock::acquire( self::pending_refund_key( $order_id, $refund_id ), self::PENDING_TTL );
	}

	private static function pending_document_key( int $order_id, string $doc_type ): string {
		return 'oblio_fgwoo_pending_doc_' . $doc_type . '_' . $order_id;
	}

	private static function pending_refund_key( int $order_id, int $refund_id ): string {
		return 'oblio_fgwoo_pending_refund_' . $order_id . '_' . $refund_id;
	}

	public function enqueue_stock_batch( int $offset, int $attempt = 1, int $delay = 0, string $token = '' ): bool {
		if ( ! $this->available() ) {
			return false;
		}
		$payload = array(
			'offset'  => $offset,
			'attempt' => $attempt,
			'token'   => $token,
		);
		return $this->schedule_action( self::HOOK_STOCK_BATCH, $payload, $delay );
	}

	/**
	 * Wraps as_enqueue_async_action()/as_schedule_single_action(): both return
	 * an int action ID on success and 0 on failure (e.g. a DB error), and can
	 * throw in rarer cases.
	 *
	 * @param string              $hook    Action hook to schedule.
	 * @param array<string,mixed> $payload Single-element action args payload.
	 * @param int                 $delay   Seconds from now to run at; 0 or less enqueues immediately.
	 */
	private function schedule_action( string $hook, array $payload, int $delay ): bool {
		try {
			if ( $delay > 0 ) {
				$action_id = as_schedule_single_action( time() + $delay, $hook, array( $payload ), self::GROUP );
			} else {
				$action_id = as_enqueue_async_action( $hook, array( $payload ), self::GROUP );
			}
		} catch ( \Throwable $exception ) {
			return false;
		}

		return (int) $action_id > 0;
	}

	/**
	 * Stock-batch actions carry a payload (offset/attempt/token), so passing
	 * a group here would make Action Scheduler match on args = [] exactly
	 * (real behaviour, verified against action-scheduler's own
	 * as_unschedule_all_actions()/as_unschedule_action()) and cancel
	 * nothing. Hook-only (no group) is its documented wildcard - "cancel
	 * every pending action for this hook" - and the hook name is already
	 * plugin-unique, so omitting the group is safe.
	 */
	public function cancel_stock_batches(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_STOCK_BATCH );
		}
	}

	/**
	 * @param bool   $enabled  Whether the recurring action should be scheduled at all.
	 * @param string $interval Interval key (e.g. 'hourly', '15min').
	 * @return bool False only if a (re)schedule was actually attempted and
	 *              Action Scheduler failed to create it (action ID 0) - true
	 *              for "already correctly scheduled" and "disabled" too.
	 */
	public function ensure_stock_scheduled( bool $enabled, string $interval ): bool {
		if ( ! $this->available() ) {
			return true;
		}

		as_unschedule_all_actions( self::HOOK_STOCK_SYNC, array(), self::OLD_GROUP );

		$scheduled       = false !== as_next_scheduled_action( self::HOOK_STOCK_SYNC, array(), self::GROUP );
		$stored_interval = (string) get_option( 'oblio_fgwoo_stock_scheduled_interval', '' );

		if ( ! $enabled ) {
			if ( $scheduled ) {
				as_unschedule_all_actions( self::HOOK_STOCK_SYNC, array(), self::GROUP );
				delete_option( 'oblio_fgwoo_stock_scheduled_interval' );
			}
			return true;
		}

		if ( $scheduled && $stored_interval === $interval ) {
			return true;
		}

		$seconds = $this->interval_seconds( $interval );
		as_unschedule_all_actions( self::HOOK_STOCK_SYNC, array(), self::GROUP );
		$action_id = as_schedule_recurring_action( time() + $seconds, $seconds, self::HOOK_STOCK_SYNC, array(), self::GROUP );
		if ( (int) $action_id <= 0 ) {
			return false;
		}
		update_option( 'oblio_fgwoo_stock_scheduled_interval', $interval, false );
		return true;
	}

	private function interval_seconds( string $interval ): int {
		switch ( $interval ) {
			case 'daily':
				return DAY_IN_SECONDS;
			case '12h':
			case 'twicedaily':
				return 12 * HOUR_IN_SECONDS;
			case '6h':
				return 6 * HOUR_IN_SECONDS;
			case '3h':
				return 3 * HOUR_IN_SECONDS;
			case '30min':
				return 30 * MINUTE_IN_SECONDS;
			case '15min':
				return 15 * MINUTE_IN_SECONDS;
			case '5min':
				return 5 * MINUTE_IN_SECONDS;
			case '1min':
				return MINUTE_IN_SECONDS;
			case 'hourly':
			default:
				return HOUR_IN_SECONDS;
		}
	}

	/**
	 * @param bool   $enabled  Whether the recurring action should be scheduled at all.
	 * @param string $interval Interval key (e.g. 'hourly', '15min').
	 * @return bool False only if a (re)schedule was actually attempted and
	 *              Action Scheduler failed to create it (action ID 0) - true
	 *              for "already correctly scheduled" and "disabled" too.
	 */
	public function ensure_reconcile_scheduled( bool $enabled, string $interval = 'hourly' ): bool {
		if ( ! $this->available() ) {
			return true;
		}

		as_unschedule_all_actions( self::HOOK_RECONCILE, array(), self::OLD_GROUP );

		$scheduled       = false !== as_next_scheduled_action( self::HOOK_RECONCILE, array(), self::GROUP );
		$stored_interval = (string) get_option( 'oblio_fgwoo_reconcile_scheduled_interval', '' );

		if ( ! $enabled ) {
			if ( $scheduled ) {
				as_unschedule_all_actions( self::HOOK_RECONCILE, array(), self::GROUP );
				delete_option( 'oblio_fgwoo_reconcile_scheduled_interval' );
			}
			return true;
		}

		if ( $scheduled && $stored_interval === $interval ) {
			return true;
		}

		$seconds = $this->interval_seconds( $interval );
		as_unschedule_all_actions( self::HOOK_RECONCILE, array(), self::GROUP );
		$action_id = as_schedule_recurring_action( time() + $seconds, $seconds, self::HOOK_RECONCILE, array(), self::GROUP );
		if ( (int) $action_id <= 0 ) {
			return false;
		}
		update_option( 'oblio_fgwoo_reconcile_scheduled_interval', $interval, false );
		return true;
	}

	/**
	 * Self-deduplicating: no-ops if a refresh is already pending/running, so
	 * a CIF change and a stale-cache page load in the same window can't
	 * double up on the same nomenclature calls.
	 */
	public function enqueue_nomenclature_refresh(): void {
		if ( ! $this->available() ) {
			return;
		}

		if ( function_exists( 'as_has_scheduled_action' ) ) {
			if ( as_has_scheduled_action( self::HOOK_NOMENCLATURE, array(), self::GROUP ) ) {
				return;
			}
		} elseif ( false !== as_next_scheduled_action( self::HOOK_NOMENCLATURE, array(), self::GROUP ) ) {
			return;
		}

		as_enqueue_async_action( self::HOOK_NOMENCLATURE, array(), self::GROUP );
	}

	public function backoff( int $attempt ): int {
		$base   = min( HOUR_IN_SECONDS, 30 * ( 2 ** max( 0, $attempt - 1 ) ) );
		$jitter = function_exists( 'wp_rand' ) ? wp_rand( 0, 20 ) : 0;

		return (int) ( $base + $jitter );
	}
}
