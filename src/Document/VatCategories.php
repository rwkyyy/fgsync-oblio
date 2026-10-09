<?php
/**
 * Resolves line VAT rates to the Oblio account's own VAT categories.
 *
 * Oblio picks a category by percentage and silently maps a nameless 19% to the
 * default 21% category, so every taxed line is sent with the exact name and
 * percentage of a category the account has.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Document;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
final class VatCategories {

	public const TRANSIENT = 'oblio_fgwoo_vat_rates';

	public const OBLIO_SETTINGS_URL = 'https://www.oblio.eu/account/cote_tva';

	// Internal per-line matching tolerance set by LineVat; never sent to Oblio.
	public const TOLERANCE_FIELD = '_vatTolerance';

	private const TTL = DAY_IN_SECONDS;

	private const DEFAULT_TOLERANCE = 0.01;

	private ClientFactory $factory;

	private Settings $settings;

	private Logger $logger;

	private bool $refreshed = false;

	/** @var array<int,array{name:string,percent:float|int,default:bool}>|null */
	private ?array $loaded = null;

	public function __construct( ClientFactory $factory, Settings $settings, Logger $logger ) {
		$this->factory  = $factory;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	/**
	 * Fills in vatName/vatPercentage on every line priced with a rate but no
	 * category name. Named lines (SDD) and lines without a rate (taxes off) are
	 * left to Oblio.
	 *
	 * @param array<int,array<string,mixed>> $products Document lines.
	 * @return array<int,array<string,mixed>>
	 * @throws DocumentException When the account has no category for a rate.
	 */
	public function apply( array $products ): array {
		foreach ( $products as $index => $line ) {
			unset( $products[ $index ][ self::TOLERANCE_FIELD ] );
			if ( ! array_key_exists( 'vatPercentage', $line ) || null === $line['vatPercentage'] || empty( $this->all() ) ) {
				continue;
			}

			$name = (string) ( $line['vatName'] ?? '' );
			if ( '' !== $name ) {
				$products[ $index ]['vatName'] = $this->named( $name, (float) $line['vatPercentage'] );
				continue;
			}

			$tolerance = (float) ( $line[ self::TOLERANCE_FIELD ] ?? self::DEFAULT_TOLERANCE );
			$category  = $this->find( (float) $line['vatPercentage'], $tolerance );
			if ( null === $category ) {
				throw new DocumentException(
					sprintf(
						/* translators: 1: VAT percentage, 2: product line name. */
						esc_html__( 'Contul Oblio nu are o cotă TVA de %1$s%% (linia „%2$s”). Adaug-o în Oblio → Setări → Cote TVA cu numele „%1$s”, apoi emite din nou documentul.', 'fgsync-oblio' ),
						esc_html( self::rate_label( (float) $line['vatPercentage'] ) ),
						esc_html( (string) ( $line['name'] ?? '' ) )
					)
				);
			}

			$products[ $index ]['vatName']       = $category['name'];
			$products[ $index ]['vatPercentage'] = $category['percent'];
		}

		return $products;
	}

	/**
	 * The account's exact spelling of a configured category name. Trailing
	 * spaces are ignored when comparing, as Oblio does, because WooCommerce
	 * trims saved settings while Oblio pads duplicate names with them.
	 *
	 * @param string $name    Configured category name.
	 * @param float  $percent Line rate.
	 * @throws DocumentException When the account has no such category.
	 */
	private function named( string $name, float $percent ): string {
		$category = $this->by_name( $this->all(), $name, $percent );
		if ( null === $category && ! $this->refreshed ) {
			$category = $this->by_name( $this->fetch(), $name, $percent );
		}
		if ( null === $category ) {
			throw new DocumentException(
				sprintf(
					/* translators: 1: VAT category name, 2: VAT percentage. */
					esc_html__( 'Categoria TVA „%1$s” (%2$s%%) nu există în contul Oblio. Alege alta în FGSync → Setări → TVA sau adaug-o în Oblio → Setări → Cote TVA.', 'fgsync-oblio' ),
					esc_html( $name ),
					esc_html( (string) $percent )
				)
			);
		}
		return $category['name'];
	}

	/**
	 * @param array<int,array{name:string,percent:float|int,default:bool}> $categories Account categories.
	 * @param string                                                       $name       Category name.
	 * @param float                                                        $percent    Line rate.
	 * @return array{name:string,percent:float|int,default:bool}|null
	 */
	private function by_name( array $categories, string $name, float $percent ): ?array {
		foreach ( $categories as $category ) {
			if ( rtrim( $category['name'] ) === rtrim( $name ) && abs( (float) $category['percent'] - $percent ) < 0.0001 ) {
				return $category;
			}
		}
		return null;
	}

	/**
	 * Taxed WooCommerce rates the account has no category for, grouped by
	 * rate. Unknown (empty) categories report nothing.
	 *
	 * @param array<int|string,mixed>                                         $categories Cached account categories.
	 * @param array<int,array{id:int,country:string,state:string,rate:float}> $rows       WooCommerce tax rate rows.
	 * @return array<string,array<int,string>> Rate label => countries ('' for all countries).
	 */
	public static function missing( array $categories, array $rows ): array {
		$categories = self::normalize( $categories );
		if ( empty( $categories ) ) {
			return array();
		}

		$missing = array();
		foreach ( $rows as $row ) {
			if ( $row['rate'] <= 0 ) {
				continue;
			}
			foreach ( $categories as $category ) {
				if ( abs( (float) $category['percent'] - $row['rate'] ) < 0.0001 ) {
					continue 2;
				}
			}
			$label               = self::rate_label( $row['rate'] );
			$missing[ $label ]   = $missing[ $label ] ?? array();
			$missing[ $label ][] = $row['country'];
		}

		uksort( $missing, static fn ( $left, $right ): int => (float) $left <=> (float) $right );
		return array_map( static fn ( array $countries ): array => array_values( array_unique( $countries ) ), $missing );
	}

	/**
	 * A rate as Oblio recommends naming its category: 25.5, 21, 5.5.
	 *
	 * @param float $rate VAT percentage.
	 */
	public static function rate_label( float $rate ): string {
		return rtrim( rtrim( number_format( $rate, 2, '.', '' ), '0' ), '.' );
	}

	/**
	 * "Oblio → Setări → Cote TVA" as a link to that page, for admin screens.
	 */
	public static function settings_link(): string {
		return sprintf( '<a href="%s" target="_blank" rel="noopener">%s</a>', esc_url( self::OBLIO_SETTINGS_URL ), esc_html__( 'Oblio → Setări → Cote TVA', 'fgsync-oblio' ) );
	}

	/**
	 * Account categories at 0%, for the untaxed-lines setting.
	 *
	 * @param array<int|string,mixed> $categories Cached account categories.
	 * @return array<string,string> Name => label.
	 */
	public static function untaxed_options( array $categories ): array {
		$options = array();
		foreach ( $categories as $category ) {
			if ( is_array( $category ) && isset( $category['name'], $category['percent'] ) && 0.0 === (float) $category['percent'] ) {
				$options[ rtrim( (string) $category['name'] ) ] = rtrim( (string) $category['name'] ) . ' (0%)';
			}
		}
		return $options;
	}

	/**
	 * @return array<int,array{name:string,percent:float|int,default:bool}>
	 */
	public function all(): array {
		if ( null === $this->loaded ) {
			$cached       = get_transient( self::TRANSIENT );
			$this->loaded = is_array( $cached ) && ! empty( $cached ) ? $cached : $this->fetch();
		}
		return $this->loaded;
	}

	/**
	 * Looks the rate up in the cached list, then once more in a fresh one in
	 * case the category was added in Oblio since.
	 *
	 * @param float $percent   Line rate.
	 * @param float $tolerance Allowed distance in percentage points.
	 * @return array{name:string,percent:float|int,default:bool}|null
	 */
	private function find( float $percent, float $tolerance ): ?array {
		$category = $this->nearest( $this->all(), $percent, $tolerance );
		if ( null === $category && ! $this->refreshed ) {
			$category = $this->nearest( $this->fetch(), $percent, $tolerance );
		}
		return $category;
	}

	/**
	 * Closest category within the tolerance; the account default wins a tie,
	 * then Oblio's own list order.
	 *
	 * @param array<int,array{name:string,percent:float|int,default:bool}> $categories Account categories.
	 * @param float                                                        $percent    Line rate.
	 * @param float                                                        $tolerance  Allowed distance in percentage points.
	 * @return array{name:string,percent:float|int,default:bool}|null
	 */
	private function nearest( array $categories, float $percent, float $tolerance ): ?array {
		$best      = null;
		$best_diff = $tolerance;
		foreach ( $categories as $category ) {
			$diff = abs( (float) $category['percent'] - $percent );
			if ( $diff > $tolerance + 0.0001 ) {
				continue;
			}
			if ( null === $best || $diff < $best_diff - 0.0001 || ( abs( $diff - $best_diff ) <= 0.0001 && $category['default'] && ! $best['default'] ) ) {
				$best      = $category;
				$best_diff = $diff;
			}
		}
		return $best;
	}

	/**
	 * @return array<int,array{name:string,percent:float|int,default:bool}>
	 */
	private function fetch(): array {
		$this->refreshed = true;
		$this->loaded    = array();

		$categories = self::normalize( $this->factory->create()->vat_rates( (string) $this->settings->get( 'cif' ) ) );

		if ( empty( $categories ) ) {
			$this->logger->warning( 'Oblio returned no VAT categories; lines keep the rate without a category name' );
			return array();
		}

		set_transient( self::TRANSIENT, $categories, self::TTL );
		$this->loaded = $categories;
		return $categories;
	}

	/**
	 * @param array<int|string,mixed> $rows Raw vat_rates rows.
	 * @return array<int,array{name:string,percent:float|int,default:bool}>
	 */
	public static function normalize( array $rows ): array {
		$categories = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'], $row['percent'] ) || ! is_numeric( $row['percent'] ) ) {
				continue;
			}
			$categories[] = array(
				'name'    => (string) $row['name'],
				'percent' => 0 + $row['percent'],
				'default' => ! empty( $row['default'] ),
			);
		}
		return $categories;
	}
}
