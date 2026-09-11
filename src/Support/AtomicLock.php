<?php
/**
 * Atomic, TTL-bounded mutex built on the wp_options unique key.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class AtomicLock {

	public static function acquire( string $option, int $ttl ): bool {
		global $wpdb;

		$now     = time();
		$expires = $now + max( 1, $ttl );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				(string) $expires
			)
		);

		if ( 1 === (int) $inserted ) {
			self::flush( $option );
			return true;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
		if ( null === $current || (int) $current > $now ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				(string) $expires,
				$option,
				(string) (int) $current
			)
		);

		self::flush( $option );
		return 1 === (int) $claimed;
	}

	public static function renew( string $option, int $ttl ): void {
		update_option( $option, (string) ( time() + max( 1, $ttl ) ), false );
	}

	public static function release( string $option ): void {
		delete_option( $option );
	}

	private static function flush( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
