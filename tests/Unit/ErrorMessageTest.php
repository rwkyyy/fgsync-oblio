<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Admin\ErrorMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass( \FGSyncOblio\Admin\ErrorMessage::class )]
final class ErrorMessageTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function messages(): array {
		return array(
			'oblio markup'   => array(
				esc_html( "Produsele urmatoare nu au stoc suficient:\n<b>Natural statine forte, 60cps, Hypericum</b>." ),
				"Produsele urmatoare nu au stoc suficient:\nNatural statine forte, 60cps, Hypericum.",
			),
			'escaped quotes' => array(
				esc_html( 'Produsul "Gnc Triple Strength" nu are stoc suficient.' ),
				'Produsul "Gnc Triple Strength" nu are stoc suficient.',
			),
			'plain text'     => array( 'Comandă inexistentă.', 'Comandă inexistentă.' ),
		);
	}

	#[DataProvider( 'messages' )]
	public function test_a_stored_message_becomes_plain_text( string $stored, string $expected ): void {
		$this->assertSame( $expected, ErrorMessage::plain_text( $stored ) );
	}
}
