<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\LogReader;
use FGSyncOblio\Admin\QueueStatus;
use FGSyncOblio\Admin\StatusPanel;
use FGSyncOblio\Admin\UpdateChecker;
use FGSyncOblio\Extensibility\HookInspector;
use FGSyncOblio\Extensibility\HookRegistry;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass( \FGSyncOblio\Admin\StatusPanel::class )]
final class StatusPanelTest extends TestCase {

	private StatusPanel $panel;

	protected function setUp(): void {
		oblio_test_reset();
		$this->panel = new StatusPanel(
			new Settings(),
			new LogReader(),
			new QueueStatus(),
			new UpdateChecker(),
			new HookInspector( new HookRegistry() )
		);
	}

	private function count_issued( array $entries ): int {
		return ( new ReflectionMethod( StatusPanel::class, 'count_issued' ) )->invoke( $this->panel, $entries );
	}

	/**
	 * Real issuance log messages are English ("... issued"), not the
	 * Romanian "emis" this used to search for - the counter used to always
	 * read zero regardless of how many documents were actually issued.
	 */
	public function test_counts_real_issuance_log_messages(): void {
		$entries = array(
			array( 'message' => 'Order #1: invoice S 1 issued' ),
			array( 'message' => 'Order #2 refund #3: storno S 2 (partial) issued' ),
			array( 'message' => 'Order #4: manual full storno S 3 issued' ),
		);

		$this->assertSame( 3, $this->count_issued( $entries ) );
	}

	public function test_does_not_count_unrelated_or_failure_messages(): void {
		$entries = array(
			array( 'message' => 'Order #1: invoice deleted' ),
			array( 'message' => 'Queue: storno for order #1 refund #2 failed (attempt 1/5): boom, retrying in 30s' ),
			array( 'message' => 'Queue: invoice for order #1 permanent failure: bad CIF' ),
		);

		$this->assertSame( 0, $this->count_issued( $entries ) );
	}
}
