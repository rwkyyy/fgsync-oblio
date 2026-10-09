<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Document\DocumentException;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( VatCategories::class )]
final class VatCategoriesTest extends TestCase {

	private Settings $settings;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email', 'shop@example.test' );
		$this->settings->set( 'secret', 'token' );
		$this->settings->set( 'cif', 'RO123' );
	}

	private function categories(): VatCategories {
		$factory = new ClientFactory( $this->settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		return new VatCategories( $factory, $this->settings, new Logger() );
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 */
	private function queue_vat_rates( array $rows ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) ),
		);
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( array( 'data' => $rows ) ),
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function line( $percent, string $name = '' ): array {
		return array(
			'name'          => 'Produs',
			'vatName'       => $name,
			'vatPercentage' => $percent,
			'vatIncluded'   => true,
		);
	}

	public function test_fetches_and_caches_the_accounts_categories(): void {
		$this->queue_vat_rates( array( array( 'name' => 'Finlanda', 'percent' => '25.5', 'default' => false ), array( 'name' => 'Fara procent' ), 'garbage' ) );

		$lines = $this->categories()->apply( array( $this->line( 25.5 ) ) );

		$this->assertSame( array( 'Finlanda', 25.5 ), array( $lines[0]['vatName'], $lines[0]['vatPercentage'] ) );
		$this->assertCount( 1, $GLOBALS['oblio_test_transients'][ VatCategories::TRANSIENT ] );
	}

	public function test_the_default_category_wins_between_equal_rates(): void {
		oblio_test_seed_vat_categories();
		$GLOBALS['oblio_test_transients'][ VatCategories::TRANSIENT ][] = array( 'name' => 'Alta 21', 'percent' => 21, 'default' => false );

		$lines = $this->categories()->apply( array( $this->line( 21 ) ) );

		$this->assertSame( 'Normala', $lines[0]['vatName'] );
	}

	public function test_a_category_added_in_oblio_after_caching_is_found_by_refetching(): void {
		oblio_test_seed_vat_categories();
		$this->queue_vat_rates( array( array( 'name' => 'Austria', 'percent' => 20, 'default' => false ) ) );

		$lines = $this->categories()->apply( array( $this->line( 20, '' ) + array( VatCategories::TOLERANCE_FIELD => 0.01 ) ) );

		$this->assertSame( 'Austria', $lines[0]['vatName'] );
		$this->assertArrayNotHasKey( VatCategories::TOLERANCE_FIELD, $lines[0] );
	}

	public function test_a_rate_missing_after_refetch_fails_with_a_clear_message(): void {
		oblio_test_seed_vat_categories();
		$this->queue_vat_rates( array( array( 'name' => 'Normala', 'percent' => 21, 'default' => true ) ) );

		$this->expectException( DocumentException::class );
		$this->expectExceptionMessage( 'Contul Oblio nu are o cotă TVA de 20% (linia „Produs”). Adaug-o în Oblio → Setări → Cote TVA cu numele „20”' );

		$this->categories()->apply( array( $this->line( 20 ) ) );
	}

	public function test_named_lines_lines_without_a_rate_and_discount_lines_are_left_alone(): void {
		oblio_test_seed_vat_categories();
		$lines = array(
			$this->line( 0, 'SDD' ),
			$this->line( null ),
			array(
				'name'     => 'Discount',
				'discount' => 5.0,
			),
		);

		$this->assertSame( $lines, $this->categories()->apply( $lines ) );
	}

	public function test_an_empty_category_list_keeps_lines_as_they_are(): void {
		$this->queue_vat_rates( array() );

		$lines = $this->categories()->apply( array( $this->line( 21 ) + array( VatCategories::TOLERANCE_FIELD => 0.01 ) ) );

		$this->assertSame( array( '', 21 ), array( $lines[0]['vatName'], $lines[0]['vatPercentage'] ) );
		$this->assertArrayNotHasKey( VatCategories::TOLERANCE_FIELD, $lines[0] );
	}

	public function test_a_line_outside_its_tolerance_does_not_match(): void {
		oblio_test_seed_vat_categories();
		$this->queue_vat_rates( array( array( 'name' => 'Redusa ', 'percent' => 5, 'default' => false ) ) );

		$this->expectException( DocumentException::class );

		$this->categories()->apply( array( $this->line( 5.5 ) + array( VatCategories::TOLERANCE_FIELD => 0.01 ) ) );
	}

	public function test_a_configured_name_is_sent_with_the_accounts_exact_spelling(): void {
		$GLOBALS['oblio_test_transients'][ VatCategories::TRANSIENT ] = array(
			array( 'name' => 'Taxare inversa', 'percent' => 21, 'default' => false ),
			array( 'name' => 'Taxare inversa ', 'percent' => 0, 'default' => false ),
		);

		$lines = $this->categories()->apply( array( $this->line( 0, 'Taxare inversa' ) ) );

		$this->assertSame( 'Taxare inversa ', $lines[0]['vatName'] );
	}

	public function test_a_configured_name_missing_from_the_account_points_to_the_setting(): void {
		oblio_test_seed_vat_categories();
		$this->queue_vat_rates( array( array( 'name' => 'Normala', 'percent' => 21, 'default' => true ) ) );

		$this->expectException( DocumentException::class );
		$this->expectExceptionMessage( 'Categoria TVA „TVA Inclus” (0%) nu există în contul Oblio. Alege alta în FGSync → Setări → TVA' );

		$this->categories()->apply( array( $this->line( 0, 'TVA Inclus' ) ) );
	}

	public function test_untaxed_options_list_only_zero_rate_categories_without_padding(): void {
		$options = VatCategories::untaxed_options(
			array(
				array( 'name' => 'Normala', 'percent' => 21, 'default' => true ),
				array( 'name' => 'SDD', 'percent' => 0, 'default' => false ),
				array( 'name' => 'Taxare inversa ', 'percent' => 0, 'default' => false ),
				'garbage',
			)
		);

		$this->assertSame( array( 'SDD' => 'SDD (0%)', 'Taxare inversa' => 'Taxare inversa (0%)' ), $options );
	}

	public function test_missing_lists_taxed_rates_without_a_category_grouped_by_rate(): void {
		$rows = array(
			array( 'id' => 1, 'country' => 'RO', 'state' => '', 'rate' => 21.0 ),
			array( 'id' => 2, 'country' => 'FR', 'state' => '', 'rate' => 20.0 ),
			array( 'id' => 3, 'country' => 'HU', 'state' => '', 'rate' => 27.0 ),
			array( 'id' => 4, 'country' => 'AT', 'state' => '', 'rate' => 20.0 ),
			array( 'id' => 5, 'country' => 'FR', 'state' => 'XX', 'rate' => 20.0 ),
			array( 'id' => 6, 'country' => 'FI', 'state' => '', 'rate' => 25.5 ),
			array( 'id' => 7, 'country' => 'BE', 'state' => '', 'rate' => 5.5 ),
			array( 'id' => 8, 'country' => '', 'state' => '', 'rate' => 0.0 ),
		);

		$missing = VatCategories::missing( $this->seeded(), $rows );

		$this->assertSame(
			array(
				'5.5' => array( 'BE' ),
				'20'  => array( 'FR', 'AT' ),
				'27'  => array( 'HU' ),
			),
			$missing
		);
	}

	public function test_missing_reports_nothing_while_the_categories_are_unknown(): void {
		$rows = array( array( 'id' => 1, 'country' => 'FR', 'state' => '', 'rate' => 20.0 ) );

		$this->assertSame( array(), VatCategories::missing( array(), $rows ) );
	}

	public function test_rate_label_is_the_plain_number_oblio_recommends_as_category_name(): void {
		$this->assertSame( array( '25.5', '21', '5.5', '0' ), array_map( array( VatCategories::class, 'rate_label' ), array( 25.5, 21.0, 5.50, 0.0 ) ) );
	}

	/**
	 * @return array<int|string,mixed>
	 */
	private function seeded(): array {
		oblio_test_seed_vat_categories();
		return $GLOBALS['oblio_test_transients'][ VatCategories::TRANSIENT ];
	}
}
