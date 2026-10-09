<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\AdminBarStatus;
use FGSyncOblio\Admin\QueueStatus;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( AdminBarStatus::class )]
final class AdminBarStatusTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
		update_option( 'oblio_fgwoo_email', 'shop@example.com' );
		update_option( 'oblio_fgwoo_secret', 'encrypted-secret' );
		update_option( 'oblio_fgwoo_cif', 'RO123' );
	}

	private function bar(): AdminBarStatus {
		return new AdminBarStatus( new Settings(), new ConnectionHealth(), new QueueStatus() );
	}

	/**
	 * A page rendered while healthy must pick up a later auth failure on its
	 * next poll, without a reload.
	 */
	public function test_tone_poll_reports_a_failure_recorded_after_render(): void {
		$bar    = $this->bar();
		$health = new ConnectionHealth();
		$health->record_success();
		$this->assertSame( 'green', $bar->tone() );

		$health->record_failure( 'Autorizare eșuată (HTTP 400)' );
		$bar->handle_tone();

		$this->assertSame(
			array(
				'success' => true,
				'data'    => array( 'tone' => 'red' ),
			),
			$GLOBALS['oblio_test_json_response']
		);
	}
}
