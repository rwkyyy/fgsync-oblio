<?php
/**
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Tests\Unit;

use OblioWoo\Webhook\WebhookManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \OblioWoo\Webhook\WebhookManager::class )]
final class WebhookManagerTest extends TestCase {

	private const DESIRED = array( 'stock', 'Collect/Inserted' );

	public function test_reuses_matching_subscription(): void {
		$this->assertTrue(
			WebhookManager::is_reusable( 'stock', self::DESIRED, 'abc', 'abc', 'RO123', 'RO123' )
		);
	}

	public function test_reuses_when_response_omits_cif(): void {
		$this->assertTrue(
			WebhookManager::is_reusable( 'stock', self::DESIRED, 'abc', 'abc', '', 'RO123' )
		);
	}

	public function test_rejects_stale_secret_after_rotation(): void {
		$this->assertFalse(
			WebhookManager::is_reusable( 'stock', self::DESIRED, 'old-secret', 'new-secret', 'RO123', 'RO123' )
		);
	}

	public function test_rejects_different_company(): void {
		$this->assertFalse(
			WebhookManager::is_reusable( 'stock', self::DESIRED, 'abc', 'abc', 'RO999', 'RO123' )
		);
	}

	public function test_rejects_unwanted_topic(): void {
		$this->assertFalse(
			WebhookManager::is_reusable( 'Invoice/Cancel', self::DESIRED, 'abc', 'abc', 'RO123', 'RO123' )
		);
	}

	public function test_rejects_when_current_secret_is_empty(): void {
		$this->assertFalse(
			WebhookManager::is_reusable( 'stock', self::DESIRED, '', '', 'RO123', 'RO123' )
		);
	}
}
