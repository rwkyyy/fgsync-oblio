<?php
/**
 * Contract for a webhook topic handler.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Webhook;

interface WebhookHandler {

	public function handle( array $data ): void;
}
