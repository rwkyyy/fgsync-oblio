<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Legacy\Importer;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( Importer::class )]
final class ImporterTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
	}

	private function import(): void {
		$logger = new Logger();
		( new Importer( $this->settings, new ClientFactory( $this->settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) ), $logger ) )->import();
	}

	public function test_old_auto_invoicing_also_turns_on_invoicing_on_payment(): void {
		update_option( 'oblio_invoice_autogen', '1' );

		$this->import();

		$this->assertSame( 'yes', $this->settings->get( 'invoice_autogen' ) );
		$this->assertSame( 'yes', $this->settings->get( 'invoice_on_payment' ) );
	}

	public function test_without_old_auto_invoicing_payment_stays_off(): void {
		update_option( 'oblio_invoice_autogen', '0' );

		$this->import();

		$this->assertSame( 'no', $this->settings->get( 'invoice_on_payment' ) );
	}
}
