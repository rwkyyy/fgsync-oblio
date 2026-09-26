<?php
/**
 * Atomic, TTL-bounded mutex built on the wp_options unique key.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Support;

final class AtomicLock {

	public static function acquire( string $option, int $ttl ): ?string {
		global $wpdb;

		$now     = time();
		$expires = $now + max( 1, $ttl );
		$owner   = wp_generate_password( 20, false );
		$value   = self::encode( $expires, $owner );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$inserted = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
				$option,
				$value
			)
		);

		if ( 1 === (int) $inserted ) {
			self::flush( $option );
			return $owner;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
		if ( null === $current || self::expires_of( (string) $current ) > $now ) {
			return null;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$claimed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$option,
				(string) $current
			)
		);

		self::flush( $option );
		return 1 === (int) $claimed ? $owner : null;
	}

	public static function renew( string $option, string $owner, int $ttl ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
		if ( null === $current || self::owner_of( (string) $current ) !== $owner ) {
			return false;
		}

		$value = self::encode( time() + max( 1, $ttl ), $owner );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$renewed = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
				$value,
				$option,
				(string) $current
			)
		);

		self::flush( $option );
		return 1 === (int) $renewed;
	}

	public static function release( string $option, string $owner ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$current = $wpdb->get_var(
			$wpdb->prepare( "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s", $option )
		);
		if ( null === $current || self::owner_of( (string) $current ) !== $owner ) {
			return false;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				(string) $current
			)
		);

		self::flush( $option );
		return 1 === (int) $deleted;
	}

	public static function force_release( string $option ): void {
		delete_option( $option );
	}

	/**
	 * CAS-delete for a plain (non-lock-encoded) option, e.g. a token mirror kept
	 * alongside a lock. Deletes only if the option's current value still equals
	 * $expected, so a value already overwritten by someone else is left alone.
	 *
	 * @param string $option   Option name.
	 * @param string $expected Value the option must currently hold to be deleted.
	 */
	public static function delete_if_matches( string $option, string $expected ): bool {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name = %s AND option_value = %s",
				$option,
				$expected
			)
		);

		self::flush( $option );
		return 1 === (int) $deleted;
	}

	public static function is_locked( string $option ): bool {
		$value = get_option( $option, false );
		return false !== $value && self::expires_of( (string) $value ) > time();
	}

	/**
	 * @param string $option Option name.
	 * @return int|null Seconds until the lock expires, or null if not currently locked.
	 */
	public static function seconds_remaining( string $option ): ?int {
		$value = get_option( $option, false );
		if ( false === $value ) {
			return null;
		}
		$remaining = self::expires_of( (string) $value ) - time();
		return $remaining > 0 ? $remaining : null;
	}

	private static function encode( int $expires, string $owner ): string {
		return $expires . '|' . $owner;
	}

	private static function expires_of( string $value ): int {
		return (int) strtok( $value, '|' );
	}

	private static function owner_of( string $value ): string {
		$parts = explode( '|', $value, 2 );
		return $parts[1] ?? '';
	}

	private static function flush( string $option ): void {
		wp_cache_delete( $option, 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
