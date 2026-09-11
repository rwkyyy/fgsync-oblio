<?php
/**
 * Renders the Oblio Status panel (log, queue, update, hook overrides).
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Extensibility\HookInspector;
use OblioWoo\Stock\StockSyncCoordinator;
use OblioWoo\Support\Settings;
final class StatusPanel {

	private Settings $settings;

	private LogReader $log;

	private QueueStatus $queue;

	private UpdateChecker $update;

	private HookInspector $hooks;

	public function __construct( Settings $settings, LogReader $log, QueueStatus $queue, UpdateChecker $update, HookInspector $hooks ) {
		$this->settings = $settings;
		$this->log      = $log;
		$this->queue    = $queue;
		$this->update   = $update;
		$this->hooks    = $hooks;
	}

	public function render(): void {
		$entries    = $this->log->today();
		$totals     = $this->queue->totals();
		$by_hook    = $this->queue->by_hook();
		$overrides  = $this->hooks->all_overrides();
		$docs_today = $this->count_issued( $entries );

		echo '<div class="oblio-status">';
		$this->summary( $totals, $docs_today );
		echo '<div class="oblio-grid">';
		echo '<div class="oblio-col-main">';
		$this->log_card( $entries );
		echo '</div><div class="oblio-col-side">';
		$this->queue_card( $totals, $by_hook );
		$this->update_card();
		$this->hooks_card( $overrides );
		echo '</div></div></div>';
	}

	private function summary( array $totals, int $docs_today ): void {
		$connected = $this->settings->has_credentials() && '' !== (string) $this->settings->get( 'cif' );
		$last_sync = (int) get_option( StockSyncCoordinator::LAST_SYNC_OPTION, 0 );

		echo '<div class="oblio-summary">';

		$this->tile(
			__( 'Conexiune', 'facturare-gestiune-oblio-woocommerce' ),
			$connected ? esc_html__( 'Configurată', 'facturare-gestiune-oblio-woocommerce' ) : esc_html__( 'Neconfigurată', 'facturare-gestiune-oblio-woocommerce' ),
			$connected ? esc_html( (string) $this->settings->get( 'cif' ) ) : esc_html__( 'Introdu datele în secțiunea "Conectare"', 'facturare-gestiune-oblio-woocommerce' )
		);

		$this->tile(
			__( 'Documente azi', 'facturare-gestiune-oblio-woocommerce' ),
			(string) $docs_today,
			esc_html__( 'aprox. · din jurnalul fișier', 'facturare-gestiune-oblio-woocommerce' ),
			'orange'
		);
		/* translators: %d: number of pending queue jobs */
		$pending_text = sprintf( esc_html__( '%d în așteptare', 'facturare-gestiune-oblio-woocommerce' ), (int) $totals['pending'] );
		$this->tile(
			__( 'Coadă', 'facturare-gestiune-oblio-woocommerce' ),
			sprintf( '%d / %d', (int) $totals['in-progress'], (int) $totals['failed'] ),
			esc_html__( 'active / eșuate', 'facturare-gestiune-oblio-woocommerce' ) . ' · ' . $pending_text
		);
		$this->tile(
			__( 'Sincronizare stoc', 'facturare-gestiune-oblio-woocommerce' ),
			$last_sync ? esc_html( human_time_diff( $last_sync ) ) : esc_html__( 'niciodată', 'facturare-gestiune-oblio-woocommerce' ),
			$last_sync ? esc_html__( 'în urmă', 'facturare-gestiune-oblio-woocommerce' ) : '',
			'purple'
		);
		echo '</div>';
	}

	private function tile( string $label, string $value, string $sub, string $tone = 'ink' ): void {
		printf(
			'<div class="oblio-tile"><div class="lab">%s</div><div class="val v-%s">%s</div><div class="sub">%s</div></div>',
			esc_html( $label ),
			esc_attr( $tone ),
			esc_html( $value ),
			wp_kses_post( $sub )
		);
	}

	private function log_card( array $entries ): void {
		$logs_url = admin_url( 'admin.php?page=wc-status&tab=logs' );

		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Jurnal activitate · azi', 'facturare-gestiune-oblio-woocommerce' ) . '</h2>';
		echo '<span class="oblio-spacer"></span>';
		echo '<a class="oblio-link" href="' . esc_url( $logs_url ) . '" target="_blank">' . esc_html__( 'Jurnal complet ↗', 'facturare-gestiune-oblio-woocommerce' ) . '</a></div>';

		if ( empty( $entries ) ) {
			echo '<p class="oblio-empty">' . esc_html__( 'Fără intrări.', 'facturare-gestiune-oblio-woocommerce' ) . '</p></div>';
			return;
		}

		echo '<div class="oblio-logbody" id="oblio-logbody">';
		foreach ( $entries as $entry ) {
			$level = in_array( $entry['level'], array( 'info', 'warning', 'error' ), true ) ? $entry['level'] : 'info';
			printf(
				'<div class="oblio-logline lvl-%s"><span class="t">%s</span><span class="lvl">%s</span><span class="msg">%s</span></div>',
				esc_attr( $level ),
				esc_html( $entry['time'] ),
				esc_html( strtoupper( substr( $entry['level'], 0, 5 ) ) ),
				esc_html( $entry['message'] )
			);
		}
		echo '</div></div>';
	}

	private function queue_card( array $totals, array $by_hook ): void {
		$as_url = admin_url( 'admin.php?page=wc-status&tab=action-scheduler' );

		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Coadă procesare', 'facturare-gestiune-oblio-woocommerce' ) . '</h2>';
		echo '<span class="oblio-spacer"></span><span class="oblio-grp">grup <code>oblio</code></span></div>';

		foreach ( $by_hook as $label => $counts ) {
			echo '<div class="oblio-qrow"><span class="qname">' . esc_html( $label ) . '</span><span class="oblio-spacer"></span>';
			printf( '<span class="count">%d</span>', (int) $counts['pending'] );
			if ( $counts['failed'] > 0 ) {
				printf( '<span class="count fail">%d</span>', (int) $counts['failed'] );
			}
			echo '</div>';
		}

		echo '<div class="oblio-qactions">';

		if ( $this->settings->stock_sync_configured() ) {
			echo '<button type="button" class="button button-primary oblio-sync-now">' . esc_html__( 'Sincronizează stoc', 'facturare-gestiune-oblio-woocommerce' ) . '</button>';
			echo '<span class="oblio-sync-result"></span>';
		}
		echo '<a class="button" href="' . esc_url( $as_url ) . '">' . esc_html__( 'Vezi coada', 'facturare-gestiune-oblio-woocommerce' ) . '</a>';
		echo '</div></div>';
	}

	private function update_card(): void {
		$installed = $this->update->installed();
		$latest    = $this->update->latest();
		$available = $this->update->update_available();
		$checked   = $this->update->last_checked();

		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Actualizare', 'facturare-gestiune-oblio-woocommerce' ) . '</h2>';
		echo '<span class="oblio-spacer"></span>';
		if ( $available ) {
			echo '<span class="oblio-badge-upd">' . esc_html__( 'disponibilă', 'facturare-gestiune-oblio-woocommerce' ) . '</span>';
		}
		echo '</div><div class="oblio-upd">';
		if ( $available ) {
			printf( '<div class="verline"><span class="cur">%s</span><span class="arrow">→</span><span class="new">%s</span></div>', esc_html( $installed ), esc_html( $latest ) );
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Actualizează', 'facturare-gestiune-oblio-woocommerce' ) . '</a>';
		} else {
			printf( '<div class="verline"><span class="cur">%s</span> <span class="oblio-ok">%s</span></div>', esc_html( $installed ), esc_html__( 'la zi', 'facturare-gestiune-oblio-woocommerce' ) );
		}
		echo '<div class="meta">';
		if ( $checked ) {
			/* translators: %s: human-readable time difference (e.g. "2 hours") */
			printf( esc_html__( 'Verificat %s în urmă.', 'facturare-gestiune-oblio-woocommerce' ), esc_html( human_time_diff( $checked ) ) );
		}
		echo ' <a class="oblio-link" href="' . esc_url( $this->update->check_now_url() ) . '">' . esc_html__( 'Verifică acum', 'facturare-gestiune-oblio-woocommerce' ) . '</a></div>';
		echo '<div class="note">' . esc_html__( 'Livrată prin WordPress.org, fără updater propriu.', 'facturare-gestiune-oblio-woocommerce' ) . '</div>';
		echo '</div></div>';
	}

	private function hooks_card( array $overrides ): void {
		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Suprascrieri acțiuni', 'facturare-gestiune-oblio-woocommerce' ) . '</h2>';
		echo '<span class="oblio-spacer"></span><span class="oblio-count-warn">' . (int) count( $overrides ) . '</span></div>';

		if ( empty( $overrides ) ) {
			echo '<p class="oblio-empty">' . esc_html__( 'Niciun hook suprascris, comportament implicit.', 'facturare-gestiune-oblio-woocommerce' ) . '</p></div>';
			return;
		}

		foreach ( $overrides as $hook => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				echo '<div class="oblio-hookrow"><span class="hookname">' . esc_html( $hook ) . '</span>';
				echo '<span class="oblio-tag over">' . esc_html__( 'suprascris', 'facturare-gestiune-oblio-woocommerce' ) . '</span>';
				printf(
					'<span class="hooksrc">%s:%d · prioritate %d</span></div>',
					esc_html( $this->short_path( $callback['file'] ) ),
					(int) $callback['line'],
					(int) $callback['priority']
				);
			}
		}

		echo '<div class="oblio-hooknote">' . esc_html__( 'Suprascrierile sunt permise, listate aici ca să nu fie niciodată tăcute.', 'facturare-gestiune-oblio-woocommerce' ) . '</div></div>';
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
			if ( false !== strpos( $entry['message'], 'issued' ) ) {
				++$count;
			}
		}
		return $count;
	}
}
