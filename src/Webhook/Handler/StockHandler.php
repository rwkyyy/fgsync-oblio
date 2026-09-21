<?php
/**
 * Webhook handler: Oblio stock changed → trigger a (debounced) sync.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Webhook\Handler;

use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Webhook\WebhookHandler;
final class StockHandler implements WebhookHandler {

	private StockSyncCoordinator $coordinator;

	public function __construct( StockSyncCoordinator $coordinator ) {
		$this->coordinator = $coordinator;
	}

	public function handle( array $data ): void {
		unset( $data );

		$this->coordinator->request_webhook_sync();
	}
}
