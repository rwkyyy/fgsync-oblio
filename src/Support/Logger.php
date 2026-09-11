<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class Logger {

	private const SOURCE = 'oblio';

	private $logger = null;

	public function info( string $message, array $context = array() ): void {
		$this->log( 'info', $message, $context );
	}

	public function warning( string $message, array $context = array() ): void {
		$this->log( 'warning', $message, $context );
	}

	public function error( string $message, array $context = array() ): void {
		$this->log( 'error', $message, $context );
	}

	public function debug( string $message, array $context = array() ): void {
		if ( 'yes' !== get_option( 'oblio_fgwoo_debug_logging', 'no' ) ) {
			return;
		}
		$this->log( 'debug', $message, $context );
	}

	private function log( string $level, string $message, array $context ): void {
		if ( null === $this->logger ) {
			if ( ! function_exists( 'wc_get_logger' ) ) {
				return;
			}
			$this->logger = wc_get_logger();
		}

		if ( ! empty( $context ) ) {
			$message .= ' ' . wp_json_encode( $context );
		}

		$this->logger->log( $level, $message, array( 'source' => self::SOURCE ) );
	}
}
