<?php
/**
 * Renders parsed log entries as HTML rows (initial page load + AJAX tail).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

final class LogRenderer {

	public static function rows( array $entries ): string {
		if ( empty( $entries ) ) {
			return '<p class="oblio-empty">' . esc_html__( 'Fără intrări.', 'oblio-fgwoo' ) . '</p>';
		}

		$html = '';
		foreach ( $entries as $entry ) {
			$level = in_array( $entry['level'], array( 'info', 'warning', 'error' ), true ) ? $entry['level'] : 'info';
			$html .= sprintf(
				'<div class="oblio-logline lvl-%s"><span class="t">%s</span><span class="lvl">%s</span><span class="msg">%s</span></div>',
				esc_attr( $level ),
				esc_html( $entry['time'] ),
				esc_html( strtoupper( substr( $entry['level'], 0, 5 ) ) ),
				esc_html( $entry['message'] )
			);
		}
		return $html;
	}
}
