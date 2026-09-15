<?php
/**
 * "Sync stock now" AJAX handlers (start + one step, looped by the browser).
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
		add_action( 'wp_ajax_oblio_fgwoo_stock_sync_now', array( $this, 'handle_start' ) );
		add_action( 'wp_ajax_oblio_fgwoo_stock_sync_step', array( $this, 'handle_step' ) );
		add_action( 'wp_ajax_oblio_fgwoo_stock_sync_unlock', array( $this, 'handle_unlock' ) );
	}

	public function handle_start(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		if ( ! $this->settings->stock_sync_configured() ) {
			wp_send_json_error( array( 'message' => __( 'Sincronizarea stocului este dezactivată. Activeaz-o din tabul Stoc.', 'oblio-fgwoo' ) ) );
		}

		$result = $this->coordinator->begin_full_sync();

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['reason'] ?? __( 'Sincronizarea a eșuat.', 'oblio-fgwoo' ) ) ) );
		}

		wp_send_json_success( array( 'token' => (string) $result['token'] ) );
	}

	public function handle_step(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		$token  = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$offset = isset( $_POST['offset'] ) ? absint( wp_unslash( $_POST['offset'] ) ) : 0;

		$result = $this->coordinator->step( $offset, $token );

		if ( empty( $result['ok'] ) ) {
			wp_send_json_error( array( 'message' => (string) ( $result['reason'] ?? __( 'Sincronizarea a eșuat.', 'oblio-fgwoo' ) ) ) );
		}

		$scanned = (int) ( $result['scanned'] ?? 0 );
		$updated = (int) ( $result['updated'] ?? 0 );
		$done    = ! empty( $result['done'] );

		wp_send_json_success(
			array(
				'done'       => $done,
				'nextOffset' => (int) ( $result['next_offset'] ?? 0 ),
				'scanned'    => $scanned,
				'updated'    => $updated,
				'message'    => $done
					? sprintf(
						/* translators: 1: updated product count, 2: scanned product count */
						__( 'Sincronizare completă: %1$d din %2$d produse actualizate.', 'oblio-fgwoo' ),
						$updated,
						$scanned
					)
					: sprintf(
						/* translators: 1: scanned product count so far, 2: updated product count so far */
						__( '%1$d produse verificate, %2$d actualizate…', 'oblio-fgwoo' ),
						$scanned,
						$updated
					),
			)
		);
	}

	public function handle_unlock(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		$this->coordinator->unlock();

		wp_send_json_success( array( 'message' => __( 'Blocarea a fost eliberată. Poți porni din nou sincronizarea.', 'oblio-fgwoo' ) ) );
	}
}
