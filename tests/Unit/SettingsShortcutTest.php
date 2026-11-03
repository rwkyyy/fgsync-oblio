<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\SettingsShortcut;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( SettingsShortcut::class )]
final class SettingsShortcutTest extends TestCase {

	protected function setUp(): void {
		oblio_test_reset();
	}

	public function test_hooks_the_plugin_row_of_this_plugin(): void {
		( new SettingsShortcut() )->register();

		$this->assertContains( 'plugin_action_links_fgsync-oblio/fgsync-oblio.php', array_column( $GLOBALS['oblio_test_add_action_calls'], 'hook' ) );
	}

	public function test_woocommerce_menu_shortcut_sits_right_before_settings(): void {
		$shortcut = new SettingsShortcut();
		$shortcut->register();

		$menu_hooks = array_values( array_filter( $GLOBALS['oblio_test_add_action_calls'], static fn ( array $call ): bool => 'admin_menu' === $call['hook'] ) );
		$this->assertSame( 49, $menu_hooks[0]['priority'] );

		$shortcut->add_submenu();

		$this->assertSame( 'woocommerce', $GLOBALS['oblio_test_submenu_pages'][0]['parent'] );
		$this->assertSame( 'manage_woocommerce', $GLOBALS['oblio_test_submenu_pages'][0]['capability'] );
		$this->assertSame( 'admin.php?page=fgsync-oblio', $GLOBALS['oblio_test_submenu_pages'][0]['slug'] );
	}

	public function test_the_woocommerce_settings_tab_uses_the_plugin_slug(): void {
		$shortcut = new SettingsShortcut();
		$shortcut->register();

		$this->assertSame( array( 'general', 'fgsync-oblio' ), array_keys( $shortcut->add_tab( array( 'general' => 'General' ) ) ) );
		$this->assertContains( 'woocommerce_settings_fgsync-oblio', array_column( $GLOBALS['oblio_test_add_action_calls'], 'hook' ) );
	}

	public function test_settings_link_comes_first(): void {
		$links = ( new SettingsShortcut() )->action_links( array( 'deactivate' => '<a href="#">Dezactivează</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'page=fgsync-oblio', $links['settings'] );
		$this->assertStringContainsString( '>Setări</a>', $links['settings'] );
	}
}
