<?php
/**
 * Top admin-bar shortcut to the FGSync Status screen, with a health dot.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Settings;
use WP_Admin_Bar;
final class AdminBarStatus {

	private const DEFAULT_QUEUE_THRESHOLD = 50;

	private Settings $settings;

	private ConnectionHealth $health;

	private QueueStatus $queue;

	public function __construct( Settings $settings, ConnectionHealth $health, QueueStatus $queue ) {
		$this->settings = $settings;
		$this->health   = $health;
		$this->queue    = $queue;
	}

	public function register(): void {
		add_action( 'admin_bar_menu', array( $this, 'add_node' ), 90 );
	}

	public function add_node( WP_Admin_Bar $admin_bar ): void {
		if ( ! $this->settings->is_enabled( 'admin_bar_status' ) || ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$tone = $this->tone();

		$admin_bar->add_node(
			array(
				'id'    => 'oblio-fgwoo-status',
				'title' => '<span class="oblio-fgwoo-adminbar-dot oblio-fgwoo-adminbar-dot-' . esc_attr( $tone ) . '"></span><span class="ab-label">FGSync</span>',
				'href'  => SettingsPage::url( 'status' ),
			)
		);

		$this->print_style();
	}

	private function tone(): string {
		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			return 'muted';
		}
		if ( ! $this->health->is_healthy() ) {
			return 'red';
		}

		$threshold = (int) apply_filters( 'oblio_fgwoo_admin_bar_queue_threshold', self::DEFAULT_QUEUE_THRESHOLD );
		$totals    = $this->queue->admin_bar_totals();
		$busy      = (int) ( $totals['pending'] ?? 0 ) + (int) ( $totals['failed'] ?? 0 );

		return $busy > $threshold ? 'yellow' : 'green';
	}

	private function print_style(): void {
		static $printed = false;
		if ( $printed ) {
			return;
		}
		$printed = true;

		echo '<style>
			#wpadminbar .oblio-fgwoo-adminbar-dot { display: inline-block; width: 8px; height: 8px; border-radius: 50%; margin-right: 6px; vertical-align: middle; }
			#wpadminbar .oblio-fgwoo-adminbar-dot-green { background: #157347; }
			#wpadminbar .oblio-fgwoo-adminbar-dot-yellow { background: #b5560f; }
			#wpadminbar .oblio-fgwoo-adminbar-dot-red { background: #c62d1c; }
			#wpadminbar .oblio-fgwoo-adminbar-dot-muted { background: #6b6577; }
		</style>';
	}
}
