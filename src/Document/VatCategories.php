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
			if ( ! array_key_exists( 'vatPercentage', $line ) || null === $line['vatPercentage'] || '' !== (string) ( $line['vatName'] ?? '' ) ) {
				continue;
			}
			if ( empty( $this->all() ) ) {
				continue;
			}

			$tolerance = (float) ( $line[ self::TOLERANCE_FIELD ] ?? self::DEFAULT_TOLERANCE );
			$category  = $this->find( (float) $line['vatPercentage'], $tolerance );
			if ( null === $category ) {
				throw new DocumentException(
					sprintf(
						/* translators: 1: VAT percentage, 2: product line name. */
						esc_html__( 'Contul Oblio nu are o cotă TVA de %1$s%% (linia „%2$s”). Adaug-o în Oblio → Setări → Cote TVA, apoi emite din nou documentul.', 'fgsync-oblio' ),
						esc_html( (string) $line['vatPercentage'] ),
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

		$categories = array();
		foreach ( $this->factory->create()->vat_rates( (string) $this->settings->get( 'cif' ) ) as $row ) {
			if ( ! is_array( $row ) || ! isset( $row['name'], $row['percent'] ) || ! is_numeric( $row['percent'] ) ) {
				continue;
			}
			$categories[] = array(
				'name'    => (string) $row['name'],
				'percent' => 0 + $row['percent'],
				'default' => ! empty( $row['default'] ),
			);
		}

		if ( empty( $categories ) ) {
			$this->logger->warning( 'Oblio returned no VAT categories; lines keep the rate without a category name' );
			return array();
		}

		set_transient( self::TRANSIENT, $categories, self::TTL );
		$this->loaded = $categories;
		return $categories;
	}
}
