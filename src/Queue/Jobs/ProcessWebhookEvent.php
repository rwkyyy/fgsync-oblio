<?php
/**
 * Action Scheduler job: process a received webhook event.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Queue\Jobs;

use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Webhook\TopicRegistry;
use Throwable;
final class ProcessWebhookEvent {

	private TopicRegistry $registry;

	private Logger $logger;

	public function __construct( TopicRegistry $registry, Logger $logger ) {
		$this->registry = $registry;
		$this->logger   = $logger;
	}

	public function register(): void {
		add_action( Scheduler::HOOK_WEBHOOK, array( $this, 'run' ) );
	}

	public function run( $payload ): void {
		$payload = is_array( $payload ) ? $payload : array();
		$topic   = (string) ( $payload['topic'] ?? '' );
		$data    = (array) ( $payload['data'] ?? array() );

		if ( '' === $topic ) {
			return;
		}

		try {
			$this->registry->dispatch( $topic, $data );
		} catch ( Throwable $exception ) {
			$this->logger->error( sprintf( 'Webhook %s: handler error: %s', $topic, $exception->getMessage() ) );
		}
	}
}
