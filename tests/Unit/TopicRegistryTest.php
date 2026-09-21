<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Webhook\TopicRegistry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Webhook\TopicRegistry::class )]
final class TopicRegistryTest extends TestCase {

	public function test_slug_for_topic(): void {
		$this->assertSame( 'collect-inserted', TopicRegistry::slug_for( 'Collect/Inserted' ) );
		$this->assertSame( 'stock', TopicRegistry::slug_for( 'stock' ) );
		$this->assertSame( 'invoice-cancel', TopicRegistry::slug_for( 'Invoice/Cancel' ) );
	}

	public function test_topic_for_slug_round_trip(): void {
		$this->assertSame( 'Collect/Inserted', TopicRegistry::topic_for_slug( 'collect-inserted' ) );
		$this->assertSame( 'Invoice/Cancel', TopicRegistry::topic_for_slug( 'invoice-cancel' ) );
	}

	public function test_topic_for_unknown_slug_is_empty(): void {
		$this->assertSame( '', TopicRegistry::topic_for_slug( 'not-a-topic' ) );
	}

	public function test_dispatch_calls_registered_handler(): void {
		$handler = new class() implements \FGSyncOblio\Webhook\WebhookHandler {
			public array $received = array();
			public function handle( array $data ): void {
				$this->received = $data;
			}
		};

		$registry = new TopicRegistry();
		$registry->register( 'stock', $handler );
		$registry->dispatch( 'stock', array( 'foo' => 'bar' ) );

		$this->assertSame( array( 'foo' => 'bar' ), $handler->received );
	}
}
