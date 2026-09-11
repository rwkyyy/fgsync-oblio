<?php
/**
 * Global pacer for outbound Oblio document requests.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class RateLimiter {

	public const DOC_OPTION = 'oblio_fgwoo_doc_slot';

	private const MAX_ATTEMPTS = 25;

	private const MAX_WAIT = 600;

	private SlotStore $store;

	private int $spacing;

	private $clock;

	private $sleeper;

	public function __construct( ?SlotStore $store = null, int $spacing = 4, ?callable $clock = null, ?callable $sleeper = null ) {
		$this->store   = $store ?? new WpdbSlotStore( self::DOC_OPTION );
		$this->spacing = max( 1, $spacing );
		$this->clock   = $clock ?? 'time';
		$this->sleeper = $sleeper ?? static function ( int $seconds ): void {
			if ( $seconds > 0 ) {
				sleep( $seconds );
			}
		};
	}

	public static function plan( int $now, int $current, int $spacing ): array {
		$fire_at = max( $now, $current );
		return array( $fire_at, $fire_at + $spacing );
	}

	public function reserve(): int {
		$now     = (int) ( $this->clock )();
		$fire_at = $now;

		for ( $attempt = 0; $attempt < self::MAX_ATTEMPTS; $attempt++ ) {
			$raw    = $this->store->read();
			$stored = null === $raw ? 0 : $raw;

			$current = $stored > $now + self::MAX_WAIT ? $now : $stored;

			list( $fire_at, $next_floor ) = self::plan( $now, $current, $this->spacing );

			$reserved = null === $raw
				? $this->store->insert( $next_floor )
				: $this->store->compare_and_set( $stored, $next_floor );

			if ( $reserved ) {
				return $fire_at - $now;
			}
		}

		return max( $this->spacing, $fire_at - $now );
	}

	public function peek(): int {
		$now     = (int) ( $this->clock )();
		$raw     = $this->store->read();
		$current = null === $raw ? 0 : $raw;
		if ( $current > $now + self::MAX_WAIT ) {
			return 0;
		}
		return max( 0, $current - $now );
	}

	public function throttle(): void {
		$wait = $this->reserve();
		if ( $wait > 0 ) {
			( $this->sleeper )( $wait );
		}
	}
}
