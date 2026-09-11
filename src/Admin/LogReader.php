<?php
/**
 * Reads today's Oblio entries from the WooCommerce file logs.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

final class LogReader {

	public function today( int $limit = 200 ): array {
		$entries = array();

		foreach ( $this->files_for_today() as $file ) {
			foreach ( $this->tail( $file, $limit ) as $line ) {
				$parsed = $this->parse( $line );
				if ( null !== $parsed ) {
					$entries[] = $parsed;
				}
			}
		}

		if ( count( $entries ) > $limit ) {
			$entries = array_slice( $entries, -$limit );
		}

		return $entries;
	}

	private function tail( string $file, int $max_lines ): array {
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged
		$handle = @fopen( $file, 'rb' );
		if ( false === $handle ) {
			return array();
		}

		$chunk  = 8192;
		$buffer = '';
		$lines  = array();
		$found  = 0;
		fseek( $handle, 0, SEEK_END );
		$position = ftell( $handle );

		while ( $position > 0 && $found <= $max_lines ) {
			$read      = (int) min( $chunk, $position );
			$position -= $read;
			fseek( $handle, $position );
			$buffer = fread( $handle, $read ) . $buffer;
			$lines  = explode( "\n", $buffer );
			$found  = count( $lines );
		}
		fclose( $handle );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_system_operations_fopen, WordPress.WP.AlternativeFunctions.file_system_operations_fread, WordPress.WP.AlternativeFunctions.file_system_operations_fclose, WordPress.PHP.NoSilencedErrors.Discouraged

		$lines = array_values( array_filter( array_map( 'rtrim', $lines ), static fn ( string $line ): bool => '' !== $line ) );
		return count( $lines ) > $max_lines ? array_slice( $lines, -$max_lines ) : $lines;
	}

	private function log_dir(): string {
		if ( defined( 'WC_LOG_DIR' ) ) {
			return (string) WC_LOG_DIR;
		}
		$uploads = wp_upload_dir();
		return trailingslashit( $uploads['basedir'] ) . 'wc-logs/';
	}

	private function files_for_today(): array {
		$pattern = $this->log_dir() . 'oblio-' . gmdate( 'Y-m-d' ) . '-*.log';
		$files   = glob( $pattern );
		return is_array( $files ) ? $files : array();
	}

	private function parse( string $line ): ?array {
		if ( ! preg_match( '/^(\S+)\s+(\S+)\s+(.*)$/', $line, $matches ) ) {
			return null;
		}

		$timestamp = strtotime( $matches[1] );
		$message   = $matches[3];

		$context_pos = strpos( $message, ' {"source"' );
		if ( false !== $context_pos ) {
			$message = substr( $message, 0, $context_pos );
		}

		return array(
			'time'    => $timestamp ? gmdate( 'H:i:s', $timestamp ) : '',
			'level'   => strtolower( $matches[2] ),
			'message' => $message,
		);
	}
}
