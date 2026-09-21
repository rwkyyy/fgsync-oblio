<?php
/**
 * Registry of supported webhook topics and their handlers.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Webhook;

final class TopicRegistry {

	public const KNOWN_TOPICS = array(
		'stock',
		'Collect/Inserted',
		'Invoice/SaveDraft',
		'Proforma/SaveDraft',
		'Notice/SaveDraft',
		'TaxReceipt/SaveDraft',
		'Invoice/Update',
		'Proforma/Update',
		'Notice/Update',
		'Invoice/Cancel',
		'Proforma/Cancel',
		'Notice/Cancel',
		'TaxReceipt/Cancel',
	);

	private array $handlers = array();

	public function register( string $topic, WebhookHandler $handler ): void {
		$this->handlers[ $topic ] = $handler;
	}

	public function dispatch( string $topic, array $data ): void {
		if ( isset( $this->handlers[ $topic ] ) ) {
			$this->handlers[ $topic ]->handle( $data );
		}

		do_action( 'oblio_fgwoo_webhook', $topic, $data );

		do_action( 'oblio_fgwoo_webhook_' . self::slug_for( $topic ), $data );
	}

	public static function slug_for( string $topic ): string {
		return strtolower( str_replace( array( '/', ' ' ), '-', $topic ) );
	}

	public static function topic_for_slug( string $slug ): string {
		foreach ( self::KNOWN_TOPICS as $topic ) {
			if ( self::slug_for( $topic ) === $slug ) {
				return $topic;
			}
		}
		return '';
	}
}
