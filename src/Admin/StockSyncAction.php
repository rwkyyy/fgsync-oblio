<?php
/**
 * "Sync stock now" AJAX handler.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Stock\StockSyncCoordinator;
use OblioWoo\Support\Settings;
final class StockSyncAction {

	private StockSyncCoordinator $coordinator;

	private Settings $settings;

	public function __construct( StockSyncCoordinator $coordinator, Settings $settings ) {
		$this->coordinator = $coordinator;
		$this->settings    = $settings;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_stock_sync_now', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'facturare-gestiune-oblio-woocommerce' ) ), 403 );
		}

		if ( ! $this->settings->stock_sync_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Sincronizarea stocului este dezactivată. Activeaz-o din tabul Stoc.', 'facturare-gestiune-oblio-woocommerce' ) ) );
		}

		$started = $this->coordinator->start( true );

		if ( $started ) {
			wp_send_json_success( array( 'message' => __( 'Sincronizarea a pornit. Vezi progresul în coadă.', 'facturare-gestiune-oblio-woocommerce' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Nu s-a putut porni: verifică emailul, secretul și firma.', 'facturare-gestiune-oblio-woocommerce' ) ) );
	}
}
