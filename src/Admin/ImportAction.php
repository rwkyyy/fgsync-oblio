<?php
/**
 * "Import from the legacy plugin" AJAX handler.
 *
 * @package OblioWoo
 */

declare( strict_types=1 );

namespace OblioWoo\Admin;

use OblioWoo\Legacy\Importer;
final class ImportAction {

	private Importer $importer;

	public function __construct( Importer $importer ) {
		$this->importer = $importer;
	}

	public function register(): void {
		add_action( 'wp_ajax_oblio_fgwoo_import_legacy', array( $this, 'handle' ) );
	}

	public function handle(): void {
		if ( ! check_ajax_referer( ConnectionTest::NONCE_ACTION, 'nonce', false ) || ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'Acțiune neautorizată.', 'oblio-fgwoo' ) ), 403 );
		}

		$count = $this->importer->import();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: %d: number of settings */
					_n( '%d setare importată din pluginul vechi.', '%d setări importate din pluginul vechi.', $count, 'oblio-fgwoo' ),
					$count
				),
			)
		);
	}
}
