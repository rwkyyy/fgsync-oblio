<?php
/**
 * Admin notice when the legacy "WooCommerce Oblio" plugin is also active.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

final class LegacyNotice {

	public function register(): void {
		add_action( 'admin_notices', array( $this, 'render' ) );
	}

	public function render(): void {

		if ( ! defined( 'WP_OBLIO_FILE' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		$message = sprintf(
			/* translators: %s: URL of the Plugins admin page */
			__( 'Pluginul vechi „WooCommerce Oblio” este activ în același timp cu acesta. Rularea ambelor poate genera facturi duble. Dezactivează pluginul vechi din <a href="%s">Plugin-uri</a>.', 'facturare-gestiune-oblio-woocommerce' ),
			esc_url( admin_url( 'plugins.php' ) )
		);

		printf( '<div class="notice notice-warning"><p>%s</p></div>', wp_kses_post( $message ) );
	}
}
