<?php
/**
 * Minimal lazy service container.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class Container {

	private array $factories = array();

	private array $resolved = array();

	public function set( string $id, callable $factory ): void {
		$this->factories[ $id ] = $factory;
	}

	public function get( string $id ): object {
		if ( isset( $this->resolved[ $id ] ) ) {
			return $this->resolved[ $id ];
		}

		if ( ! isset( $this->factories[ $id ] ) ) {
			throw new \InvalidArgumentException( sprintf( 'Unknown service: %s', esc_html( $id ) ) );
		}

		$this->resolved[ $id ] = ( $this->factories[ $id ] )( $this );

		return $this->resolved[ $id ];
	}

	public function has( string $id ): bool {
		return isset( $this->factories[ $id ] );
	}
}
