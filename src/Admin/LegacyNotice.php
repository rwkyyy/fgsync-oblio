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
			__( '<strong>ATENȚIE</strong> modulul „WooCommerce Oblio” vechi este activ! Continuarea poate genera facturi dublate! Vă recomandăm să dezactivați modulul vechi din secțiunea <a href="%s">plugin-uri</a>.', 'oblio-fgwoo' ),
			esc_url( admin_url( 'plugins.php' ) )
		);

		printf( '<div class="notice notice-warning"><p>%s</p></div>', wp_kses_post( $message ) );
	}
}
