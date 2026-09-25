<?php
/**
 * Summarises the plugin's Action Scheduler queue for the Status panel.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Queue\Scheduler;
final class QueueStatus {

	private const CACHE     = 'oblio_fgwoo_queue_counts';
	private const CACHE_TTL = 30;

	private const ADMIN_BAR_CACHE     = 'oblio_fgwoo_queue_counts_admin_bar';
	private const ADMIN_BAR_CACHE_TTL = 5 * MINUTE_IN_SECONDS;

	private ?array $snapshot = null;

	public function available(): bool {
		return class_exists( \ActionScheduler::class ) && class_exists( \ActionScheduler_Store::class );
	}

	public function totals(): array {
		return $this->snapshot()['totals'];
	}

	/**
	 * Pending+failed only, cached longer - for the admin-bar dot, which needs
	 * a coarse signal on every page load rather than the full per-hook
	 * breakdown the Status panel shows.
	 */
	public function admin_bar_totals(): array {
		$cached = get_transient( self::ADMIN_BAR_CACHE );
		if ( is_array( $cached ) && isset( $cached['pending'], $cached['failed'] ) ) {
			return $cached;
		}

		$totals = array(
			'pending' => $this->count( array( 'status' => \ActionScheduler_Store::STATUS_PENDING ) ),
			'failed'  => $this->count( array( 'status' => \ActionScheduler_Store::STATUS_FAILED ) ),
		);
		set_transient( self::ADMIN_BAR_CACHE, $totals, self::ADMIN_BAR_CACHE_TTL );
		return $totals;
	}

	public function by_hook(): array {
		return $this->snapshot()['by_hook'];
	}

	private function snapshot(): array {
		if ( null !== $this->snapshot ) {
			return $this->snapshot;
		}

		$cached = get_transient( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['totals'], $cached['by_hook'] ) ) {
			$this->snapshot = $cached;
			return $cached;
		}

		$snapshot = array(
			'totals'  => $this->compute_totals(),
			'by_hook' => $this->compute_by_hook(),
		);
		set_transient( self::CACHE, $snapshot, self::CACHE_TTL );
		$this->snapshot = $snapshot;
		return $snapshot;
	}

	private function compute_totals(): array {
		return array(
			'pending'     => $this->count( array( 'status' => \ActionScheduler_Store::STATUS_PENDING ) ),
			'in-progress' => $this->count( array( 'status' => \ActionScheduler_Store::STATUS_RUNNING ) ),
			'failed'      => $this->count( array( 'status' => \ActionScheduler_Store::STATUS_FAILED ) ),
			'complete'    => $this->count( array( 'status' => \ActionScheduler_Store::STATUS_COMPLETE ) ),
		);
	}

	private function compute_by_hook(): array {
		$hooks = array(
			Scheduler::HOOK_GENERATE    => 'generate_document',
			Scheduler::HOOK_REFUND      => 'generate_refund',
			Scheduler::HOOK_STOCK_BATCH => 'stock_sync_batch',
			Scheduler::HOOK_RECONCILE   => 'reconcile_invoices',
		);

		$result = array();
		foreach ( $hooks as $hook => $label ) {
			$result[ $label ] = array(
				'pending' => $this->count(
					array(
						'hook'   => $hook,
						'status' => \ActionScheduler_Store::STATUS_PENDING,
					)
				),
				'failed'  => $this->count(
					array(
						'hook'   => $hook,
						'status' => \ActionScheduler_Store::STATUS_FAILED,
					)
				),
			);
		}
		return $result;
	}

	private function count( array $args ): int {
		if ( ! $this->available() ) {
			return 0;
		}
		$args['group'] = Scheduler::GROUP;
		return (int) \ActionScheduler::store()->query_actions( $args, 'count' );
	}
}
