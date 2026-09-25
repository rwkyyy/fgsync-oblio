<?php
/**
 * Tracks the outcome of the most recent Oblio API/auth call, for a passive
 * health signal (no dedicated ping - reuses calls that already happen).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Support;

final class ConnectionHealth {

	private const OPTION = 'oblio_fgwoo_connection_health';

	private const SUCCESS_REWRITE_INTERVAL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Skips the write when already healthy and recently confirmed, so a busy
	 * page (several successful API calls in one request) or a quiet account
	 * (one call every few minutes) doesn't rewrite the option every time -
	 * only a recovery from failure or a stale confirmation triggers a write.
	 */
	public function record_success(): void {
		$state   = get_option( self::OPTION, null );
		$was_ok  = is_array( $state ) && ! empty( $state['ok'] );
		$last_at = is_array( $state ) ? (int) ( $state['at'] ?? 0 ) : 0;

		if ( $was_ok && ( time() - $last_at ) < self::SUCCESS_REWRITE_INTERVAL ) {
			return;
		}

		update_option(
			self::OPTION,
			array(
				'ok'     => true,
				'at'     => time(),
				'reason' => '',
			),
			false
		);
	}

	public function record_failure( string $reason ): void {
		update_option(
			self::OPTION,
			array(
				'ok'     => false,
				'at'     => time(),
				'reason' => $reason,
			),
			false
		);
	}

	/**
	 * No recorded outcome yet reads as healthy - it's unproven, not broken.
	 */
	public function is_healthy(): bool {
		$state = get_option( self::OPTION, null );
		return ! is_array( $state ) || ! empty( $state['ok'] );
	}

	public function last_error(): string {
		$state = get_option( self::OPTION, null );
		if ( ! is_array( $state ) || ! empty( $state['ok'] ) ) {
			return '';
		}
		return (string) ( $state['reason'] ?? '' );
	}

	public function last_checked(): int {
		$state = get_option( self::OPTION, null );
		return is_array( $state ) ? (int) ( $state['at'] ?? 0 ) : 0;
	}
}
