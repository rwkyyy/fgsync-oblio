<?php
/**
 * Action Scheduler job: process a received webhook event.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Queue\Jobs;

use OblioWoo\Queue\Scheduler;
use OblioWoo\Support\Logger;
use OblioWoo\Webhook\TopicRegistry;
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
