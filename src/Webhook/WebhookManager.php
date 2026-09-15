<?php
/**
 * Reconciles webhook subscriptions with Oblio.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Webhook;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Queue\Scheduler;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
final class WebhookManager {

	private const IDS_OPTION = 'oblio_fgwoo_webhook_ids';

	private const RECONCILE_HOOK = 'oblio_fgwoo_reconcile_webhooks';

	private Settings $settings;

	private ClientFactory $factory;

	private Logger $logger;

	private bool $account_change_pending = false;

	public function __construct( Settings $settings, ClientFactory $factory, Logger $logger ) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->logger   = $logger;
	}

	public function register(): void {

		add_action( 'update_option_oblio_fgwoo_stock_sync_trigger', array( $this, 'reconcile' ) );
		add_action( 'add_option_oblio_fgwoo_stock_sync_trigger', array( $this, 'reconcile' ) );

		add_action( 'update_option_oblio_fgwoo_email', array( $this, 'on_account_change' ) );
		add_action( 'update_option_oblio_fgwoo_cif', array( $this, 'on_account_change' ) );

		add_action( self::RECONCILE_HOOK, array( $this, 'run_deferred_account_reconcile' ) );
	}

	public function on_account_change(): void {
		if ( ! $this->settings->stock_webhook_enabled() || $this->account_change_pending ) {
			return;
		}
		$this->account_change_pending = true;
		$this->settings->set( 'webhook_secret', wp_generate_password( 32, false ) );

		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::RECONCILE_HOOK, array(), Scheduler::GROUP );
			return;
		}
		add_action( 'shutdown', array( $this, 'run_deferred_account_reconcile' ) );
	}

	public function run_deferred_account_reconcile(): void {
		$this->factory->tokens()->clear();
		$this->reconcile();
	}

	public function reconcile(): void {
		if ( ! $this->is_configured() ) {
			return;
		}

		$this->ensure_secret();

		try {
			$client   = $this->factory->create();
			$existing = $client->list_webhooks();

			$ours = array();
			foreach ( $existing as $hook ) {
				$endpoint = (string) ( $hook['endpoint'] ?? '' );
				if ( ! $this->belongs_to_us( $endpoint ) ) {
					continue;
				}
				$ours[ (string) ( $hook['topic'] ?? '' ) ][] = array(
					'id'       => $hook['id'] ?? null,
					'endpoint' => $endpoint,
					'cif'      => (string) ( $hook['cif'] ?? '' ),
				);
			}

			if ( ! $this->settings->stock_webhook_enabled() ) {
				$removed = 0;
				foreach ( $ours as $subs ) {
					foreach ( $subs as $sub ) {
						if ( null !== $sub['id'] ) {
							$client->delete_webhook( $sub['id'] );
							++$removed;
						}
					}
				}
				delete_option( self::IDS_OPTION );
				$this->logger->info( sprintf( 'Webhooks disabled: %d subscription(s) removed', $removed ) );
				return;
			}

			$desired = $this->desired_topics();
			$cif     = (string) $this->settings->get( 'cif' );
			$secret  = (string) $this->settings->get( 'webhook_secret' );

			$ids = array();
			foreach ( $ours as $topic => $subs ) {
				foreach ( $subs as $sub ) {
					$reusable = null !== $sub['id']
						&& ! isset( $ids[ $topic ] )
						&& self::is_reusable( $topic, $desired, $this->endpoint_secret( $sub['endpoint'] ), $secret, $sub['cif'], $cif );
					if ( $reusable ) {
						$ids[ $topic ] = $sub['id'];
					} elseif ( null !== $sub['id'] ) {
						$client->delete_webhook( $sub['id'] );
						$this->logger->info( sprintf( 'Webhook removed (expired): %s', $topic ) );
					}
				}
			}

			foreach ( $desired as $topic ) {
				if ( isset( $ids[ $topic ] ) ) {
					continue;
				}
				$created       = $client->create_webhook( $cif, $topic, $this->endpoint_for( $topic ) );
				$ids[ $topic ] = $created['id'] ?? null;
				$this->logger->info( sprintf( 'Webhook registered: %s', $topic ) );
			}

			update_option( self::IDS_OPTION, $ids, false );
		} catch ( ApiException $exception ) {
			$this->logger->error( 'Webhook sync failed: ' . $exception->status_message() );
		}
	}

	public static function is_reusable( string $topic, array $desired, string $sub_secret, string $current_secret, string $sub_cif, string $current_cif ): bool {
		return in_array( $topic, $desired, true )
			&& '' !== $current_secret
			&& hash_equals( $current_secret, $sub_secret )
			&& ( '' === $sub_cif || $sub_cif === $current_cif );
	}

	private function endpoint_secret( string $endpoint ): string {
		$query = (string) wp_parse_url( $endpoint, PHP_URL_QUERY );
		if ( '' === $query ) {
			return '';
		}
		parse_str( $query, $params );
		return isset( $params['secret'] ) ? (string) $params['secret'] : '';
	}

	public function desired_topics(): array {
		$topics = array();
		if ( $this->settings->stock_webhook_enabled() ) {
			$topics[] = 'stock';
		}

		return (array) apply_filters( 'oblio_fgwoo_webhook_topics', $topics );
	}

	public function endpoint_for( string $topic ): string {
		$url = rest_url( 'oblio/v1/webhook/' . TopicRegistry::slug_for( $topic ) );
		return add_query_arg( 'secret', (string) $this->settings->get( 'webhook_secret' ), $url );
	}

	public function ensure_secret(): void {
		if ( '' === (string) $this->settings->get( 'webhook_secret' ) ) {
			$this->settings->set( 'webhook_secret', wp_generate_password( 32, false ) );
		}
	}

	private function belongs_to_us( string $endpoint ): bool {
		$base = rest_url( 'oblio/v1/webhook/' );
		return '' !== $endpoint && 0 === strpos( $endpoint, $base );
	}

	private function is_configured(): bool {
		return $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
	}
}
