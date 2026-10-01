<?php
/**
 * Canonical order-meta keys for issued documents + read/write helpers.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Order;

use FGSyncOblio\Document\DocumentResult;
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
		$order->update_meta_data( self::key( $type, 'date' ), '' !== $result->date ? $result->date : current_time( 'Y-m-d' ) );
		$order->save();

		self::bump_latest_number( $type, $result->series_name, $result->number );
	}

	public static function record_invoice_stock_usage( WC_Order $order, bool $used ): void {
		$order->update_meta_data( self::key( self::TYPE_INVOICE, 'use_stock' ), $used ? '1' : '0' );
	}

	public static function invoice_used_stock( WC_Order $order ): bool {
		return '1' === (string) $order->get_meta( self::key( self::TYPE_INVOICE, 'use_stock' ) );
	}

	/**
	 * Used to gate manual deletion (Oblio only allows deleting the most
	 * recently issued document in a series). Answered from the
	 * latest-number-per-series option bumped by save()/cleared by clear() -
	 * an O(1) lookup - rather than a live "does any order have a higher
	 * number" query, which on a store with a large order history has no way
	 * to short-circuit and can turn into a full scan (this previously took
	 * down an order screen on a ~9.5k-order store). Falls back to assuming
	 * "last" (allow the attempt) when the cache hasn't been populated yet -
	 * e.g. a series that hasn't issued anything since this tracking was
	 * added, or legacy pre-migration documents - the same conservative
	 * default already used below for an order with no document at all.
	 * Oblio's own API still rejects the actual delete if this is wrong.
	 *
	 * @param WC_Order $order Order to check.
	 * @param string   $type  Document type.
	 */
	public static function is_last_document( WC_Order $order, string $type ): bool {
		if ( self::TYPE_PROFORMA === $type ) {
			return true;
		}

		$doc = self::get( $order, $type );
		if ( null === $doc || '' === $doc['number'] || '' === $doc['series'] ) {
			return true;
		}

		$latest = (int) get_option( self::latest_number_option( $type, $doc['series'] ), 0 );

		return 0 === $latest || (int) $doc['number'] >= $latest;
	}

	private static function latest_number_option( string $type, string $series ): string {
		return self::PREFIX . 'latest_number_' . $type . '_' . sanitize_key( $series );
	}

	/**
	 * Monotonic high-water mark, never lowered here - only clear() (on
	 * delete) resets it, forcing the next check back to the conservative
	 * "unknown" default until a subsequent save() re-seeds it.
	 *
	 * @param string $type   Document type.
	 * @param string $series Series name.
	 * @param string $number Newly issued document number.
	 */
	private static function bump_latest_number( string $type, string $series, string $number ): void {
		if ( '' === $series || '' === $number ) {
			return;
		}
		$option = self::latest_number_option( $type, $series );
		$number = (int) $number;
		if ( $number > (int) get_option( $option, 0 ) ) {
			update_option( $option, $number, false );
		}
	}

	public static function persisted_idempotency_key( WC_Order $order, string $meta_key, string $fresh ): string {
		$existing = (string) $order->get_meta( $meta_key );
		if ( '' !== $existing ) {
			return $existing;
		}
		$order->update_meta_data( $meta_key, $fresh );
		$order->save();
		return $fresh;
	}

	public static function clear( WC_Order $order, string $type ): void {
		$series = (string) $order->get_meta( self::key( $type, 'series' ) );

		foreach ( array( 'series', 'number', 'link', 'date' ) as $field ) {
			$order->delete_meta_data( self::key( $type, $field ) );
		}
		if ( self::TYPE_INVOICE === $type ) {
			// Otherwise stale, excluding this order from stock reservations forever.
			$order->delete_meta_data( self::key( self::TYPE_INVOICE, 'use_stock' ) );
		}
		if ( in_array( $type, array( self::TYPE_INVOICE, self::TYPE_PROFORMA ), true ) ) {
			foreach ( array( 'link', 'series_name', 'number', 'date' ) as $field ) {
				$order->delete_meta_data( 'oblio_' . $type . '_' . $field );
			}
		}
		$order->save();

		// Deleting is only ever allowed on the series' true latest number, so
		// the cached high-water mark is now stale - reset it to "unknown"
		// rather than guess the new latest (is_last_document() falls back to
		// allowing the next attempt; a subsequent save() re-seeds it).
		if ( '' !== $series ) {
			delete_option( self::latest_number_option( $type, $series ) );
		}
	}
}
