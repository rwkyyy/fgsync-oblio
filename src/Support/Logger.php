<?php
/**
 * WooCommerce logger wrapper.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Support;

final class Logger {

	private const SOURCE = 'fgsync-oblio';

	private $logger = null;

	/**
	 * Carries the site address so log files downloaded from different shops don't share a name.
	 */
	public static function source(): string {
		$site = (string) preg_replace( '#^https?://(www\.)?#i', '', (string) get_option( 'home', '' ) );
		$site = trim( (string) preg_replace( '/[^a-z0-9]+/', '-', strtolower( $site ) ), '-' );
		return '' === $site ? self::SOURCE : self::SOURCE . '-' . $site;
	}

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

		$this->logger->log( $level, $message, array( 'source' => self::source() ) );
	}
}
