<?php
/**
 * A shortcut to the Oblio settings page from the WooCommerce settings screen.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

final class SettingsShortcut {

	private const TAB_ID = 'oblio-fgwoo';

	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_' . self::TAB_ID, array( $this, 'render' ) );
	}

	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = __( 'Oblio', 'fgsync-oblio' );
		return $tabs;
	}

	public function render(): void {
		echo '<p>' . esc_html__( 'Setările Oblio se află pe pagina dedicată, cu design propriu.', 'fgsync-oblio' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( SettingsPage::url() ),
			esc_html__( 'Deschide setările Oblio', 'fgsync-oblio' )
		);
	}
}
