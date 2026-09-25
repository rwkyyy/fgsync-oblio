<?php
/**
 * AJAX handler for the "show in admin bar" toggle on the Status panel.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Support\Settings;
final class AdminBarToggle {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_toggle_admin_bar', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'fgsync-oblio' ) ), 403 );
		}

		$enabled = ! empty( $_POST['enabled'] ) && 'yes' === sanitize_key( wp_unslash( $_POST['enabled'] ) );
		$this->settings->set( 'admin_bar_status', $enabled ? 'yes' : 'no' );

		wp_send_json_success();
	}
}
