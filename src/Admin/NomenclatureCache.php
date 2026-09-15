<?php
/**
 * Caches Oblio nomenclature (companies, series, warehouses) for the settings UI.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Api\Exception\ApiException;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
final class NomenclatureCache {

	private const SERIES_TRANSIENT     = 'oblio_fgwoo_series';
	private const MANAGEMENT_TRANSIENT = 'oblio_fgwoo_management';

	private const TTL = WEEK_IN_SECONDS;

	private ClientFactory $factory;

	private Settings $settings;

	private Logger $logger;

	public function __construct( ClientFactory $factory, Settings $settings, Logger $logger ) {
		$this->factory  = $factory;
		$this->settings = $settings;
		$this->logger   = $logger;
	}

	public function register(): void {
		add_action( 'update_option_oblio_fgwoo_cif', array( $this, 'on_cif_change' ) );
		add_action( 'add_option_oblio_fgwoo_cif', array( $this, 'on_cif_change' ) );
	}

	public function on_cif_change(): void {
		$this->refresh();
		add_action( 'shutdown', array( $this, 'prime' ) );
	}

	public function prime(): void {
		$cif = (string) $this->settings->get( 'cif' );
		if ( '' === $cif || ! $this->settings->has_credentials() ) {
			return;
		}

		try {
			$client = $this->factory->create();
			set_transient( self::SERIES_TRANSIENT, (array) $client->series( $cif ), self::TTL );
			set_transient( self::MANAGEMENT_TRANSIENT, (array) $client->management( $cif ), self::TTL );
		} catch ( ApiException $exception ) {
			$this->logger->error( 'Nomenclature refresh failed: ' . $exception->status_message() );
			return;
		}

		$this->logger->debug( 'Nomenclature refreshed (series + management)' );
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
