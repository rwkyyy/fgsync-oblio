<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Stock\StockShortage;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( StockShortage::class )]
final class StockShortageTest extends TestCase {

	private const REFUSAL = 'Nu am putut descarca gestiunea pentru acest document.';

	private StockShortage $shortage;

	protected function setUp(): void {
		oblio_test_reset();

		$settings = new Settings();
		$settings->set( 'email', 'shop@example.test' );
		$settings->set( 'secret', 'token' );
		$client = ( new ClientFactory( $settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) ) )->create();

		$this->shortage = new StockShortage( $client, 'RO123' );
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
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
	 * @param array<string,float> $stock Quantity per gestiune under Sediu.
	 */
	private function respond_stock( string $code, array $stock, string $type = 'Marfa' ): void {
		$entries = array();
		foreach ( $stock as $management => $quantity ) {
			$entries[] = array(
				'workStation' => 'Sediu',
				'management'  => $management,
				'quantity'    => $quantity,
			);
		}
		$this->respond(
			array(
				'data' => array(
					array(
						'code'        => $code,
						'productType' => $type,
						'stock'       => $entries,
					),
				),
			)
		);
	}

	/**
	 * @return array<string,mixed>
	 */
	private function line( string $code, float $quantity, string $name = 'Produs', string $management = 'Central' ): array {
		return array(
			'name'        => $name,
			'code'        => $code,
			'quantity'    => $quantity,
			'productType' => 'Marfa',
			'management'  => $management,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $lines
	 */
	private function describe( array $lines, string $message = self::REFUSAL ): string {
		return $this->shortage->describe(
			array(
				'workStation' => 'Sediu',
				'products'    => $lines,
			),
			$message
		);
	}

	/**
	 * @return array<int,string>
	 */
	private function looked_up_urls(): array {
		return array_values(
			array_filter(
				array_column( $GLOBALS['oblio_test_http_calls'], 'url' ),
				static fn ( string $url ): bool => str_contains( $url, '/api/nomenclature/products' )
			)
		);
	}

	/**
	 * The shop sold a unit kept in Showroom while invoices take stock from Central (artkimia, 2026-11-21).
	 */
	public function test_stock_in_another_gestiune_is_shown_with_the_transfer_to_make(): void {
		$this->respond_stock( 'SACS5', array( 'Central' => 0, 'Showroom' => 1 ) );

		$this->assertSame(
			'Stoc în Oblio: SACS5 (necesar 1): Sediu / Central 0, Sediu / Showroom 1. Stocul lipsă este în altă gestiune: transferă-l în Sediu / Central din Oblio, apoi emite din nou.',
			$this->describe( array( $this->line( 'SACS5', 1 ) ) )
		);
	}

	public function test_a_product_without_stock_anywhere_gets_no_transfer_hint(): void {
		$this->respond( array( 'data' => array( array( 'code' => 'ACG15', 'productType' => 'Marfa', 'stock' => array() ) ) ) );

		$this->assertSame(
			'Stoc în Oblio: ACG15 (necesar 1): fără stoc în nicio gestiune.',
			$this->describe( array( $this->line( 'ACG15', 1 ) ) )
		);
	}

	public function test_too_little_stock_in_every_gestiune_gets_no_transfer_hint(): void {
		$this->respond_stock( 'ACS20', array( 'Central' => 1, 'Showroom' => 0.5 ) );

		$this->assertSame(
			'Stoc în Oblio: ACS20 (necesar 2): Sediu / Central 1, Sediu / Showroom 0,5.',
			$this->describe( array( $this->line( 'ACS20', 2 ) ) )
		);
	}

	public function test_only_the_short_products_are_listed(): void {
		$this->respond_stock( 'AKF0038', array( 'Central' => 36 ) );
		$this->respond_stock( 'SACS5', array( 'Central' => 0, 'Showroom' => 1 ) );

		$text = $this->describe( array( $this->line( 'AKF0038', 1 ), $this->line( 'SACS5', 1 ) ) );

		$this->assertStringStartsWith( 'Stoc în Oblio: SACS5 (necesar 1)', $text );
		$this->assertStringNotContainsString( 'AKF0038', $text );
	}

	public function test_nothing_is_added_when_every_product_has_enough_stock(): void {
		$this->respond_stock( 'AKF0038', array( 'Central' => 36 ) );

		$this->assertSame( '', $this->describe( array( $this->line( 'AKF0038', 1 ) ) ) );
	}

	public function test_a_failed_lookup_adds_nothing(): void {
		$this->respond( array( 'statusMessage' => 'Server error' ), 500 );

		$this->assertSame( '', $this->describe( array( $this->line( 'SACS5', 1 ) ) ) );
	}

	public function test_lines_with_the_same_code_add_up_and_services_and_discounts_are_not_looked_up(): void {
		$this->respond_stock( 'GEMA', array( 'Central' => 4 ) );

		$text = $this->describe(
			array(
				$this->line( 'GEMA', 3 ),
				$this->line( 'GEMA', 2 ),
				array( 'code' => 'TRANSPORT', 'quantity' => 1, 'productType' => 'Serviciu' ),
				array( 'name' => 'Discount "Gema"', 'discount' => 5, 'discountType' => 'valoric' ),
			)
		);

		$this->assertSame( 'Stoc în Oblio: GEMA (necesar 5): Sediu / Central 4.', $text );
		$this->assertCount( 1, $this->looked_up_urls() );
	}

	public function test_another_product_type_under_the_same_code_is_ignored(): void {
		$this->respond(
			array(
				'data' => array(
					array( 'code' => 'KIT', 'productType' => 'Produs finit', 'stock' => array( array( 'workStation' => 'Sediu', 'management' => 'Central', 'quantity' => 9 ) ) ),
					array( 'code' => 'KIT', 'productType' => 'Marfa', 'stock' => array( array( 'workStation' => 'Sediu', 'management' => 'Central', 'quantity' => 0 ) ) ),
				),
			)
		);

		$this->assertSame( 'Stoc în Oblio: KIT (necesar 1): Sediu / Central 0.', $this->describe( array( $this->line( 'KIT', 1 ) ) ) );
	}

	public function test_the_implicit_gestiune_is_compared_with_the_whole_stock_and_gets_no_hint(): void {
		$this->respond_stock( 'SACS5', array( 'Central' => 0, 'Showroom' => 1 ) );
		$this->respond_stock( 'ACS20', array( 'Central' => 0, 'Showroom' => 0 ) );

		$text = $this->describe( array( $this->line( 'SACS5', 1, 'Produs', '' ), $this->line( 'ACS20', 1, 'Produs', '' ) ) );

		$this->assertSame( 'Stoc în Oblio: ACS20 (necesar 1): Sediu / Central 0, Sediu / Showroom 0.', $text );
	}

	public function test_the_products_oblio_names_are_looked_up_first_and_lookups_are_capped(): void {
		$lines = array();
		for ( $index = 1; $index <= 12; $index++ ) {
			$lines[] = $this->line( 'SKU-' . $index, 1, 'Produs ' . $index . ' & co' );
			$this->respond_stock( 'SKU-' . $index, array( 'Central' => 5 ) );
		}

		$this->describe( $lines, 'Produsele urmatoare nu au stoc suficient: &lt;b&gt;Produs 12 &amp; co&lt;/b&gt;.' );

		$urls = $this->looked_up_urls();
		$this->assertCount( 10, $urls );
		$this->assertStringContainsString( 'code=SKU-12', $urls[0] );
		$this->assertStringContainsString( 'code=SKU-1&', $urls[1] . '&' );
	}

	public function test_the_text_is_escaped(): void {
		$this->respond_stock( 'A<b>', array( 'Central' => 0 ) );

		$this->assertStringContainsString( 'A&lt;b&gt; (necesar 1)', $this->describe( array( $this->line( 'A<b>', 1 ) ) ) );
	}
}
