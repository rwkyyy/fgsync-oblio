<?php
/**
 * "Facturi" section in the customer's WooCommerce account.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Frontend;

use OblioWoo\Order\OrderMeta;
use WC_Order;
final class AccountInvoices {

	private const ENDPOINT = 'oblio-facturi';

	public function register(): void {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ), 0 );
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_item' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'content' ) );
	}

	public function add_endpoint(): void {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );

		if ( get_option( 'oblio_fgwoo_flush_rewrite' ) ) {
			flush_rewrite_rules( false );
			delete_option( 'oblio_fgwoo_flush_rewrite' );
		}
	}

	public function query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT;
		return $vars;
	}

	public function menu_item( array $items ): array {
		$logout = array();
		if ( isset( $items['customer-logout'] ) ) {
			$logout = array( 'customer-logout' => $items['customer-logout'] );
			unset( $items['customer-logout'] );
		}
		$items[ self::ENDPOINT ] = __( 'Facturi', 'oblio-fgwoo' );
		return $items + $logout;
	}

	public function content(): void {
		$user_id = get_current_user_id();
		if ( ! $user_id || ! function_exists( 'wc_get_orders' ) ) {
			return;
		}

		$per_page = 20;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only paging of the user's own orders.
		$page   = isset( $_GET['invoices_page'] ) ? max( 1, absint( wp_unslash( $_GET['invoices_page'] ) ) ) : 1;
		$result = wc_get_orders(
			array(
				'customer'   => $user_id,
				'limit'      => $per_page,
				'paged'      => $page,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'paginate'   => true,

				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- account page, scoped to one customer.
				'meta_query' => array(
					'relation' => 'OR',
					array(
						'key'     => OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' ),
						'compare' => 'EXISTS',
					),
					array(
						'key'     => OrderMeta::key( OrderMeta::TYPE_STORNO, 'link' ),
						'compare' => 'EXISTS',
					),
					array(
						'key'     => 'oblio_invoice_link',
						'compare' => 'EXISTS',
					),
				),
			)
		);

		$orders = is_object( $result ) ? (array) $result->orders : array();
		if ( empty( $orders ) ) {
			echo '<p>' . esc_html__( 'Nu există facturi.', 'oblio-fgwoo' ) . '</p>';
			return;
		}

		echo '<table class="woocommerce-orders-table shop_table"><thead><tr>';
		echo '<th>' . esc_html__( 'Comandă', 'oblio-fgwoo' ) . '</th>';
		echo '<th>' . esc_html__( 'Factură', 'oblio-fgwoo' ) . '</th>';
		echo '<th>' . esc_html__( 'Storno / Retur', 'oblio-fgwoo' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $orders as $order ) {
			if ( ! $order instanceof WC_Order ) {
				continue;
			}
			echo '<tr><td>#' . esc_html( $order->get_order_number() ) . '</td>';
			echo '<td>' . wp_kses_post( $this->link( OrderMeta::get( $order, OrderMeta::TYPE_INVOICE ) ) ) . '</td>';
			echo '<td>' . wp_kses_post( $this->storno_links( $order ) ) . '</td></tr>';
		}

		echo '</tbody></table>';

		$this->pagination( (int) $result->max_num_pages, $page );
	}

	private function pagination( int $max_pages, int $current ): void {
		if ( $max_pages <= 1 ) {
			return;
		}
		$base = wc_get_account_endpoint_url( self::ENDPOINT );
		echo '<div class="woocommerce-pagination oblio-invoices-pagination">';
		if ( $current > 1 ) {
			printf( '<a class="button" href="%s">%s</a> ', esc_url( add_query_arg( 'invoices_page', $current - 1, $base ) ), esc_html__( 'Anterioare', 'oblio-fgwoo' ) );
		}
		printf(
			'<span class="oblio-invoices-page"> %s </span>',
			esc_html(
				sprintf(
					/* translators: 1: current page, 2: total pages */
					__( 'Pagina %1$d din %2$d', 'oblio-fgwoo' ),
					$current,
					$max_pages
				)
			)
		);
		if ( $current < $max_pages ) {
			printf( '<a class="button" href="%s">%s</a>', esc_url( add_query_arg( 'invoices_page', $current + 1, $base ) ), esc_html__( 'Următoarele', 'oblio-fgwoo' ) );
		}
		echo '</div>';
	}

	private function link( ?array $document ): string {
		if ( null === $document ) {
			return '';
		}
		return sprintf(
			'<a href="%s" target="_blank" rel="noopener">%s %s</a>',
			esc_url( $document['link'] ),
			esc_html( $document['series'] ),
			esc_html( $document['number'] )
		);
	}

	private function storno_links( WC_Order $order ): string {
		$links = array();
		foreach ( OrderMeta::storno_list( $order ) as $storno ) {
			$link = (string) ( $storno['link'] ?? '' );
			if ( '' === $link ) {
				continue;
			}
			$tag     = empty( $storno['full'] )
				? __( 'parțial', 'oblio-fgwoo' )
				: __( 'total', 'oblio-fgwoo' );
			$links[] = sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s %s (%s)</a>',
				esc_url( $link ),
				esc_html( (string) ( $storno['series'] ?? '' ) ),
				esc_html( (string) ( $storno['number'] ?? '' ) ),
				esc_html( $tag )
			);
		}
		return empty( $links ) ? '' : implode( '<br>', $links );
	}
}
