<?php
/**
 * Plugin Name:       FGSync for Oblio
 * Plugin URI:        https://uprise.ro/dezvoltari/dezvoltari-wp-woo/
 * Description:       Invoicing and stock management for WooCommerce through Oblio. Automatic invoices, proformas, delivery notes and credit notes in Oblio, with queued processing and warehouse-based stock sync. Native WooCommerce integration (HPOS + classic orders). Independent integration, not developed or endorsed by Oblio.eu.
 * Version:           1.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Eduard Doloc
 * Author URI:        https://profiles.wordpress.org/rwky/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       fgsync-oblio
 * Domain Path:       /languages
 *
 * @package FGSyncOblio
 */

defined( 'ABSPATH' ) || exit;

define( 'FGSYNC_OBLIO_VERSION', '1.3.0' );
define( 'FGSYNC_OBLIO_FILE', __FILE__ );
define( 'FGSYNC_OBLIO_DIR', plugin_dir_path( __FILE__ ) );
define( 'FGSYNC_OBLIO_URL', plugin_dir_url( __FILE__ ) );
define( 'FGSYNC_OBLIO_BASENAME', plugin_basename( __FILE__ ) );
define( 'FGSYNC_OBLIO_MIN_PHP', '8.1' );
define( 'FGSYNC_OBLIO_MIN_WC', '8.2' );

if ( version_compare( PHP_VERSION, FGSYNC_OBLIO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Facturare Oblio necesită PHP %1$s sau mai nou. Rulezi PHP %2$s.', 'fgsync-oblio' ),
						FGSYNC_OBLIO_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

if ( is_readable( FGSYNC_OBLIO_DIR . 'vendor/autoload.php' ) ) {
	require FGSYNC_OBLIO_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'FGSyncOblio\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'FGSyncOblio\\' ) );
		$path     = FGSYNC_OBLIO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', FGSYNC_OBLIO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', FGSYNC_OBLIO_FILE, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		\FGSyncOblio\Plugin::instance()->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		\FGSyncOblio\Plugin::instance()->deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, FGSYNC_OBLIO_MIN_WC, '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: required WooCommerce version */
								__( 'Facturare Oblio necesită WooCommerce %s sau mai nou să fie activ.', 'fgsync-oblio' ),
								FGSYNC_OBLIO_MIN_WC
							)
						)
					);
				}
			);
			return;
		}

		\FGSyncOblio\Plugin::instance()->boot();
	},
	11
);
