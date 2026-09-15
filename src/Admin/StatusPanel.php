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
			__( 'Conexiune', 'oblio-fgwoo' ),
			$connected ? esc_html__( 'Configurată', 'oblio-fgwoo' ) : esc_html__( 'Neconfigurată', 'oblio-fgwoo' ),
			$connected ? esc_html( (string) $this->settings->get( 'cif' ) ) : esc_html__( 'Introdu datele în secțiunea "Conectare"', 'oblio-fgwoo' )
		);

		$this->tile(
			__( 'Documente azi', 'oblio-fgwoo' ),
			(string) $docs_today,
			esc_html__( 'aprox. · din jurnalul fișier', 'oblio-fgwoo' ),
			'orange'
		);
		/* translators: %d: number of pending queue jobs */
		$pending_text = sprintf( esc_html__( '%d în așteptare', 'oblio-fgwoo' ), (int) $totals['pending'] );
		$this->tile(
			__( 'Coadă', 'oblio-fgwoo' ),
			sprintf( '%d / %d', (int) $totals['in-progress'], (int) $totals['failed'] ),
			esc_html__( 'active / eșuate', 'oblio-fgwoo' ) . ' · ' . $pending_text
		);
		$this->tile(
			__( 'Sincronizare stoc', 'oblio-fgwoo' ),
			$last_sync ? esc_html( human_time_diff( $last_sync ) ) : esc_html__( 'niciodată', 'oblio-fgwoo' ),
			$last_sync ? esc_html__( 'în urmă', 'oblio-fgwoo' ) : '',
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

		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Jurnal activitate · azi', 'oblio-fgwoo' ) . '</h2>';
		echo '<span class="oblio-spacer"></span>';
		echo '<label class="oblio-autoupdate"><input type="checkbox" id="oblio-log-autoupdate"> ' . esc_html__( 'Actualizare automată', 'oblio-fgwoo' ) . '</label>';
		echo '<a class="oblio-link" href="' . esc_url( $logs_url ) . '" target="_blank">' . esc_html__( 'Jurnal complet ↗', 'oblio-fgwoo' ) . '</a></div>';

		echo '<div class="oblio-logbody" id="oblio-logbody">' . LogRenderer::rows( $entries ) . '</div></div>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- rows already escaped per-field in LogRenderer.
	}

	private function queue_card( array $totals, array $by_hook ): void {
		$as_url = admin_url( 'admin.php?page=wc-status&tab=action-scheduler' );

		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Coadă procesare', 'oblio-fgwoo' ) . '</h2>';
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
			echo '<button type="button" class="button button-primary oblio-sync-now" title="' . esc_attr__( 'Rulează întregul catalog acum, prin pași succesivi; poate dura câteva minute pe cataloage mari.', 'oblio-fgwoo' ) . '">' . esc_html__( 'Sincronizează stoc', 'oblio-fgwoo' ) . '</button>';
			echo '<span class="oblio-sync-result"></span>';
			if ( \OblioWoo\Support\AtomicLock::is_locked( StockSyncCoordinator::RUN_LOCK ) ) {
				echo '<button type="button" class="button oblio-sync-unlock" title="' . esc_attr__( 'O sincronizare pare blocată (probabil întreruptă de server înainte să termine).', 'oblio-fgwoo' ) . '">' . esc_html__( 'Deblochează sincronizarea', 'oblio-fgwoo' ) . '</button>';
				echo '<span class="oblio-unlock-result"></span>';
			}
		}
		echo '<a class="button" href="' . esc_url( $as_url ) . '">' . esc_html__( 'Vezi coada', 'oblio-fgwoo' ) . '</a>';
		echo '</div></div>';
	}

	private function update_card(): void {
		$installed = $this->update->installed();
		$latest    = $this->update->latest();
		$available = $this->update->update_available();
		$checked   = $this->update->last_checked();

		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Actualizare', 'oblio-fgwoo' ) . '</h2>';
		echo '<span class="oblio-spacer"></span>';
		if ( $available ) {
			echo '<span class="oblio-badge-upd">' . esc_html__( 'disponibilă', 'oblio-fgwoo' ) . '</span>';
		}
		echo '</div><div class="oblio-upd">';
		if ( $available ) {
			printf( '<div class="verline"><span class="cur">%s</span><span class="arrow">→</span><span class="new">%s</span></div>', esc_html( $installed ), esc_html( $latest ) );
			echo '<a class="button button-primary" href="' . esc_url( admin_url( 'plugins.php' ) ) . '">' . esc_html__( 'Actualizează', 'oblio-fgwoo' ) . '</a>';
		} else {
			printf( '<div class="verline"><span class="cur">%s</span> <span class="oblio-ok">%s</span></div>', esc_html( $installed ), esc_html__( 'la zi', 'oblio-fgwoo' ) );
		}
		echo '<div class="meta">';
		if ( $checked ) {
			/* translators: %s: human-readable time difference (e.g. "2 hours") */
			printf( esc_html__( 'Verificat %s în urmă.', 'oblio-fgwoo' ), esc_html( human_time_diff( $checked ) ) );
		}
		echo ' <a class="oblio-link" href="' . esc_url( $this->update->check_now_url() ) . '">' . esc_html__( 'Verifică acum', 'oblio-fgwoo' ) . '</a></div>';
		echo '<div class="note">' . esc_html__( 'Livrată prin WordPress.org, fără updater propriu.', 'oblio-fgwoo' ) . '</div>';
		echo '</div></div>';
	}

	private function hooks_card( array $overrides ): void {
		echo '<div class="oblio-card"><div class="oblio-card-h"><h2>' . esc_html__( 'Suprascrieri acțiuni', 'oblio-fgwoo' ) . '</h2>';
		echo '<span class="oblio-spacer"></span><span class="oblio-count-warn">' . (int) count( $overrides ) . '</span></div>';

		if ( empty( $overrides ) ) {
			echo '<p class="oblio-empty">' . esc_html__( 'Niciun hook suprascris, comportament implicit.', 'oblio-fgwoo' ) . '</p></div>';
			return;
		}

		foreach ( $overrides as $hook => $callbacks ) {
			foreach ( $callbacks as $callback ) {
				echo '<div class="oblio-hookrow"><span class="hookname">' . esc_html( $hook ) . '</span>';
				echo '<span class="oblio-tag over">' . esc_html__( 'suprascris', 'oblio-fgwoo' ) . '</span>';
				printf(
					'<span class="hooksrc">%s:%d · prioritate %d</span></div>',
					esc_html( $this->short_path( $callback['file'] ) ),
					(int) $callback['line'],
					(int) $callback['priority']
				);
			}
		}

		echo '<div class="oblio-hooknote">' . esc_html__( 'Suprascrierile sunt permise, listate aici ca să nu fie niciodată tăcute.', 'oblio-fgwoo' ) . '</div></div>';
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
			if ( false !== strpos( $entry['message'], ' emis' ) ) {
				++$count;
			}
		}
		return $count;
	}
}
