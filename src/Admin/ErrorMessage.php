<?php
/**
 * Plain text for showing a stored failure reason or exception message.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

final class ErrorMessage {

	/**
	 * Messages are HTML-escaped when the exception is thrown, and Oblio's can
	 * contain markup such as <b>, so escaping them again shows the tags as text.
	 *
	 * @param string $message Stored failure reason or exception message.
	 */
	public static function plain_text( string $message ): string {
		return wp_strip_all_tags( html_entity_decode( $message, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}
}
