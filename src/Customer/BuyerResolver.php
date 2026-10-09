<?php
/**
 * Works out who the buyer is from the order's checkout data.
 *
 * The "Facturare - Persoana Fizica sau Juridica" plugin is the recommended
 * source: its `av_facturare` meta (classic and block checkout) states the
 * buyer type explicitly. Without it, fields are detected from other checkout
 * plugins and order meta key names.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Customer;

use WC_Order;
final class BuyerResolver {

	private const FACTURARE_META = 'av_facturare';

	public function resolve( WC_Order $order ): BuyerProfile {
		$facturare = $order->get_meta( self::FACTURARE_META );
		$facturare = is_array( $facturare ) ? $facturare : array();
		$type      = (string) ( $facturare['tip_facturare'] ?? '' );

		if ( 'pers-jur' === $type || 'pers-fiz' === $type ) {
			$profile = $this->from_facturare( $order, $facturare, 'pers-jur' === $type );
		} else {
			$profile = $this->detected( $order, $facturare );
		}

		$custom = apply_filters( 'oblio_fgwoo_buyer_profile', $profile, $order );
		return $custom instanceof BuyerProfile ? $custom : $profile;
	}

	/**
	 * @param WC_Order            $order       Order.
	 * @param array<string,mixed> $facturare   The plugin's av_facturare meta.
	 * @param bool                $is_business Whether the buyer chose "persoana juridica".
	 */
	private function from_facturare( WC_Order $order, array $facturare, bool $is_business ): BuyerProfile {
		$value = static fn ( string $key ): string => is_scalar( $facturare[ $key ] ?? null ) ? trim( (string) $facturare[ $key ] ) : '';

		return new BuyerProfile(
			$is_business,
			$order->get_billing_country(),
			$is_business ? trim( $order->get_billing_company() ) : '',
			$is_business ? $value( 'cui' ) : '',
			$is_business ? '' : $value( 'cnp' ),
			$is_business ? $value( 'nr_reg_com' ) : '',
			$is_business ? $value( 'nume_banca' ) : '',
			$is_business ? $value( 'iban' ) : '',
			BuyerProfile::SOURCE_FACTURARE
		);
	}

	/**
	 * @param WC_Order            $order     Order.
	 * @param array<string,mixed> $facturare av_facturare meta without a buyer type (older plugin versions).
	 */
	private function detected( WC_Order $order, array $facturare ): BuyerProfile {
		$curiero = $order->get_meta( 'curiero_pf_pj_option' );
		$curiero = is_array( $curiero ) ? $curiero : array();

		$tax_id = $this->first( array( $facturare['cui'] ?? null, $facturare['cnp'] ?? null, $curiero['cui'] ?? null ) );
		$tax_id = '' !== $tax_id ? $tax_id : $this->meta_by_pattern( $order, '/(cif|cui|nif|company_details)$/i' );

		$registration = $this->first( array( $facturare['nr_reg_com'] ?? null, $curiero['nr_reg_com'] ?? null ) );
		$registration = '' !== $registration ? $registration : $this->meta_by_pattern( $order, '/(regcom|reg_com|(^|[_-])rc)$/i' );

		$bank = $this->first( array( $facturare['nume_banca'] ?? null ) );
		$bank = '' !== $bank ? $bank : $this->meta_by_pattern( $order, '/(^|[_-])bank(_name|_details)?$/i' );
		$iban = $this->first( array( $facturare['iban'] ?? null ) );
		$iban = '' !== $iban ? $iban : $this->meta_by_pattern( $order, '/(iban)$/i' );

		$is_cnp = self::looks_like_cnp( $tax_id );

		return new BuyerProfile(
			'' !== $tax_id && ! $is_cnp,
			$order->get_billing_country(),
			trim( $order->get_billing_company() ),
			$is_cnp ? '' : $tax_id,
			$is_cnp ? $tax_id : '',
			$registration,
			$bank,
			$iban,
			BuyerProfile::SOURCE_DETECTED
		);
	}

	/**
	 * @param array<int,mixed> $candidates Values in priority order.
	 */
	private function first( array $candidates ): string {
		foreach ( $candidates as $value ) {
			if ( is_scalar( $value ) && '' !== trim( (string) $value ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	private function meta_by_pattern( WC_Order $order, string $pattern ): string {
		foreach ( $order->get_meta_data() as $meta ) {
			$data  = $meta->get_data();
			$key   = (string) ( $data['key'] ?? '' );
			$value = $data['value'] ?? '';
			if ( '' !== $key && is_scalar( $value ) && '' !== trim( (string) $value ) && preg_match( $pattern, $key ) ) {
				return trim( (string) $value );
			}
		}
		return '';
	}

	/**
	 * A Romanian CNP entered where a tax ID is expected marks an individual.
	 *
	 * @param string $value Tax ID as entered.
	 */
	private static function looks_like_cnp( string $value ): bool {
		return 1 === preg_match( '/^[1-9]\d{12}$/', $value );
	}
}
