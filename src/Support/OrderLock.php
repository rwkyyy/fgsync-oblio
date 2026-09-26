<?php
/**
 * Per-order lock covering the full document lifecycle (invoice, proforma, storno).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Support;

final class OrderLock {

	private const TTL = 900;

	public const WAIT      = 20;
	public const FAIL_FAST = 0;

	/**
	 * $wait <= 0 tries once and returns immediately on contention, instead of
	 * blocking - for background/non-interactive callers (queue jobs, inline
	 * email issuance) that would rather fail fast and let their own retry/
	 * reschedule logic handle contention than tie up a worker for up to WAIT
	 * seconds and ~100 lock-poll queries.
	 *
	 * @param int $order_id Order ID to lock.
	 * @param int $wait     Seconds to keep retrying before giving up.
	 */
	public static function acquire( int $order_id, int $wait = self::WAIT ): ?string {
		$key = self::key( $order_id );
		if ( $wait <= 0 ) {
			return AtomicLock::acquire( $key, self::TTL );
		}

		$deadline = microtime( true ) + $wait;
		do {
			$owner = AtomicLock::acquire( $key, self::TTL );
			if ( null !== $owner ) {
				return $owner;
			}
			usleep( 200000 );
		} while ( microtime( true ) < $deadline );

		return null;
	}

	public static function renew( int $order_id, string $owner ): void {
		AtomicLock::renew( self::key( $order_id ), $owner, self::TTL );
	}

	public static function release( int $order_id, string $owner ): void {
		AtomicLock::release( self::key( $order_id ), $owner );
	}

	private static function key( int $order_id ): string {
		return 'oblio_fgwoo_issue_lock_' . $order_id;
	}
}
