<?php
/**
 * "Facturare Oblio" meta box on the order screen.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Settings;
use WC_Order;
use WP_Post;
final class OrderMetaBox {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function register(): void {
		add_action( 'add_meta_boxes', array( $this, 'add' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue' ) );
	}

	public function add(): void {
		$screen = class_exists( OrderUtil::class ) && OrderUtil::custom_orders_table_usage_is_enabled()
			? wc_get_page_screen_id( 'shop-order' )
			: 'shop_order';

		add_meta_box(
			'oblio_fgwoo_order',
			__( 'Facturare Oblio', 'facturare-gestiune-oblio-woocommerce' ),
			array( $this, 'render' ),
			$screen,
			'side',
			'high'
		);
	}

	public function enqueue( string $hook_suffix ): void {
		$screen          = get_current_screen();
		$is_order_screen = $screen && in_array( $screen->id, array( 'shop_order', wc_get_page_screen_id( 'shop-order' ) ), true );
		if ( ! $is_order_screen ) {
			return;
		}

		wp_enqueue_style( 'oblio-fgwoo-admin', OBLIO_FGWOO_URL . 'assets/css/admin.css', array(), OBLIO_FGWOO_VERSION );
		wp_enqueue_script( 'oblio-fgwoo-order', OBLIO_FGWOO_URL . 'assets/js/order-actions.js', array( 'jquery' ), OBLIO_FGWOO_VERSION, true );
		wp_localize_script(
			'oblio-fgwoo-order',
			'oblioFgwooOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( OrderActions::NONCE_ACTION ),
				'i18n'    => array(
					'working'       => __( 'Se procesează…', 'facturare-gestiune-oblio-woocommerce' ),
					'confirm'       => __( 'Sigur?', 'facturare-gestiune-oblio-woocommerce' ),
					'error'         => __( 'Eroare', 'facturare-gestiune-oblio-woocommerce' ),
					'requestFailed' => __( 'Cererea a eșuat', 'facturare-gestiune-oblio-woocommerce' ),
				),
			)
		);
	}

	public function render( $post_or_order ): void {
		$order = $post_or_order instanceof WC_Order ? $post_or_order : wc_get_order( $post_or_order->ID ?? 0 );
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$order_id = $order->get_id();

		$this->inline_styles();

		echo '<div class="oblio-orderbox" data-order="' . esc_attr( (string) $order_id ) . '">';

		$this->document_row( $order, OrderMeta::TYPE_INVOICE, __( 'Factură', 'facturare-gestiune-oblio-woocommerce' ), true );
		$this->document_row( $order, OrderMeta::TYPE_PROFORMA, __( 'Proformă', 'facturare-gestiune-oblio-woocommerce' ), false );
		if ( $this->settings->is_enabled( 'notice_enabled' ) ) {
			$this->document_row( $order, OrderMeta::TYPE_NOTICE, __( 'Aviz', 'facturare-gestiune-oblio-woocommerce' ), false );
		}
		$this->storno_row( $order );

		echo '<div class="oblio-orderbox-result"></div>';
		echo '</div>';
	}

	private function inline_styles(): void {
		$css = <<<'CSS'
<style>
.oblio-orderbox .oblio-orderbox-row { margin: 0 0 4px; }
.oblio-orderbox .button,
.oblio-orderbox a.button {
	display: block;
	width: 100%;
	box-sizing: border-box;
	margin: 0 0 8px;
	padding: 9px 12px;
	text-align: center;
	font-size: 13px;
	font-weight: 600;
	line-height: 1.4;
	height: auto;
	border-radius: 6px;
	border: 1px solid #623394;
	background: #623394;
	color: #fff;
	text-decoration: none;
	box-shadow: none;
	transition: background .12s ease, border-color .12s ease, color .12s ease;
}
.oblio-orderbox .button:hover,
.oblio-orderbox a.button:hover { background: #4d2975; border-color: #4d2975; color: #fff; }
.oblio-orderbox .button:focus { box-shadow: 0 0 0 1px #b5540e; outline: none; }
.oblio-orderbox .button-primary,
.oblio-orderbox .button-primary:focus { background: #f36e21; border-color: #f36e21; color: #fff; text-shadow: none; }
.oblio-orderbox .button-primary:hover { background: #d8600f; border-color: #d8600f; color: #fff; }
.oblio-orderbox .oblio-danger { background: #c62d1c; border-color: #c62d1c; color: #fff; }
.oblio-orderbox .oblio-danger:hover { background: #a02417; border-color: #a02417; color: #fff; }
.oblio-orderbox .button:disabled,
.oblio-orderbox .button.disabled { opacity: .6; cursor: default; }
.oblio-orderbox .oblio-orderbox-result { font-size: 12px; margin-top: 2px; }
</style>
CSS;
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static inline CSS.
	}

	private function document_row( WC_Order $order, string $doc_type, string $label, bool $with_stock ): void {
		$document = OrderMeta::get( $order, $doc_type );
		echo '<p class="oblio-orderbox-row">';

		if ( null !== $document ) {
			printf(
				'<a class="button" href="%s" target="_blank">%s %s %s</a> ',
				esc_url( $document['link'] ),
				esc_html( sprintf( /* translators: %s: doc label */ __( 'Vezi %s', 'facturare-gestiune-oblio-woocommerce' ), $label ) ),
				esc_html( $document['series'] ),
				esc_html( $document['number'] )
			);

			if ( OrderMeta::is_last_document( $order, $doc_type ) ) {
				printf(
					'<button type="button" class="button oblio-danger oblio-order-action" data-task="delete" data-doc-type="%1$s" data-confirm="1" data-confirm-msg="%2$s">%3$s</button>',
					esc_attr( $doc_type ),
					esc_attr__( 'Ștergi definitiv acest document din Oblio? Acțiunea este ireversibilă.', 'facturare-gestiune-oblio-woocommerce' ),
					esc_html__( 'Șterge', 'facturare-gestiune-oblio-woocommerce' )
				);
			} else {
				echo '<span class="oblio-orderbox-hint" style="color:#6b6577;font-size:12px;">'
					. esc_html__( 'Nu se poate șterge: nu este ultimul document din serie. Emite un storno.', 'facturare-gestiune-oblio-woocommerce' )
					. '</span>';
			}
		} else {
			printf(
				'<button type="button" class="button button-primary oblio-order-action" data-task="issue" data-doc-type="%1$s"%2$s>%3$s</button>',
				esc_attr( $doc_type ),
				$with_stock ? ' data-use-stock="1"' : '',
				esc_html( sprintf( /* translators: %s: doc label */ __( 'Emite %s', 'facturare-gestiune-oblio-woocommerce' ), $label ) )
			);
			if ( $with_stock ) {
				printf(
					' <button type="button" class="button oblio-order-action" data-task="issue" data-doc-type="%1$s">%2$s</button>',
					esc_attr( $doc_type ),
					esc_html__( 'Emite fără descărcare', 'facturare-gestiune-oblio-woocommerce' )
				);
			}
		}
		echo '</p>';
	}

	private function storno_row( WC_Order $order ): void {
		if ( ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			return;
		}
		$storni = OrderMeta::storno_list( $order );
		echo '<p class="oblio-orderbox-row">';
		if ( ! empty( $storni ) ) {
			foreach ( $storni as $storno ) {
				$link = (string) ( $storno['link'] ?? '' );
				if ( '' === $link ) {
					continue;
				}
				$label = empty( $storno['full'] )
					? __( 'Vezi storno parțial', 'facturare-gestiune-oblio-woocommerce' )
					: __( 'Vezi storno total', 'facturare-gestiune-oblio-woocommerce' );
				printf(
					'<a class="button" href="%s" target="_blank">%s %s %s</a>',
					esc_url( $link ),
					esc_html( $label ),
					esc_html( (string) ( $storno['series'] ?? '' ) ),
					esc_html( (string) ( $storno['number'] ?? '' ) )
				);
			}
		} else {
			printf(
				'<button type="button" class="button oblio-order-action oblio-danger" data-task="storno" data-doc-type="invoice" data-confirm="1" data-confirm-msg="%s">%s</button>',
				esc_attr__( 'Emiți factura storno pentru această comandă? Acțiunea este ireversibilă.', 'facturare-gestiune-oblio-woocommerce' ),
				esc_html__( 'Stornează factura', 'facturare-gestiune-oblio-woocommerce' )
			);
		}
		echo '</p>';
	}
}
