<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\StockSyncAction;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Stock\StockReservations;
use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( StockSyncAction::class )]
final class StockSyncActionTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	/**
	 * @return array<string,mixed>
	 */
	private function step(): array {
		$settings    = new Settings();
		$coordinator = new StockSyncCoordinator( $settings, new Scheduler(), new StockReservations( new OrderStore(), new Logger() ), new Logger() );
		( new StockSyncAction( $coordinator, $settings ) )->handle_step();
		return $GLOBALS['oblio_test_json_response']['data'];
	}

	public function test_a_finished_run_with_type_skips_sends_a_linked_message(): void {
		update_option(
			StockSyncCoordinator::LAST_RESULT_OPTION,
			array(
				'scanned' => 290,
				'updated' => 4,
				'skipped' => 1,
				'codes'   => array( 'TORT-05' ),
			)
		);

		$data = $this->step();

		$this->assertTrue( $data['done'] );
		$this->assertStringStartsWith( 'Sincronizare completă: 4 din 290 produse actualizate. 1 produs sărit', $data['message'] );
		$this->assertStringContainsString( 'data-section="advanced">Avansat</a>', $data['message_html'] );
		$this->assertStringContainsString( 'data-section="stock">„Sincronizează doar produsele cu același tip”</a>', $data['message_html'] );
	}

	public function test_a_run_without_skips_sends_plain_text_only(): void {
		update_option( StockSyncCoordinator::LAST_RESULT_OPTION, array( 'scanned' => 10, 'updated' => 2 ) );

		$data = $this->step();

		$this->assertSame( 'Sincronizare completă: 2 din 10 produse actualizate.', $data['message'] );
		$this->assertSame( '', $data['message_html'] );
	}
}
