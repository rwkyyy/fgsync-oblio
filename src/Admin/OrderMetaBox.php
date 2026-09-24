<?php
/**
 * "Facturare Oblio" meta box on the order screen.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use Automattic\WooCommerce\Utilities\OrderUtil;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Settings;
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
			__( 'Facturare Oblio', 'fgsync-oblio' ),
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

		wp_enqueue_style( 'fgsync-oblio-admin', FGSYNC_OBLIO_URL . 'assets/css/admin.css', array(), FGSYNC_OBLIO_VERSION );
		wp_enqueue_script( 'fgsync-oblio-order', FGSYNC_OBLIO_URL . 'assets/js/order-actions.js', array( 'jquery' ), FGSYNC_OBLIO_VERSION, true );
		wp_localize_script(
			'fgsync-oblio-order',
			'fgsyncOblioOrder',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( OrderActions::NONCE_ACTION ),
				'i18n'    => array(
					'working'       => __( 'Se procesează…', 'fgsync-oblio' ),
					'confirm'       => __( 'Sigur?', 'fgsync-oblio' ),
					'error'         => __( 'Eroare', 'fgsync-oblio' ),
					'requestFailed' => __( 'Cererea a eșuat', 'fgsync-oblio' ),
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

		echo '<div class="oblio-fgwoo-orderbox" data-order="' . esc_attr( (string) $order_id ) . '">';

		$errors   = array();
		$invoiced = null !== OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );

		$errors[] = $this->document_row( $order, OrderMeta::TYPE_INVOICE, __( 'Factură', 'fgsync-oblio' ), true );
		if ( ! $invoiced || null !== OrderMeta::get( $order, OrderMeta::TYPE_PROFORMA ) ) {
			$errors[] = $this->document_row( $order, OrderMeta::TYPE_PROFORMA, __( 'Proformă', 'fgsync-oblio' ), false );
		}
		if ( $this->settings->is_enabled( 'notice_enabled' ) ) {
			$errors[] = $this->document_row( $order, OrderMeta::TYPE_NOTICE, __( 'Aviz', 'fgsync-oblio' ), false );
		}
		$this->storno_row( $order );

		foreach ( array_filter( $errors ) as $reason ) {
			printf(
				'<p class="oblio-fgwoo-orderbox-row"><span class="oblio-fgwoo-orderbox-error">%s</span></p>',
				esc_html( $reason )
			);
		}

		echo '<div class="oblio-fgwoo-orderbox-result"></div>';
		echo '</div>';
	}

	private function document_row( WC_Order $order, string $doc_type, string $label, bool $with_stock ): string {
		$document = OrderMeta::get( $order, $doc_type );
		$reason   = '';
		echo '<p class="oblio-fgwoo-orderbox-row">';

		if ( null !== $document ) {
			printf(
				'<a class="button" href="%s" target="_blank">%s %s %s</a> ',
				esc_url( $document['link'] ),
				esc_html( sprintf( /* translators: %s: doc label */ __( 'Vezi %s', 'fgsync-oblio' ), $label ) ),
				esc_html( $document['series'] ),
				esc_html( $document['number'] )
			);

			if ( OrderMeta::is_last_document( $order, $doc_type ) ) {
				printf(
					'<button type="button" class="button oblio-fgwoo-danger oblio-fgwoo-order-action" data-task="delete" data-doc-type="%1$s" data-confirm="1" data-confirm-msg="%2$s">%3$s</button>',
					esc_attr( $doc_type ),
					esc_attr__( 'Ștergi definitiv acest document din Oblio? Acțiunea este ireversibilă.', 'fgsync-oblio' ),
					esc_html__( 'Șterge', 'fgsync-oblio' )
				);
			}
		} else {
			printf(
				'<button type="button" class="button button-primary oblio-fgwoo-order-action" data-task="issue" data-doc-type="%1$s"%2$s>%3$s</button>',
				esc_attr( $doc_type ),
				$with_stock ? ' data-use-stock="1"' : '',
				esc_html( sprintf( /* translators: %s: doc label */ __( 'Emite %s', 'fgsync-oblio' ), $label ) )
			);
			if ( $with_stock ) {
				printf(
					' <button type="button" class="button oblio-fgwoo-order-action" data-task="issue" data-doc-type="%1$s">%2$s</button>',
					esc_attr( $doc_type ),
					esc_html__( 'Emite fără descărcare', 'fgsync-oblio' )
				);
			}

			$reason = (string) $order->get_meta( OrderMeta::key( $doc_type, 'failed' ) );
		}
		echo '</p>';

		return $reason;
	}

	private function storno_row( WC_Order $order ): void {
		if ( ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE ) ) {
			return;
		}
		$storni = OrderMeta::storno_list( $order );
		echo '<p class="oblio-fgwoo-orderbox-row">';
		if ( ! empty( $storni ) ) {
			foreach ( $storni as $storno ) {
				$link = (string) ( $storno['link'] ?? '' );
				if ( '' === $link ) {
					continue;
				}
				$label = empty( $storno['full'] )
					? __( 'Vezi storno parțial', 'fgsync-oblio' )
					: __( 'Vezi storno total', 'fgsync-oblio' );
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
				'<button type="button" class="button oblio-fgwoo-order-action oblio-fgwoo-danger" data-task="storno" data-doc-type="invoice" data-confirm="1" data-confirm-msg="%s">%s</button>',
				esc_attr__( 'Emiți factura storno pentru această comandă? Acțiunea este ireversibilă.', 'fgsync-oblio' ),
				esc_html__( 'Stornează factura', 'fgsync-oblio' )
			);
		}
		echo '</p>';
	}
}
