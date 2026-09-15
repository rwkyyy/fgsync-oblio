<?php
/**
 * Injects an "open invoice" button into WooCommerce order emails.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Document;

use OblioWoo\Order\OrderMeta;
use OblioWoo\Support\Logger;
use OblioWoo\Support\RateLimiter;
use OblioWoo\Support\Settings;
use WC_Email;
use WC_Order;
final class EmailButton {

	private const ISSUE_LOCK = 'oblio_fgwoo_email_issue_lock_';

	private const MAX_INLINE_WAIT = 5;

	private Settings $settings;

	private DocumentIssuer $documents;

	private Logger $logger;

	private RateLimiter $rate_limiter;

	public function __construct( Settings $settings, DocumentIssuer $documents, Logger $logger, RateLimiter $rate_limiter ) {
		$this->settings     = $settings;
		$this->documents    = $documents;
		$this->logger       = $logger;
		$this->rate_limiter = $rate_limiter;
	}

	public function register(): void {
		add_action( 'woocommerce_email_before_order_table', array( $this, 'render' ), 15, 4 );
	}

	public function render( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
		unset( $email );

		if ( $sent_to_admin || ! $order instanceof WC_Order ) {
			return;
		}
		if ( 'button' !== $this->settings->email_mode() ) {
			return;
		}

		$statuses = array_values( array_filter( array_map( 'strval', (array) $this->settings->get( 'email_button_statuses', array() ) ) ) );
		if ( empty( $statuses ) || ! in_array( $order->get_status(), $statuses, true ) ) {
			return;
		}

		$document = $this->ensure_invoice( $order );
		if ( null === $document || '' === $document['link'] ) {
			return;
		}

		$label = (string) $this->settings->get( 'email_button_label', '' );
		if ( '' === $label ) {
			$label = __( 'Vezi factura', 'oblio-fgwoo' );
		}

		$label = (string) apply_filters( 'oblio_fgwoo_email_button_label', $label, $order );

		if ( $plain_text ) {
			echo "\n" . esc_html( $label ) . ': ' . esc_url_raw( $document['link'] ) . "\n\n";
			return;
		}

		printf(
			'<p style="margin:0 0 24px;"><a href="%1$s" style="display:inline-block;padding:12px 22px;background:#f36e21;color:#130b17;font-weight:700;font-size:15px;text-decoration:none;border-radius:6px;">%2$s</a></p>',
			esc_url( $document['link'] ),
			esc_html( $label )
		);
	}

	private function ensure_invoice( WC_Order $order ) {
		$document = OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		if ( null !== $document && '' !== $document['link'] ) {
			return $document;
		}

		$order_id = $order->get_id();
		if ( $order_id <= 0 || ! wc_get_order( $order_id ) instanceof WC_Order ) {
			return $document;
		}

		if ( ! (bool) apply_filters( 'oblio_fgwoo_email_button_issue', true, $order ) ) {
			return $document;
		}

		if ( ! $this->settings->has_credentials() || '' === (string) $this->settings->get( 'cif' ) ) {
			return $document;
		}
		if ( '' !== (string) $order->get_meta( OrderMeta::key( OrderMeta::TYPE_INVOICE, 'failed' ) ) ) {
			return $document;
		}

		if ( $this->rate_limiter->peek() > self::MAX_INLINE_WAIT ) {
			return $document;
		}

		$lock = self::ISSUE_LOCK . $order->get_id();
		if ( false !== get_transient( $lock ) ) {
			return OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		}
		set_transient( $lock, 1, 30 );

		try {
			$result = $this->documents->issue(
				$order,
				OrderMeta::TYPE_INVOICE,
				array( 'use_stock' => $this->settings->is_enabled( 'invoice_autogen_use_stock' ) )
			);
			return array(
				'series' => $result->series_name,
				'number' => $result->number,
				'link'   => $result->link,
				'date'   => '',
			);
		} catch ( \Throwable $exception ) {
			$this->logger->warning( sprintf( 'Email button: could not ensure the invoice for order #%d: %s', $order->get_id(), $exception->getMessage() ) );
			return OrderMeta::get( $order, OrderMeta::TYPE_INVOICE );
		} finally {
			delete_transient( $lock );
		}
	}
}
