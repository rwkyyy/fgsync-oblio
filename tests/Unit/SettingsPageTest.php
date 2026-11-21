<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\ConnectionTest;
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

	private Settings $settings;

	private ClientFactory $factory;

	protected function setUp(): void {
		oblio_test_reset();
		$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = 'yes';

		$settings      = new Settings();
		$logger        = new Logger();
		$client        = new ClientFactory( $settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter() );
		$hook_registry = new HookRegistry();
		$nomenclature  = new NomenclatureCache( $client, $settings, $logger, new Scheduler() );

		$this->page = new SettingsPage(
			$settings,
			$client,
			$nomenclature,
			new StatusPanel( $settings, new LogReader(), new QueueStatus(), new UpdateChecker(), new HookInspector( $hook_registry ), new ConnectionHealth(), new OrderStore() ),
			$hook_registry,
			new HookInspector( $hook_registry ),
			$logger,
			new ConnectionTest( $client, $nomenclature, $logger, $settings )
		);
		$this->settings = $settings;
		$this->factory  = $client;
	}

	/**
	 * @param array<int|string,mixed> $data
	 */
	private function respond( array $data, int $code = 200 ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => $code ),
			'body'     => wp_json_encode( $data ),
		);
	}

	/**
	 * @param array<string,string> $post Submitted settings.
	 */
	private function save( array $post ): void {
		$_POST = array( 'oblio_fgwoo_save' => '1' ) + $post;
		( new ReflectionMethod( SettingsPage::class, 'handle_save' ) )->invoke( $this->page );
	}

	private function account_error(): ?string {
		return ( new \ReflectionProperty( SettingsPage::class, 'account_error' ) )->getValue( $this->page );
	}

	/**
	 * The new account's companies have to be stored before the fields are
	 * saved, or its CIF isn't an allowed option yet.
	 */
	public function test_a_new_api_key_loads_the_account_before_saving_the_fields(): void {
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'cif', 'RO123' );
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( array( 'cif' => 'RO123', 'company' => 'New SRL', 'useStock' => '0' ) ) ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array() ) );

		$this->save(
			array(
				'oblio_fgwoo_email'  => 'shop@example.test',
				'oblio_fgwoo_secret' => 'new-token',
			)
		);

		$this->assertSame( array( 'RO123' => 'New SRL' ), get_option( ConnectionTest::COMPANIES_OPTION ) );
		$this->assertNull( $this->account_error() );
		$this->assertCount( 1, $GLOBALS['oblio_test_saved_fields'] );
	}

	public function test_a_new_email_is_stored_before_the_account_is_loaded(): void {
		$this->settings->set( 'email', 'old@example.test' );
		$this->settings->set( 'cif', 'RO123' );
		$this->factory->set_secret( 'token' );
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( array( 'cif' => 'RO123', 'company' => 'New SRL', 'useStock' => '0' ) ) ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array() ) );

		$this->save( array( 'oblio_fgwoo_email' => 'new@example.test' ) );

		$this->assertSame( 'new@example.test', $GLOBALS['oblio_test_http_calls'][0]['args']['body']['client_id'] );
		$this->assertSame( array( 'RO123' => 'New SRL' ), get_option( ConnectionTest::COMPANIES_OPTION ) );
	}

	public function test_unchanged_credentials_do_not_call_oblio(): void {
		$this->settings->set( 'email', 'shop@example.test' );
		$this->factory->set_secret( 'token' );

		$this->save( array( 'oblio_fgwoo_email' => 'shop@example.test' ) );

		$this->assertSame( array(), $GLOBALS['oblio_test_http_calls'] );
		$this->assertCount( 1, $GLOBALS['oblio_test_saved_fields'] );
	}

	public function test_a_rejected_account_is_reported_and_the_fields_are_still_saved(): void {
		$this->settings->set( 'email', 'shop@example.test' );
		update_option( ConnectionTest::COMPANIES_OPTION, array( 'RO999' => 'Old SRL' ) );
		$this->respond( array( 'statusMessage' => 'Invalid credentials' ), 401 );

		$this->save(
			array(
				'oblio_fgwoo_email'  => 'shop@example.test',
				'oblio_fgwoo_secret' => 'wrong-token',
			)
		);

		$this->assertNotNull( $this->account_error() );
		$this->assertSame( array( 'RO999' => 'Old SRL' ), get_option( ConnectionTest::COMPANIES_OPTION ) );
		$this->assertCount( 1, $GLOBALS['oblio_test_saved_fields'] );
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

	public function test_a_tab_link_targets_the_element_and_names_the_tab(): void {
		$this->assertSame(
			'<a href="https://example.test/wp-admin/admin.php?page=fgsync-oblio#oblio_fgwoo_email" class="oblio-fgwoo-tab-link" data-section="connection">Conectare</a>',
			SettingsPage::tab_link( '', 'oblio_fgwoo_email', 'Conectare' )
		);
		$this->assertStringContainsString( 'section=status#oblio-fgwoo-log" class="oblio-fgwoo-tab-link" data-section="status">', SettingsPage::tab_link( 'status', 'oblio-fgwoo-log', 'Stare' ) );
	}

	public function test_debug_logging_links_to_the_activity_log(): void {
		$fields = array_column( ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'advanced' ), null, 'id' );

		$this->assertStringContainsString( 'section=status#' . StatusPanel::LOG_CARD . '" class="oblio-fgwoo-tab-link" data-section="status">Stare → Jurnal activitate</a>', $fields['oblio_fgwoo_debug_logging']['desc'] );
	}

	public function test_stock_sync_matches_product_types_by_default(): void {
		$fields = array_column( ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'stock' ), null, 'id' );

		$this->assertSame( 'checkbox', $fields['oblio_fgwoo_stock_match_product_type']['type'] );
		$this->assertStringContainsString( 'page=fgsync-oblio&amp;section=advanced#oblio_fgwoo_product_type" class="oblio-fgwoo-tab-link" data-section="advanced">Avansat</a>', $fields['oblio_fgwoo_stock_match_product_type']['desc'] );
		$this->assertTrue( $this->settings->is_enabled( 'stock_match_product_type' ) );
	}

	public function test_mentions_checkbox_sits_above_a_prefilled_textarea(): void {
		$fields = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'advanced' );
		$ids    = array_column( $fields, 'id' );
		$by_id  = array_column( $fields, null, 'id' );

		$this->assertSame( array_search( 'oblio_fgwoo_invoice_mentions', $ids, true ) - 1, array_search( 'oblio_fgwoo_invoice_mentions_enabled', $ids, true ) );
		$this->assertSame( 'checkbox', $by_id['oblio_fgwoo_invoice_mentions_enabled']['type'] );
		$this->assertSame( 'no', $by_id['oblio_fgwoo_invoice_mentions_enabled']['default'] );
		$this->assertStringStartsWith( 'Pentru comanda [order_id]', $by_id['oblio_fgwoo_invoice_mentions']['value'] );
	}

	public function test_mentions_checkbox_starts_ticked_when_text_was_already_saved(): void {
		$this->settings->set( 'invoice_mentions', 'Comanda [order_id]' );

		$by_id = array_column( ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'advanced' ), null, 'id' );

		$this->assertSame( 'yes', $by_id['oblio_fgwoo_invoice_mentions_enabled']['default'] );
		$this->assertSame( 'Comanda [order_id]', $by_id['oblio_fgwoo_invoice_mentions']['value'] );
	}

	public function test_email_button_custom_color_is_opt_in_and_saved(): void {
		$fields = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'email' );
		$ids    = array_column( $fields, 'id' );
		$by_id  = array_column( $fields, null, 'id' );
		$saved  = array_column( ( new ReflectionMethod( SettingsPage::class, 'saveable_fields' ) )->invoke( $this->page ), 'id' );

		$this->assertSame( array_search( 'oblio_fgwoo_email_button_label', $ids, true ) + 1, array_search( 'oblio_fgwoo_email_button_custom_color', $ids, true ) );
		$this->assertSame( array_search( 'oblio_fgwoo_email_button_custom_color', $ids, true ) + 1, array_search( 'oblio_fgwoo_email_button_color', $ids, true ) );
		$this->assertSame( 'checkbox', $by_id['oblio_fgwoo_email_button_custom_color']['type'] );
		$this->assertSame( 'no', $by_id['oblio_fgwoo_email_button_custom_color']['default'] );
		$this->assertSame( 'color', $by_id['oblio_fgwoo_email_button_color']['type'] );
		$this->assertContains( 'oblio_fgwoo_email_button_custom_color', $saved );
		$this->assertContains( 'oblio_fgwoo_email_button_color', $saved );
		$this->assertFalse( $this->settings->is_enabled( 'email_button_custom_color' ) );
	}

	public function test_stock_reservation_statuses_are_saved_and_default_to_the_previous_fixed_list(): void {
		$fields = array_column( ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'stock' ), null, 'id' );
		$saved  = array_column( ( new ReflectionMethod( SettingsPage::class, 'saveable_fields' ) )->invoke( $this->page ), 'id' );

		$this->assertSame( 'multiselect', $fields['oblio_fgwoo_stock_reserve_statuses']['type'] );
		$this->assertSame( array( 'on-hold', 'processing', 'pending' ), $fields['oblio_fgwoo_stock_reserve_statuses']['default'] );
		$this->assertContains( 'oblio_fgwoo_stock_reserve_statuses', $saved );
	}

	public function test_tax_tab_opens_with_the_advice_to_consult_the_developer_and_accountant(): void {
		foreach ( array( 'yes', 'no' ) as $calc_taxes ) {
			$GLOBALS['oblio_test_options']['woocommerce_calc_taxes'] = $calc_taxes;

			$this->assertStringStartsWith( '<p><u>Înainte să luați decizii în această secțiune', $this->tax_fields()[0]['desc'], $calc_taxes );
		}
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

	/**
	 * Browsers don't submit disabled controls; saving them would reset the
	 * kept warehouse/bundle choices to no, [] or the default.
	 */
	public function test_disabled_fields_are_left_out_of_the_save(): void {
		set_transient( 'oblio_fgwoo_management', array(), WEEK_IN_SECONDS );

		$ids = array_column( ( new ReflectionMethod( SettingsPage::class, 'saveable_fields' ) )->invoke( $this->page ), 'id' );

		foreach ( array( 'workstation', 'management', 'invoice_autogen_use_stock', 'stock_locations', 'bundle_line_mode' ) as $key ) {
			$this->assertNotContains( 'oblio_fgwoo_' . $key, $ids, $key );
		}
		$this->assertContains( 'oblio_fgwoo_email', $ids );
	}

	public function test_enabled_warehouse_fields_are_saved(): void {
		$ids = array_column( ( new ReflectionMethod( SettingsPage::class, 'saveable_fields' ) )->invoke( $this->page ), 'id' );

		$this->assertContains( 'oblio_fgwoo_stock_locations', $ids );
		$this->assertContains( 'oblio_fgwoo_invoice_autogen_use_stock', $ids );
	}

	public function test_warehouse_fields_stay_enabled_while_the_warehouse_list_is_unknown(): void {
		$fields = ( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'documents' );
		$by_id  = array_column( $fields, null, 'id' );

		$this->assertArrayNotHasKey( 'custom_attributes', $by_id['oblio_fgwoo_management'] );
	}

	/**
	 * @param array<int,string> $synced Synced locations.
	 * @return array{0:string,1:string} Descriptions of „Gestiune (emitere)” and „Gestiuni (locații)”.
	 */
	private function gestiune_descriptions( array $synced, string $management = 'Central' ): array {
		set_transient(
			'oblio_fgwoo_management',
			array(
				array( 'workStation' => 'Sediu', 'management' => 'Central' ),
				array( 'workStation' => 'Sediu', 'management' => 'Showroom' ),
			),
			WEEK_IN_SECONDS
		);
		$GLOBALS['oblio_test_options']['oblio_fgwoo_stock_sync_trigger'] ??= 'schedule';
		$this->settings->set( 'workstation', 'Sediu' );
		$this->settings->set( 'management', $management );
		$this->settings->set( 'stock_locations', $synced );

		$by_id = array_column(
			array_merge(
				( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'documents' ),
				( new ReflectionMethod( SettingsPage::class, 'get_settings' ) )->invoke( $this->page, 'stock' )
			),
			null,
			'id'
		);
		return array( $by_id['oblio_fgwoo_management']['desc'], $by_id['oblio_fgwoo_stock_locations']['desc'] );
	}

	public function test_both_gestiune_settings_warn_when_the_shop_sells_from_gestiuni_documents_dont_use(): void {
		list( $management, $locations ) = $this->gestiune_descriptions( array( 'Sediu|Central', 'Sediu|Showroom' ) );

		foreach ( array( $management, $locations ) as $desc ) {
			$this->assertStringContainsString( 'magazinul vinde stocul din Sediu / Central, Sediu / Showroom, dar facturile și avizele descarcă stocul doar din Sediu / Central.', $desc );
		}
		$this->assertStringContainsString( 'section=stock#oblio_fgwoo_stock_locations', $management );
		$this->assertStringContainsString( 'section=documents#oblio_fgwoo_management', $locations );
	}

	public function test_an_empty_location_list_sums_every_gestiune_and_warns(): void {
		list( , $locations ) = $this->gestiune_descriptions( array() );

		$this->assertStringContainsString( 'vinde stocul din Sediu / Central, Sediu / Showroom', $locations );
	}

	public function test_a_synced_gestiune_other_than_the_issuing_one_warns(): void {
		list( , $locations ) = $this->gestiune_descriptions( array( 'Sediu|Showroom' ) );

		$this->assertStringContainsString( 'vinde stocul din Sediu / Showroom, dar facturile și avizele descarcă stocul doar din Sediu / Central', $locations );
	}

	public function test_syncing_only_the_issuing_gestiune_does_not_warn(): void {
		foreach ( $this->gestiune_descriptions( array( 'Sediu|Central' ) ) as $desc ) {
			$this->assertStringNotContainsString( 'oblio-fgwoo-field-warning', $desc );
		}
	}

	public function test_the_implicit_gestiune_warns_only_when_several_are_summed(): void {
		list( , $single ) = $this->gestiune_descriptions( array( 'Sediu|Central' ), '' );
		list( , $summed ) = $this->gestiune_descriptions( array(), '' );

		$this->assertStringNotContainsString( 'oblio-fgwoo-field-warning', $single );
		$this->assertStringContainsString( 'doar din gestiunea implicită din Oblio', $summed );
	}

	public function test_no_gestiune_warning_while_stock_sync_is_off(): void {
		$GLOBALS['oblio_test_options']['oblio_fgwoo_stock_sync_trigger'] = 'off';

		foreach ( $this->gestiune_descriptions( array( 'Sediu|Central', 'Sediu|Showroom' ) ) as $desc ) {
			$this->assertStringNotContainsString( 'oblio-fgwoo-field-warning', $desc );
		}
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

	public function test_the_api_key_hint_sits_under_the_secret_field(): void {
		ob_start();
		$this->page->render_secret_field( array( 'id' => 'oblio_fgwoo_secret' ) );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'Contul meu → Setări → Date cont', $html );
		$this->assertStringContainsString( 'href="https://www.oblio.eu/account/settings"', $html );

		$fields = ( new ReflectionMethod( SettingsPage::class, 'connection_fields' ) )->invoke( $this->page, static fn ( string $key ): string => 'oblio_fgwoo_' . $key );
		$this->assertArrayNotHasKey( 'desc', $fields[0] );
	}

	public function test_preia_ultimele_date_is_not_styled_as_save(): void {
		ob_start();
		$this->page->render_test_field( array() );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'id="oblio_fgwoo_test_connection"', $html );
		$this->assertStringNotContainsString( 'button-primary', $html );
	}
}
