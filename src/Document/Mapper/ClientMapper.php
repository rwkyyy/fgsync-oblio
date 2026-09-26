<?php
/**
 * Maps a WooCommerce order's billing data to an Oblio client payload.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document\Mapper;

use WC_Order;
final class ClientMapper {

	public function map( WC_Order $order, array $ctx = array() ): array {
		$first   = $order->get_billing_first_name();
		$last    = $order->get_billing_last_name();
		$contact = trim( $first . ' ' . $last );
		$company = trim( $order->get_billing_company() );

		$city  = $order->get_billing_city();
		$state = $this->resolve_state( $order );
		if ( 'București' === $state && preg_match( '/^S\s*([1-6])$/', $city, $matches ) ) {
			$city = 'SECTOR ' . $matches[1];
		}

		$address = trim( $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), ', ' );

		$client = array(
			'cif'          => $this->field( $order, 'cif', '/(cif|cui|nif|company_details)$/i' ),
			'name'         => '' !== $company ? $company : $contact,
			'rc'           => $this->field( $order, 'rc', '/(regcom|reg_com|rc)$/i' ),
			'address'      => $address,
			'state'        => $state,
			'city'         => $city,
			'country'      => $this->resolve_country( $order ),
			'iban'         => $this->field( $order, 'iban', '/(iban)$/i' ),
			'bank'         => $this->field( $order, 'bank', '/(bank|bank_name|bank_details)$/i' ),
			'email'        => $order->get_billing_email(),
			'phone'        => $order->get_billing_phone(),
			'contact'      => $contact,
			'save'         => (bool) ( $ctx['save'] ?? true ),
			'autocomplete' => (int) ( $ctx['autocomplete'] ?? 0 ),
		);

		return (array) apply_filters( 'oblio_fgwoo_client_data', $client, $order );
	}

	private function field( WC_Order $order, string $field, string $pattern ): string {
		$value = apply_filters( 'oblio_fgwoo_client_field', null, $field, $order );
		if ( is_string( $value ) ) {
			return $value;
		}
		$legacy = $this->legacy_checkout_value( $order, $field );
		if ( null !== $legacy ) {
			return $legacy;
		}
		return $this->find_meta_by_pattern( $order, $pattern );
	}

	/**
	 * `av_facturare` / `curiero_pf_pj_option` are order-meta arrays written by
	 * popular third-party Romanian checkout plugins (CIF/RC + PF-PJ fields).
	 * WooCommerce's own get_meta() already unserializes them; only cif/rc are
	 * ever stored in them, so any other field falls straight through to the
	 * regex scan.
	 *
	 * @param WC_Order $order Order to read.
	 * @param string   $field One of 'cif', 'rc', 'iban', 'bank'.
	 * @return string|null Resolved value, or null when not found so the caller
	 *                      can fall through to the regex scan.
	 */
	private function legacy_checkout_value( WC_Order $order, string $field ): ?string {
		if ( 'cif' !== $field && 'rc' !== $field ) {
			return null;
		}
		$av_facturare = $order->get_meta( 'av_facturare' );
		$curiero      = $order->get_meta( 'curiero_pf_pj_option' );
		$av_facturare = is_array( $av_facturare ) ? $av_facturare : array();
		$curiero      = is_array( $curiero ) ? $curiero : array();

		$value = 'cif' === $field
			? ( $av_facturare['cui'] ?? $av_facturare['cnp'] ?? $curiero['cui'] ?? null )
			: ( $av_facturare['nr_reg_com'] ?? $curiero['nr_reg_com'] ?? null );

		return ( null !== $value && is_scalar( $value ) ) ? (string) $value : null;
	}

	private function find_meta_by_pattern( WC_Order $order, string $pattern ): string {
		foreach ( $order->get_meta_data() as $meta ) {
			$data = $meta->get_data();
			$key  = (string) ( $data['key'] ?? '' );
			if ( '' !== $key && preg_match( $pattern, $key ) ) {
				$value = $data['value'] ?? '';
				return is_scalar( $value ) ? (string) $value : '';
			}
		}
		return '';
	}

	private function resolve_state( WC_Order $order ): string {
		$country = $order->get_billing_country();
		$code    = $order->get_billing_state();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$states = WC()->countries->get_states( $country );
			if ( is_array( $states ) && isset( $states[ $code ] ) ) {
				return (string) $states[ $code ];
			}
		}
		return (string) $code;
	}

	private function resolve_country( WC_Order $order ): string {
		$code = $order->get_billing_country();
		if ( function_exists( 'WC' ) && WC()->countries ) {
			$countries = WC()->countries->get_countries();
			if ( is_array( $countries ) && isset( $countries[ $code ] ) ) {
				return (string) $countries[ $code ];
			}
		}
		return (string) $code;
	}
}
