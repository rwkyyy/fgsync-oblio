<?php
/**
 * Sends the customer a document notification email.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document;

use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Settings;
use WC_Order;
final class InvoiceEmailer {

	private Settings $settings;

	public function __construct( Settings $settings ) {
		$this->settings = $settings;
	}

	public function maybe_send( WC_Order $order, DocumentResult $result ): void {

		if ( 'standalone' !== $this->settings->email_mode() ) {
			return;
		}

		$to = $order->get_billing_email();
		if ( '' === $to || ! is_email( $to ) ) {
			return;
		}

		$type = OrderMeta::TYPE_PROFORMA === $result->doc_type
			? __( 'Proforma', 'facturare-gestiune-oblio-woocommerce' )
			: __( 'Factura', 'facturare-gestiune-oblio-woocommerce' );

		$created  = $order->get_date_created();
		$issue_ts = $created ? (int) $created->format( 'U' ) : time();
		$due_days = (int) $this->settings->get( 'invoice_due', 0 );
		$contact  = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$company  = trim( $order->get_billing_company() );

		$search  = array( '[serie]', '[numar]', '[link]', '[type]', '[issue_date]', '[due_date]', '[total]', '[contact_name]', '[client_name]' );
		$replace = array(
			$result->series_name,
			$result->number,
			sprintf( '<a href="%1$s">%2$s %3$s</a>', esc_url( $result->link ), esc_html( $result->series_name ), esc_html( $result->number ) ),
			$type,
			gmdate( 'd.m.Y', $issue_ts ),
			gmdate( 'd.m.Y', $issue_ts + $due_days * DAY_IN_SECONDS ),
			wp_strip_all_tags( $order->get_formatted_order_total() ),
			$contact,
			'' !== $company ? $company : $contact,
		);

		$subject = str_replace( $search, $replace, (string) $this->settings->get( 'email_subject', '' ) );
		$message = str_replace( $search, $replace, nl2br( (string) $this->settings->get( 'email_message', '' ) ) );

		$headers = array( 'Content-Type: text/html; charset=UTF-8' );

		$cc = (string) $this->settings->get( 'email_cc', '' );
		if ( '' !== $cc ) {
			$headers[] = 'Cc: ' . $cc;
		}

		$from = (string) $this->settings->get( 'email_from', '' );
		if ( '' !== $from && is_email( $from ) ) {
			$headers[] = sprintf( 'From: %s <%s>', get_option( 'blogname' ), $from );
		}

		wp_mail( $to, $subject, $message, $headers );
	}
}
