<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\LogReader;
use FGSyncOblio\Support\Logger;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Admin\LogReader::class )]
final class LogReaderTest extends TestCase {

	/** @var array<int,string> */
	private array $files = array();

	protected function setUp(): void {
		oblio_test_reset();
		if ( ! defined( 'WC_LOG_DIR' ) ) {
			define( 'WC_LOG_DIR', sys_get_temp_dir() . '/fgsync-oblio-logs-' . getmypid() . '/' );
		}
		if ( ! is_dir( WC_LOG_DIR ) ) {
			mkdir( WC_LOG_DIR, 0777, true );
		}
	}

	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			unlink( $file );
		}
	}

	private function write_log( string $source, string $message ): void {
		$file          = WC_LOG_DIR . $source . '-' . gmdate( 'Y-m-d' ) . '-abc123.log';
		$this->files[] = $file;
		file_put_contents( $file, gmdate( 'c' ) . ' INFO ' . $message . ' {"source":"' . $source . '"}' . "\n" );
	}

	public function test_today_reads_only_this_sites_log(): void {
		$GLOBALS['oblio_test_options']['home'] = 'https://shop.example.com';
		$this->write_log( Logger::source(), 'Order #1: invoice FV 1 issued' );
		$this->write_log( 'oblio-fgwoo', 'Order #2: invoice FV 2 issued' );
		$this->write_log( 'fgsync-oblio-other-shop-ro', 'Order #3: invoice FV 3 issued' );

		$entries = ( new LogReader() )->today();

		$this->assertSame( array( 'Order #1: invoice FV 1 issued' ), array_column( $entries, 'message' ) );
	}
}
