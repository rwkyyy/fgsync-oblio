<?php
/**
 * Single source of truth for the plugin's public extension points.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Extensibility;

final class HookRegistry {

	public function all(): array {
		return array(
			'oblio_fgwoo_document_data'                   => array(
				'type'    => 'filter',
				'label'   => __( 'Datele documentului (payload factură/proformă)', 'fgsync-oblio' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_client_data'                     => array(
				'type'    => 'filter',
				'label'   => __( 'Datele clientului', 'fgsync-oblio' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_client_field'                    => array(
				'type'    => 'filter',
				'label'   => __( 'Câmp client (CIF/RC/IBAN/bancă)', 'fgsync-oblio' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_collection_is_paid'              => array(
				'type'    => 'filter',
				'label'   => __( 'Decizia de încasare', 'fgsync-oblio' ),
				'section' => 'collection',
			),
			'oblio_fgwoo_collect_type'                    => array(
				'type'    => 'filter',
				'label'   => __( 'Tipul de încasare per gateway', 'fgsync-oblio' ),
				'section' => 'collection',
			),
			'oblio_fgwoo_document_language'               => array(
				'type'    => 'filter',
				'label'   => __( 'Limba documentului (WPML)', 'fgsync-oblio' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_document_currency'               => array(
				'type'    => 'filter',
				'label'   => __( 'Moneda documentului (EUR pentru facturare OSS în afara României)', 'fgsync-oblio' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_reconcile_lookback_days'         => array(
				'type'    => 'filter',
				'label'   => __( 'Fereastra de reconciliere (zile)', 'fgsync-oblio' ),
				'section' => 'documents',
			),
			'oblio_fgwoo_email_button_label'              => array(
				'type'    => 'filter',
				'label'   => __( 'Eticheta butonului de factură din email', 'fgsync-oblio' ),
				'section' => 'email',
			),
			'oblio_fgwoo_email_button_issue'              => array(
				'type'    => 'filter',
				'label'   => __( 'Emiterea sincronă a facturii la randarea emailului', 'fgsync-oblio' ),
				'section' => 'email',
			),
			'oblio_fgwoo_storno_data'                     => array(
				'type'  => 'filter',
				'label' => __( 'Payload storno (retur)', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_admin_bar_queue_threshold'       => array(
				'type'  => 'filter',
				'label' => __( 'Pragul de coadă „mare” pentru punctul galben din bara de admin', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_stock_aggregate'                 => array(
				'type'    => 'filter',
				'label'   => __( 'Stoc agregat per produs', 'fgsync-oblio' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_quantity'                  => array(
				'type'    => 'filter',
				'label'   => __( 'Cantitatea finală de stoc', 'fgsync-oblio' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_price'                     => array(
				'type'    => 'filter',
				'label'   => __( 'Prețul final la sincronizare', 'fgsync-oblio' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_reservation_lookback_days' => array(
				'type'    => 'filter',
				'label'   => __( 'Fereastra rezervării de stoc (zile)', 'fgsync-oblio' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_stock_reservation_statuses'      => array(
				'type'    => 'filter',
				'label'   => __( 'Statusurile de comandă tratate ca rezervate', 'fgsync-oblio' ),
				'section' => 'stock',
			),
			'oblio_fgwoo_document_issued'                 => array(
				'type'  => 'action',
				'label' => __( 'După emiterea unui document', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_document_deleted'                => array(
				'type'  => 'action',
				'label' => __( 'După ștergerea unui document', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_storno_issued'                   => array(
				'type'  => 'action',
				'label' => __( 'După emiterea unui storno', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_booted'                          => array(
				'type'  => 'action',
				'label' => __( 'După inițializarea pluginului', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_returns_feature_slug'            => array(
				'type'  => 'filter',
				'label' => __( 'Slug-ul funcției Retururi WooCommerce (experimental)', 'fgsync-oblio' ),
			),
			'oblio_fgwoo_returns_hooks'                   => array(
				'type'  => 'filter',
				'label' => __( 'Hook-urile de finalizare a returului (experimental)', 'fgsync-oblio' ),
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
