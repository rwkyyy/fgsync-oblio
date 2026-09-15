<?php
/**
 * REST controller for incoming Oblio webhooks.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Webhook;

use OblioWoo\Queue\Scheduler;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
use WP_REST_Request;
use WP_REST_Server;
final class RestController {

	private Settings $settings;

	private Scheduler $scheduler;

	private Logger $logger;

	public function __construct( Settings $settings, Scheduler $scheduler, Logger $logger ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->logger    = $logger;
	}

	public function register(): void {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			'oblio/v1',
			'/webhook/(?P<topic>[a-zA-Z0-9-]+)',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handle' ),
				'permission_callback' => array( $this, 'verify' ),
				'args'                => array(
					'topic' => array( 'sanitize_callback' => 'sanitize_key' ),
				),
			)
		);
	}

	public function verify( WP_REST_Request $request ): bool {
		if ( ! $this->settings->stock_webhook_enabled() ) {
			$this->logger->warning( sprintf( 'Webhook rejected: stock webhooks disabled (topic: %s)', (string) $request->get_param( 'topic' ) ) );
			return false;
		}
		$secret   = (string) $this->settings->get( 'webhook_secret' );
		$provided = (string) $request->get_param( 'secret' );
		$valid    = '' !== $secret && hash_equals( $secret, $provided );
		if ( ! $valid ) {
			$this->logger->warning( sprintf( 'Webhook rejected: invalid secret (topic: %s)', (string) $request->get_param( 'topic' ) ) );
		}
		return $valid;
	}

	public function extract_event( WP_REST_Request $request ): array {
		$topic  = TopicRegistry::topic_for_slug( (string) $request->get_param( 'topic' ) );
		$params = $request->get_json_params();
		$params = is_array( $params ) ? $params : array();
		$data   = isset( $params['data'] ) && is_array( $params['data'] ) ? $params['data'] : $params;

		return array(
			'topic'      => $topic,
			'request_id' => (string) $request->get_header( 'x_oblio_request_id' ),
			'data'       => $data,
		);
	}

	public function handle( WP_REST_Request $request ): void {
		$event = $this->extract_event( $request );

		if ( '' === $event['topic'] ) {
			$this->logger->warning( sprintf( 'Webhook received: unknown topic slug "%s"', (string) $request->get_param( 'topic' ) ) );
		} elseif ( $this->is_replay( $event['request_id'] ) ) {
			$this->logger->debug( sprintf( 'Webhook received: %s, ignored (replay of request %s)', $event['topic'], $event['request_id'] ) );
		} else {
			$this->scheduler->enqueue_webhook( $event['topic'], $event['data'] );
			$this->logger->info( sprintf( 'Webhook received: %s, queued for processing', $event['topic'] ) );
		}

		$this->send_ack( base64_encode( $event['request_id'] ) );
	}

	private function is_replay( string $request_id ): bool {
		if ( '' === $request_id ) {
			return false;
		}
		$key = 'oblio_fgwoo_wh_' . md5( $request_id );
		if ( false !== get_transient( $key ) ) {
			return true;
		}
		set_transient( $key, 1, HOUR_IN_SECONDS );
		return false;
	}

	private function send_ack( string $body ): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- base64 ack, plain text.
		exit;
	}
}
