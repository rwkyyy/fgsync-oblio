<?php
/**
 * Suggested privacy-policy text for the site's Privacy Policy editor.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

final class Privacy {

	public function register(): void {
		add_action( 'admin_init', array( $this, 'add_content' ) );
	}

	public function add_content(): void {
		if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
			return;
		}

		$content = '<p class="privacy-policy-tutorial">'
			. esc_html__( 'This plugin sends order and customer data to Oblio (oblio.eu) in order to create and manage invoices, proformas, delivery notes and credit notes.', 'oblio-fgwoo' )
			. '</p><p>'
			. esc_html__( 'When a document is issued for an order, the following is sent to Oblio: the customer name, company and tax/registration identifiers, billing address, email and phone (when provided), the order line items, shipping and fees, totals, VAT and payment method. This is necessary to issue the invoice. Oblio acts as the accounting service provider for this data; see Oblio\'s terms and privacy information at https://www.oblio.eu/terms.', 'oblio-fgwoo' )
			. '</p>';

		wp_add_privacy_policy_content( 'Oblio Invoicing', wp_kses_post( $content ) );
	}
}
