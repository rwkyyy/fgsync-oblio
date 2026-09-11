<?php
/**
 * Action Scheduler wrapper for the plugin's background jobs.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Queue;

final class Scheduler {

	public const GROUP             = 'oblio';
	public const HOOK_GENERATE     = 'oblio_fgwoo_generate_document';
	public const HOOK_REFUND       = 'oblio_fgwoo_generate_refund';
	public const HOOK_RECONCILE    = 'oblio_fgwoo_reconcile';
	public const HOOK_STOCK_SYNC   = 'oblio_fgwoo_stock_sync';
	public const HOOK_STOCK_BATCH  = 'oblio_fgwoo_stock_batch';
	public const HOOK_STOCK_SETTLE = 'oblio_fgwoo_stock_settle';
	public const HOOK_WEBHOOK      = 'oblio_fgwoo_process_webhook';

	public const SCHEDULE_CHECK = 'oblio_fgwoo_schedule_check';

	private ?array $pending_generate = null;

	private ?array $pending_refund = null;

	public function available(): bool {
		return function_exists( 'as_enqueue_async_action' );
	}

	public function enqueue_document( int $order_id, string $doc_type, array $options = array(), int $attempt = 1, int $delay = 0 ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		if ( 1 === $attempt && $this->has_pending_document( $order_id, $doc_type ) ) {
			return false;
		}

		$payload = array(
			'order_id' => $order_id,
			'doc_type' => $doc_type,
			'options'  => $options,
			'attempt'  => $attempt,
		);

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK_GENERATE, array( $payload ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK_GENERATE, array( $payload ), self::GROUP );
		}

		if ( null !== $this->pending_generate ) {
			$this->pending_generate[ $order_id . '|' . $doc_type ] = true;
		}
		return true;
	}

	public function reschedule_document( int $order_id, string $doc_type, array $options, int $attempt, int $delay ): void {
		if ( ! $this->available() ) {
			return;
		}
		$payload = array(
			'order_id' => $order_id,
			'doc_type' => $doc_type,
			'options'  => $options,
			'attempt'  => $attempt,
		);
		as_schedule_single_action( time() + max( 1, $delay ), self::HOOK_GENERATE, array( $payload ), self::GROUP );
		if ( null !== $this->pending_generate ) {
			$this->pending_generate[ $order_id . '|' . $doc_type ] = true;
		}
	}

	public function reschedule_refund( int $order_id, int $refund_id, int $attempt, int $delay ): void {
		if ( ! $this->available() ) {
			return;
		}
		$payload = array(
			'order_id'  => $order_id,
			'refund_id' => $refund_id,
			'attempt'   => $attempt,
		);
		as_schedule_single_action( time() + max( 1, $delay ), self::HOOK_REFUND, array( $payload ), self::GROUP );
		if ( null !== $this->pending_refund ) {
			$this->pending_refund[ $order_id . '|' . $refund_id ] = true;
		}
	}

	public function enqueue_refund( int $order_id, int $refund_id, int $attempt = 1, int $delay = 0 ): bool {
		if ( ! $this->available() ) {
			return false;
		}

		if ( 1 === $attempt && $this->has_pending_refund( $order_id, $refund_id ) ) {
			return false;
		}

		$payload = array(
			'order_id'  => $order_id,
			'refund_id' => $refund_id,
			'attempt'   => $attempt,
		);

		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK_REFUND, array( $payload ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK_REFUND, array( $payload ), self::GROUP );
		}

		if ( null !== $this->pending_refund ) {
			$this->pending_refund[ $order_id . '|' . $refund_id ] = true;
		}
		return true;
	}

	public function has_pending_document( int $order_id, string $doc_type ): bool {
		if ( null === $this->pending_generate ) {
			$this->pending_generate = $this->build_pending_map(
				self::HOOK_GENERATE,
				static fn ( array $payload ): string => (int) ( $payload['order_id'] ?? 0 ) . '|' . (string) ( $payload['doc_type'] ?? '' )
			);
		}
		return isset( $this->pending_generate[ $order_id . '|' . $doc_type ] );
	}

	public function has_pending_refund( int $order_id, int $refund_id ): bool {
		if ( null === $this->pending_refund ) {
			$this->pending_refund = $this->build_pending_map(
				self::HOOK_REFUND,
				static fn ( array $payload ): string => (int) ( $payload['order_id'] ?? 0 ) . '|' . (int) ( $payload['refund_id'] ?? 0 )
			);
		}
		return isset( $this->pending_refund[ $order_id . '|' . $refund_id ] );
	}

	private function build_pending_map( string $hook, callable $key ): array {
		$map = array();
		foreach ( $this->pending_payloads( $hook ) as $payload ) {
			$map[ $key( $payload ) ] = true;
		}
		return $map;
	}

	private function pending_payloads( string $hook ): array {
		if ( ! function_exists( 'as_get_scheduled_actions' ) || ! class_exists( \ActionScheduler_Store::class ) ) {
			return array();
		}

		$statuses = array( \ActionScheduler_Store::STATUS_PENDING, \ActionScheduler_Store::STATUS_RUNNING );
		$payloads = array();
		$per_page = 200;

		foreach ( $statuses as $status ) {

			$offset = 0;
			do {
				$actions = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => self::GROUP,
						'status'   => $status,
						'per_page' => $per_page,
						'offset'   => $offset,
					)
				);
				$actions = (array) $actions;
				foreach ( $actions as $action ) {
					$args       = $action->get_args();
					$payloads[] = $args[0] ?? array();
				}
				$found   = count( $actions );
				$offset += $per_page;
			} while ( $per_page === $found );
		}

		return $payloads;
	}

	public function enqueue_stock_batch( int $offset, int $attempt = 1, int $delay = 0, string $token = '' ): void {
		if ( ! $this->available() ) {
			return;
		}
		$payload = array(
			'offset'  => $offset,
			'attempt' => $attempt,
			'token'   => $token,
		);
		if ( $delay > 0 ) {
			as_schedule_single_action( time() + $delay, self::HOOK_STOCK_BATCH, array( $payload ), self::GROUP );
		} else {
			as_enqueue_async_action( self::HOOK_STOCK_BATCH, array( $payload ), self::GROUP );
		}
	}

	public function schedule_settle( int $delay ): void {
		if ( ! $this->available() ) {
			return;
		}
		as_schedule_single_action( time() + max( 1, $delay ), self::HOOK_STOCK_SETTLE, array(), self::GROUP );
	}

	public function settle_pending(): bool {
		return $this->available() && false !== as_next_scheduled_action( self::HOOK_STOCK_SETTLE, array(), self::GROUP );
	}

	public function cancel_stock_batches(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_STOCK_BATCH, array(), self::GROUP );
		}
	}

	public function enqueue_webhook( string $topic, array $data ): void {
		if ( ! $this->available() ) {
			return;
		}
		as_enqueue_async_action(
			self::HOOK_WEBHOOK,
			array(
				array(
					'topic' => $topic,
					'data'  => $data,
				),
			),
			self::GROUP
		);
	}

	public function ensure_stock_scheduled( bool $enabled, string $interval ): void {
		if ( ! $this->available() ) {
			return;
		}

		$scheduled       = false !== as_next_scheduled_action( self::HOOK_STOCK_SYNC, array(), self::GROUP );
		$stored_interval = (string) get_option( 'oblio_fgwoo_stock_scheduled_interval', '' );

		if ( ! $enabled ) {
			if ( $scheduled ) {
				as_unschedule_all_actions( self::HOOK_STOCK_SYNC, array(), self::GROUP );
				delete_option( 'oblio_fgwoo_stock_scheduled_interval' );
			}
			return;
		}

		if ( $scheduled && $stored_interval === $interval ) {
			return;
		}

		$seconds = $this->interval_seconds( $interval );
		as_unschedule_all_actions( self::HOOK_STOCK_SYNC, array(), self::GROUP );
		as_schedule_recurring_action( time() + $seconds, $seconds, self::HOOK_STOCK_SYNC, array(), self::GROUP );
		update_option( 'oblio_fgwoo_stock_scheduled_interval', $interval, false );
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

	public function ensure_reconcile_scheduled( bool $enabled, string $interval = 'hourly' ): void {
		if ( ! $this->available() ) {
			return;
		}

		$scheduled       = false !== as_next_scheduled_action( self::HOOK_RECONCILE, array(), self::GROUP );
		$stored_interval = (string) get_option( 'oblio_fgwoo_reconcile_scheduled_interval', '' );

		if ( ! $enabled ) {
			if ( $scheduled ) {
				as_unschedule_all_actions( self::HOOK_RECONCILE, array(), self::GROUP );
				delete_option( 'oblio_fgwoo_reconcile_scheduled_interval' );
			}
			return;
		}

		if ( $scheduled && $stored_interval === $interval ) {
			return;
		}

		$seconds = $this->interval_seconds( $interval );
		as_unschedule_all_actions( self::HOOK_RECONCILE, array(), self::GROUP );
		as_schedule_recurring_action( time() + $seconds, $seconds, self::HOOK_RECONCILE, array(), self::GROUP );
		update_option( 'oblio_fgwoo_reconcile_scheduled_interval', $interval, false );
	}

	public function backoff( int $attempt ): int {
		$base   = min( HOUR_IN_SECONDS, 30 * ( 2 ** max( 0, $attempt - 1 ) ) );
		$jitter = function_exists( 'wp_rand' ) ? wp_rand( 0, 20 ) : 0;

		return (int) ( $base + $jitter );
	}
}
