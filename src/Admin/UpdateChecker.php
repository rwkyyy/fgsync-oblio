<?php
/**
 * Reads WordPress' own update state for this plugin (no custom updater).
 *
 * @package FGSyncOblio
 */

declare( strict_types=1 );

namespace FGSyncOblio\Admin;

final class UpdateChecker {

	public function installed(): string {
		return defined( 'FGSYNC_OBLIO_VERSION' ) ? FGSYNC_OBLIO_VERSION : '';
	}

	public function latest(): string {
		$transient = get_site_transient( 'update_plugins' );
		$basename  = defined( 'FGSYNC_OBLIO_BASENAME' ) ? FGSYNC_OBLIO_BASENAME : '';

		if ( is_object( $transient ) && isset( $transient->response[ $basename ]->new_version ) ) {
			return (string) $transient->response[ $basename ]->new_version;
		}
		return $this->installed();
	}

	public function update_available(): bool {
		return version_compare( $this->installed(), $this->latest(), '<' );
	}

	public function last_checked(): int {
		$transient = get_site_transient( 'update_plugins' );
		return is_object( $transient ) && isset( $transient->last_checked ) ? (int) $transient->last_checked : 0;
	}

	public function check_now_url(): string {
		return wp_nonce_url( self_admin_url( 'update-core.php?force-check=1' ), 'upgrader-force-check' );
	}
}
