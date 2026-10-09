<?php
/**
 * "Import EU VAT rates" button on the TVA tab and its admin-post handler.
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Document\VatCategories;
use FGSyncOblio\Support\Logger;
use FGSyncOblio\Tax\EuVatRateImporter;
use FGSyncOblio\Tax\EuVatRates;
use InvalidArgumentException;
final class EuVatImportAction {

	public const ACTION = 'oblio_fgwoo_import_eu_vat';

	public const FIELD_TYPE = 'oblio_fgwoo_eu_vat_import';

	private const RESULT_ARG = 'oblio_fgwoo_eu_vat';

	private EuVatRateImporter $importer;

	private Logger $logger;

	public function __construct( EuVatRateImporter $importer, Logger $logger ) {
		$this->importer = $importer;
		$this->logger   = $logger;
	}

	public function register(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle' ) );
		add_action( 'woocommerce_admin_field_' . self::FIELD_TYPE, array( $this, 'render_field' ) );
		add_action( 'admin_notices', array( $this, 'update_notice' ) );
	}

	/**
	 * Tells the shop when a plugin update brought rates that differ from the
	 * rows it imported earlier. Only on FGSync and WooCommerce tax screens.
	 */
	public function update_notice(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- screen detection only.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! current_user_can( 'manage_woocommerce' ) || ( SettingsPage::PAGE_SLUG !== $page && ! ( 'wc-settings' === $page && 'tax' === $tab ) ) ) {
			return;
		}

		try {
			$pending = $this->importer->has_updates( EuVatRates::bundled(), $this->base_country() );
		} catch ( InvalidArgumentException $exception ) {
			return;
		}
		if ( ! $pending ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%s %s <a href="%s">%s</a></p></div>',
			esc_html__( 'FGSync: au apărut cote TVA UE noi față de cele importate.', 'fgsync-oblio' ),
			wp_kses_post(
				sprintf(
					/* translators: %s: link to Oblio's VAT categories page */
					__( 'Pentru fiecare cotă nouă adaugă și o categorie în %s, numită după procent (de ex. „25.5”).', 'fgsync-oblio' ),
					VatCategories::settings_link()
				)
			),
			esc_url( SettingsPage::url( 'tax' ) . '#' . self::FIELD_TYPE ),
			esc_html__( 'Vezi modificările', 'fgsync-oblio' )
		);
	}

	public function handle(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( self::ACTION ) ) {
			wp_die( esc_html__( 'Acțiune neautorizată.', 'fgsync-oblio' ), 403 );
		}

		try {
			$plan   = $this->importer->import( EuVatRates::bundled(), $this->base_country(), __( 'TVA', 'fgsync-oblio' ) );
			$result = count( $plan['add'] ) . '-' . count( $plan['update'] ) . '-' . count( $plan['kept'] );
			$this->logger->info( sprintf( 'EU VAT rates imported: %d added, %d updated, %d kept', count( $plan['add'] ), count( $plan['update'] ), count( $plan['kept'] ) ) );
		} catch ( InvalidArgumentException $exception ) {
			$this->logger->error( 'EU VAT rate import failed: ' . $exception->getMessage() );
			$result = 'error';
		}

		wp_safe_redirect( add_query_arg( self::RESULT_ARG, $result, SettingsPage::url( 'tax' ) ) );
		exit;
	}

	/**
	 * @param array<string,mixed> $field WooCommerce settings field.
	 */
	public function render_field( array $field ): void {
		unset( $field );
		echo '<tr valign="top" id="' . esc_attr( self::FIELD_TYPE ) . '"><th scope="row" class="titledesc">' . esc_html__( 'Import cote standard', 'fgsync-oblio' ) . '</th><td class="forminp">';
		echo wp_kses_post( $this->result_notice() );

		try {
			$bundle = EuVatRates::bundled();
		} catch ( InvalidArgumentException $exception ) {
			echo '<p class="description oblio-fgwoo-warning">' . esc_html__( 'Fișierul cu cote TVA UE din plugin lipsește sau este invalid. Reinstalează pluginul.', 'fgsync-oblio' ) . '</p></td></tr>';
			return;
		}

		$plan = $this->importer->plan( $bundle, $this->base_country() );
		echo wp_kses_post( $this->description( $bundle ) );
		echo wp_kses_post( $this->plan_summary( $plan ) );
		if ( ! empty( $plan['add'] ) || ! empty( $plan['update'] ) ) {
			printf(
				'<a class="button" href="%s">%s</a>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION ) ),
				esc_html__( 'Importă cotele standard de TVA ale UE', 'fgsync-oblio' )
			);
			echo '<p class="description">' . sprintf(
				/* translators: %s: link to the "Cote lipsă în Oblio" list */
				esc_html__( 'După import, verifică lista %s de mai sus.', 'fgsync-oblio' ),
				SettingsPage::tab_link( 'tax', VatCategoryCheck::FIELD_TYPE, __( '„Cote lipsă în Oblio”', 'fgsync-oblio' ) ) // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in tab_link().
			) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * @param array<string,mixed> $bundle Bundle.
	 */
	private function description( array $bundle ): string {
		$html  = '<p class="description">' . wp_kses_post( __( '<strong>Clienți persoane fizice din UE.</strong> Sub 10.000 € pe an în vânzări la distanță în UE se aplică TVA-ul României, deci nu ai nevoie de import. Peste prag sau dacă ești înregistrat în OSS se aplică cota țării clientului: pentru acest lucru există importul de mai jos.', 'fgsync-oblio' ) ) . '</p>';
		$html .= '<p class="description">' . wp_kses_post( __( '<strong>Firme din UE cu cod de TVA valid.</strong> Taxare inversă, fără TVA, deci cotele importate nu li se aplică. Firma trebuie scutită de TVA la checkout de un plugin care verifică codul în VIES; FGSync nu face acest lucru.', 'fgsync-oblio' ) ) . '</p>';
		$html .= '<p class="description">' . sprintf(
			/* translators: 1: link to the WooCommerce standard tax rates, 2: date the bundled rates were fetched */
			wp_kses_post( __( '<strong>Ce face importul.</strong> Adaugă în %1$s câte un rând pentru fiecare stat UE, cu cota standard actualizată la %2$s, și unul pentru restul lumii, cu cota țării magazinului. <br><strong>Cotele existente rămân neschimbate!</strong>', 'fgsync-oblio' ) ),
			self::tax_settings_link(),
			esc_html( (string) $bundle['fetched_at'] )
		) . '</p>';

		return $html;
	}

	/**
	 * What pressing the button would change, grouped so new, changed and
	 * untouched rates are told apart.
	 *
	 * @param array{add:array<string,float>,update:array<string,array{from:float,to:float}>,kept:array<int,string>} $plan Planned changes.
	 */
	private function plan_summary( array $plan ): string {
		if ( empty( $plan['add'] ) && empty( $plan['update'] ) ) {
			return '<p class="description"><strong>' . esc_html__( 'Cotele sunt la zi: nu este nimic de importat/actualizat.', 'fgsync-oblio' ) . '</strong></p>';
		}

		$groups = array(
			array(
				/* translators: %d: number of rates */
				__( 'Cote noi ce pot fi importate: (%d)', 'fgsync-oblio' ),
				'is-new',
				array_map( fn ( string $country, float $rate ): string => sprintf( '%s %s%%', $this->label( $country ), $this->percent( $rate ) ), array_keys( $plan['add'] ), $plan['add'] ),
			),
			array(
				/* translators: %d: number of rates */
				__( 'Cote modificate (%d)', 'fgsync-oblio' ),
				'is-changed',
				array_map( fn ( string $country, array $change ): string => sprintf( '%s %s%% → %s%%', $this->label( $country ), $this->percent( $change['from'] ), $this->percent( $change['to'] ) ), array_keys( $plan['update'] ), $plan['update'] ),
			),
			array(
				/* translators: %d: number of countries */
				__( 'Rândurile tale, neschimbate (%d)', 'fgsync-oblio' ),
				'',
				array_map( array( $this, 'label' ), $plan['kept'] ),
			),
		);

		$html = '';
		foreach ( $groups as list( $title, $class, $items ) ) {
			if ( empty( $items ) ) {
				continue;
			}
			$chips = '';
			foreach ( $items as $item ) {
				$chips .= '<span class="oblio-fgwoo-chip ' . esc_attr( $class ) . '">' . esc_html( $item ) . '</span> ';
			}
			$html .= '<p class="description"><strong>' . esc_html( sprintf( $title, count( $items ) ) ) . '</strong></p><div class="oblio-fgwoo-chips">' . $chips . '</div>';
		}

		return $html;
	}

	/**
	 * "WooCommerce → Setări → Taxe" as a link to the standard rates table.
	 */
	public static function tax_settings_link(): string {
		return sprintf( '<a href="%s">%s</a>', esc_url( admin_url( 'admin.php?page=wc-settings&tab=tax&section=standard' ) ), esc_html__( 'WooCommerce → Setări → Taxe', 'fgsync-oblio' ) );
	}

	private function result_notice(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display-only redirect flag.
		$result = isset( $_GET[ self::RESULT_ARG ] ) ? sanitize_text_field( wp_unslash( $_GET[ self::RESULT_ARG ] ) ) : '';
		if ( 'error' === $result ) {
			return '<p class="description oblio-fgwoo-warning">' . esc_html__( 'Importul cotelor TVA a eșuat. Detalii în jurnalul FGSync.', 'fgsync-oblio' ) . '</p>';
		}
		if ( ! preg_match( '/^(\d+)-(\d+)-(\d+)$/', $result, $counts ) ) {
			return '';
		}
		return '<p class="description oblio-fgwoo-success">' . sprintf(
			/* translators: 1: rows added, 2: rows updated, 3: countries kept */
			esc_html__( 'Cote TVA importate: %1$d adăugate, %2$d actualizate, %3$d păstrate.', 'fgsync-oblio' ),
			(int) $counts[1],
			(int) $counts[2],
			(int) $counts[3]
		) . '</p>';
	}

	private function label( string $country ): string {
		return '*' === $country ? __( 'restul lumii', 'fgsync-oblio' ) : $country;
	}

	private function percent( float $rate ): string {
		return VatCategories::rate_label( $rate );
	}

	private function base_country(): string {
		if ( function_exists( 'WC' ) && WC()->countries ) {
			return (string) WC()->countries->get_base_country();
		}
		return 'RO';
	}
}
