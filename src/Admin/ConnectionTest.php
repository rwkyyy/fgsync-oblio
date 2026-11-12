<?php
/**
 * "Test connection" AJAX handler.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Api\OblioClient;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
final class ConnectionTest {

	public const NONCE_ACTION     = 'oblio_fgwoo_admin';
	public const COMPANIES_OPTION = 'oblio_fgwoo_companies';

	public const USE_STOCK_OPTION = 'oblio_fgwoo_companies_use_stock';

	private ClientFactory $factory;

	private NomenclatureCache $nomenclature;

	private Logger $logger;

	private Settings $settings;

	public function __construct( ClientFactory $factory, NomenclatureCache $nomenclature, Logger $logger, Settings $settings ) {
		$this->factory      = $factory;
		$this->nomenclature = $nomenclature;
		$this->logger       = $logger;
		$this->settings     = $settings;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_test_connection', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'fgsync-oblio' ) ), 403 );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$secret = isset( $_POST['secret'] ) ? trim( (string) wp_unslash( $_POST['secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque token, trimmed only.

		$saved_credentials = '' === $secret && $email === (string) $this->settings->get( 'email' );
		if ( '' === $secret ) {
			$secret = $this->factory->get_secret();
		}

		if ( '' === $email || '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'Introdu emailul și cheia API.', 'fgsync-oblio' ) ) );
		}

		try {
			$client = $this->factory->create_with( $email, $secret );
			if ( $saved_credentials ) {
				$sync   = $this->sync( $client );
				$map    = $sync['companies'];
				$loaded = $sync['loaded'];
			} else {
				// Unsaved credentials only fill the dropdown; saving them runs sync().
				$map    = self::parse( $client->test_connection() );
				$loaded = false;
			}
		} catch ( ApiException $exception ) {
			$this->logger->error( 'Connection test failed: ' . $exception->status_message() );
			wp_send_json_error( array( 'message' => $exception->status_message() ) );
		}

		$cif = (string) $this->settings->get( 'cif' );
		if ( ! $saved_credentials && ! isset( $map[ $cif ] ) ) {
			$cif = 1 === count( $map ) ? (string) array_key_first( $map ) : '';
		}

		$this->logger->info( sprintf( 'Connection test succeeded, %d compan%s found', count( $map ), 1 === count( $map ) ? 'y' : 'ies' ) );

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %d: number of companies */
					_n( 'Conectat. %d firmă găsită.', 'Conectat. %d firme găsite.', count( $map ), 'fgsync-oblio' ),
					count( $map )
				),
				'companies' => $map,
				'cif'       => $cif,
				'reload'    => $loaded && $saved_credentials,
			)
		);
	}

	/**
	 * Stores the account's companies and reloads the nomenclature with the
	 * same client, so every cached list comes from one account.
	 *
	 * @param OblioClient $client Client for the account being stored.
	 * @throws ApiException If Oblio rejects the credentials.
	 * @return array{companies: array<string,string>, loaded: bool}
	 */
	public function sync( OblioClient $client ): array {
		$companies = (array) $client->test_connection();
		$map       = self::parse( $companies );
		update_option( self::COMPANIES_OPTION, $map, false );
		NomenclatureCache::store_stock_flags( $companies );

		if ( '' === (string) $this->settings->get( 'cif' ) && 1 === count( $map ) ) {
			// The CIF hook primes the nomenclature from the now-saved credentials.
			$this->settings->set( 'cif', (string) array_key_first( $map ) );
			$loaded = $this->nomenclature->is_complete();
		} else {
			$this->nomenclature->refresh();
			$loaded = $this->nomenclature->prime( $client, $companies );
		}

		return array(
			'companies' => $map,
			'loaded'    => $loaded,
		);
	}

	/**
	 * @param array<int,array<string,mixed>> $companies Oblio company list.
	 * @return array<string,string>
	 */
	private static function parse( array $companies ): array {
		$map = array();
		foreach ( $companies as $company ) {
			if ( ! empty( $company['cif'] ) ) {
				$map[ (string) $company['cif'] ] = (string) ( $company['company'] ?? $company['cif'] );
			}
		}
		return $map;
	}
}
