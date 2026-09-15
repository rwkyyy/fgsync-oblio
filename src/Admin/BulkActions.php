<?php
/**
 * Bulk Oblio document actions on the orders list (invoice, proforma, storno).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Compat\OrderStore;
use OblioWoo\Order\OrderMeta;
use OblioWoo\Queue\Scheduler;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
use WC_Order;
final class BulkActions {

	private const ACTIONS = array(
		'oblio_fgwoo_issue_invoice'  => 'invoice',
		'oblio_fgwoo_issue_proforma' => 'proforma',
		'oblio_fgwoo_issue_storno'   => 'storno',
	);

	private Settings $settings;

	private Scheduler $scheduler;

	private OrderStore $orders;

	private Logger $logger;

	public function __construct( Settings $settings, Scheduler $scheduler, OrderStore $orders, Logger $logger ) {
		$this->settings  = $settings;
		$this->scheduler = $scheduler;
		$this->orders    = $orders;
		$this->logger    = $logger;
	}

	public function register(): void {
		add_filter( 'bulk_actions-woocommerce_page_wc-orders', array( $this, 'add' ) );
		add_filter( 'bulk_actions-edit-shop_order', array( $this, 'add' ) );
		add_filter( 'handle_bulk_actions-woocommerce_page_wc-orders', array( $this, 'handle' ), 10, 3 );
		add_filter( 'handle_bulk_actions-edit-shop_order', array( $this, 'handle' ), 10, 3 );
		add_action( 'admin_notices', array( $this, 'notice' ) );
	}

	public function add( array $actions ): array {
		$actions['oblio_fgwoo_issue_invoice']  = __( 'Oblio: emite factură', 'oblio-fgwoo' );
		$actions['oblio_fgwoo_issue_proforma'] = __( 'Oblio: emite proformă', 'oblio-fgwoo' );
		$actions['oblio_fgwoo_issue_storno']   = __( 'Oblio: emite storno (rambursare)', 'oblio-fgwoo' );
		return $actions;
	}

	public function handle( $redirect, $action, $ids ): string {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return $redirect;
		}

		$type      = self::ACTIONS[ $action ];
		$queued    = 0;
		$skipped   = 0;
		$use_stock = $this->settings->is_enabled( 'invoice_autogen_use_stock' );

		foreach ( (array) $ids as $order_id ) {
			$order = $this->orders->get_order( (int) $order_id );
			if ( null === $order || ! $this->eligible( $order, $type ) ) {
				++$skipped;
				continue;
			}

			if ( 'storno' === $type ) {

				$this->scheduler->enqueue_refund( (int) $order_id, 0 );
			} elseif ( 'proforma' === $type ) {
				$this->scheduler->enqueue_document( (int) $order_id, OrderMeta::TYPE_PROFORMA );
			} else {
				$this->scheduler->enqueue_document( (int) $order_id, OrderMeta::TYPE_INVOICE, array( 'use_stock' => $use_stock ) );
			}
			++$queued;
		}

		$this->logger->info( sprintf( 'Bulk action: %s queued for %d order(s), skipped %d', $type, $queued, $skipped ) );

		return add_query_arg(
			array(
				'oblio_fgwoo_bulk'    => $type,
				'oblio_fgwoo_queued'  => $queued,
				'oblio_fgwoo_skipped' => $skipped,
			),
			$redirect
		);
	}

	private function eligible( WC_Order $order, string $type ): bool {
		switch ( $type ) {
			case 'invoice':
				return ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE );
			case 'proforma':
				return ! OrderMeta::has( $order, OrderMeta::TYPE_INVOICE )
					&& ! OrderMeta::has( $order, OrderMeta::TYPE_PROFORMA );
			case 'storno':
				return OrderMeta::has( $order, OrderMeta::TYPE_INVOICE )
					&& null === OrderMeta::get( $order, OrderMeta::TYPE_STORNO );
			default:
				return false;
		}
	}

	public function notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only display of our own redirect args.
		if ( ! isset( $_GET['oblio_fgwoo_bulk'] ) ) {
			return;
		}
		$type    = sanitize_key( wp_unslash( $_GET['oblio_fgwoo_bulk'] ) );
		$queued  = isset( $_GET['oblio_fgwoo_queued'] ) ? absint( $_GET['oblio_fgwoo_queued'] ) : 0;
		$skipped = isset( $_GET['oblio_fgwoo_skipped'] ) ? absint( $_GET['oblio_fgwoo_skipped'] ) : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$queued_text = $this->queued_text( $type, $queued );
		if ( '' === $queued_text ) {
			return;
		}

		$message = 'Oblio: ' . $queued_text;
		$class   = 'notice-success';

		if ( $skipped > 0 ) {
			$message .= ' ' . sprintf(
				/* translators: 1: number of orders, 2: reason they were skipped */
				_n( '%1$d comandă a fost sărită (%2$s).', '%1$d comenzi au fost sărite (%2$s).', $skipped, 'oblio-fgwoo' ),
				$skipped,
				$this->skip_reason( $type )
			);
			if ( 0 === $queued ) {
				$class = 'notice-warning';
			}
		}

		printf(
			'<div class="notice %s is-dismissible"><p>%s</p></div>',
			esc_attr( $class ),
			esc_html( $message )
		);
	}

	private function queued_text( string $type, int $count ): string {
		switch ( $type ) {
			case 'invoice':
				/* translators: %d: number of orders */
				return sprintf( _n( '%d factură pusă în coadă.', '%d facturi puse în coadă.', $count, 'oblio-fgwoo' ), $count );
			case 'proforma':
				/* translators: %d: number of orders */
				return sprintf( _n( '%d proformă pusă în coadă.', '%d proforme puse în coadă.', $count, 'oblio-fgwoo' ), $count );
			case 'storno':
				/* translators: %d: number of orders */
				return sprintf( _n( '%d storno pus în coadă.', '%d storno puse în coadă.', $count, 'oblio-fgwoo' ), $count );
			default:
				return '';
		}
	}

	private function skip_reason( string $type ): string {
		switch ( $type ) {
			case 'invoice':
				return __( 'aveau deja factură', 'oblio-fgwoo' );
			case 'proforma':
				return __( 'aveau deja factură sau proformă', 'oblio-fgwoo' );
			case 'storno':
				return __( 'nu aveau factură sau aveau deja storno', 'oblio-fgwoo' );
			default:
				return '';
		}
	}
}
