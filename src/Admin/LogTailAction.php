<?php
/**
 * "Log tail" AJAX handler, polled by the Status panel's auto-update toggle.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

final class LogTailAction {

	private LogReader $log;

	public function __construct( LogReader $log ) {
		$this->log = $log;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_log_tail', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		wp_send_json_success( array( 'html' => LogRenderer::rows( $this->log->today() ) ) );
	}
}
