<?php
/**
 * The bundled EU VAT rate table (data/eu-vat-rates.json).
 *
 * The file is refreshed monthly by the vat-rates GitHub workflow from
 * api.vatcomply.com and reviewed as a pull request; the plugin never calls
 * that API itself.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tax;

use InvalidArgumentException;
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- messages go to the plugin log and the update workflow log, never to a page.
final class EuVatRates {

	public const SOURCE = 'https://api.vatcomply.com/vat_rates';

	private const MEMBER_STATES = 27;

	private const MAX_RATE = 30.0;

	/** @var array{source:string,fetched_at:string,rates:array<string,array<string,mixed>>}|null */
	private static ?array $bundled = null;

	/**
	 * Builds the bundle from a vatcomply response: member states only, keyed
	 * by WooCommerce country code (Greece is EL in VAT data, GR in WooCommerce).
	 *
	 * @param array<int,mixed> $items      vatcomply /vat_rates response.
	 * @param string           $fetched_at Date of the fetch (Y-m-d).
	 * @return array{source:string,fetched_at:string,rates:array<string,array{standard:float,reduced:array<int,float>,super_reduced:float|null,parking:float|null}>}
	 */
	public static function from_vatcomply( array $items, string $fetched_at ): array {
		$rates = array();
		foreach ( $items as $item ) {
			if ( ! is_array( $item ) || true !== ( $item['member_state'] ?? null ) || ! is_string( $item['country_code'] ?? null ) ) {
				continue;
			}
			$country           = 'EL' === $item['country_code'] ? 'GR' : $item['country_code'];
			$rates[ $country ] = array(
				'standard'      => self::number( $item['standard_rate'] ?? null ),
				'reduced'       => array_map( array( self::class, 'number' ), is_array( $item['reduced_rates'] ?? null ) ? array_values( $item['reduced_rates'] ) : array() ),
				'super_reduced' => self::optional( $item['super_reduced_rate'] ?? null ),
				'parking'       => self::optional( $item['parking_rate'] ?? null ),
			);
		}
		ksort( $rates );

		return array(
			'source'     => self::SOURCE,
			'fetched_at' => $fetched_at,
			'rates'      => $rates,
		);
	}

	/**
	 * @param array<string,mixed> $data Bundle contents.
	 * @throws InvalidArgumentException When the table is incomplete or holds an implausible rate.
	 */
	public static function validate( array $data ): void {
		$rates = $data['rates'] ?? null;
		if ( ! is_array( $rates ) || self::MEMBER_STATES !== count( $rates ) ) {
			throw new InvalidArgumentException( sprintf( 'Expected %d EU member states, got %d.', self::MEMBER_STATES, is_array( $rates ) ? count( $rates ) : 0 ) );
		}
		if ( ! isset( $rates['RO'] ) ) {
			throw new InvalidArgumentException( 'Romania is missing from the EU VAT rates.' );
		}
		if ( ! is_string( $data['fetched_at'] ?? null ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $data['fetched_at'] ) ) {
			throw new InvalidArgumentException( 'Missing or malformed fetched_at date.' );
		}
		foreach ( $rates as $country => $rate ) {
			$all = array_merge( array( $rate['standard'] ?? null ), (array) ( $rate['reduced'] ?? array() ), array_filter( array( $rate['super_reduced'] ?? null, $rate['parking'] ?? null ), 'is_numeric' ) );
			foreach ( $all as $value ) {
				if ( ! is_numeric( $value ) || (float) $value < 0 || (float) $value > self::MAX_RATE ) {
					throw new InvalidArgumentException( sprintf( 'Implausible VAT rate for %s: %s', $country, is_scalar( $value ) ? (string) $value : gettype( $value ) ) );
				}
			}
			if ( (float) $rate['standard'] <= 0 ) {
				throw new InvalidArgumentException( sprintf( 'Standard VAT rate for %s must be positive.', $country ) );
			}
		}
	}

	/**
	 * Whether two bundles hold the same rates, ignoring when they were fetched.
	 *
	 * @param array<string,mixed> $left  Bundle.
	 * @param array<string,mixed> $right Bundle.
	 */
	public static function same_rates( array $left, array $right ): bool {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- also runs in the update script, outside WordPress.
		return json_encode( $left['rates'] ?? null ) === json_encode( $right['rates'] ?? null );
	}

	/**
	 * @return array{source:string,fetched_at:string,rates:array<string,array<string,mixed>>}
	 * @throws InvalidArgumentException When the bundled file is missing or invalid.
	 */
	public static function bundled(): array {
		if ( null !== self::$bundled ) {
			return self::$bundled;
		}
		$path = dirname( __DIR__, 2 ) . '/data/eu-vat-rates.json';
		$data = is_readable( $path ) ? json_decode( (string) file_get_contents( $path ), true ) : null; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local bundled file.
		if ( ! is_array( $data ) ) {
			throw new InvalidArgumentException( 'The bundled EU VAT rate file is missing or unreadable.' );
		}
		self::validate( $data );
		self::$bundled = $data;
		return $data;
	}

	/**
	 * @param mixed $value Rate from the API.
	 */
	private static function number( $value ): float {
		return is_numeric( $value ) ? (float) $value : -1.0;
	}

	/**
	 * @param mixed $value Optional rate from the API.
	 */
	private static function optional( $value ): ?float {
		return is_numeric( $value ) ? (float) $value : null;
	}
}
