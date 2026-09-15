<?php
/**
 * "Documente Oblio" filter dropdown on the orders list (HPOS + legacy).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Order\OrderMeta;
use WP_Query;
final class OrderListFilter {

	private const PARAM = 'oblio_fgwoo_doc_filter';

	private const VALUES = array( 'none', 'proforma', 'invoice', 'notice', 'storno', 'failed' );

	public function register(): void {

		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( $this, 'render_dropdown' ), 20, 2 );
		add_filter( 'woocommerce_order_list_table_prepare_items_query_args', array( $this, 'filter_hpos_query' ) );

		add_action( 'restrict_manage_posts', array( $this, 'render_dropdown' ), 20, 2 );
		add_action( 'pre_get_posts', array( $this, 'filter_legacy_query' ) );
	}

	public function render_dropdown( $screen_type = '', $which = '' ): void {
		unset( $which );
		if ( 'shop_order' !== $screen_type ) {
			return;
		}

		$current = $this->requested_filter();
		$options = array(
			''         => __( 'Documente Oblio: toate', 'oblio-fgwoo' ),
			'none'     => __( 'Fără documente', 'oblio-fgwoo' ),
			'proforma' => __( 'Cu proformă (fără factură)', 'oblio-fgwoo' ),
			'invoice'  => __( 'Cu factură', 'oblio-fgwoo' ),
			'notice'   => __( 'Cu aviz', 'oblio-fgwoo' ),
			'storno'   => __( 'Cu storno', 'oblio-fgwoo' ),
			'failed'   => __( 'Emitere eșuată', 'oblio-fgwoo' ),
		);

		echo '<select name="' . esc_attr( self::PARAM ) . '" id="' . esc_attr( self::PARAM ) . '">';
		foreach ( $options as $value => $label ) {
			printf(
				'<option value="%s"%s>%s</option>',
				esc_attr( $value ),
				selected( $current, $value, false ),
				esc_html( $label )
			);
		}
		echo '</select>';
	}

	public function filter_hpos_query( array $args ): array {
		$filter = $this->requested_filter();
		if ( '' === $filter ) {
			return $args;
		}
		if ( ! isset( $args['meta_query'] ) || ! is_array( $args['meta_query'] ) ) {
			$args['meta_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin list filter, opt-in.
		}
		$args['meta_query'][] = self::meta_query_for( $filter ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		return $args;
	}

	public function filter_legacy_query( WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( null === $screen || 'edit-shop_order' !== $screen->id ) {
			return;
		}
		$filter = $this->requested_filter();
		if ( '' === $filter ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = self::meta_query_for( $filter );
		$query->set( 'meta_query', $meta_query );
	}

	public static function meta_query_for( string $filter ): array {
		$invoice  = OrderMeta::key( OrderMeta::TYPE_INVOICE, 'link' );
		$proforma = OrderMeta::key( OrderMeta::TYPE_PROFORMA, 'link' );

		switch ( $filter ) {
			case 'none':
				return array(
					'relation' => 'AND',
					array(
						'key'     => $invoice,
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => $proforma,
						'compare' => 'NOT EXISTS',
					),
				);
			case 'proforma':
				return array(
					'relation' => 'AND',
					array(
						'key'     => $proforma,
						'compare' => 'EXISTS',
					),
					array(
						'key'     => $invoice,
						'compare' => 'NOT EXISTS',
					),
				);
			case 'invoice':
				return array(
					'key'     => $invoice,
					'compare' => 'EXISTS',
				);
			case 'notice':
				return array(
					'key'     => OrderMeta::key( OrderMeta::TYPE_NOTICE, 'link' ),
					'compare' => 'EXISTS',
				);
			case 'storno':
				return array(
					'key'     => OrderMeta::key( OrderMeta::TYPE_STORNO, 'link' ),
					'compare' => 'EXISTS',
				);
			case 'failed':
				return array(
					'key'     => OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ),
					'compare' => 'EXISTS',
				);
			default:
				return array();
		}
	}

	private function requested_filter(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin list filter.
		$value = isset( $_GET[ self::PARAM ] ) ? sanitize_key( wp_unslash( $_GET[ self::PARAM ] ) ) : '';
		return in_array( $value, self::VALUES, true ) ? $value : '';
	}
}
