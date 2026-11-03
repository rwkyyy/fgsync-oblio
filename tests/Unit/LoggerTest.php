<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Support\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Support\Logger::class )]
final class LoggerTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function sites(): array {
		return array(
			'www and https'  => array( 'https://www.frunzaverde.ro', 'fgsync-oblio-frunzaverde-ro' ),
			'subfolder site' => array( 'https://staging.uprise.ro/fgtest/', 'fgsync-oblio-staging-uprise-ro-fgtest' ),
			'port, no https' => array( 'http://localhost:8080', 'fgsync-oblio-localhost-8080' ),
			'no address'     => array( '', 'fgsync-oblio' ),
		);
	}

	#[DataProvider( 'sites' )]
	public function test_source_carries_the_site_address( string $home, string $expected ): void {
		$GLOBALS['oblio_test_options']['home'] = $home;

		$this->assertSame( $expected, Logger::source() );
	}

	public function test_entries_are_logged_under_the_site_source(): void {
		$GLOBALS['oblio_test_options']['home'] = 'https://shop.example.com';

		( new Logger() )->info( 'hello' );

		$this->assertSame( 'fgsync-oblio-shop-example-com', $GLOBALS['oblio_test_wc_logs'][0]['context']['source'] );
	}
}
