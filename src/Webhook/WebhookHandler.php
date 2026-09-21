<?php
/**
 * Contract for a webhook topic handler.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Webhook;

interface WebhookHandler {

	public function handle( array $data ): void;
}
