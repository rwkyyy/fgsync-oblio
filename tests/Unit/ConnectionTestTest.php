<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\ConnectionTest;
use FGSyncOblio\Admin\NomenclatureCache;
use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Encryption;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\RateLimiter;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( ConnectionTest::class )]
final class ConnectionTestTest extends TestCase {

	private Settings $settings;

	private ClientFactory $factory;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->factory  = new ClientFactory( $this->settings, new Encryption(), new Logger(), new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		$this->settings->set( 'email', 'shop@example.test' );
		$this->factory->set_secret( 'token' );
		$_POST = array(
			'email'  => 'shop@example.test',
			'secret' => '',
		);
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function connection(): ConnectionTest {
		$logger = new Logger();
		return new ConnectionTest( $this->factory, new NomenclatureCache( $this->factory, $this->settings, $logger, new Scheduler() ), $logger, $this->settings );
	}

	private function handle(): void {
		$this->connection()->handle();
	}

	/**
	 * @return array<int,string> client_id of every token request.
	 */
	private function authorized_emails(): array {
		$emails = array();
		foreach ( $GLOBALS['oblio_test_http_calls'] as $call ) {
			if ( str_contains( (string) $call['url'], '/api/authorize/token' ) ) {
				$emails[] = (string) $call['args']['body']['client_id'];
			}
		}
		return $emails;
	}

	/**
	 * @param array<int|string,mixed> $data
	 */
	private function respond( array $data ): void {
		$GLOBALS['oblio_test_http_responses'][] = array(
			'response' => array( 'code' => 200 ),
			'body'     => wp_json_encode( $data ),
		);
	}

	public function test_saved_credentials_store_each_companys_stock_flag_and_select_a_single_company(): void {
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond(
			array(
				'data' => array(
					array( 'cif' => 'RO123', 'company' => 'Digital SRL', 'useStock' => '0' ),
				),
			)
		);

		$this->handle();

		$this->assertTrue( $GLOBALS['oblio_test_json_response']['success'] );
		$this->assertSame( array( 'RO123' => false ), get_option( ConnectionTest::USE_STOCK_OPTION ) );
		$this->assertSame( 'RO123', $this->settings->get( 'cif' ) );
		$this->assertFalse( NomenclatureCache::uses_stock() );
	}

	public function test_a_missing_stock_flag_stays_unknown(): void {
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( array( 'cif' => 'RO123', 'company' => 'Shop SRL' ) ) ) );

		$this->handle();

		$this->assertSame( array(), get_option( ConnectionTest::USE_STOCK_OPTION ) );
		$this->assertNull( NomenclatureCache::uses_stock() );
	}

	/**
	 * Testing credentials that aren't saved must not replace the live
	 * account's companies or stock flags.
	 */
	public function test_unsaved_credentials_only_list_the_companies(): void {
		$this->settings->set( 'cif', 'RO999' );
		update_option( ConnectionTest::COMPANIES_OPTION, array( 'RO999' => 'Old SRL' ) );
		update_option( ConnectionTest::USE_STOCK_OPTION, array( 'RO999' => true ) );
		$_POST = array(
			'email'  => 'other@example.test',
			'secret' => 'other-token',
		);
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( array( 'cif' => 'RO123', 'company' => 'New SRL', 'useStock' => '0' ) ) ) );

		$this->handle();

		$response = $GLOBALS['oblio_test_json_response'];
		$this->assertTrue( $response['success'] );
		$this->assertSame( array( 'RO123' => 'New SRL' ), $response['data']['companies'] );
		$this->assertSame( 'RO123', $response['data']['cif'] );
		$this->assertFalse( $response['data']['reload'] );
		$this->assertSame( array( 'RO999' => 'Old SRL' ), get_option( ConnectionTest::COMPANIES_OPTION ) );
		$this->assertSame( array( 'RO999' => true ), get_option( ConnectionTest::USE_STOCK_OPTION ) );
		$this->assertSame( 'RO999', $this->settings->get( 'cif' ) );
	}

	/**
	 * Series, warehouses and VAT categories must come from the same account
	 * as the companies, not from whatever credentials happen to be saved.
	 */
	public function test_sync_loads_the_nomenclature_with_the_given_client(): void {
		$this->settings->set( 'cif', 'RO123' );
		$this->respond( array( 'access_token' => 'tok', 'token_type' => 'Bearer', 'expires_in' => 3600 ) );
		$this->respond( array( 'data' => array( array( 'cif' => 'RO123', 'company' => 'New SRL', 'useStock' => '1' ) ) ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array() ) );
		$this->respond( array( 'data' => array() ) );

		$result = $this->connection()->sync( $this->factory->create_with( 'other@example.test', 'other-token' ) );

		$this->assertTrue( $result['loaded'] );
		$this->assertSame( array( 'other@example.test' ), $this->authorized_emails() );
		$this->assertSame( array( 'RO123' => 'New SRL' ), get_option( ConnectionTest::COMPANIES_OPTION ) );
	}
}
