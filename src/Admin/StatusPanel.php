<?php
/**
 * Renders the Oblio Status panel (log, queue, update, hook overrides).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

use FGSyncOblio\Compat\OrderStore;
use FGSyncOblio\Extensibility\HookInspector;
use FGSyncOblio\Queue\Scheduler;
use FGSyncOblio\Stock\StockSyncCoordinator;
use FGSyncOblio\Support\ConnectionHealth;
use FGSyncOblio\Support\Settings;
final class StatusPanel {

	/**
	 * Settings never allowed into the diagnostics dump - personal/business
	 * identifying data (account email, API secret, company tax ID, named
	 * individuals and their ID documents). The dump is meant to be pasted
	 * into a public support ticket, so these are omitted outright rather
	 * than masked.
	 */
	public const LOG_CARD = 'oblio-fgwoo-log';

	public const HOOKS_CARD = 'oblio-fgwoo-hooks';

	private const OMIT_FROM_DUMP = array(
		'email',
		'secret',
		'cif',
		'email_from',
		'email_cc',
		'invoice_issuer_name',
		'invoice_issuer_id',
		'invoice_deputy_name',
		'invoice_deputy_identity_card',
		'invoice_deputy_auto',
		'invoice_seles_agent',
	);

	private Settings $settings;

	private LogReader $log;

	private QueueStatus $queue;

	private UpdateChecker $update;

	private HookInspector $hooks;

	private ConnectionHealth $health;

	private OrderStore $orders;

	public function __construct( Settings $settings, LogReader $log, QueueStatus $queue, UpdateChecker $update, HookInspector $hooks, ConnectionHealth $health, OrderStore $orders ) {
		$this->settings = $settings;
		$this->log      = $log;
		$this->queue    = $queue;
		$this->update   = $update;
		$this->hooks    = $hooks;
		$this->health   = $health;
		$this->orders   = $orders;
	}

	public function render(): void {
		$entries    = $this->log->today();
		$totals     = $this->queue->totals();
		$by_hook    = $this->queue->by_hook();
		$overrides  = $this->hooks->all_overrides();
		$docs_today = $this->count_issued( $entries );

		echo '<div class="oblio-fgwoo-status">';
		$this->admin_bar_toggle();
		$this->summary( $totals, $docs_today );
		echo '<div class="oblio-fgwoo-grid">';
		echo '<div class="oblio-fgwoo-col-main">';
		$this->log_card( $entries );
		echo '</div><div class="oblio-fgwoo-col-side">';
		$this->queue_card( $totals, $by_hook );
		$this->update_card();
		$this->hooks_card( $overrides );
		echo '</div></div>';
		$this->config_card( $totals, $by_hook );
		echo '</div>';
	}

	private function admin_bar_toggle(): void {
		$checked = $this->settings->is_enabled( 'admin_bar_status' );
		echo '<label class="oblio-fgwoo-adminbar-toggle"><input type="checkbox" id="oblio-fgwoo-adminbar-toggle"' . ( $checked ? ' checked' : '' ) . '> '
			. esc_html__( 'Bulină de stare conexiune cu Oblio.eu', 'fgsync-oblio' ) . '</label><br>';
	}

	private function summary( array $totals, int $docs_today ): void {
		$connected = $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
		$last_sync = (int) get_option( StockSyncCoordinator::LAST_SYNC_OPTION, 0 );

		echo '<div class="oblio-fgwoo-summary">';

		$this->tile(
			__( 'Conexiune', 'fgsync-oblio' ),
			$connected ? esc_html__( 'Configurată', 'fgsync-oblio' ) : esc_html__( 'Neconfigurată', 'fgsync-oblio' ),
			$connected ? esc_html( (string) $this->settings->get( 'cif' ) ) : sprintf(
				/* translators: %s: link to the Conectare tab */
				esc_html__( 'Introdu datele în %s', 'fgsync-oblio' ),
				SettingsPage::tab_link( '', 'oblio_fgwoo_email', __( 'Conectare', 'fgsync-oblio' ) )
			)
		);

		$this->tile(
			__( 'Documente azi', 'fgsync-oblio' ),
			(string) $docs_today,
			esc_html__( 'aprox. · din jurnalul fișier', 'fgsync-oblio' ),
			'orange'
		);
		/* translators: %d: number of pending queue jobs */
		$pending_text = sprintf( esc_html__( '%d în așteptare', 'fgsync-oblio' ), (int) $totals['pending'] );
		$this->tile(
			__( 'Coadă', 'fgsync-oblio' ),
			sprintf( '%d / %d', (int) $totals['in-progress'], (int) $totals['failed'] ),
			esc_html__( 'active / eșuate', 'fgsync-oblio' ) . ' · ' . $pending_text
		);
		$this->tile(
			__( 'Sincronizare stoc', 'fgsync-oblio' ),
			$last_sync ? esc_html( human_time_diff( $last_sync ) ) : esc_html__( 'niciodată', 'fgsync-oblio' ),
			$last_sync ? esc_html__( 'în urmă', 'fgsync-oblio' ) : '',
			'purple'
		);
		echo '</div>';

		$skipped = StockSyncCoordinator::skipped_message(
			StockSyncCoordinator::result( (array) get_option( StockSyncCoordinator::LAST_RESULT_OPTION, array() ) ),
			SettingsPage::type_skip_links()
		);
		if ( '' !== $skipped ) {
			echo '<div class="notice notice-warning inline"><p>' . wp_kses_post( $skipped ) . '</p></div>';
		}
	}

	private function tile( string $label, string $value, string $sub, string $tone = 'ink' ): void {
		printf(
			'<div class="oblio-fgwoo-tile"><div class="lab">%s</div><div class="val v-%s">%s</div><div class="sub">%s</div></div>',
			esc_html( $label ),
			esc_attr( $tone ),
			esc_html( $value ),
			wp_kses_post( $sub )
		);
	}

	private function log_card( array $entries ): void {
		$logs_url = admin_url( 'admin.php?page=wc-status&tab=logs' );

		echo '<div class="oblio-fgwoo-card" id="' . esc_attr( self::LOG_CARD ) . '"><div class="oblio-fgwoo-card-h"><h2>' . esc_html__( 'Jurnal activitate · azi', 'fgsync-oblio' ) . '</h2>';
		echo '<span class="oblio-fgwoo-spacer"></span>';
		echo '<label class="oblio-fgwoo-autoupdate"><input type="checkbox" id="oblio-fgwoo-log-autoupdate"> ' . esc_html__( 'Actualizare automată', 'fgsync-oblio' ) . '</label>';
		echo '<a class="oblio-fgwoo-link" href="' . esc_url( $logs_url ) . '" target="_blank">' . esc_html__( 'Jurnal complet ↗', 'fgsync-oblio' ) . '</a></div>';

		echo '<div class="oblio-fgwoo-logbody" id="oblio-fgwoo-logbody">' . LogRenderer::rows( $entries ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows already escaped per-field in LogRenderer.
	}

	private function queue_card( array $totals, array $by_hook ): void {
		$as_url = admin_url( 'admin.php?page=wc-status&tab=action-scheduler' );

		echo '<div class="oblio-fgwoo-card"><div class="oblio-fgwoo-card-h"><h2>' . esc_html__( 'Coadă procesare', 'fgsync-oblio' ) . '</h2>';
		echo '<span class="oblio-fgwoo-spacer"></span><span class="oblio-fgwoo-grp">grup <code>' . esc_html( Scheduler::GROUP ) . '</code></span></div>';

		foreach ( $by_hook as $label => $counts ) {
			echo '<div class="oblio-fgwoo-qrow"><span class="qname">' . esc_html( $label ) . '</span><span class="oblio-fgwoo-spacer"></span>';
			printf( '<span class="count">%d</span>', (int) $counts['pending'] );
			if ( $counts['failed'] > 0 ) {
				printf( '<span class="count fail">%d</span>', (int) $counts['failed'] );
			}
			echo '</div>';
		}

		echo '<div class="oblio-fgwoo-qactions">';

		if ( $this->settings->stock_sync_configured() ) {
			echo '<button type="button" class="button button-primary oblio-fgwoo-sync-now" title="' . esc_attr__( 'Rulează întregul catalog acum, prin pași succesivi; poate dura câteva minute pe cataloage mari.', 'fgsync-oblio' ) . '">' . esc_html__( 'Sincronizează stoc', 'fgsync-oblio' ) . '</button>';
			echo '<span class="oblio-fgwoo-sync-result"></span>';
			if ( StockSyncCoordinator::is_run_stale() ) {
				echo '<button type="button" class="button oblio-fgwoo-sync-unlock" title="' . esc_attr__( 'O sincronizare pare blocată (probabil întreruptă de server înainte să termine).', 'fgsync-oblio' ) . '">' . esc_html__( 'Deblochează sincronizarea', 'fgsync-oblio' ) . '</button>';
				echo '<span class="oblio-fgwoo-unlock-result"></span>';
			}
		}
		echo '<a class="button" href="' . esc_url( $as_url ) . '">' . esc_html__( 'Vezi coada', 'fgsync-oblio' ) . '</a>';
		echo '</div></div>';
	}

	private function update_card(): void {
		$installed = $this->update->installed();
		$latest    = $this->update->latest();
		$available = $this->update->update_available();
		$checked   = $this->update->last_checked();

		echo '<div class="oblio-fgwoo-card"><div class="oblio-fgwoo-card-h"><h2>' . esc_html__( 'Actualizare', 'fgsync-oblio' ) . '</h2>';
		echo '<span class="oblio-fgwoo-spacer"></span>';
		if ( $available ) {
			echo '<span class="oblio-fgwoo-badge-upd">' . esc_html__( 'disponibilă', 'fgsync-oblio' ) . '</span>';
		}
		echo '</div><div class="oblio-fgwoo-upd">';
		if ( $available ) {
			printf( '<div class="verline"><span class="cur">%s</span><span class="arrow">→</span><span class="new">%s</span></div>', esc_html( $installed ), esc_html( $latest ) );
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Actualizează', 'fgsync-oblio' ) . '</a>';
		} else {
			printf( '<div class="verline"><span class="cur">%s</span> <span class="oblio-fgwoo-ok">%s</span></div>', esc_html( $installed ), esc_html__( 'la zi', 'fgsync-oblio' ) );
		}
		echo '<div class="meta">';
		if ( $checked ) {
			/* translators: %s: human-readable time difference (e.g. "2 hours") */
			printf( esc_html__( 'Verificat %s în urmă.', 'fgsync-oblio' ), esc_html( human_time_diff( $checked ) ) );
		}
		echo ' <a class="oblio-fgwoo-link" href="' . esc_url( $this->update->check_now_url() ) . '">' . esc_html__( 'Verifică acum', 'fgsync-oblio' ) . '</a></div>';
		echo '<div class="note">' . esc_html__( 'Actualizările sunt verificate și livrate prin WordPress.org.', 'fgsync-oblio' ) . '</div>';
		echo '</div></div>';
	}

	private function hooks_card( array $overrides ): void {
		echo '<div class="oblio-fgwoo-card" id="' . esc_attr( self::HOOKS_CARD ) . '"><div class="oblio-fgwoo-card-h"><h2>' . esc_html__( 'Suprascrieri acțiuni', 'fgsync-oblio' ) . '</h2>';
		echo '<span class="oblio-fgwoo-spacer"></span><span class="oblio-fgwoo-count-warn">' . (int) count( $overrides ) . '</span></div>';

		if ( empty( $overrides ) ) {
			echo '<p class="oblio-fgwoo-empty">' . esc_html__( 'Niciun hook suprascris, comportament implicit.', 'fgsync-oblio' ) . '</p></div>';
			return;
		}

		foreach ( $overrides as $hook => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				echo '<div class="oblio-fgwoo-hookrow"><span class="hookname">' . esc_html( $hook ) . '</span>';
				echo '<span class="oblio-fgwoo-tag over">' . esc_html__( 'suprascris', 'fgsync-oblio' ) . '</span>';
				printf(
					'<span class="hooksrc">%s:%d · prioritate %d</span></div>',
					esc_html( $this->short_path( $callback['file'] ) ),
					(int) $callback['line'],
					(int) $callback['priority']
				);
			}
		}

		echo '<div class="oblio-fgwoo-hooknote">' . esc_html__( 'Suprascrierile sunt permise, listate aici ca să nu fie niciodată tăcute.', 'fgsync-oblio' ) . '</div></div>';
	}

	/**
	 * @param array<string,int>               $totals  Pending/in-progress/failed counts.
	 * @param array<string,array<string,int>> $by_hook Pending/failed counts per queue job.
	 */
	private function config_card( array $totals, array $by_hook ): void {
		echo '<div class="oblio-fgwoo-card oblio-fgwoo-config-card"><div class="oblio-fgwoo-card-h"><h2>' . esc_html__( 'Diagnoză rapidă', 'fgsync-oblio' ) . '</h2>';
		echo '<span class="oblio-fgwoo-spacer"></span>';
		echo '<button type="button" class="button oblio-fgwoo-config-copy" data-label="' . esc_attr__( 'Copiază', 'fgsync-oblio' ) . '" data-copied="' . esc_attr__( 'Copiat ✓', 'fgsync-oblio' ) . '">' . esc_html__( 'Copiază', 'fgsync-oblio' ) . '</button></div>';
		echo '<pre class="oblio-fgwoo-config-dump" id="oblio-fgwoo-config-dump">' . esc_html( $this->build_diagnostics_dump( $totals, $by_hook ) ) . '</pre>';
		echo '<div class="oblio-fgwoo-hooknote">' . sprintf(
			/* translators: %s: link to the GitHub new-issue page */
			esc_html__( 'Nu include cheia API, emailul contului, CIF-ul sau alte date cu caracter personal. Trimite-ne acest text când %s.', 'fgsync-oblio' ),
			'<a href="https://github.com/rwkyyy/fgsync-oblio/issues/new" target="_blank" rel="noopener noreferrer" class="oblio-fgwoo-link">' . esc_html__( 'deschizi un raport nou pe GitHub ↗', 'fgsync-oblio' ) . '</a>'
		) . '</div></div>';
	}

	/**
	 * Markdown, wrapped in a collapsed <details> block - this is meant to be
	 * pasted directly into a GitHub issue, where it renders as a table
	 * instead of a wall of text and stays out of the way until expanded.
	 *
	 * @param array<string,int>               $totals  Pending/in-progress/failed counts.
	 * @param array<string,array<string,int>> $by_hook Pending/failed counts per queue job.
	 */
	private function build_diagnostics_dump( array $totals, array $by_hook ): string {
		global $wp_version;

		$lines   = array();
		$lines[] = '<details>';
		$lines[] = '<summary>Store debug info · FGSync pentru Oblio</summary>';
		$lines[] = '';
		$lines[] = sprintf(
			'FGSync pentru Oblio %s · WordPress %s · WooCommerce %s · PHP %s',
			FGSYNC_OBLIO_VERSION,
			isset( $wp_version ) ? $wp_version : '—',
			defined( 'WC_VERSION' ) ? WC_VERSION : '—',
			PHP_VERSION
		);
		$lines[] = '';
		$lines[] = '**Mediu**';
		$lines[] = '';
		array_push( $lines, ...$this->markdown_table( $this->environment_rows() ) );
		$lines[] = '';
		$lines[] = '**WooCommerce**';
		$lines[] = '';
		array_push( $lines, ...$this->markdown_table( $this->woocommerce_rows() ) );
		$lines[] = '';
		$lines[] = '**Coadă Oblio (Action Scheduler)**';
		$lines[] = '';
		array_push( $lines, ...$this->markdown_table( $this->queue_rows( $totals, $by_hook ) ) );
		$lines[] = '';
		$lines[] = '**Conexiune Oblio**';
		$lines[] = '';
		array_push( $lines, ...$this->markdown_table( $this->connection_rows() ) );
		$lines[] = '';
		$lines[] = '**Setări plugin**';
		$lines[] = '';
		array_push( $lines, ...$this->markdown_table( $this->settings_rows() ) );
		$lines[] = '';
		$lines[] = '</details>';

		return implode( "\n", $lines );
	}

	/**
	 * @param array<string,string> $rows Label => value pairs for one section.
	 * @return array<int,string>
	 */
	private function markdown_table( array $rows ): array {
		$lines   = array();
		$lines[] = '| Cheie | Valoare |';
		$lines[] = '|---|---|';
		foreach ( $rows as $key => $value ) {
			$lines[] = sprintf( '| %s | %s |', $this->table_cell( (string) $key ), $this->table_cell( $value ) );
		}
		return $lines;
	}

	/**
	 * Escapes a value for a Markdown table cell - pipes would otherwise
	 * split into extra columns, and raw newlines (e.g. the multi-line email
	 * template settings) would break the row entirely.
	 *
	 * @param string $value Raw cell content.
	 */
	private function table_cell( string $value ): string {
		$value = str_replace( '|', '\\|', $value );
		return str_replace( array( "\r\n", "\n" ), '<br>', $value );
	}

	/**
	 * @return array<string,string>
	 */
	private function environment_rows(): array {
		return array(
			'hpos'                    => $this->yn( $this->orders->is_hpos() ),
			'multisite'               => $this->yn( is_multisite() ),
			'wp_debug'                => $this->yn( defined( 'WP_DEBUG' ) && WP_DEBUG ),
			'wp_cron'                 => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? 'dezactivat (necesită cron real)' : 'activ',
			'php_memory_limit'        => $this->ini( 'memory_limit' ),
			'php_max_execution_time'  => $this->ini( 'max_execution_time' ) . 's',
			'php_post_max_size'       => $this->ini( 'post_max_size' ),
			'php_upload_max_filesize' => $this->ini( 'upload_max_filesize' ),
		);
	}

	/**
	 * @return array<string,string>
	 */
	private function woocommerce_rows(): array {
		$classes = array( 'Standard' );
		if ( class_exists( \WC_Tax::class ) ) {
			array_push( $classes, ...\WC_Tax::get_tax_classes() );
		}

		return array(
			'moneda'                  => function_exists( 'get_woocommerce_currency' ) ? get_woocommerce_currency() : '—',
			'taxe_active'             => $this->yn( function_exists( 'wc_tax_enabled' ) && wc_tax_enabled() ),
			'taxe_calculate_dupa'     => $this->tax_based_on_label( (string) get_option( 'woocommerce_tax_based_on', 'shipping' ) ),
			'preturi_cu_taxe_incluse' => $this->yn( function_exists( 'wc_prices_include_tax' ) && wc_prices_include_tax() ),
			'clase_taxe'              => implode( ', ', $classes ),
		);
	}

	/**
	 * Translates WooCommerce's internal woocommerce_tax_based_on values
	 * (shipping/billing/base) - raw, they read as meaningless jargon outside
	 * WooCommerce's own tax settings screen.
	 *
	 * @param string $value Raw woocommerce_tax_based_on option value.
	 */
	private function tax_based_on_label( string $value ): string {
		switch ( $value ) {
			case 'billing':
				return 'adresa de facturare';
			case 'base':
				return 'adresa magazinului';
			case 'shipping':
				return 'adresa de livrare';
			default:
				return $value;
		}
	}

	/**
	 * @param array<string,int>               $totals  Pending/in-progress/failed counts.
	 * @param array<string,array<string,int>> $by_hook Pending/failed counts per queue job.
	 * @return array<string,string>
	 */
	private function queue_rows( array $totals, array $by_hook ): array {
		$rows                                = array();
		$rows['action_scheduler_disponibil'] = $this->yn( $this->queue->available() );
		$rows['total']                       = sprintf( '%d în așteptare, %d active, %d eșuate', (int) $totals['pending'], (int) $totals['in-progress'], (int) $totals['failed'] );
		foreach ( $by_hook as $label => $counts ) {
			$rows[ $label ] = sprintf( '%d în așteptare, %d eșuate', (int) $counts['pending'], (int) $counts['failed'] );
		}
		return $rows;
	}

	/**
	 * @return array<string,string>
	 */
	private function connection_rows(): array {
		$rows                       = array();
		$rows['credentiale_setate'] = $this->yn( $this->settings->has_credentials() );
		$rows['stare']              = $this->health->is_healthy() ? 'ok' : 'eroare';
		if ( ! $this->health->is_healthy() && '' !== $this->health->last_error() ) {
			$rows['ultima_eroare'] = $this->health->last_error();
		}
		$checked                   = $this->health->last_checked();
		$rows['ultima_verificare'] = $checked ? human_time_diff( $checked ) . ' în urmă' : 'niciodată';
		return $rows;
	}

	/**
	 * @return array<string,string>
	 */
	private function settings_rows(): array {
		$rows = array();
		foreach ( $this->settings->all_keys() as $key ) {
			if ( in_array( $key, self::OMIT_FROM_DUMP, true ) ) {
				continue;
			}
			$rows[ $key ] = $this->format_setting_value( $this->settings->get( $key ) );
		}
		return $rows;
	}

	private function yn( bool $value ): string {
		return $value ? 'da' : 'nu';
	}

	private function ini( string $key ): string {
		$value = ini_get( $key );
		return false !== $value && '' !== $value ? $value : '—';
	}

	/**
	 * @param mixed $value Raw option value as returned by Settings::get().
	 */
	private function format_setting_value( $value ): string {
		if ( is_array( $value ) ) {
			return empty( $value ) ? '—' : implode( ', ', array_map( 'strval', $value ) );
		}

		$value = (string) $value;
		return '' !== $value ? $value : '—';
	}

	private function short_path( string $file ): string {
		if ( defined( 'WP_CONTENT_DIR' ) && 0 === strpos( $file, WP_CONTENT_DIR ) ) {
			return ltrim( substr( $file, strlen( WP_CONTENT_DIR ) ), '/' );
		}
		return basename( $file );
	}

	private function count_issued( array $entries ): int {
		$count = 0;
		foreach ( $entries as $entry ) {
			if ( false !== strpos( $entry['message'], ' issued' ) ) {
				++$count;
			}
		}
		return $count;
	}
}
