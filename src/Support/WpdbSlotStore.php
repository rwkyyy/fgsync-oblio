<?php
/**
 * Slot store backed by a single wp_options row with atomic compare-and-swap.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class WpdbSlotStore implements SlotStore {

	private string $option;

	public function __construct( string $option ) {
		$this->option = $option;
	}

	public function read(): ?int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
				$this->option
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return null === $value ? null : (int) $value;
	}

	public function insert( int $value ): bool {
		global $wpdb;
		$suppressed = $wpdb->suppress_errors( true );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$this->option,
				(string) $value
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->suppress_errors( $suppressed );
		return is_int( $rows ) && $rows > 0;
	}

	public function compare_and_set( int $expected, int $value ): bool {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s"
					. ' WHERE option_name = %s AND option_value = %s',
				(string) $value,
				$this->option,
				(string) $expected
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return 1 === (int) $rows;
	}
}
