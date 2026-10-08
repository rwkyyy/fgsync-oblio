<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\LogReader;
use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Admin\QueueStatus;
use FGSyncOblio\Admin\SettingsPage;
use FGSyncOblio\Admin\StatusPanel;
use FGSyncOblio\Admin\UpdateChecker;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Extensibility\HookInspector;
use FGSyncOblio\Extensibility\HookRegistry;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

#[CoversClass( \FGSyncOblio\Admin\SettingsPage::class )]
final class SettingsPageTest extends TestCase {

	private SettingsPage $page;

	protected function setUp(): void {
		oblio_test_reset();

		$settings      = new Settings();
		$logger        = new Logger();
		$client        = new ClientFactory( $settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter() );
		$hook_registry = new HookRegistry();

		$this->page = new SettingsPage(
			$settings,
			$client,
			new NomenclatureCache( $client, $settings, $logger, new Scheduler() ),
			new StatusPanel( $settings, new LogReader(), new QueueStatus(), new UpdateChecker(), new HookInspector( $hook_registry ), new ConnectionHealth(), new OrderStore() ),
			$hook_registry,
			new HookInspector( $hook_registry ),
			$logger
		);
	}

	private function bundle_field(): array {
		$fields = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'advanced' );

		foreach ( $fields as $field ) {
			if ( 'oblio_fgwoo_bundle_line_mode' === ( $field['id'] ?? '' ) ) {
				return $field;
			}
		}

		$this->fail( 'bundle_line_mode field not found in advanced settings.' );
	}

	public function test_bundle_select_is_disabled_without_product_bundles_plugin(): void {
		$field = $this->bundle_field();

		$this->assertSame( array( 'disabled' => 'disabled' ), $field['custom_attributes'] );
	}

	#[RunInSeparateProcess]
	public function test_bundle_select_is_enabled_with_product_bundles_plugin(): void {
		if ( ! class_exists( 'WC_Product_Bundle' ) ) {
			eval( 'class WC_Product_Bundle {}' );
		}

		$field = $this->bundle_field();

		$this->assertSame( array(), $field['custom_attributes'] );
	}
}
