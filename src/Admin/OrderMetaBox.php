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
			__( 'Facturare Oblio', 'oblio-fgwoo' ),
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
					'working'       => __( 'Se procesează…', 'oblio-fgwoo' ),
					'confirm'       => __( 'Sigur?', 'oblio-fgwoo' ),
					'error'         => __( 'Eroare', 'oblio-fgwoo' ),
					'requestFailed' => __( 'Cererea a eșuat', 'oblio-fgwoo' ),
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

		echo '<div class="oblio-orderbox" data-order="' . esc_attr( (string) $order_id ) . '">';

		$this->document_row( $order, OrderMeta::TYPE_INVOICE, __( 'Factură', 'oblio-fgwoo' ), true );
		$this->document_row( $order, OrderMeta::TYPE_PROFORMA, __( 'Proformă', 'oblio-fgwoo' ), false );
		if ( $this->settings->is_enabled( 'notice_enabled' ) ) {
			$this->document_row( $order, OrderMeta::TYPE_NOTICE, __( 'Aviz', 'oblio-fgwoo' ), false );
		}
		$this->storno_row( $order );

		echo '<div class="oblio-orderbox-result"></div>';
		echo '</div>';
	}

	private function document_row( WC_Order $order, string $doc_type, string $label, bool $with_stock ): void {
		$document = OrderMeta::get( $order, $doc_type );
		echo '<p class="oblio-orderbox-row">';

		if ( null !== $document ) {
			printf(
				'<a class="button" href="%s" target="_blank">%s %s %s</a> ',
				esc_url( $document['link'] ),
				esc_html( sprintf( /* translators: %s: doc label */ __( 'Vezi %s', 'oblio-fgwoo' ), $label ) ),
				esc_html( $document['series'] ),
				esc_html( $document['number'] )
			);

			if ( OrderMeta::is_last_document( $order, $doc_type ) ) {
				printf(
					'<button type="button" class="button oblio-danger oblio-order-action" data-task="delete" data-doc-type="%1$s" data-confirm="1" data-confirm-msg="%2$s">%3$s</button>',
					esc_attr( $doc_type ),
					esc_attr__( 'Ștergi definitiv acest document din Oblio? Acțiunea este ireversibilă.', 'oblio-fgwoo' ),
					esc_html__( 'Șterge', 'oblio-fgwoo' )
				);
			} else {
				echo '<span class="oblio-orderbox-hint" style="color:#6b6577;font-size:12px;">'
					. esc_html__( 'Nu se poate șterge: nu este ultimul document din serie. Emite un storno.', 'oblio-fgwoo' )
					. '</span>';
			}
		} else {
			printf(
				'<button type="button" class="button button-primary oblio-order-action" data-task="issue" data-doc-type="%1$s"%2$s>%3$s</button>',
				esc_attr( $doc_type ),
				$with_stock ? ' data-use-stock="1"' : '',
				esc_html( sprintf( /* translators: %s: doc label */ __( 'Emite %s', 'oblio-fgwoo' ), $label ) )
			);
			if ( $with_stock ) {
				printf(
					' <button type="button" class="button oblio-order-action" data-task="issue" data-doc-type="%1$s">%2$s</button>',
					esc_attr( $doc_type ),
					esc_html__( 'Emite fără descărcare', 'oblio-fgwoo' )
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
					? __( 'Vezi storno parțial', 'oblio-fgwoo' )
					: __( 'Vezi storno total', 'oblio-fgwoo' );
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
				esc_attr__( 'Emiți factura storno pentru această comandă? Acțiunea este ireversibilă.', 'oblio-fgwoo' ),
				esc_html__( 'Stornează factura', 'oblio-fgwoo' )
			);
		}
		echo '</p>';
	}
}
