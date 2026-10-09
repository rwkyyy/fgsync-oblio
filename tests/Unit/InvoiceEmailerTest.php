<?php
/**
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Tests\Unit;

use FGSyncOblio\Document\DocumentResult;
use FGSyncOblio\Document\InvoiceEmailer;
use FGSyncOblio\Order\OrderMeta;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Support\Settings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WC_Order;

#[CoversClass( \FGSyncOblio\Document\InvoiceEmailer::class )]
final class InvoiceEmailerTest extends TestCase {

	private Settings $settings;

	private InvoiceEmailer $emailer;

	protected function setUp(): void {
		oblio_test_reset();
		$this->settings = new Settings();
		$this->settings->set( 'email_mode', 'standalone' );
		$this->emailer = new InvoiceEmailer( $this->settings, new Logger() );
	}

	private function order_with_billing_email( string $email ): WC_Order {
		$order = new WC_Order( 1 );
		$order->set_billing(
			array(
				'email'      => $email,
				'first_name' => 'Ana',
				'last_name'  => 'Pop',
			)
		);
		return $order;
	}

	/**
	 * The order id is enough for diagnostics - the customer's email address
	 * has no business being in a log a shop manager (or support) can read.
	 */
	public function test_a_successful_send_logs_the_order_id_but_not_the_email_address(): void {
		$order  = $this->order_with_billing_email( 'customer@example.test' );
		$result = new DocumentResult( OrderMeta::TYPE_INVOICE, 'FCT', '1', 'https://example.test/doc' );

		$this->emailer->maybe_send( $order, $result );

		$this->assertCount( 1, $GLOBALS['oblio_test_mail_calls'] );
		$this->assertCount( 1, $GLOBALS['oblio_test_wc_logs'] );
		$message = $GLOBALS['oblio_test_wc_logs'][0]['message'];
		$this->assertStringContainsString( 'order #1', $message );
		$this->assertStringNotContainsString( 'customer@example.test', $message );
	}

	public function test_a_failed_send_logs_an_error_without_the_email_address(): void {
		$GLOBALS['oblio_test_mail_result'] = false;
		$order                             = $this->order_with_billing_email( 'customer@example.test' );
		$result                            = new DocumentResult( OrderMeta::TYPE_INVOICE, 'FCT', '1', 'https://example.test/doc' );

		$this->emailer->maybe_send( $order, $result );

		$this->assertSame( 'error', $GLOBALS['oblio_test_wc_logs'][0]['level'] );
		$this->assertStringNotContainsString( 'customer@example.test', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	public function test_an_invalid_billing_email_is_skipped_and_not_logged_with_the_address(): void {
		$order  = $this->order_with_billing_email( 'not-an-email' );
		$result = new DocumentResult( OrderMeta::TYPE_INVOICE, 'FCT', '1', 'https://example.test/doc' );

		$this->emailer->maybe_send( $order, $result );

		$this->assertCount( 0, $GLOBALS['oblio_test_mail_calls'] );
		$this->assertStringNotContainsString( 'not-an-email', $GLOBALS['oblio_test_wc_logs'][0]['message'] );
	}

	public function test_disabled_email_mode_skips_sending_entirely(): void {
		$this->settings->set( 'email_mode', 'off' );
		$order  = $this->order_with_billing_email( 'customer@example.test' );
		$result = new DocumentResult( OrderMeta::TYPE_INVOICE, 'FCT', '1', 'https://example.test/doc' );

		$this->emailer->maybe_send( $order, $result );

		$this->assertCount( 0, $GLOBALS['oblio_test_mail_calls'] );
	}

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function document_labels(): array {
		return array(
			'invoice'  => array( OrderMeta::TYPE_INVOICE, 'S-a emis Factura FCT 1' ),
			'proforma' => array( OrderMeta::TYPE_PROFORMA, 'S-a emis Proforma FCT 1' ),
			'aviz'     => array( OrderMeta::TYPE_NOTICE, 'S-a emis Avizul FCT 1' ),
		);
	}

	#[DataProvider( 'document_labels' )]
	public function test_the_type_token_names_the_issued_document( string $doc_type, string $subject ): void {
		$order = $this->order_with_billing_email( 'customer@example.test' );

		$this->emailer->maybe_send( $order, new DocumentResult( $doc_type, 'FCT', '1', 'https://example.test/doc' ) );

		$this->assertSame( $subject, $GLOBALS['oblio_test_mail_calls'][0]['subject'] );
	}
}
