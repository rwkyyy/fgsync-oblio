<?php
/**
 * Maps a WooCommerce order's billing data to an Oblio client payload.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document\Mapper;

use FGSyncOblio\Customer\BuyerResolver;
use WC_Order;
final class ClientMapper {

	private BuyerResolver $resolver;

	public function __construct( ?BuyerResolver $resolver = null ) {
		$this->resolver = $resolver ?? new BuyerResolver();
	}

	public function map( WC_Order $order, array $ctx = array() ): array {
		$first   = $order->get_billing_first_name();
		$last    = $order->get_billing_last_name();
		$contact = trim( $first . ' ' . $last );
		$buyer   = $this->resolver->resolve( $order );

		$city  = $order->get_billing_city();
		$state = $this->resolve_state( $order );
		if ( 'București' === $state && preg_match( '/^S\s*([1-6])$/', $city, $matches ) ) {
			$city = 'SECTOR ' . $matches[1];
		}

		$address = trim( $order->get_billing_address_1() . ', ' . $order->get_billing_address_2(), ', ' );

		$client = array(
			'cif'          => $this->field( $order, 'cif', $buyer->oblio_cif() ),
			'name'         => '' !== $buyer->company ? $buyer->company : $contact,
			'rc'           => $this->field( $order, 'rc', $buyer->registration ),
			'address'      => $address,
			'state'        => $state,
			'city'         => $city,
			'country'      => $this->resolve_country( $order ),
			'iban'         => $this->field( $order, 'iban', $buyer->iban ),
			'bank'         => $this->field( $order, 'bank', $buyer->bank ),
			'email'        => $order->get_billing_email(),
			'phone'        => $order->get_billing_phone(),
			'contact'      => $contact,
			'save'         => (bool) ( $ctx['save'] ?? true ),
			'autocomplete' => (int) ( $ctx['autocomplete'] ?? 0 ),
		);

		return (array) apply_filters( 'oblio_fgwoo_client_data', $client, $order );
	}

	private function field( WC_Order $order, string $field, string $resolved ): string {
		$value = apply_filters( 'oblio_fgwoo_client_field', null, $field, $order );
		return is_string( $value ) ? $value : $resolved;
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
