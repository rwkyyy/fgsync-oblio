<?php
/**
 * A shortcut to the Oblio settings page from the WooCommerce settings screen.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

final class SettingsShortcut {

	private const TAB_ID = 'oblio';

	public function register(): void {
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_' . self::TAB_ID, array( $this, 'render' ) );
	}

	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = __( 'Oblio', 'oblio-fgwoo' );
		return $tabs;
	}

	public function render(): void {
		echo '<p>' . esc_html__( 'Setările Oblio se află pe pagina dedicată, cu design propriu.', 'oblio-fgwoo' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( SettingsPage::url() ),
			esc_html__( 'Deschide setările Oblio', 'oblio-fgwoo' )
		);
	}
}
