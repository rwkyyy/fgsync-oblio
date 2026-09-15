<?php
/**
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Tests\Unit;

use OblioWoo\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \OblioWoo\Support\Settings::class )]
final class SettingsTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
	}

	public function test_returns_declared_default(): void {
		$this->assertSame( 'RO', $this->settings->get( 'language' ) );
		$this->assertSame( 'transform', $this->settings->get( 'proforma_on_invoice' ) );
	}

	public function test_option_name_is_prefixed(): void {
		$this->assertSame( 'oblio_fgwoo_email', $this->settings->option_name( 'email' ) );
	}

	public function test_set_and_get(): void {
		$this->settings->set( 'email', 'user@example.test' );
		$this->assertSame( 'user@example.test', $this->settings->get( 'email' ) );
	}

	public function test_is_enabled_and_credentials(): void {
		$this->assertFalse( $this->settings->is_enabled( 'invoice_autogen' ) );
		$this->assertFalse( $this->settings->has_credentials() );

		$this->settings->set( 'email', 'a@b.ro' );
		$this->settings->set( 'secret', 'x' );
		$this->assertTrue( $this->settings->has_credentials() );

		$this->settings->set( 'invoice_autogen', 'yes' );
		$this->assertTrue( $this->settings->is_enabled( 'invoice_autogen' ) );
	}

	public function test_stock_sync_trigger_defaults_off(): void {
		$this->assertSame( 'off', $this->settings->stock_sync_trigger() );
		$this->assertFalse( $this->settings->stock_schedule_enabled() );
		$this->assertFalse( $this->settings->stock_webhook_enabled() );
		$this->assertFalse( $this->settings->stock_sync_configured() );
	}

	public function test_stock_sync_trigger_stored_value_wins(): void {
		$this->settings->set( 'stock_sync_trigger', 'schedule' );

		$this->assertSame( 'schedule', $this->settings->stock_sync_trigger() );
		$this->assertTrue( $this->settings->stock_schedule_enabled() );
		$this->assertFalse( $this->settings->stock_webhook_enabled() );
		$this->assertTrue( $this->settings->stock_sync_configured() );
	}

	public function test_stock_sync_trigger_webhook_is_temporarily_disabled(): void {
		$this->settings->set( 'stock_sync_trigger', 'webhook' );
		$this->assertSame( 'off', $this->settings->stock_sync_trigger() );
		$this->assertFalse( $this->settings->stock_sync_configured() );

		$this->settings->set( 'stock_sync_trigger', 'both' );
		$this->assertSame( 'schedule', $this->settings->stock_sync_trigger() );
		$this->assertTrue( $this->settings->stock_schedule_enabled() );

		$this->assertFalse( $this->settings->stock_webhook_enabled() );
	}

	public function test_stock_sync_trigger_derived_from_legacy_flags(): void {

		$this->settings->set( 'stock_sync', 'yes' );
		$this->settings->set( 'webhooks_enabled', 'yes' );
		$this->settings->set( 'webhook_stock', 'yes' );

		$this->assertSame( 'schedule', $this->settings->stock_sync_trigger() );
		$this->assertTrue( $this->settings->stock_schedule_enabled() );
		$this->assertFalse( $this->settings->stock_webhook_enabled() );
	}

	public function test_stock_sync_trigger_derived_schedule_only(): void {
		$this->settings->set( 'stock_sync', 'yes' );

		$this->assertSame( 'schedule', $this->settings->stock_sync_trigger() );
		$this->assertTrue( $this->settings->stock_schedule_enabled() );
		$this->assertFalse( $this->settings->stock_webhook_enabled() );
	}
}
