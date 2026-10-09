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
			$client    = $this->factory->create_with( $email, $secret );
			$companies = $client->test_connection();
		} catch ( ApiException $exception ) {
			$this->logger->error( 'Connection test failed: ' . $exception->status_message() );
			wp_send_json_error( array( 'message' => $exception->status_message() ) );
		}

		$map       = array();
		$use_stock = array();
		foreach ( $companies as $company ) {
			if ( ! empty( $company['cif'] ) ) {
				$map[ (string) $company['cif'] ] = (string) ( $company['company'] ?? $company['cif'] );
				if ( isset( $company['useStock'] ) && is_scalar( $company['useStock'] ) ) {
					$use_stock[ (string) $company['cif'] ] = (bool) (int) $company['useStock'];
				}
			}
		}
		update_option( self::COMPANIES_OPTION, $map, false );
		update_option( self::USE_STOCK_OPTION, $use_stock, false );

		if ( '' === (string) $this->settings->get( 'cif' ) && 1 === count( $map ) ) {
			$this->settings->set( 'cif', (string) array_key_first( $map ) );
			$loaded = $this->nomenclature->is_complete();
		} else {
			$this->nomenclature->refresh();
			$loaded = $this->nomenclature->prime();
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
				'cif'       => (string) $this->settings->get( 'cif' ),
				'reload'    => $loaded && $saved_credentials,
			)
		);
	}
}
