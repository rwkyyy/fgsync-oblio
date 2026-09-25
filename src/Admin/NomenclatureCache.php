<?php
/**
 * Caches Oblio nomenclature (companies, series, warehouses) for the settings UI.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Api\ClientFactory;
use FGSyncOblio\Api\Exception\ApiException;
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
		add_action( Scheduler::HOOK_NOMENCLATURE, array( $this, 'refresh_with_backoff' ) );
	}

	public function on_cif_change(): void {
		$this->refresh();
		$this->scheduler->enqueue_nomenclature_refresh();
	}

	public function prime(): bool {
		$cif = (string) $this->settings->get( 'cif' );
		if ( '' === $cif || ! $this->settings->has_credentials() ) {
			return false;
		}

		try {
			$client = $this->factory->create();
			set_transient( self::SERIES_TRANSIENT, (array) $client->series( $cif ), self::TTL );
			set_transient( self::MANAGEMENT_TRANSIENT, (array) $client->management( $cif ), self::TTL );
		} catch ( ApiException $exception ) {
			$this->logger->error( 'Nomenclature refresh failed: ' . $exception->status_message() );
			return false;
		}

		$this->logger->debug( 'Nomenclature refreshed (series + management)' );
		return true;
	}

	/**
	 * Stale-while-revalidate for page render: never blocks the current
	 * request on Oblio. If the cache is missing, the actual refresh runs in
	 * a background Action Scheduler job - this load just shows whatever's
	 * cached (possibly nothing). A failed refresh sets a short backoff so a
	 * down API isn't retried on every single page load.
	 */
	public function ensure_fresh(): void {
		if ( false !== get_transient( self::SERIES_TRANSIENT ) && false !== get_transient( self::MANAGEMENT_TRANSIENT ) ) {
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

	public function refresh(): void {
		delete_transient( self::SERIES_TRANSIENT );
		delete_transient( self::MANAGEMENT_TRANSIENT );
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
