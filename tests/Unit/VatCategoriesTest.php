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
		$this->expectExceptionMessage( 'Contul Oblio nu are o cotă TVA de 20% (linia „Produs”)' );

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
}
