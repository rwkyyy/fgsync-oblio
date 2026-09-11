<?php
/**
 * Dedicated Oblio admin page with tabbed sections.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Api\ClientFactory;
use OblioWoo\Extensibility\HookInspector;
use OblioWoo\Extensibility\HookRegistry;
use OblioWoo\Support\Settings;
use WC_Admin_Settings;
final class SettingsPage {

	private const PAGE_SLUG = 'oblio';

	private Settings $settings;

	private ClientFactory $factory;

	private NomenclatureCache $nomenclature;

	private StatusPanel $status_panel;

	private HookRegistry $hook_registry;

	private HookInspector $hook_inspector;

	private ?array $sections_cache = null;

	public function __construct(
		Settings $settings,
		ClientFactory $factory,
		NomenclatureCache $nomenclature,
		StatusPanel $status_panel,
		HookRegistry $hook_registry,
		HookInspector $hook_inspector
	) {
		$this->settings       = $settings;
		$this->factory        = $factory;
		$this->nomenclature   = $nomenclature;
		$this->status_panel   = $status_panel;
		$this->hook_registry  = $hook_registry;
		$this->hook_inspector = $hook_inspector;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );

		add_action( 'woocommerce_admin_field_oblio_secret', array( $this, 'render_secret_field' ) );
		add_action( 'woocommerce_admin_field_oblio_test', array( $this, 'render_test_field' ) );
		add_action( 'woocommerce_admin_field_oblio_stock_sync', array( $this, 'render_stock_sync_field' ) );
		add_action( 'woocommerce_admin_field_oblio_import', array( $this, 'render_import_field' ) );

		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ), 20 );
	}

	public function add_menu(): void {
		add_menu_page(
			__( 'Oblio', 'facturare-gestiune-oblio-woocommerce' ),
			__( 'Oblio', 'facturare-gestiune-oblio-woocommerce' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render' ),
			OBLIO_FGWOO_URL . 'assets/images/icon.png'
		);
	}

	public static function url( string $section = '' ): string {
		$url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );

		return '' !== $section ? add_query_arg( 'section', $section, $url ) : $url;
	}

	private function current_section(): string {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only nav.
		return isset( $_GET['section'] ) ? sanitize_key( wp_unslash( $_GET['section'] ) ) : '';
	}

	public function enqueue_assets( string $hook_suffix ): void {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook_suffix ) {
			return;
		}

		wp_enqueue_style( 'woocommerce_admin_styles' );
		wp_enqueue_script( 'wc-enhanced-select' );

		wp_enqueue_style( 'oblio-fgwoo-admin', OBLIO_FGWOO_URL . 'assets/css/admin.css', array(), OBLIO_FGWOO_VERSION );
		wp_enqueue_script( 'oblio-fgwoo-settings', OBLIO_FGWOO_URL . 'assets/js/admin-settings.js', array( 'jquery' ), OBLIO_FGWOO_VERSION, true );
		wp_localize_script(
			'oblio-fgwoo-settings',
			'oblioFgwoo',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( ConnectionTest::NONCE_ACTION ),
				'i18n'    => array(
					'testing'       => __( 'Se testează…', 'facturare-gestiune-oblio-woocommerce' ),
					'select'        => __( 'Selectează', 'facturare-gestiune-oblio-woocommerce' ),
					'error'         => __( 'Eroare', 'facturare-gestiune-oblio-woocommerce' ),
					'requestFailed' => __( 'Cererea a eșuat', 'facturare-gestiune-oblio-woocommerce' ),
					'confirmImport' => __( 'Import setările din pluginul vechi?', 'facturare-gestiune-oblio-woocommerce' ),
				),
			)
		);
	}

	private function get_sections(): array {
		return array(
			''           => __( 'Conectare', 'facturare-gestiune-oblio-woocommerce' ),
			'documents'  => __( 'Documente', 'facturare-gestiune-oblio-woocommerce' ),
			'collection' => __( 'Încasare', 'facturare-gestiune-oblio-woocommerce' ),
			'stock'      => __( 'Sincronizare', 'facturare-gestiune-oblio-woocommerce' ),
			'email'      => __( 'Email', 'facturare-gestiune-oblio-woocommerce' ),
			'advanced'   => __( 'Avansat', 'facturare-gestiune-oblio-woocommerce' ),
			'status'     => __( 'Stare', 'facturare-gestiune-oblio-woocommerce' ),
		);
	}

	public function render(): void {
		$section = $this->current_section();
		if ( ! array_key_exists( $section, $this->get_sections() ) ) {
			$section = '';
		}
		$saved      = $this->handle_save();
		$active_dom = '' === $section ? 'connection' : $section;
		?>
		<div class="wrap oblio-page">
			<div class="oblio-page-head">
				<img class="oblio-page-logo" src="<?php echo esc_url( OBLIO_FGWOO_URL . 'assets/images/oblio.png' ); ?>"
					width="24" height="24" alt=""/>
				<span class="oblio-page-title"><?php esc_html_e( 'Oblio', 'facturare-gestiune-oblio-woocommerce' ); ?></span>
				<span class="oblio-page-sub"><?php esc_html_e( 'Facturare și Gestiune pentru WooCommerce', 'facturare-gestiune-oblio-woocommerce' ); ?></span>
				<span class="oblio-page-ver">v<?php echo esc_html( OBLIO_FGWOO_VERSION ); ?></span>
			</div>

			<nav class="oblio-tabs">
				<?php
				foreach ( $this->get_sections() as $id => $label ) :
					$dom = '' === $id ? 'connection' : $id;
					?>
					<a href="<?php echo esc_url( self::url( $id ) ); ?>"
						class="oblio-tab<?php echo esc_attr( $section === $id ? ' active' : '' ); ?>"
						data-section="<?php echo esc_attr( $dom ); ?>"><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>

			<div class="oblio-page-body" data-active="<?php echo esc_attr( $active_dom ); ?>">
				<?php
				if ( $saved ) {
					echo '<div class="notice notice-success inline oblio-saved"><p>' . esc_html__( 'Setările au fost salvate.', 'facturare-gestiune-oblio-woocommerce' ) . '</p></div>';
				}

				if ( 'status' === $section ) {
					$this->status_panel->render();
				} else {
					echo '<form method="post" action="" class="oblio-settings-form">';
					wp_nonce_field( 'oblio_fgwoo_save_settings' );
					foreach ( $this->settings_sections() as $id => $fields ) {
						$dom = '' === $id ? 'connection' : $id;
						printf( '<div class="oblio-section" data-section="%s">', esc_attr( $dom ) );
						$this->override_notice( $id );
						WC_Admin_Settings::output_fields( $fields );
						echo '</div>';
					}
					?>
					<p class="submit">
						<button type="submit" name="oblio_fgwoo_save" value="1"
								class="button button-primary"><?php esc_html_e( 'Salvează modificările', 'facturare-gestiune-oblio-woocommerce' ); ?></button>
					</p>
					<?php
					echo '</form>';
				}
				?>
			</div>
		</div>
		<?php
	}

	private function handle_save(): bool {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked below.
		if ( empty( $_POST['oblio_fgwoo_save'] ) ) {
			return false;
		}
		if ( ! current_user_can( 'manage_woocommerce' ) || ! check_admin_referer( 'oblio_fgwoo_save_settings' ) ) {
			return false;
		}

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- opaque token, trimmed; sanitized below.
		$raw_secret = isset( $_POST['oblio_fgwoo_secret'] ) ? trim( (string) wp_unslash( $_POST['oblio_fgwoo_secret'] ) ) : '';
		if ( '' !== $raw_secret ) {
			$this->factory->set_secret( sanitize_text_field( $raw_secret ) );
		}

		$secret_id = $this->settings->option_name( 'secret' );

		$display = array( 'oblio_secret', 'oblio_test', 'oblio_import', 'oblio_stock_sync', 'title', 'sectionend' );

		$fields = array();
		foreach ( $this->settings_sections() as $section_fields ) {
			foreach ( $section_fields as $field ) {
				if ( ( $field['id'] ?? '' ) === $secret_id ) {
					continue;
				}
				if ( in_array( $field['type'] ?? '', $display, true ) ) {
					continue;
				}
				$fields[] = $field;
			}
		}

		WC_Admin_Settings::save_fields( $fields );

		delete_transient( \OblioWoo\Queue\Scheduler::SCHEDULE_CHECK );

		return true;
	}

	private function settings_sections(): array {
		if ( null !== $this->sections_cache ) {
			return $this->sections_cache;
		}
		$sections = array();
		foreach ( array_keys( $this->get_sections() ) as $id ) {
			if ( 'status' === $id ) {
				continue;
			}
			$sections[ $id ] = $this->with_checkbox_labels( $this->get_settings( $id ) );
		}
		$this->sections_cache = $sections;

		return $sections;
	}

	private function with_checkbox_labels( array $fields ): array {
		foreach ( $fields as &$field ) {
			if ( 'checkbox' !== ( $field['type'] ?? '' ) ) {
				continue;
			}
			if ( '' !== (string) ( $field['desc'] ?? '' ) && empty( $field['desc_tip'] ) ) {
				$field['desc_tip'] = $field['desc'];
			}
			$field['desc'] = __( 'Activare', 'facturare-gestiune-oblio-woocommerce' );
		}
		unset( $field );

		return $fields;
	}

	private function override_notice( string $section ): void {
		$overridden = array();
		foreach ( $this->hook_registry->for_section( $section ) as $hook ) {
			if ( ! empty( $this->hook_inspector->overrides_for( $hook ) ) ) {
				$overridden[] = $hook;
			}
		}
		if ( empty( $overridden ) ) {
			return;
		}
		printf(
			'<div class="notice notice-info inline"><p>%s <code>%s</code>. %s</p></div>',
			esc_html__( 'Comportament personalizat prin filtre:', 'facturare-gestiune-oblio-woocommerce' ),
			implode( '</code>, <code>', array_map( 'esc_html', $overridden ) ),
			esc_html__( 'Vezi tabul „Stare” pentru sursă.', 'facturare-gestiune-oblio-woocommerce' )
		);
	}

	private function get_settings( string $section ): array {
		$opt = fn( string $key ): string => $this->settings->option_name( $key );

		switch ( $section ) {
			case '':
				return $this->connection_fields( $opt );
			case 'documents':
				return $this->documents_fields( $opt );
			case 'collection':
				return $this->collection_fields( $opt );
			case 'stock':
				return $this->stock_fields( $opt );
			case 'email':
				return $this->email_fields( $opt );
			case 'advanced':
				return $this->advanced_fields( $opt );
			default:
				return array();
		}
	}

	private function connection_fields( callable $opt ): array {
		return array(
			array(
				'title' => __( 'Conectare Oblio', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: link to the Oblio account settings page */
					__( 'Cheia API se găsește în Oblio → Contul meu → Setări → Date cont. %s', 'facturare-gestiune-oblio-woocommerce' ),
					'<a href="https://www.oblio.eu/account/settings" target="_blank" rel="noopener noreferrer" class="oblio-link">' . esc_html__( 'Deschide setările Oblio ↗', 'facturare-gestiune-oblio-woocommerce' ) . '</a>'
				),
				'id'    => 'oblio_fgwoo_connection',
			),
			array(
				'title' => __( 'Email', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'email',
				'id'    => $opt( 'email' ),
				'desc'  => __( 'Emailul contului Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Cheie API', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'oblio_secret',
				'id'    => $opt( 'secret' ),
			),
			array(
				'type' => 'oblio_test',
				'id'   => 'oblio_fgwoo_test',
			),

			array(
				'title'   => __( 'Firmă (CIF)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'cif' ),
				'options' => $this->cif_options(),
				'desc'    => __( 'Firma pentru care se emit documentele. Apasă „Preia ultimele date” ca să o încarci.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'oblio_import',
				'id'   => 'oblio_fgwoo_import',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_connection',
			),
		);
	}

	private function documents_fields( callable $opt ): array {
		return array(

			array(
				'title' => __( 'Serii și date document', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Aici poți configura setările comune ale documentelor, dar și opțiuni per document.', 'facturare-gestiune-oblio-woocommerce' ),
				'id'    => 'oblio_fgwoo_documents_series',
			),
			array(
				'title'   => __( 'Serie factură', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'series_invoice' ),
				'options' => $this->series_options( 'Factura' ),
				'desc'    => __( 'Seria pe care se emit facturile.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Serie proformă', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'series_proforma' ),
				'options' => $this->series_options( 'Proforma' ),
				'desc'    => __( 'Seria pe care se emit proformele.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Serie aviz', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'series_notice' ),
				'options' => $this->series_options( 'Aviz' ),
				'desc'    => __( 'Seria pe care se emit avizele de însoțire.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Data documentului', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'issue_date_basis' ),
				'options' => array(
					'issue' => __( 'Data emiterii', 'facturare-gestiune-oblio-woocommerce' ),
					'order' => __( 'Data comenzii', 'facturare-gestiune-oblio-woocommerce' ),
				),
				'desc'    => __( 'Ce dată apare pe document: ziua emiterii sau ziua comenzii.', 'facturare-gestiune-oblio-woocommerce' ),
			),

			array(
				'title'   => __( 'Punct de lucru', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'workstation' ),
				'options' => array( '' => __( 'Implicit', 'facturare-gestiune-oblio-woocommerce' ) ) + $this->nomenclature->workstations(),
				'desc'    => __( 'Punctul de lucru pe care se emit documentele. „Implicit” folosește setarea din Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Gestiune (emitere)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'management' ),
				'options' => array( '' => __( 'Implicit', 'facturare-gestiune-oblio-woocommerce' ) ) + $this->nomenclature->managements(),
				'desc'    => __( 'Gestiunea din care se descarcă stocul la emiterea documentelor.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'       => __( 'Unitate de măsură', 'facturare-gestiune-oblio-woocommerce' ),
				'type'        => 'text',
				'id'          => $opt( 'measuring_unit' ),
				'desc'        => __( 'Unitatea implicită pentru produsele fără una setată.', 'facturare-gestiune-oblio-woocommerce' ),
				'default'     => 'buc',
				'placeholder' => 'ex: buc',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_documents_series',
			),

			array(
				'title' => __( 'Proformă', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'id'    => 'oblio_fgwoo_documents_proforma',
			),
			array(
				'title' => __( 'Emite proformă automat', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'proforma_autogen' ),
				'desc'  => __( 'Generează o proformă automat, după regulile de mai jos.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'La primirea comenzii', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'checkbox',
				'id'      => $opt( 'proforma_on_received' ),
				'default' => 'yes',
				'desc'    => __( 'Emite proforma la recepționarea comenzii. Debifează pentru a o emite când comanda intră în anumite statusuri (alese mai jos).', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Statusuri pentru proformă', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'id'      => $opt( 'proforma_autogen_statuses' ),
				'options' => $this->order_status_options(),
				'desc'    => __( 'Proforma se emite când comanda intră într-unul dintre aceste statusuri. Folosit doar când „La primirea comenzii” este debifat.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Când se facturează o comandă cu proformă', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'proforma_on_invoice' ),
				'default' => 'transform',
				'options' => array(
					'transform' => __( 'Emite factura pe baza proformei', 'facturare-gestiune-oblio-woocommerce' ),
					'delete'    => __( 'Șterge proforma, apoi emite o factură nouă', 'facturare-gestiune-oblio-woocommerce' ),
				),
				'desc'    => __( 'Implicit, factura se generează pe baza proformei, iar proforma rămâne în Oblio, legată de factură (așa funcționează Oblio). „Șterge” elimină proforma din Oblio și emite o factură separată, fără legătură cu proforma.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_documents_proforma',
			),

			array(
				'title' => __( 'Factură', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'id'    => 'oblio_fgwoo_documents_invoice',
			),
			array(
				'title' => __( 'Emite factură automat', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'invoice_autogen' ),
				'desc'  => __( 'Când comanda ajunge la unul din statusurile alese mai jos.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Când se emit facturile', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'invoice_generation' ),
				'options' => array(
					'event' => __( 'La schimbarea statusului (imediat)', 'facturare-gestiune-oblio-woocommerce' ),
					'batch' => __( 'Programat (în loturi, la interval)', 'facturare-gestiune-oblio-woocommerce' ),
				),
				'desc'    => __( '„Imediat” emite factura când comanda intră în status. „Programat” emite periodic, util dacă factura se face abia la livrare.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Interval programare', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'invoice_batch_interval' ),
				'options' => $this->batch_interval_options(),
				'desc'    => __( 'Folosit doar în modul „Programat”. Independent de acest interval, factura se emite mai devreme dacă o altă acțiune are nevoie de ea (de exemplu, trimiterea emailului cu factura).', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Statusuri pentru emitere', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'id'      => $opt( 'invoice_autogen_statuses' ),
				'options' => $this->order_status_options(),
				'default' => $this->settings->default( 'invoice_autogen_statuses' ),
				'desc'    => __( 'Se poate selecta unul sau mai multe statusuri.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Descarcă din stoc la factura automată', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'invoice_autogen_use_stock' ),
				'desc'  => __( 'Scade cantitățile din gestiunea Oblio când factura se emite automat.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'             => __( 'Scadență (zile)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'              => 'number',
				'id'                => $opt( 'invoice_due' ),
				'custom_attributes' => array( 'min' => 0 ),
				'desc'              => __( 'Numărul de zile până la scadență. 0 = fără termen.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_documents_invoice',
			),

			array(
				'title' => __( 'Storno (rambursări)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'id'    => 'oblio_fgwoo_documents_storno',
			),
			array(
				'title' => __( 'Emite storno automat la rambursări', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'storno_autogen' ),
				'desc'  => __( 'La o rambursare WooCommerce (parțială sau totală) se emite un storno în Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			...$this->returns_field( $opt ),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_documents_storno',
			),
		);
	}

	private function returns_field( callable $opt ): array {
		if ( ! (bool) apply_filters( 'oblio_fgwoo_returns_experimental', false ) ) {
			return array();
		}

		return array(
			array(
				'title' => __( 'Emite storno la retururi (experimental)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'returns_storno' ),
				'desc'  => __( 'Experimental: funcția „Returns” din WooCommerce nu are încă un API stabil, așa că integrarea folosește hook-uri presupuse (filtrabile). Necesită funcția „Returns” activă. A nu se folosi în producție fără testare.', 'facturare-gestiune-oblio-woocommerce' ),
			),
		);
	}

	private function collection_fields( callable $opt ): array {
		return array(
			array(
				'title' => __( 'Încasare („marchează ca plătit”)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Marchează facturile ca încasate în Oblio, în funcție de metoda de plată.', 'facturare-gestiune-oblio-woocommerce' ),
				'id'    => 'oblio_fgwoo_collection',
			),
			array(
				'title'   => __( 'Mod încasare', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'collect_mode' ),
				'options' => array(
					'off'      => __( 'Dezactivat', 'facturare-gestiune-oblio-woocommerce' ),
					'card'     => __( 'Doar plăți cu cardul (nu ramburs / transfer)', 'facturare-gestiune-oblio-woocommerce' ),
					'all'      => __( 'Toate metodele (cu excepții)', 'facturare-gestiune-oblio-woocommerce' ),
					'selected' => __( 'Doar metodele selectate', 'facturare-gestiune-oblio-woocommerce' ),
				),
			),
			array(
				'title'   => __( 'Metode încasate', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'id'      => $opt( 'collect_gateways' ),
				'options' => $this->gateway_options(),
				'desc'    => __( 'Folosit când modul este „Doar metodele selectate”.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Excepții', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'id'      => $opt( 'collect_exceptions' ),
				'options' => $this->gateway_options(),
				'desc'    => __( 'Aceste metode NU se marchează ca încasate.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_collection',
			),
		);
	}

	private function stock_fields( callable $opt ): array {
		$endpoint = '<code>' . esc_html( rest_url( 'oblio/v1/webhook/' ) ) . '</code>';

		return array(
			array(
				'title' => __( 'Sincronizare stoc', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Poți să preiei stocul (și prețul) din Oblio în WooCommerce. <u>Codul produsului</u> Oblio trebuie să fie identic cu SKU-ul din WooCommerce. <br>O sincronizare parcurge întotdeauna întregul catalog, în loturi, nu doar produsul modificat, iar durata este proporțională cu numărul de modificări necesare.', 'facturare-gestiune-oblio-woocommerce' ),
				'id'    => 'oblio_fgwoo_stock',
			),
			array(
				'title'   => __( 'Mod sincronizare', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'stock_sync_trigger' ),
				'default' => $this->settings->stock_sync_trigger(),
				'options' => array(
					'off'      => __( 'Dezactivată', 'facturare-gestiune-oblio-woocommerce' ),
					'schedule' => __( 'Programată', 'facturare-gestiune-oblio-woocommerce' ),
					'webhook'  => __( 'Webhook (notificare din Oblio)', 'facturare-gestiune-oblio-woocommerce' ),
					'both'     => __( 'Ambele', 'facturare-gestiune-oblio-woocommerce' ),
				),
				'desc'    => __(
					'<strong>Programată</strong> - rulează la un interval fix definit mai jos și preia toate modificările.<br><strong>Webhook</strong> - pornește când Oblio anunță o modificare de stoc (după 15 min de la ultima notificare) - actualizare mai rapidă, dar se face o sincronizare completă a catalogului.<br><strong>Ambele</strong> - folosește ambele sisteme.',
					'facturare-gestiune-oblio-woocommerce'
				),
			),
			array(
				'title'   => __( 'Interval', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'stock_interval' ),
				'options' => $this->interval_options(),
				'desc'    => __( 'Cât de des rulează sincronizarea programată.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'             => __( 'Întârziere după webhook (minute)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'              => 'number',
				'id'                => $opt( 'webhook_stock_delay' ),
				'default'           => 15,
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				/* translators: %s: webhook endpoint URL */
				'desc'              => sprintf( __( 'După o notificare de la Oblio, sincronizarea așteaptă acest număr de minute de liniște înainte să pornească; fiecare notificare nouă resetează cronometrul. Astfel o rafală de modificări declanșează o singură sincronizare, nu una pentru fiecare notificare. Dacă o sincronizare este deja în curs când pornește cea nouă, cea în curs este oprită și repornită cu datele noi. De reținut: dacă folosești o casă de marcat sau o aplicație POS care schimbă stocul de multe ori, fiecare schimbare trimite o notificare separată, de aceea sincronizarea este grupată și amânată. Cererile sunt verificate printr-un secret în URL. Endpoint: %s', 'facturare-gestiune-oblio-woocommerce' ), $endpoint ),
			),
			array(
				'title'   => __( 'Gestiuni (locații)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'id'      => $opt( 'stock_locations' ),
				'options' => $this->nomenclature->locations(),
				'desc'    => __( 'Implicit - stocul se însumează pe locațiile selectate. <br>Lăsați câmpul gol pentru a le prelua pe toate SAU selectați locația de unde doriți actualizarea de stoc', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Actualizează prețul la sincronizare', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'stock_update_price' ),
				'desc'  => __( 'Preia prețul produsului din Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Rezervă stoc pentru comenzi nefacturate', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'stock_reserve_orders' ),
				'desc'  => __( 'Scade din stoc comenzile în așteptare / în procesare, din intervalul de mai jos.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'             => __( 'Interval rezervare (zile)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'              => 'number',
				'id'                => $opt( 'stock_reserve_days' ),
				'default'           => 30,
				'custom_attributes' => array(
					'min'  => 1,
					'step' => 1,
				),
				'desc'              => __( 'Câte zile în urmă se caută comenzile nefacturate care rezervă stoc. Implicit 30.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'oblio_stock_sync',
				'id'   => 'oblio_fgwoo_stock_sync_now',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_stock',
			),
		);
	}

	private function email_fields( callable $opt ): array {
		$tokens = '[serie] [numar] [link] [type] [issue_date] [due_date] [total] [contact_name] [client_name]';

		return array(
			array(
				'title' => __( 'Email către clienți', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'id'    => 'oblio_fgwoo_email',
			),
			array(
				'title'   => __( 'Mod notificare', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'email_mode' ),
				'default' => $this->settings->email_mode(),
				'options' => array(
					'off'        => __( 'Dezactivat', 'facturare-gestiune-oblio-woocommerce' ),
					'button'     => __( 'Nativ în mail-ul WooCommerce', 'facturare-gestiune-oblio-woocommerce' ),
					'standalone' => __( 'Email separat (la emitere)', 'facturare-gestiune-oblio-woocommerce' ),
				),
				'desc'    => __( '<strong>Email separat</strong> - trimite un mesaj propriu la emiterea documentului.<br>
                <strong>Buton</strong> - adaugă un buton către factură în emailul WooCommerce al comenzii.<br>
                <strong>Dacă factura nu există</strong> la trimiterea acelui email, atunci aceasta este emisă pe loc (chiar dacă emiterea automată este oprită)!', 'facturare-gestiune-oblio-woocommerce' ),
			),

			array(
				'title' => __( 'De la (email)', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'email',
				'id'    => $opt( 'email_from' ),
				'desc'  => __( 'Adresa afișată ca expeditor. Gol = adresa site-ului.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'CC', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'text',
				'id'    => $opt( 'email_cc' ),
				'desc'  => __( 'Adrese suplimentare în copie, separate prin virgulă.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'       => __( 'Subiect', 'facturare-gestiune-oblio-woocommerce' ),
				'type'        => 'text',
				'id'          => $opt( 'email_subject' ),
				'default'     => $this->settings->default( 'email_subject' ),
				'placeholder' => (string) $this->settings->default( 'email_subject' ),
			),
			array(
				'title'       => __( 'Mesaj', 'facturare-gestiune-oblio-woocommerce' ),
				'type'        => 'textarea',
				'id'          => $opt( 'email_message' ),
				'default'     => $this->settings->default( 'email_message' ),
				'placeholder' => (string) $this->settings->default( 'email_message' ),
				/* translators: %s: list of tokens */
					'desc'    => sprintf( __( 'Taguri: %s', 'facturare-gestiune-oblio-woocommerce' ), '<code>' . esc_html( $tokens ) . '</code>' ),
				'css'         => 'min-width:400px;height:150px;',
			),

			array(
				'title'   => __( 'Status de comandă', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'multiselect',
				'class'   => 'wc-enhanced-select',
				'id'      => $opt( 'email_button_statuses' ),
				'options' => $this->order_status_options(),
				'default' => $this->settings->default( 'email_button_statuses' ),
				'desc'    => __( 'Butonul apare în emailurile WooCommerce pentru aceste statusuri (ex. „Finalizată / Completed”).', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Text buton', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'text',
				'id'      => $opt( 'email_button_label' ),
				'default' => $this->settings->default( 'email_button_label' ),
				'desc'    => __( 'Textul afișat pe butonul din email.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_email',
			),
		);
	}

	private function advanced_fields( callable $opt ): array {
		$mention_tokens      = '[order_id] [date] [payment] [shipping] [site]';
		$mention_placeholder = __( "Pentru comanda [order_id], din [date], plătit prin [payment], livrat prin [shipping].\nComandă efectuată pe [site]", 'facturare-gestiune-oblio-woocommerce' );

		return array(
			array(
				'title' => __( 'Opțiuni avansate', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'title',
				'id'    => 'oblio_fgwoo_advanced',
			),
			array(
				'title'   => __( 'Limbă document', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'language' ),
				'options' => $this->language_options(),
				'desc'    => __( 'Limba în care se emit documentele în Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'   => __( 'Tip produs implicit', 'facturare-gestiune-oblio-woocommerce' ),
				'type'    => 'select',
				'id'      => $opt( 'product_type' ),
				'options' => $this->product_type_options(),
				'desc'    => __( 'Tipul cu care se trimit produsele noi către Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title'       => __( 'Mențiuni', 'facturare-gestiune-oblio-woocommerce' ),
				'type'        => 'textarea',
				'id'          => $opt( 'invoice_mentions' ),
				'placeholder' => $mention_placeholder,
				/* translators: %s: list of tokens */
					'desc'    => sprintf( __( 'Taguri: %s', 'facturare-gestiune-oblio-woocommerce' ), '<code>' . esc_html( $mention_tokens ) . '</code>' ),
			),
			array(
				'title' => __( 'Întocmit de', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'text',
				'id'    => $opt( 'invoice_issuer_name' ),
				'desc'  => __( 'Numele persoanei care apare ca întocmitor al documentului.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Delegat', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'text',
				'id'    => $opt( 'invoice_deputy_name' ),
				'desc'  => __( 'Numele delegatului, dacă documentul îl cere.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Completează automat datele firmelor după CIF', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'autocomplete_company' ),
				'desc'  => __( 'La comenzile tip B2B, preia datele firmei din Oblio pe baza CIF-ului pentru a fi folosite în datele de facturare', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Ascunde detalii produs', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'hide_description' ),
				'desc'  => __( 'Trece pe document doar numele produsului, fără descriere.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Include discountul în prețul produsului', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'invoice_discount_in_product' ),
				'desc'  => __( 'Scade discountul direct din preț, fără o linie separată de reducere.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'NU salva prețul în Oblio la emitere', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'notsave_price' ),
				'desc'  => __( 'Emite documentul fără a actualiza prețul produsului în nomenclatorul Oblio.', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'title' => __( 'Jurnalizare / debug', 'facturare-gestiune-oblio-woocommerce' ),
				'type'  => 'checkbox',
				'id'    => $opt( 'debug_logging' ),
				'desc'  => __( 'Adaugă intrări detaliate în jurnalul WooCommerce, le poți vedea în secțiunea "Stare".', 'facturare-gestiune-oblio-woocommerce' ),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'oblio_fgwoo_advanced',
			),
		);
	}

	private function cif_options(): array {
		$options = array( '' => __( 'Selectează', 'facturare-gestiune-oblio-woocommerce' ) );
		foreach ( $this->nomenclature->companies() as $cif => $name ) {
			$options[ (string) $cif ] = sprintf( ' % s (%s)', (string) $name, (string) $cif );
		}
		$current = (string) $this->settings->get( 'cif' );
		if ( '' !== $current && ! isset( $options[ $current ] ) ) {
			$options[ $current ] = $current;
		}

		return $options;
	}

	private function series_options( string $type ): array {
		return array( '' => __( 'Selectează', 'facturare-gestiune-oblio-woocommerce' ) ) + $this->nomenclature->series( $type );
	}

	private function gateway_options(): array {
		$options = array();
		if ( function_exists( 'WC' ) && WC()->payment_gateways ) {
			foreach ( WC()->payment_gateways->payment_gateways() as $id => $gateway ) {
				$title          = $gateway->get_title();
				$options[ $id ] = '' !== $title ? $title : $id;
			}
		}

		return $options;
	}

	private function interval_options(): array {
		return array(
			'hourly' => __( 'La fiecare oră', 'facturare-gestiune-oblio-woocommerce' ),
			'6h'     => __( 'La fiecare 6 ore', 'facturare-gestiune-oblio-woocommerce' ),
			'12h'    => __( 'La fiecare 12 ore', 'facturare-gestiune-oblio-woocommerce' ),
			'daily'  => __( 'La fiecare 24 ore', 'facturare-gestiune-oblio-woocommerce' ),
		);
	}

	private function batch_interval_options(): array {
		return array(
			'1min'   => __( 'La fiecare minut', 'facturare-gestiune-oblio-woocommerce' ),
			'5min'   => __( 'La fiecare 5 minute', 'facturare-gestiune-oblio-woocommerce' ),
			'15min'  => __( 'La fiecare 15 minute', 'facturare-gestiune-oblio-woocommerce' ),
			'30min'  => __( 'La fiecare 30 de minute', 'facturare-gestiune-oblio-woocommerce' ),
			'hourly' => __( 'La fiecare oră', 'facturare-gestiune-oblio-woocommerce' ),
			'3h'     => __( 'La fiecare 3 ore', 'facturare-gestiune-oblio-woocommerce' ),
			'6h'     => __( 'La fiecare 6 ore', 'facturare-gestiune-oblio-woocommerce' ),
			'12h'    => __( 'La fiecare 12 ore', 'facturare-gestiune-oblio-woocommerce' ),
		);
	}

	private function order_status_options(): array {
		$options = array();
		if ( function_exists( 'wc_get_order_statuses' ) ) {
			foreach ( wc_get_order_statuses() as $key => $label ) {
				$options[ str_replace( 'wc-', '', $key ) ] = $label;
			}
		}

		return $options;
	}

	private function language_options(): array {
		return array(
			'RO' => 'Română',
			'EN' => 'Engleză',
			'FR' => 'Franceză',
			'IT' => 'Italiană',
			'SP' => 'Spaniolă',
			'HU' => 'Maghiară',
			'DE' => 'Germană',
			'BG' => 'Bulgară',
		);
	}

	private function product_type_options(): array {
		$types = array(
			'Marfa',
			'Semifabricate',
			'Produs finit',
			'Produs rezidual',
			'Produse agricole',
			'Animale si pasari',
			'Ambalaje',
			'Serviciu',
		);

		return array_combine( $types, $types );
	}

	public function render_secret_field( array $field ): void {
		$id          = (string) ( $field['id'] ?? '' );
		$placeholder = $this->factory->has_secret()
				? __( '••••••••(setat, lasă gol pentru a păstra)', 'facturare-gestiune-oblio-woocommerce' )
				: '';
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"><label
						for="<?php echo esc_attr( $id ); ?>"><?php esc_html_e( 'API secret', 'facturare-gestiune-oblio-woocommerce' ); ?></label>
			</th>
			<td class="forminp">
				<input type="password" name="<?php echo esc_attr( $id ); ?>" id="<?php echo esc_attr( $id ); ?>"
						value="" autocomplete="new-password" placeholder="<?php echo esc_attr( $placeholder ); ?>"
						class="regular-text"/>
			</td>
		</tr>
		<?php
	}

	public function render_test_field( array $field ): void {
		unset( $field );
		?>
		<tr valign="top">
			<th scope="row" class="titledesc"></th>
			<td class="forminp">
				<button type="button" class="button button-primary"
						id="oblio_fgwoo_test_connection"><?php esc_html_e( 'Preia ultimele date', 'facturare-gestiune-oblio-woocommerce' ); ?></button>
				<span id="oblio_fgwoo_test_result" style="margin-inline-start:8px;"></span>
				<p class="description"><?php esc_html_e( 'Verifică datele de conectare și încarcă firma și seriile din Oblio.', 'facturare-gestiune-oblio-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render_import_field( array $field ): void {
		unset( $field );
		if ( false === get_option( 'oblio_email', false ) ) {
			return;
		}
		?>
		<tr valign="top">
			<th scope="row"
				class="titledesc"><?php esc_html_e( 'Import din pluginul vechi', 'facturare-gestiune-oblio-woocommerce' ); ?></th>
			<td class="forminp">
				<button type="button"
						class="button oblio-import-now"><?php esc_html_e( 'Importă setările', 'facturare-gestiune-oblio-woocommerce' ); ?></button>
				<span class="oblio-import-result" style="margin-inline-start:8px;"></span>
				<p class="description"><?php esc_html_e( 'Copiază setările din „WooCommerce Oblio”. Facturile deja emise se afișează automat.', 'facturare-gestiune-oblio-woocommerce' ); ?></p>
			</td>
		</tr>
		<?php
	}

	public function render_stock_sync_field( array $field ): void {
		unset( $field );
		$last = (int) get_option( \OblioWoo\Stock\StockSyncCoordinator::LAST_SYNC_OPTION, 0 );
		?>
		<tr valign="top">
			<th scope="row"
				class="titledesc"><?php esc_html_e( 'Sincronizare manuală', 'facturare-gestiune-oblio-woocommerce' ); ?></th>
			<td class="forminp">
				<?php if ( $this->settings->stock_sync_configured() ) : ?>
					<button type="button"
							class="button oblio-sync-now"><?php esc_html_e( 'Sincronizează acum', 'facturare-gestiune-oblio-woocommerce' ); ?></button>
					<span class="oblio-sync-result" style="margin-inline-start:8px;"></span>
					<?php if ( $last ) : ?>
						<p class="description">
							<?php
							/* translators: %s: human time diff */
							printf( esc_html__( 'Ultima sincronizare: %s în urmă.', 'facturare-gestiune-oblio-woocommerce' ), esc_html( human_time_diff( $last ) ) );
							?>
						</p>
					<?php endif; ?>
				<?php else : ?>
					<p class="description"><?php esc_html_e( 'Alege un mod de declanșare mai sus (altul decât „Dezactivată”) pentru a putea sincroniza manual.', 'facturare-gestiune-oblio-woocommerce' ); ?></p>
				<?php endif; ?>
			</td>
		</tr>
		<?php
	}
}
