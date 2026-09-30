<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\QueueStatus;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Admin\QueueStatus::class )]
final class QueueStatusTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	/**
	 * StatusPanel never displays an all-time "complete" count - querying it
	 * was a wasted Action Scheduler round trip on every cache miss.
	 */
	public function test_totals_has_no_complete_key(): void {
		$totals = ( new QueueStatus() )->totals();

		$this->assertSame( array( 'pending', 'in-progress', 'failed' ), array_keys( $totals ) );
	}
}
