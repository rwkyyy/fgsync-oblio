<?php
/**
 * Per-order lock covering the full document lifecycle (invoice, proforma, storno).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Support;

final class OrderLock {

	private const TTL  = 900;
	private const WAIT = 20;

	public static function acquire( int $order_id ): ?string {
		$key      = self::key( $order_id );
		$deadline = microtime( true ) + self::WAIT;
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
