<?php
/**
 * Plugin Name:       Oblio Invoicing
 * Plugin URI:        https://www.oblio.eu/integrari/woocommerce
 * Description:       Automatic invoices, proformas, delivery notes and credit notes in Oblio, with queued processing and warehouse-based stock sync. Native WooCommerce integration (HPOS + classic orders).
 * Version:           1.0.2
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Requires Plugins:  woocommerce
 * Author:            Oblio
 * Author URI:        https://www.oblio.eu
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       oblio-fgwoo
 * Domain Path:       /languages
 *
 * @package OblioWoo
 */

defined( 'ABSPATH' ) || exit;

define( 'OBLIO_FGWOO_VERSION', '1.0.2' );
define( 'OBLIO_FGWOO_FILE', __FILE__ );
define( 'OBLIO_FGWOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'OBLIO_FGWOO_URL', plugin_dir_url( __FILE__ ) );
define( 'OBLIO_FGWOO_BASENAME', plugin_basename( __FILE__ ) );
define( 'OBLIO_FGWOO_MIN_PHP', '8.1' );
define( 'OBLIO_FGWOO_MIN_WC', '8.2' );

if ( version_compare( PHP_VERSION, OBLIO_FGWOO_MIN_PHP, '<' ) ) {
	add_action(
		'admin_notices',
		static function () {
			printf(
				'<div class="notice notice-error"><p>%s</p></div>',
				esc_html(
					sprintf(
						/* translators: 1: required PHP version, 2: current PHP version */
						__( 'Facturare Oblio necesită PHP %1$s sau mai nou. Rulezi PHP %2$s.', 'oblio-fgwoo' ),
						OBLIO_FGWOO_MIN_PHP,
						PHP_VERSION
					)
				)
			);
		}
	);
	return;
}

if ( is_readable( OBLIO_FGWOO_DIR . 'vendor/autoload.php' ) ) {
	require OBLIO_FGWOO_DIR . 'vendor/autoload.php';
}

spl_autoload_register(
	static function ( $class ) {
		if ( 0 !== strpos( $class, 'OblioWoo\\' ) ) {
			return;
		}
		$relative = substr( $class, strlen( 'OblioWoo\\' ) );
		$path     = OBLIO_FGWOO_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_readable( $path ) ) {
			require $path;
		}
	}
);

add_action(
	'before_woocommerce_init',
	static function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', OBLIO_FGWOO_FILE, true );
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', OBLIO_FGWOO_FILE, true );
		}
	}
);

register_activation_hook(
	__FILE__,
	static function () {
		\OblioWoo\Plugin::instance()->activate();
	}
);

register_deactivation_hook(
	__FILE__,
	static function () {
		\OblioWoo\Plugin::instance()->deactivate();
	}
);

add_action(
	'plugins_loaded',
	static function () {
		if ( ! class_exists( 'WooCommerce' ) || ! defined( 'WC_VERSION' ) || version_compare( WC_VERSION, OBLIO_FGWOO_MIN_WC, '<' ) ) {
			add_action(
				'admin_notices',
				static function () {
					printf(
						'<div class="notice notice-error"><p>%s</p></div>',
						esc_html(
							sprintf(
								/* translators: %s: required WooCommerce version */
								__( 'Facturare Oblio necesită WooCommerce %s sau mai nou să fie activ.', 'oblio-fgwoo' ),
								OBLIO_FGWOO_MIN_WC
							)
						)
					);
				}
			);
			return;
		}

		\OblioWoo\Plugin::instance()->boot();
	},
	11
);
