<?php
/**
 * Inspects the WordPress hook system for third-party overrides.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Extensibility;

final class HookInspector {

	private HookRegistry $registry;

	public function __construct( HookRegistry $registry ) {
		$this->registry = $registry;
	}

	public function overrides_for( string $hook ): array {
		global $wp_filter;

		if ( empty( $wp_filter[ $hook ] ) || ! isset( $wp_filter[ $hook ]->callbacks ) ) {
			return array();
		}

		$plugin_dir = defined( 'FGSYNC_OBLIO_DIR' ) ? FGSYNC_OBLIO_DIR : '';
		$overrides  = array();

		foreach ( $wp_filter[ $hook ]->callbacks as $priority => $callbacks ) {
			foreach ( $callbacks as $registered ) {
				$described = $this->describe( $registered['function'] ?? null );
				if ( null === $described ) {
					continue;
				}

				if ( '' !== $plugin_dir && 0 === strpos( $described['file'], $plugin_dir ) ) {
					continue;
				}
				$overrides[] = array( 'priority' => (int) $priority ) + $described;
			}
		}

		return $overrides;
	}

	public function all_overrides(): array {
		$result = array();
		foreach ( array_keys( $this->registry->all() ) as $hook ) {
			$overrides = $this->overrides_for( $hook );
			if ( ! empty( $overrides ) ) {
				$result[ $hook ] = $overrides;
			}
		}
		return $result;
	}

	public function has_any_override(): bool {
		foreach ( array_keys( $this->registry->all() ) as $hook ) {
			if ( ! empty( $this->overrides_for( $hook ) ) ) {
				return true;
			}
		}
		return false;
	}

	private function describe( $callback ): ?array {
		try {
			if ( $callback instanceof \Closure ) {
				$reflection = new \ReflectionFunction( $callback );
				return $this->from_reflection( 'closure', $reflection );
			}
			if ( is_string( $callback ) ) {
				if ( strpos( $callback, '::' ) !== false ) {
					list( $class, $method ) = explode( '::', $callback, 2 );
					$reflection             = new \ReflectionMethod( $class, $method );
					return $this->from_reflection( $callback, $reflection );
				}
				$reflection = new \ReflectionFunction( $callback );
				return $this->from_reflection( $callback, $reflection );
			}
			if ( is_array( $callback ) && count( $callback ) === 2 ) {
				$class      = is_object( $callback[0] ) ? get_class( $callback[0] ) : (string) $callback[0];
				$reflection = new \ReflectionMethod( $class, (string) $callback[1] );
				return $this->from_reflection( $class . '::' . $callback[1], $reflection );
			}
		} catch ( \Throwable $exception ) {
			return null;
		}

		return null;
	}

	private function from_reflection( string $name, $reflection ): array {
		return array(
			'callback' => $name,
			'file'     => (string) $reflection->getFileName(),
			'line'     => (int) $reflection->getStartLine(),
		);
	}
}
