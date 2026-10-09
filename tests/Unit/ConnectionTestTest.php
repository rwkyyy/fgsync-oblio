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

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$_POST          = array(
			'email'  => 'shop@example.test',
			'secret' => 'token',
		);
	}

	protected function tearDown(): void {
		$_POST = array();
	}

	private function handle(): void {
		$logger  = new Logger();
		$factory = new ClientFactory( $this->settings, new Encryption(), $logger, new ConnectionHealth(), new RateLimiter( new InMemorySlotStore() ) );
		( new ConnectionTest( $factory, new NomenclatureCache( $factory, $this->settings, $logger, new Scheduler() ), $logger, $this->settings ) )->handle();
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

	public function test_saves_each_companys_stock_flag_and_selects_a_single_company(): void {
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
}
