<?php
/**
 * Storage contract for the document rate limiter's shared slot.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

interface SlotStore {

	public function read(): ?int;

	public function insert( int $value ): bool;

	public function compare_and_set( int $expected, int $value ): bool;
}
