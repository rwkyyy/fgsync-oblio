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

	public function test_settings_link_comes_first(): void {
		$links = ( new SettingsShortcut() )->action_links( array( 'deactivate' => '<a href="#">Dezactivează</a>' ) );

		$this->assertSame( array( 'settings', 'deactivate' ), array_keys( $links ) );
		$this->assertStringContainsString( 'page=fgsync-oblio', $links['settings'] );
		$this->assertStringContainsString( '>Setări</a>', $links['settings'] );
	}
}
