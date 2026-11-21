<?php
/**
 * Explains an Oblio stock refusal with each short product's stock per gestiune.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Stock;

use FGSyncOblio\Api\OblioClient;
use Throwable;

final class StockShortage {

	private const MAX_LOOKUPS = 10;

	private OblioClient $client;

	private string $cif;

	public function __construct( OblioClient $client, string $cif ) {
		$this->client = $client;
		$this->cif    = $cif;
	}

	/**
	 * Escaped text to append to Oblio's message, '' when nothing can be added.
	 *
	 * @param array<string,mixed> $payload       Document data sent to Oblio.
	 * @param string              $oblio_message Oblio's refusal, used to look up the products it names first.
	 */
	public function describe( array $payload, string $oblio_message ): string {
		$workstation = (string) ( $payload['workStation'] ?? '' );
		$short       = array();
		$elsewhere   = '';

		foreach ( $this->needed( $payload, $oblio_message ) as $code => $line ) {
			$stock = $this->stock( $code, $line['type'] );
			if ( null === $stock ) {
				return '';
			}

			$issuing = 0.0;
			$total   = 0.0;
			foreach ( $stock as $location => $quantity ) {
				$total += $quantity;
				if ( $this->is_issuing( $location, $workstation, $line['management'] ) ) {
					$issuing += $quantity;
				}
			}
			if ( $issuing >= $line['quantity'] ) {
				continue;
			}

			$by_location = array();
			foreach ( $stock as $location => $quantity ) {
				$by_location[] = trim( str_replace( '|', ' / ', $location ), ' /' ) . ' ' . $this->format( $quantity );
			}
			$short[] = sprintf(
				/* translators: 1: product code, 2: quantity on the document, 3: stock per gestiune */
				__( '%1$s (necesar %2$s): %3$s', 'fgsync-oblio' ),
				$code,
				$this->format( $line['quantity'] ),
				empty( $by_location ) ? __( 'fără stoc în nicio gestiune', 'fgsync-oblio' ) : implode( ', ', $by_location )
			);
			if ( '' !== $line['management'] && $total >= $line['quantity'] ) {
				$elsewhere = trim( $workstation . ' / ' . $line['management'], ' /' );
			}
		}

		if ( empty( $short ) ) {
			return '';
		}

		/* translators: %s: stock of each short product */
		$text = sprintf( __( 'Stoc în Oblio: %s.', 'fgsync-oblio' ), implode( '; ', $short ) );
		if ( '' !== $elsewhere ) {
			/* translators: %s: gestiunea documents take stock from */
			$text .= ' ' . sprintf( __( 'Stocul lipsă este în altă gestiune: transferă-l în %s din Oblio, apoi emite din nou.', 'fgsync-oblio' ), $elsewhere );
		}
		return esc_html( $text );
	}

	/**
	 * Quantity per stocked product code, the products Oblio names first.
	 *
	 * @param array<string,mixed> $payload       Document data.
	 * @param string              $oblio_message Oblio's refusal.
	 * @return array<string,array{quantity:float,management:string,type:string,named:bool}>
	 */
	private function needed( array $payload, string $oblio_message ): array {
		$message = html_entity_decode( $oblio_message, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$needed  = array();

		foreach ( (array) ( $payload['products'] ?? array() ) as $line ) {
			$code     = (string) ( $line['code'] ?? '' );
			$quantity = (float) ( $line['quantity'] ?? 0 );
			$type     = (string) ( $line['productType'] ?? '' );
			if ( '' === $code || $quantity <= 0 || isset( $line['discount'] ) || 'Serviciu' === $type ) {
				continue;
			}
			$name              = (string) ( $line['name'] ?? '' );
			$needed[ $code ] ??= array(
				'quantity'   => 0.0,
				'management' => (string) ( $line['management'] ?? '' ),
				'type'       => $type,
				'named'      => false,
			);

			$needed[ $code ]['quantity'] += $quantity;
			$needed[ $code ]['named']     = $needed[ $code ]['named'] || ( '' !== $name && false !== mb_stripos( $message, $name ) );
		}

		$named = array_filter( $needed, static fn ( array $line ): bool => $line['named'] );
		return array_slice( $named + $needed, 0, self::MAX_LOOKUPS, true );
	}

	/**
	 * Oblio stock per "workStation|management", null when the lookup failed.
	 *
	 * @param string $code Product code.
	 * @param string $type Product type on the document line.
	 * @return array<string,float>|null
	 */
	private function stock( string $code, string $type ): ?array {
		try {
			$rows = $this->client->nomenclature( 'products', $this->cif, array( 'code' => $code ) );
		} catch ( Throwable $exception ) {
			unset( $exception );
			return null;
		}

		$stock = array();
		foreach ( $rows as $row ) {
			$row_code = (string) ( $row['code'] ?? $code );
			$row_type = (string) ( $row['productType'] ?? $type );
			if ( $row_code !== $code || ( '' !== $type && $row_type !== $type ) ) {
				continue;
			}
			foreach ( (array) ( $row['stock'] ?? array() ) as $entry ) {
				$location           = ( $entry['workStation'] ?? '' ) . '|' . ( $entry['management'] ?? '' );
				$stock[ $location ] = ( $stock[ $location ] ?? 0.0 ) + (float) ( $entry['quantity'] ?? 0 );
			}
			break;
		}
		return $stock;
	}

	private function is_issuing( string $location, string $workstation, string $management ): bool {
		list( $location_workstation, $location_management ) = array_pad( explode( '|', $location, 2 ), 2, '' );

		return ( '' === $workstation || $location_workstation === $workstation )
			&& ( '' === $management || $location_management === $management );
	}

	private function format( float $quantity ): string {
		return rtrim( rtrim( number_format( $quantity, 3, ',', '' ), '0' ), ',' );
	}
}
