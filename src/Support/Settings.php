<?php
/**
 * Typed settings accessor over wp_options.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Support;

final class Settings {

	private const PREFIX = 'oblio_fgwoo_';

	private const DEFAULTS = array(

		'email'                        => '',
		'secret'                       => '',
		'cif'                          => '',

		'invoice_autogen'              => 'no',
		'invoice_generation'           => 'event',
		'invoice_batch_interval'       => 'hourly',
		'invoice_autogen_status'       => 'completed',
		'invoice_autogen_statuses'     => array( 'completed' ),
		'invoice_autogen_use_stock'    => 'no',
		'proforma_autogen'             => 'no',
		'proforma_on_received'         => 'yes',
		'proforma_autogen_statuses'    => array(),
		'notice_enabled'               => 'no',
		'series_invoice'               => '',
		'series_proforma'              => '',
		'series_notice'                => '',
		'proforma_on_invoice'          => 'transform',
		'storno_autogen'               => 'no',
		'returns_storno'               => 'no',
		'issue_date_basis'             => 'issue',
		'language'                     => 'RO',
		'measuring_unit'               => 'buc',
		'measuring_unit_translation'   => '',
		'product_type'                 => 'Marfa',
		'workstation'                  => '',
		'management'                   => '',
		'invoice_due'                  => 0,
		'invoice_discount_in_product'  => 'no',
		'hide_description'             => 'no',
		'notsave_price'                => 'no',
		'autocomplete_company'         => 0,
		'invoice_mentions'             => '',
		'invoice_issuer_name'          => '',
		'invoice_issuer_id'            => '',
		'invoice_deputy_name'          => '',
		'invoice_deputy_identity_card' => '',
		'invoice_deputy_auto'          => '',
		'invoice_seles_agent'          => '',

		'collect_mode'                 => 'card',
		'collect_gateways'             => array(),
		'collect_exceptions'           => array(),

		'stock_sync'                   => 'no',
		'stock_interval'               => 'hourly',
		'stock_locations'              => array(),
		'stock_update_price'           => 'no',
		'stock_reserve_orders'         => 'no',
		'stock_reserve_days'           => 30,
		'webhook_stock_delay'          => 15,
		'stock_manual_batch'           => 250,

		'email_mode'                   => 'off',
		'email_on_issue'               => 'no',
		'email_from'                   => '',
		'email_cc'                     => '',
		'email_subject'                => 'S-a emis [type] [serie] [numar]',
		'email_message'                => "Buna ziua,\n\nVa informam ca am emis [type] [serie] [numar].\n\nPentru mai multe detalii legate de [type], accesati linkul de mai jos:\n[link]\n\nDaca sunt intrebari sau neclaritati, nu ezitati sa ne contactati.\n\nVa multumim.",

		'email_button_statuses'        => array( 'completed' ),
		'email_button_label'           => 'Vezi factura',

		'webhooks_enabled'             => 'no',
		'webhook_secret'               => '',
		'webhook_stock'                => 'no',
		'webhook_topics'               => array(),

		'debug_logging'                => 'no',
	);

	public function get( string $key, $default = null ) {
		$fallback = array_key_exists( $key, self::DEFAULTS ) ? self::DEFAULTS[ $key ] : $default;
		return get_option( self::PREFIX . $key, $fallback );
	}

	public function set( string $key, $value ): void {
		update_option( self::PREFIX . $key, $value );
	}

	public function is_enabled( string $key ): bool {
		return 'yes' === $this->get( $key );
	}

	public function default( string $key ) {
		return self::DEFAULTS[ $key ] ?? null;
	}

	public function option_name( string $key ): string {
		return self::PREFIX . $key;
	}

	public function invoice_statuses(): array {

		$raw      = get_option( self::PREFIX . 'invoice_autogen_statuses', null );
		$statuses = is_array( $raw ) ? array_values( array_filter( array_map( 'strval', $raw ) ) ) : array();
		if ( empty( $statuses ) ) {
			$single   = (string) $this->get( 'invoice_autogen_status', 'completed' );
			$statuses = '' !== $single ? array( $single ) : array( 'completed' );
		}
		return $statuses;
	}

	public function email_mode(): string {

		$mode = (string) get_option( self::PREFIX . 'email_mode', '' );
		if ( '' === $mode ) {
			return 'yes' === $this->get( 'email_on_issue' ) ? 'standalone' : 'off';
		}
		return $mode;
	}

	public function stock_sync_trigger(): string {
		$stored = (string) get_option( self::PREFIX . 'stock_sync_trigger', '' );

		// Webhook trigger temporarily disabled (payload under verification); keep any existing schedule state.
		if ( 'both' === $stored ) {
			return 'schedule';
		}
		if ( 'webhook' === $stored ) {
			return 'off';
		}
		if ( in_array( $stored, array( 'off', 'schedule' ), true ) ) {
			return $stored;
		}

		$scheduled = 'yes' === get_option( self::PREFIX . 'stock_sync', 'no' );
		return $scheduled ? 'schedule' : 'off';
	}

	public function stock_schedule_enabled(): bool {
		return 'schedule' === $this->stock_sync_trigger();
	}

	public function stock_webhook_enabled(): bool {
		return false;
	}

	public function stock_sync_configured(): bool {
		return 'off' !== $this->stock_sync_trigger();
	}

	public function webhook_stock_delay(): int {
		return max( MINUTE_IN_SECONDS, (int) $this->get( 'webhook_stock_delay', 15 ) * MINUTE_IN_SECONDS );
	}

	public function stock_manual_batch(): int {
		$oblio_page = 250;
		$raw        = (int) $this->get( 'stock_manual_batch', $oblio_page );
		if ( $raw <= 0 ) {
			return 0;
		}
		$snapped = (int) ( round( $raw / $oblio_page ) * $oblio_page );
		return max( $oblio_page, min( 10000, $snapped ) );
	}

	public function has_credentials(): bool {
		return '' !== (string) $this->get( 'email' ) && '' !== (string) $this->get( 'secret' );
	}
}
