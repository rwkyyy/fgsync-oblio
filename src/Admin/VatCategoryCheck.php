<?php
/**
 * Lists WooCommerce tax rates the Oblio account has no VAT category for.
 *
 * Oblio categories cannot be edited once used on an invoice, so a new or
 * changed rate needs a new category; Oblio recommends naming it after the
 * rate ("25.5").
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Tax\TaxRateStore;
final class VatCategoryCheck {

	public const FIELD_TYPE = 'oblio_fgwoo_vat_category_check';

	private NomenclatureCache $nomenclature;

	private TaxRateStore $store;

	public function __construct( NomenclatureCache $nomenclature, TaxRateStore $store ) {
		$this->nomenclature = $nomenclature;
		$this->store        = $store;
	}

	public function register(): void {
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render_field' ) );
	}

	/**
	 * @param array<string,mixed> $field WooCommerce settings field.
	 */
	public function render_field( array $field ): void {
		unset( $field );
		$missing = VatCategories::missing( $this->nomenclature->vat_categories(), $this->store->all_rates() );
		if ( empty( $missing ) ) {
			return;
		}

		$rows = sprintf(
			'<span class="head">%s</span> <span class="head">%s</span> <span class="head">%s</span> ',
			esc_html__( 'Cotă', 'fgsync-oblio' ),
			esc_html__( 'Nume în Oblio', 'fgsync-oblio' ),
			esc_html__( 'Țări', 'fgsync-oblio' )
		);
		foreach ( $missing as $rate => $countries ) {
			$where = implode( ', ', array_map( static fn ( string $country ): string => '' === $country ? __( 'restul lumii', 'fgsync-oblio' ) : $country, $countries ) );
			$rows .= sprintf( '<span class="rate">%1$s%%</span> <code>%1$s</code> <span>%2$s</span> ', esc_html( (string) $rate ), esc_html( $where ) );
		}

		echo '<tr valign="top" id="' . esc_attr( self::FIELD_TYPE ) . '"><th scope="row" class="titledesc">' . esc_html__( 'Cote lipsă în Oblio', 'fgsync-oblio' ) . '</th><td class="forminp">';
		echo '<p class="description">' . wp_kses_post(
			sprintf(
				/* translators: 1: link to Oblio's VAT categories page, 2: link to the "Preia ultimele date" button */
				__( 'În setările site-ului aveți cotele de TVA de mai jos, acestea trebuiesc create și în Oblio!<br><strong>Documentele cu aceste cote de TVA vor <u>EȘUA</u> la emitere deoarece Oblio depinde de existența lor.</strong><br> Adaugă-le în %1$s, apoi întoarce-te aici și apasă %2$s.', 'fgsync-oblio' ),
				VatCategories::settings_link(),
				SettingsPage::tab_link( '', 'oblio_fgwoo_test_connection', __( 'Conectare → „Preia ultimele date”', 'fgsync-oblio' ) )
			)
		) . '</p>';
		echo '<div class="oblio-fgwoo-rates">' . wp_kses_post( $rows ) . '</div></td></tr>';
	}
}
