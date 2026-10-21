<?php
/**
 * Shortcuts to the Oblio settings page from the WooCommerce menu and settings
 * screen, and from the plugin's row on the Plugins screen.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

final class SettingsShortcut {

	private const TAB_ID = 'oblio-fgwoo';

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_submenu' ), 49 );
		add_filter( 'woocommerce_settings_tabs_array', array( $this, 'add_tab' ), 60 );
		add_action( 'woocommerce_settings_' . self::TAB_ID, array( $this, 'render' ) );
		add_filter( 'plugin_action_links_' . ( defined( 'FGSYNC_OBLIO_BASENAME' ) ? FGSYNC_OBLIO_BASENAME : '' ), array( $this, 'action_links' ) );
	}

	/**
	 * @param array<int|string,string> $links Links under the plugin's name.
	 * @return array<int|string,string>
	 */
	public function action_links( array $links ): array {
		return array( 'settings' => sprintf( '<a href="%s">%s</a>', esc_url( SettingsPage::url() ), esc_html__( 'Setări', 'fgsync-oblio' ) ) ) + $links;
	}

	/**
	 * Registered at 49 so it lands right above WooCommerce's Settings (50).
	 */
	public function add_submenu(): void {
		add_submenu_page( 'woocommerce', __( 'FGSync', 'fgsync-oblio' ), __( 'FGSync', 'fgsync-oblio' ), 'manage_woocommerce', 'admin.php?page=' . SettingsPage::PAGE_SLUG );
	}

	public function add_tab( array $tabs ): array {
		$tabs[ self::TAB_ID ] = __( 'Oblio', 'fgsync-oblio' );
		return $tabs;
	}

	public function render(): void {
		echo '<p>' . esc_html__( 'Setările FGSync pentru Oblio sunt pe pagina dedicată.', 'fgsync-oblio' ) . '</p>';
		printf(
			'<p><a href="%s" class="button button-primary">%s</a></p>',
			esc_url( SettingsPage::url() ),
			esc_html__( 'Deschide setările Oblio', 'fgsync-oblio' )
		);
	}
}
