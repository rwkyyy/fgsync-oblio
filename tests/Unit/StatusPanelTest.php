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
use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Extensibility\HookInspector;
use FGSyncOblio\Extensibility\HookRegistry;
use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Support\ConnectionHealth;
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
			new HookInspector( new HookRegistry() ),
			new ConnectionHealth(),
			new OrderStore()
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

	private const SAMPLE_TOTALS  = array(
		'pending'     => 2,
		'in-progress' => 1,
		'failed'      => 3,
	);
	private const SAMPLE_BY_HOOK = array(
		'generate_document' => array(
			'pending' => 1,
			'failed'  => 2,
		),
		'stock_sync_batch'  => array(
			'pending' => 1,
			'failed'  => 1,
		),
	);

	private function build_diagnostics_dump(): string {
		return ( new ReflectionMethod( StatusPanel::class, 'build_diagnostics_dump' ) )->invoke( $this->panel, self::SAMPLE_TOTALS, self::SAMPLE_BY_HOOK );
	}

	public function test_diagnostics_dump_lists_every_non_personal_setting_with_the_version_header(): void {
		update_option( 'oblio_fgwoo_debug_logging', 'yes' );

		$dump = $this->build_diagnostics_dump();

		$this->assertStringContainsString( 'FGSync pentru Oblio ' . FGSYNC_OBLIO_VERSION, $dump );
		$this->assertStringContainsString( '| debug_logging | yes |', $dump );
	}

	/**
	 * Pasted into a GitHub issue, this must render as a collapsed section
	 * with a table inside, not a wall of plain text.
	 */
	public function test_diagnostics_dump_is_wrapped_in_a_collapsible_markdown_details_block(): void {
		$dump = $this->build_diagnostics_dump();

		$this->assertStringStartsWith( '<details>', $dump );
		$this->assertStringContainsString( '<summary>', $dump );
		$this->assertStringEndsWith( '</details>', $dump );
		$this->assertStringContainsString( '| Cheie | Valoare |', $dump );
		$this->assertStringContainsString( '|---|---|', $dump );
	}

	/**
	 * This dump is meant to be pasted into a public support ticket - the
	 * account email, API secret and company tax ID (CIF) must never appear
	 * in it at all, not even masked.
	 */
	public function test_diagnostics_dump_omits_email_secret_and_cif_entirely(): void {
		update_option( 'oblio_fgwoo_email', 'owner@example.com' );
		update_option( 'oblio_fgwoo_secret', 'ciphertext-not-for-display' );
		update_option( 'oblio_fgwoo_cif', 'RO12345678' );

		$dump = $this->build_diagnostics_dump();

		$this->assertStringNotContainsString( 'owner@example.com', $dump );
		$this->assertStringNotContainsString( 'ciphertext-not-for-display', $dump );
		$this->assertStringNotContainsString( 'RO12345678', $dump );
		$this->assertStringNotContainsString( '| email |', $dump );
		$this->assertStringNotContainsString( '| secret |', $dump );
		$this->assertStringNotContainsString( '| cif |', $dump );
	}

	/**
	 * Named individuals and their ID documents (issuer, deputy) are personal
	 * data too, even though they're not credentials - also fully omitted.
	 */
	public function test_diagnostics_dump_omits_named_individuals_and_their_documents(): void {
		update_option( 'oblio_fgwoo_invoice_issuer_name', 'Jane Doe' );
		update_option( 'oblio_fgwoo_invoice_deputy_name', 'John Roe' );
		update_option( 'oblio_fgwoo_invoice_deputy_identity_card', 'XY 123456' );
		update_option( 'oblio_fgwoo_invoice_deputy_auto', 'B 01 ABC' );

		$dump = $this->build_diagnostics_dump();

		$this->assertStringNotContainsString( 'Jane Doe', $dump );
		$this->assertStringNotContainsString( 'John Roe', $dump );
		$this->assertStringNotContainsString( 'XY 123456', $dump );
		$this->assertStringNotContainsString( 'B 01 ABC', $dump );
	}

	/**
	 * credentiale_setate replaces the raw email/secret as the connection
	 * signal, so a support ticket can still tell "is Oblio configured at
	 * all" without the dump carrying any of the credentials themselves.
	 */
	public function test_diagnostics_dump_reports_credentials_configured_without_their_values(): void {
		update_option( 'oblio_fgwoo_email', 'owner@example.com' );
		update_option( 'oblio_fgwoo_secret', 'ciphertext' );

		$dump = $this->build_diagnostics_dump();

		$this->assertStringContainsString( '| credentiale_setate | da |', $dump );
	}

	public function test_diagnostics_dump_reports_credentials_not_configured(): void {
		$dump = $this->build_diagnostics_dump();

		$this->assertStringContainsString( '| credentiale_setate | nu |', $dump );
	}

	public function test_diagnostics_dump_includes_the_passed_in_queue_totals_and_per_hook_breakdown(): void {
		$dump = $this->build_diagnostics_dump();

		$this->assertStringContainsString( '| total | 2 în așteptare, 1 active, 3 eșuate |', $dump );
		$this->assertStringContainsString( '| generate_document | 1 în așteptare, 2 eșuate |', $dump );
		$this->assertStringContainsString( '| stock_sync_batch | 1 în așteptare, 1 eșuate |', $dump );
	}

	public function test_diagnostics_dump_includes_environment_section(): void {
		$dump = $this->build_diagnostics_dump();

		$this->assertStringContainsString( '| hpos | nu |', $dump );
		$this->assertStringContainsString( '| php_memory_limit |', $dump );
		$this->assertStringContainsString( '| php_max_execution_time |', $dump );
	}

	/**
	 * A multi-line textarea setting (e.g. the email template) would
	 * otherwise split a Markdown table row across several physical lines
	 * and break the table - newlines must become <br>.
	 */
	public function test_diagnostics_dump_converts_multiline_setting_values_to_br(): void {
		update_option( 'oblio_fgwoo_invoice_mentions', "line one\nline two" );

		$dump = $this->build_diagnostics_dump();

		$this->assertStringContainsString( '| invoice_mentions | line one<br>line two |', $dump );
	}

	private function table_cell( string $value ): string {
		return ( new ReflectionMethod( StatusPanel::class, 'table_cell' ) )->invoke( $this->panel, $value );
	}

	public function test_table_cell_escapes_pipes_and_newlines(): void {
		$this->assertSame( 'a \\| b', $this->table_cell( 'a | b' ) );
		$this->assertSame( 'line one<br>line two', $this->table_cell( "line one\nline two" ) );
		$this->assertSame( 'line one<br>line two', $this->table_cell( "line one\r\nline two" ) );
	}

	private function format_setting_value( $value ): string {
		return ( new ReflectionMethod( StatusPanel::class, 'format_setting_value' ) )->invoke( $this->panel, $value );
	}

	public function test_format_setting_value_joins_arrays_and_placeholders_empties(): void {
		$this->assertSame( 'completed, processing', $this->format_setting_value( array( 'completed', 'processing' ) ) );
		$this->assertSame( '—', $this->format_setting_value( array() ) );
		$this->assertSame( '—', $this->format_setting_value( '' ) );
		$this->assertSame( 'yes', $this->format_setting_value( 'yes' ) );
	}

	private function render_summary(): string {
		ob_start();
		( new ReflectionMethod( StatusPanel::class, 'summary' ) )->invoke(
			$this->panel,
			array(
				'pending'     => 0,
				'in-progress' => 0,
				'failed'      => 0,
			),
			0
		);
		return (string) ob_get_clean();
	}

	public function test_the_last_syncs_type_skips_are_shown(): void {
		update_option( StockSyncCoordinator::LAST_RESULT_OPTION, array( 'skipped' => 1, 'codes' => array( 'TORT-05' ) ) );

		$this->assertStringContainsString( '1 produs sărit, tipul din Oblio diferă de cel din magazin: TORT-05.', $this->render_summary() );
	}

	public function test_no_warning_when_nothing_was_skipped(): void {
		update_option( StockSyncCoordinator::LAST_RESULT_OPTION, array( 'skipped' => 0 ) );

		$this->assertStringNotContainsString( 'notice-warning', $this->render_summary() );
	}

	public function test_the_skip_warning_links_to_both_settings(): void {
		update_option( StockSyncCoordinator::LAST_RESULT_OPTION, array( 'skipped' => 1, 'codes' => array( 'TORT-05' ) ) );

		$html = $this->render_summary();

		$this->assertStringContainsString( 'section=advanced#oblio_fgwoo_product_type" class="oblio-fgwoo-tab-link" data-section="advanced">Avansat</a>', $html );
		$this->assertStringContainsString( 'section=stock#oblio_fgwoo_stock_match_product_type" class="oblio-fgwoo-tab-link" data-section="stock">„Sincronizează doar produsele cu același tip”</a>', $html );
	}

	public function test_an_unconfigured_connection_links_to_the_email_field(): void {
		$this->assertStringContainsString( 'page=fgsync-oblio#oblio_fgwoo_email" class="oblio-fgwoo-tab-link" data-section="connection">Conectare</a>', $this->render_summary() );
	}
}
