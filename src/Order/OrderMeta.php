<?php
/**
 * Canonical order-meta keys for issued documents + read/write helpers.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Order;

use OblioWoo\Document\DocumentResult;
use WC_Order;
final class OrderMeta {

	public const TYPE_INVOICE  = 'invoice';
	public const TYPE_PROFORMA = 'proforma';
	public const TYPE_NOTICE   = 'notice';
	public const TYPE_STORNO   = 'storno';

	public const STORNO_LIST = 'oblio_fgwoo_storno_list';

	private const PREFIX = 'oblio_fgwoo_';

	public static function key( string $type, string $field ): string {
		return self::PREFIX . $type . '_' . $field;
	}

	public static function has( WC_Order $order, string $type ): bool {
		if ( '' !== (string) $order->get_meta( self::key( $type, 'link' ) ) ) {
			return true;
		}
		return null !== self::legacy_get( $order, $type );
	}

	public static function get( WC_Order $order, string $type ): ?array {
		$link = (string) $order->get_meta( self::key( $type, 'link' ) );
		if ( '' === $link ) {
			return self::legacy_get( $order, $type );
		}
		return array(
			'series' => (string) $order->get_meta( self::key( $type, 'series' ) ),
			'number' => (string) $order->get_meta( self::key( $type, 'number' ) ),
			'link'   => $link,
			'date'   => (string) $order->get_meta( self::key( $type, 'date' ) ),
		);
	}

	public static function storno_list( WC_Order $order ): array {
		$list = $order->get_meta( self::STORNO_LIST );
		if ( is_array( $list ) && ! empty( $list ) ) {
			return array_values( $list );
		}

		$single = self::get( $order, self::TYPE_STORNO );
		if ( null === $single ) {
			return array();
		}
		return array(
			array(
				'series' => $single['series'],
				'number' => $single['number'],
				'link'   => $single['link'],
				'full'   => '' !== (string) $order->get_meta( self::key( self::TYPE_STORNO, 'full' ) ),
				'date'   => $single['date'],
			),
		);
	}

	private static function legacy_get( WC_Order $order, string $type ): ?array {
		if ( ! in_array( $type, array( self::TYPE_INVOICE, self::TYPE_PROFORMA ), true ) ) {
			return null;
		}
		$link = (string) $order->get_meta( 'oblio_' . $type . '_link' );
		if ( '' === $link ) {
			return null;
		}
		return array(
			'series' => (string) $order->get_meta( 'oblio_' . $type . '_series_name' ),
			'number' => (string) $order->get_meta( 'oblio_' . $type . '_number' ),
			'link'   => $link,
			'date'   => (string) $order->get_meta( 'oblio_' . $type . '_date' ),
		);
	}

	public static function save( WC_Order $order, DocumentResult $result ): void {
		$type = $result->doc_type;
		$order->update_meta_data( self::key( $type, 'series' ), $result->series_name );
		$order->update_meta_data( self::key( $type, 'number' ), $result->number );
		$order->update_meta_data( self::key( $type, 'link' ), $result->link );
		$order->update_meta_data( self::key( $type, 'date' ), current_time( 'Y-m-d' ) );
		$order->save();
	}

	public static function record_invoice_stock_usage( WC_Order $order, bool $used ): void {
		$order->update_meta_data( self::key( self::TYPE_INVOICE, 'use_stock' ), $used ? '1' : '0' );
	}

	public static function invoice_used_stock( WC_Order $order ): bool {
		return '1' === (string) $order->get_meta( self::key( self::TYPE_INVOICE, 'use_stock' ) );
	}

	public static function is_last_document( WC_Order $order, string $type ): bool {
		if ( self::TYPE_PROFORMA === $type ) {
			return true;
		}

		$doc = self::get( $order, $type );
		if ( null === $doc || '' === $doc['number'] || '' === $doc['series'] ) {
			return true;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return true;
		}

		$higher = wc_get_orders(
			array(
				'limit'      => 1,
				'return'     => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- one-off admin check, indexed doc meta.
				'meta_query' => array(
					array(
						'key'     => self::key( $type, 'series' ),
						'value'   => $doc['series'],
						'compare' => '=',
					),
					array(
						'key'     => self::key( $type, 'number' ),
						'value'   => (int) $doc['number'],
						'compare' => '>',
						'type'    => 'NUMERIC',
					),
				),
			)
		);

		return empty( $higher );
	}

	public static function clear( WC_Order $order, string $type ): void {
		foreach ( array( 'series', 'number', 'link', 'date' ) as $field ) {
			$order->delete_meta_data( self::key( $type, $field ) );
		}
		$order->save();
	}
}
