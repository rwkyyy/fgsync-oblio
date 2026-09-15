<?php
/**
 * Single source of truth for the plugin's public extension points.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Extensibility;

final class HookRegistry {

	public function all(): array {
		return array(
			'oblio_fgwoo_document_data'                   => array(
				'type'    => 'filter',
				'label'   => __( 'Datele documentului (payload factură/proformă)', 'oblio-fgwoo' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_client_data'                     => array(
				'type'    => 'filter',
				'label'   => __( 'Datele clientului', 'oblio-fgwoo' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_client_field'                    => array(
				'type'    => 'filter',
				'label'   => __( 'Câmp client (CIF/RC/IBAN/bancă)', 'oblio-fgwoo' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_collection_is_paid'              => array(
				'type'    => 'filter',
				'label'   => __( 'Decizia de încasare', 'oblio-fgwoo' ),
				'section' => 'collection',
			),
			'oblio_fgwoo_collect_type'                    => array(
				'type'    => 'filter',
				'label'   => __( 'Tipul de încasare per gateway', 'oblio-fgwoo' ),
				'section' => 'collection',
			),
			'oblio_fgwoo_collect_complete_order'          => array(
				'type'    => 'filter',
				'label'   => __( 'Ordinul consideră comanda plătită la încasare', 'oblio-fgwoo' ),
				'section' => 'collection',
			),
			'oblio_fgwoo_document_language'               => array(
				'type'    => 'filter',
				'label'   => __( 'Limba documentului (WPML)', 'oblio-fgwoo' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_reconcile_lookback_days'         => array(
				'type'    => 'filter',
				'label'   => __( 'Fereastra de reconciliere (zile)', 'oblio-fgwoo' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_email_button_label'              => array(
				'type'    => 'filter',
				'label'   => __( 'Eticheta butonului de factură din email', 'oblio-fgwoo' ),
				'section' => 'email',
			),
			'oblio_fgwoo_email_button_issue'              => array(
				'type'    => 'filter',
				'label'   => __( 'Emiterea sincronă a facturii la randarea emailului', 'oblio-fgwoo' ),
				'section' => 'email',
			),
			'oblio_fgwoo_storno_data'                     => array(
				'type'  => 'filter',
				'label' => __( 'Payload storno (retur)', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_stock_aggregate'                 => array(
				'type'    => 'filter',
				'label'   => __( 'Stoc agregat per produs', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_quantity'                  => array(
				'type'    => 'filter',
				'label'   => __( 'Cantitatea finală de stoc', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_price'                     => array(
				'type'    => 'filter',
				'label'   => __( 'Prețul final la sincronizare', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_reservation_lookback_days' => array(
				'type'    => 'filter',
				'label'   => __( 'Fereastra rezervării de stoc (zile)', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_reservation_max_orders'    => array(
				'type'    => 'filter',
				'label'   => __( 'Numărul maxim de comenzi scanate pentru rezervare', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_reservation_statuses'      => array(
				'type'    => 'filter',
				'label'   => __( 'Statusurile de comandă tratate ca rezervate', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_webhook_topics'                  => array(
				'type'    => 'filter',
				'label'   => __( 'Topicuri webhook', 'oblio-fgwoo' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_document_issued'                 => array(
				'type'  => 'action',
				'label' => __( 'După emiterea unui document', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_document_deleted'                => array(
				'type'  => 'action',
				'label' => __( 'După ștergerea unui document', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_storno_issued'                   => array(
				'type'  => 'action',
				'label' => __( 'După emiterea unui storno', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_webhook'                         => array(
				'type'  => 'action',
				'label' => __( 'La primirea unui webhook', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_booted'                          => array(
				'type'  => 'action',
				'label' => __( 'După inițializarea pluginului', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_returns_feature_slug'            => array(
				'type'  => 'filter',
				'label' => __( 'Slug-ul funcției Retururi WooCommerce (experimental)', 'oblio-fgwoo' ),
			),
			'oblio_fgwoo_returns_hooks'                   => array(
				'type'  => 'filter',
				'label' => __( 'Hook-urile de finalizare a returului (experimental)', 'oblio-fgwoo' ),
			),
		);
	}

	public function for_section( string $section ): array {
		$hooks = array();
		foreach ( $this->all() as $hook => $meta ) {
			if ( ( $meta['section'] ?? '' ) === $section ) {
				$hooks[] = $hook;
			}
		}
		return $hooks;
	}
}
