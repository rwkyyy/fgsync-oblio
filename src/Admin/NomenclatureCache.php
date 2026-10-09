<?php
/**
 * Caches Oblio nomenclature (companies, series, warehouses, VAT categories) for the settings UI.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
use FGSyncOblio\Api\OblioClient;
use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
final class NomenclatureCache {

	private const SERIES_TRANSIENT     = 'oblio_fgwoo_series';
	private const MANAGEMENT_TRANSIENT = 'oblio_fgwoo_management';
	private const REFRESH_BACKOFF      = 'oblio_fgwoo_nomenclature_refresh_backoff';

	private const TTL             = WEEK_IN_SECONDS;
	private const BACKOFF_SECONDS = 5 * MINUTE_IN_SECONDS;

	private ClientFactory $factory;

	private Settings $settings;

	private Logger $logger;

	private Scheduler $scheduler;

	public function __construct( ClientFactory $factory, Settings $settings, Logger $logger, Scheduler $scheduler ) {
		$this->factory   = $factory;
		$this->settings  = $settings;
		$this->logger    = $logger;
		$this->scheduler = $scheduler;
	}

	public function register(): void {
		add_action( 'update_option_oblio_fgwoo_cif', array( $this, 'on_cif_change' ) );
		add_action( 'add_option_oblio_fgwoo_cif', array( $this, 'on_cif_change' ) );
		foreach ( array( 'email', 'secret' ) as $credential ) {
			add_action( 'update_option_oblio_fgwoo_' . $credential, array( $this, 'refresh' ) );
		}
		add_action( Scheduler::HOOK_NOMENCLATURE, array( $this, 'refresh_with_backoff' ) );
	}

	public function on_cif_change(): void {
		$this->refresh();
		if ( ! $this->prime() ) {
			$this->scheduler->enqueue_nomenclature_refresh();
		}
	}

	/**
	 * @param OblioClient|null $client Client to load with; the saved credentials when null.
	 */
	public function prime( ?OblioClient $client = null ): bool {
		$cif = (string) $this->settings->get( 'cif' );
		if ( '' === $cif || ( null === $client && ! $this->settings->has_credentials() ) ) {
			return false;
		}

		$client ??= $this->factory->create();
		$loaded   = $this->load( self::SERIES_TRANSIENT, 'series', static fn (): array => (array) $client->series( $cif ), self::TTL );
		if ( false === self::uses_stock() ) {
			set_transient( self::MANAGEMENT_TRANSIENT, array(), self::TTL );
		} else {
			$loaded = $this->load( self::MANAGEMENT_TRANSIENT, 'management', static fn (): array => (array) $client->management( $cif ), self::TTL ) && $loaded;
		}
		$loaded = $this->load( VatCategories::TRANSIENT, 'VAT categories', static fn (): array => VatCategories::normalize( (array) $client->vat_rates( $cif ) ), DAY_IN_SECONDS ) && $loaded;

		if ( $loaded ) {
			$this->logger->debug( 'Nomenclature refreshed (series, management, VAT categories)' );
		}
		return $loaded;
	}

	/**
	 * Each list is cached on its own, so one failing call doesn't leave the
	 * others empty. Oblio answers 400 for an account without warehouses,
	 * which is a valid setup: it's cached as an empty list.
	 *
	 * @param string   $transient Cache key.
	 * @param string   $label     Name for the log.
	 * @param callable $fetch     Returns the list from Oblio.
	 * @param int      $ttl       Cache lifetime.
	 */
	private function load( string $transient, string $label, callable $fetch, int $ttl ): bool {
		try {
			set_transient( $transient, $fetch(), $ttl );
			return true;
		} catch ( ApiException $exception ) {
			if ( self::MANAGEMENT_TRANSIENT === $transient && 400 === $exception->http_status() ) {
				set_transient( $transient, array(), $ttl );
				$this->logger->info( 'No Oblio warehouses for this company: ' . $exception->status_message() );
				return true;
			}
			$this->logger->error( sprintf( 'Nomenclature refresh failed (%s): %s', $label, $exception->status_message() ) );
			return false;
		}
	}

	/**
	 * Stale-while-revalidate for page render: never blocks the current
	 * request on Oblio. If the cache is missing, the actual refresh runs in
	 * a background Action Scheduler job - this load just shows whatever's
	 * cached (possibly nothing). A failed refresh sets a short backoff so a
	 * down API isn't retried on every single page load.
	 */
	public function ensure_fresh(): void {
		if ( $this->is_complete() ) {
			return;
		}
		if ( false !== get_transient( self::REFRESH_BACKOFF ) ) {
			return;
		}
		if ( '' === (string) $this->settings->get( 'cif' ) || ! $this->settings->has_credentials() ) {
			return;
		}
		$this->scheduler->enqueue_nomenclature_refresh();
	}

	/**
	 * Oblio's "useStock" flag for the selected company, from the company list
	 * loaded by „Preia ultimele date”; null when Oblio didn't send it.
	 */
	public static function uses_stock(): ?bool {
		$flags = get_option( ConnectionTest::USE_STOCK_OPTION, array() );
		$cif   = (string) get_option( 'oblio_fgwoo_cif', '' );
		return is_array( $flags ) && isset( $flags[ $cif ] ) ? (bool) $flags[ $cif ] : null;
	}

	/**
	 * True once Oblio has said the company doesn't use stock, or has answered
	 * with no warehouses; false while that's unknown.
	 */
	public static function has_no_warehouses(): bool {
		if ( false === self::uses_stock() ) {
			return true;
		}
		$cached = get_transient( self::MANAGEMENT_TRANSIENT );
		return is_array( $cached ) && empty( $cached );
	}

	public function is_complete(): bool {
		return false !== get_transient( self::SERIES_TRANSIENT ) && false !== get_transient( self::MANAGEMENT_TRANSIENT ) && false !== get_transient( VatCategories::TRANSIENT );
	}

	public function refresh_with_backoff(): void {
		if ( ! $this->prime() ) {
			set_transient( self::REFRESH_BACKOFF, 1, self::BACKOFF_SECONDS );
		}
	}

	public function companies(): array {
		$companies = get_option( ConnectionTest::COMPANIES_OPTION, array() );
		return is_array( $companies ) ? $companies : array();
	}

	public function series( string $type ): array {
		$all     = $this->all_series();
		$options = array();
		foreach ( $all as $series ) {
			if ( ( $series['type'] ?? '' ) === $type ) {
				$name             = (string) ( $series['name'] ?? '' );
				$options[ $name ] = $name;
			}
		}
		return $options;
	}

	public function locations(): array {
		$options = array();
		foreach ( $this->management() as $row ) {
			$workstation     = (string) ( $row['workStation'] ?? '' );
			$management      = (string) ( $row['management'] ?? '' );
			$key             = $workstation . '|' . $management;
			$options[ $key ] = trim( $workstation . ' / ' . $management, ' /' );
		}
		return $options;
	}

	public function workstations(): array {
		$options = array();
		foreach ( $this->management() as $row ) {
			$name = (string) ( $row['workStation'] ?? '' );
			if ( '' !== $name ) {
				$options[ $name ] = $name;
			}
		}
		return $options;
	}

	public function managements(): array {
		$options = array();
		foreach ( $this->management() as $row ) {
			$name = (string) ( $row['management'] ?? '' );
			if ( '' !== $name ) {
				$options[ $name ] = $name;
			}
		}
		return $options;
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function vat_categories(): array {
		return $this->read( VatCategories::TRANSIENT );
	}

	public function refresh(): void {
		delete_transient( self::SERIES_TRANSIENT );
		delete_transient( self::MANAGEMENT_TRANSIENT );
		delete_transient( VatCategories::TRANSIENT );
	}

	private function all_series(): array {
		return $this->read( self::SERIES_TRANSIENT );
	}

	private function management(): array {
		return $this->read( self::MANAGEMENT_TRANSIENT );
	}

	private function read( string $transient ): array {
		$cached = get_transient( $transient );
		return is_array( $cached ) ? $cached : array();
	}
}
