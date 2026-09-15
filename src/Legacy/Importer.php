<?php
/**
 * One-way importer from the legacy OblioSoftware plugin.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Legacy;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Support\Logger;
use OblioWoo\Support\Settings;
final class Importer {

	private Settings $settings;

	private ClientFactory $factory;

	private Logger $logger;

	public function __construct( Settings $settings, ClientFactory $factory, Logger $logger ) {
		$this->settings = $settings;
		$this->factory  = $factory;
		$this->logger   = $logger;
	}

	public function has_legacy_data(): bool {
		return false !== get_option( 'oblio_email', false );
	}

	private function map(): array {
		return array(
			'oblio_email'                              => array( 'email', 'copy' ),
			'oblio_api_secret'                         => array( 'secret', 'copy' ),
			'oblio_cui'                                => array( 'cif', 'copy' ),
			'oblio_series_name'                        => array( 'series_invoice', 'copy' ),
			'oblio_series_name_proforma'               => array( 'series_proforma', 'copy' ),
			'oblio_workstation'                        => array( 'workstation', 'copy' ),
			'oblio_management'                         => array( 'management', 'copy' ),
			'oblio_invoice_autogen'                    => array( 'invoice_autogen', 'bool' ),
			'oblio_invoice_autogen_use_stock'          => array( 'invoice_autogen_use_stock', 'bool' ),
			'oblio_proforma_autogen'                   => array( 'proforma_autogen', 'bool' ),
			'oblio_gen_date'                           => array( 'issue_date_basis', 'gen_date' ),
			'oblio_auto_collect'                       => array( 'collect_mode', 'collect' ),
			'oblio_webhook_card_complete'              => array( 'webhooks_enabled', 'bool' ),
			'oblio_invoice_gen_send_email'             => array( 'email_on_issue', 'bool' ),
			'oblio_invoice_send_email_from'            => array( 'email_from', 'copy' ),
			'oblio_invoice_send_email_subject'         => array( 'email_subject', 'copy' ),
			'oblio_invoice_send_email_cc'              => array( 'email_cc', 'copy' ),
			'oblio_invoice_send_email_message'         => array( 'email_message', 'copy' ),
			'oblio_stock_sync'                         => array( 'stock_sync', 'bool' ),
			'oblio_stock_adjusments'                   => array( 'stock_reserve_orders', 'bool' ),
			'oblio_update_price'                       => array( 'stock_update_price', 'bool' ),
			'oblio_invoice_language'                   => array( 'language', 'copy' ),
			'oblio_invoice_measuring_unit'             => array( 'measuring_unit', 'copy' ),
			'oblio_invoice_measuring_unit_translation' => array( 'measuring_unit_translation', 'copy' ),
			'oblio_product_type'                       => array( 'product_type', 'copy' ),
			'oblio_invoice_due'                        => array( 'invoice_due', 'int' ),
			'oblio_invoice_issuer_name'                => array( 'invoice_issuer_name', 'copy' ),
			'oblio_invoice_issuer_id'                  => array( 'invoice_issuer_id', 'copy' ),
			'oblio_invoice_deputy_name'                => array( 'invoice_deputy_name', 'copy' ),
			'oblio_invoice_deputy_identity_card'       => array( 'invoice_deputy_identity_card', 'copy' ),
			'oblio_invoice_deputy_auto'                => array( 'invoice_deputy_auto', 'copy' ),
			'oblio_invoice_seles_agent'                => array( 'invoice_seles_agent', 'copy' ),
			'oblio_invoice_mentions'                   => array( 'invoice_mentions', 'copy' ),
			'oblio_invoice_discount_in_product'        => array( 'invoice_discount_in_product', 'bool' ),
			'oblio_hide_description'                   => array( 'hide_description', 'bool' ),
			'oblio_autocomplete_company'               => array( 'autocomplete_company', 'int' ),
			'oblio_notsave_price'                      => array( 'notsave_price', 'bool' ),
		);
	}

	public function import(): int {
		$imported = 0;

		foreach ( $this->map() as $legacy_key => $target ) {
			$value = get_option( $legacy_key, null );
			if ( null === $value || false === $value ) {
				continue;
			}

			$converted = $this->convert( $value, $target[1] );

			if ( 'secret' === $target[0] ) {
				$this->factory->set_secret( (string) $converted );
			} else {
				$this->settings->set( $target[0], $converted );
			}
			++$imported;
		}

		update_option( 'oblio_fgwoo_imported_at', time(), false );
		$this->logger->info( sprintf( 'Import from legacy plugin: %d setting(s) imported', $imported ) );

		return $imported;
	}

	private function convert( $value, string $type ) {
		switch ( $type ) {
			case 'bool':
				return ( '1' === (string) $value || 1 === $value || true === $value ) ? 'yes' : 'no';
			case 'int':
				return (int) $value;
			case 'gen_date':
				return 2 === (int) $value ? 'order' : 'issue';
			case 'collect':
				$modes = array(
					0 => 'off',
					1 => 'card',
					2 => 'all',
				);
				return $modes[ (int) $value ] ?? 'off';
			case 'copy':
			default:
				return $value;
		}
	}
}
