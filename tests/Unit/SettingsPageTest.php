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
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

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

	public function test_tax_tab_offers_the_accounts_zero_rate_categories(): void {
		oblio_test_seed_vat_categories();

		$fields = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'tax' );
		$field  = array_values( array_filter( $fields, static fn ( array $field ): bool => 'oblio_fgwoo_vat_untaxed_category' === ( $field['id'] ?? '' ) ) )[0] ?? null;

		$this->assertNotNull( $field );
		$this->assertSame( array( 'Scutita', 'SFDD', 'SDD' ), array_keys( $field['options'] ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function tax_fields(): array {
		return ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'tax' );
	}

	public function test_tax_tab_with_wc_taxes_off_keeps_only_oss_and_buyer_settings(): void {
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'no';

		$fields = $this->tax_fields();
		$ids    = array_column( $fields, 'id' );

		$this->assertStringContainsString( 'Taxele sunt dezactivate în WooCommerce', $fields[0]['desc'] );
		$this->assertContains( 'oblio_fgwoo_oss_eur_currency', $ids );
		$this->assertContains( 'oblio_fgwoo_tax_buyers', $ids );
		$this->assertNotContains( 'oblio_fgwoo_vat_untaxed_category', $ids );
		$this->assertNotContains( 'oblio_fgwoo_vat_category_check', $ids );
		$this->assertNotContains( 'oblio_fgwoo_eu_vat_import', $ids );
	}

	public function test_tax_tab_with_wc_taxes_on_shows_the_vat_settings(): void {
		$ids = array_column( $this->tax_fields(), 'id' );

		$this->assertContains( 'oblio_fgwoo_vat_untaxed_category', $ids );
		$this->assertContains( 'oblio_fgwoo_vat_category_check', $ids );
		$this->assertContains( 'oblio_fgwoo_eu_vat_import', $ids );
	}

	public function test_warehouse_fields_are_disabled_when_the_account_has_no_warehouses(): void {
		set_transient( 'oblio_fgwoo_management', array(), WEEK_IN_SECONDS );

		$fields = array_merge(
			( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'documents' ),
			( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'stock' )
		);
		$by_id  = array_column( $fields, null, 'id' );

		foreach ( array( 'workstation', 'management', 'invoice_autogen_use_stock', 'stock_locations' ) as $key ) {
			$field = $by_id[ 'oblio_fgwoo_' . $key ];
			$this->assertSame( array( 'disabled' => 'disabled' ), $field['custom_attributes'], $key );
			$this->assertStringContainsString( 'Firma nu folosește gestiune de stoc în Oblio', $field['desc'], $key );
		}
	}

	public function test_warehouse_fields_stay_enabled_while_the_warehouse_list_is_unknown(): void {
		$fields = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'documents' );
		$by_id  = array_column( $fields, null, 'id' );

		$this->assertArrayNotHasKey( 'custom_attributes', $by_id['oblio_fgwoo_management'] );
	}

	public function test_oss_setting_lives_on_the_tax_tab(): void {
		$advanced = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'advanced' );

		$this->assertContains( 'oblio_fgwoo_oss_eur_currency', array_column( $this->tax_fields(), 'id' ) );
		$this->assertNotContains( 'oblio_fgwoo_oss_eur_currency', array_column( $advanced, 'id' ) );
	}

	public function test_tax_tab_recommends_the_facturare_plugin_when_missing(): void {
		$fields = $this->tax_fields();
		$ids    = array_column( $fields, 'id' );
		$notice = $fields[ array_search( 'oblio_fgwoo_tax_buyers', $ids, true ) ]['desc'];

		$this->assertStringContainsString( '<strong>Recomandăm:</strong> pluginul gratuit', $notice );
		$this->assertStringContainsString( 'wordpress.org/plugins/facturare-persoana-fizica-sau-juridica', $notice );
		$this->assertStringStartsWith( '<p class="description">', $notice );
		$this->assertArrayNotHasKey( 'title', $fields[ array_search( 'oblio_fgwoo_tax_buyers', $ids, true ) ] );
	}

	#[RunInSeparateProcess]
	public function test_tax_tab_confirms_the_facturare_plugin_when_active(): void {
		define( 'WOOFACTURARE_VERSION', '1.3.1' );

		$fields = $this->tax_fields();
		$notice = $fields[ array_search( 'oblio_fgwoo_tax_buyers', array_column( $fields, 'id' ), true ) ]['desc'];

		$this->assertStringContainsString( 'versiunea 1.3.1, activ', $notice );
	}
}
