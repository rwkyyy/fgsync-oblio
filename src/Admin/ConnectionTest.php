<?php
/**
 * "Test connection" AJAX handler.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Support\Logger;
final class ConnectionTest {

	public const NONCE_ACTION     = 'oblio_fgwoo_admin';
	public const COMPANIES_OPTION = 'oblio_fgwoo_companies';

	private ClientFactory $factory;

	private NomenclatureCache $nomenclature;

	private Logger $logger;

	public function __construct( ClientFactory $factory, NomenclatureCache $nomenclature, Logger $logger ) {
		$this->factory      = $factory;
		$this->nomenclature = $nomenclature;
		$this->logger       = $logger;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_test_connection', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( self::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		$email  = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$secret = isset( $_POST['secret'] ) ? trim( (string) wp_unslash( $_POST['secret'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque token, trimmed only.

		if ( '' === $secret ) {
			$secret = $this->factory->get_secret();
		}

		if ( '' === $email || '' === $secret ) {
			wp_send_json_error( array( 'message' => __( 'Introdu emailul și cheia API.', 'oblio-fgwoo' ) ) );
		}

		try {
			$client    = $this->factory->create_with( $email, $secret );
			$companies = $client->test_connection();
		} catch ( ApiException $exception ) {
			$this->logger->error( 'Connection test failed: ' . $exception->status_message() );
			wp_send_json_error( array( 'message' => $exception->status_message() ) );
		}

		$map = array();
		foreach ( $companies as $company ) {
			if ( ! empty( $company['cif'] ) ) {
				$map[ (string) $company['cif'] ] = (string) ( $company['company'] ?? $company['cif'] );
			}
		}
		update_option( self::COMPANIES_OPTION, $map, false );

		$this->nomenclature->refresh();
		$this->nomenclature->prime();

		$this->logger->info( sprintf( 'Connection test succeeded, %d compan%s found', count( $map ), 1 === count( $map ) ? 'y' : 'ies' ) );

		wp_send_json_success(
			array(
				'message'   => sprintf(
					/* translators: %d: number of companies */
					_n( 'Conectat. %d firmă găsită.', 'Conectat. %d firme găsite.', count( $map ), 'oblio-fgwoo' ),
					count( $map )
				),
				'companies' => $map,
			)
		);
	}
}
