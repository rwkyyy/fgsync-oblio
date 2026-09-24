( function ( $ ) {
	'use strict';

	$( function () {
		var $box = $( '.oblio-fgwoo-orderbox' );
		if ( ! $box.length ) {
			return;
		}
		var orderId = $box.data( 'order' );
		var $result = $box.find( '.oblio-fgwoo-orderbox-result' );

		$box.on( 'click', '.oblio-fgwoo-order-action', function () {
			var $btn = $( this );
			if ( $btn.data( 'confirm' ) ) {
				var message = $btn.data( 'confirm-msg' ) || fgsyncOblioOrder.i18n.confirm;
				if ( ! window.confirm( message ) ) {
					return;
				}
			}

			$box.find( '.oblio-fgwoo-order-action' ).prop( 'disabled', true );
			$result.text( fgsyncOblioOrder.i18n.working ).css( 'color', '#157347' );

			$.post( fgsyncOblioOrder.ajaxUrl, {
				action: 'oblio_fgwoo_order_action',
				nonce: fgsyncOblioOrder.nonce,
				order_id: orderId,
				task: $btn.data( 'task' ),
				doc_type: $btn.data( 'doc-type' ),
				use_stock: $btn.data( 'use-stock' ) ? 1 : 0
			} ).done( function ( response ) {
				if ( response && response.success ) {
					window.location.reload();
				} else {
					$result
						.text( ( response && response.data && response.data.message ) || fgsyncOblioOrder.i18n.error )
						.css( 'color', '#c62d1c' );
					$box.find( '.oblio-fgwoo-order-action' ).prop( 'disabled', false );
				}
			} ).fail( function () {
				$result.text( fgsyncOblioOrder.i18n.requestFailed ).css( 'color', '#c62d1c' );
				$box.find( '.oblio-fgwoo-order-action' ).prop( 'disabled', false );
			} );
		} );
	} );
}( jQuery ) );
