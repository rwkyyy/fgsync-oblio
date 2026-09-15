( function ( $ ) {
	'use strict';

	$( function () {
		var $button = $( '#oblio_fgwoo_test_connection' );
		if ( ! $button.length ) {
			return;
		}

		var $result = $( '#oblio_fgwoo_test_result' );
		var $cif    = $( '#oblio_fgwoo_cif' );

		function setResult( text, ok ) {
			$result.text( text ).css( 'color', ok ? '#157347' : '#c62d1c' );
		}

		function repopulateCif( companies ) {
			if ( ! companies || ! $cif.length ) {
				return;
			}
			var current = $cif.val();
			$cif.empty().append( $( '<option/>' ).val( '' ).text( oblioFgwoo.i18n.select ) );
			$.each( companies, function ( cif, label ) {
				$cif.append( $( '<option/>' ).val( cif ).text( label ) );
			} );
			if ( current ) {
				$cif.val( current );
			}
		}

		$button.on( 'click', function () {
			setResult( oblioFgwoo.i18n.testing, true );
			$button.prop( 'disabled', true );

			$.post( oblioFgwoo.ajaxUrl, {
				action: 'oblio_fgwoo_test_connection',
				nonce: oblioFgwoo.nonce,
				email: $( '#oblio_fgwoo_email' ).val(),
				secret: $( '#oblio_fgwoo_secret' ).val()
			} ).done( function ( response ) {
				if ( response && response.success ) {
					setResult( response.data.message, true );
					repopulateCif( response.data.companies );
				} else {
					setResult( ( response && response.data && response.data.message ) || oblioFgwoo.i18n.error, false );
				}
			} ).fail( function () {
				setResult( oblioFgwoo.i18n.requestFailed, false );
			} ).always( function () {
				$button.prop( 'disabled', false );
			} );
		} );
	} );

	$( function () {
		$( '.oblio-sync-now' ).on( 'click', function () {
			var $sync = $( this );
			var $result = $sync.siblings( '.oblio-sync-result' ).first();

			function fail( message ) {
				$result.text( message || oblioFgwoo.i18n.error ).css( 'color', '#c62d1c' );
				$sync.prop( 'disabled', false );
			}

			function step( token, offset ) {
				$.post( oblioFgwoo.ajaxUrl, {
					action: 'oblio_fgwoo_stock_sync_step',
					nonce: oblioFgwoo.nonce,
					token: token,
					offset: offset
				} ).done( function ( response ) {
					var data = response && response.data;
					if ( ! response || ! response.success || ! data ) {
						fail( data && data.message );
						return;
					}
					$result.text( data.message ).css( 'color', '#157347' );
					if ( data.done ) {
						$sync.prop( 'disabled', false );
						return;
					}
					step( token, data.nextOffset );
				} ).fail( function () {
					fail( oblioFgwoo.i18n.requestFailed );
				} );
			}

			$result.text( oblioFgwoo.i18n.syncing ).css( 'color', '#157347' );
			$sync.prop( 'disabled', true );
			$.post( oblioFgwoo.ajaxUrl, {
				action: 'oblio_fgwoo_stock_sync_now',
				nonce: oblioFgwoo.nonce
			} ).done( function ( response ) {
				var data = response && response.data;
				if ( ! response || ! response.success || ! data || ! data.token ) {
					fail( data && data.message );
					return;
				}
				step( data.token, 0 );
			} ).fail( function () {
				fail( oblioFgwoo.i18n.requestFailed );
			} );
		} );

		var logBody = document.getElementById( 'oblio-logbody' );
		if ( logBody ) {
			logBody.scrollTop = logBody.scrollHeight;
		}
	} );

	$( function () {
		$( '.oblio-sync-unlock' ).on( 'click', function () {
			var $btn = $( this );
			var $result = $btn.siblings( '.oblio-unlock-result' ).first();
			$btn.prop( 'disabled', true );
			$result.text( '…' ).css( 'color', '#157347' );
			$.post( oblioFgwoo.ajaxUrl, {
				action: 'oblio_fgwoo_stock_sync_unlock',
				nonce: oblioFgwoo.nonce
			} ).done( function ( response ) {
				var data = response && response.data;
				var ok = response && response.success;
				$result.text( ( data && data.message ) || '' ).css( 'color', ok ? '#157347' : '#c62d1c' );
				if ( ok ) {
					var $wrap = $btn.closest( '.oblio-lock-warning' );
					( $wrap.length ? $wrap : $btn ).fadeOut( 400 );
				} else {
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$result.text( oblioFgwoo.i18n.requestFailed ).css( 'color', '#c62d1c' );
				$btn.prop( 'disabled', false );
			} );
		} );
	} );

	$( function () {
		var $toggle = $( '#oblio-log-autoupdate' );
		var $logBody = $( '#oblio-logbody' );
		if ( ! $toggle.length || ! $logBody.length ) {
			return;
		}

		var STORAGE_KEY = 'oblioFgwooLogAutoupdate';
		var timer = null;

		function poll() {
			$.post( oblioFgwoo.ajaxUrl, {
				action: 'oblio_fgwoo_log_tail',
				nonce: oblioFgwoo.nonce
			} ).done( function ( response ) {
				var data = response && response.data;
				if ( response && response.success && data && 'string' === typeof data.html ) {
					$logBody.html( data.html );
					$logBody[ 0 ].scrollTop = $logBody[ 0 ].scrollHeight;
				}
			} );
		}

		function stop() {
			if ( timer ) {
				clearInterval( timer );
				timer = null;
			}
		}

		function start() {
			stop();
			timer = setInterval( poll, 5000 );
		}

		$toggle.on( 'change', function () {
			var on = $toggle.is( ':checked' );
			try {
				window.localStorage.setItem( STORAGE_KEY, on ? '1' : '0' );
			} catch ( e ) {}
			if ( on ) {
				start();
			} else {
				stop();
			}
		} );

		var remembered = false;
		try {
			remembered = '1' === window.localStorage.getItem( STORAGE_KEY );
		} catch ( e ) {
			remembered = false;
		}
		if ( remembered ) {
			$toggle.prop( 'checked', true );
			start();
		}
	} );

	$( function () {
		$( '.oblio-import-now' ).on( 'click', function () {
			var $btn = $( this );
			var $result = $btn.siblings( '.oblio-import-result' ).first();
			if ( ! window.confirm( oblioFgwoo.i18n.confirmImport ) ) {
				return;
			}
			$result.text( '…' ).css( 'color', '#157347' );
			$btn.prop( 'disabled', true );
			$.post( oblioFgwoo.ajaxUrl, {
				action: 'oblio_fgwoo_import_legacy',
				nonce: oblioFgwoo.nonce
			} ).done( function ( response ) {
				if ( response && response.success ) {
					$result.text( response.data.message ).css( 'color', '#157347' );
					setTimeout( function () { window.location.reload(); }, 1200 );
				} else {
					$result.text( ( response && response.data && response.data.message ) || oblioFgwoo.i18n.error ).css( 'color', '#c62d1c' );
					$btn.prop( 'disabled', false );
				}
			} ).fail( function () {
				$result.text( oblioFgwoo.i18n.requestFailed ).css( 'color', '#c62d1c' );
				$btn.prop( 'disabled', false );
			} );
		} );
	} );

	$( function () {
		var $page = $( '.oblio-page' );
		var $body = $( '.oblio-page-body' );
		if ( ! $page.length || ! $body.find( '.oblio-section' ).length ) {
			return;
		}

		var $form = $body.find( '.oblio-settings-form' );
		var $tabs = $page.find( '.oblio-tab' );
		var $nav  = $page.find( '.oblio-tabs' );

		$nav.attr( 'role', 'tablist' );
		$tabs.each( function () {
			var $tab   = $( this );
			var name   = $tab.data( 'section' );
			var $panel = $body.find( '.oblio-section[data-section="' + name + '"]' );
			$tab.attr( { role: 'tab', id: 'oblio-tab-' + name, 'aria-controls': 'oblio-panel-' + name } );
			if ( $panel.length ) {
				$panel.attr( { role: 'tabpanel', id: 'oblio-panel-' + name, 'aria-labelledby': 'oblio-tab-' + name, tabindex: '0' } );
			}
		} );

		function enhance( $scope ) {
			if ( ! $.fn.selectWoo ) {
				return;
			}
			$scope.find( 'select.wc-enhanced-select' ).each( function () {
				var $select = $( this );
				if ( null === this.offsetParent ) {
					return;
				}
				if ( $select.hasClass( 'select2-hidden-accessible' ) ) {
					$select.selectWoo( 'destroy' );
				}
				$select.selectWoo( { width: '100%', minimumResultsForSearch: 10 } ).addClass( 'enhanced' );
			} );
		}

		function toggleRow( inputId, show ) {
			var el = document.getElementById( inputId );
			if ( ! el ) {
				return;
			}
			var row = el.closest( 'tr' );
			if ( row ) {
				row.style.display = show ? '' : 'none';
			}
		}

		function applyConditionals() {
			var mode = $( '#oblio_fgwoo_email_mode' ).val() || '';
			[ 'oblio_fgwoo_email_from', 'oblio_fgwoo_email_cc', 'oblio_fgwoo_email_subject', 'oblio_fgwoo_email_message' ].forEach( function ( id ) {
				toggleRow( id, 'standalone' === mode );
			} );
			[ 'oblio_fgwoo_email_button_statuses', 'oblio_fgwoo_email_button_label' ].forEach( function ( id ) {
				toggleRow( id, 'button' === mode );
			} );
			var gen = $( '#oblio_fgwoo_invoice_generation' ).val() || '';
			toggleRow( 'oblio_fgwoo_invoice_batch_interval', 'batch' === gen );

			var collect = $( '#oblio_fgwoo_collect_mode' ).val() || '';
			toggleRow( 'oblio_fgwoo_collect_gateways', 'selected' === collect );
			toggleRow( 'oblio_fgwoo_collect_exceptions', 'all' === collect );

			var proformaOn       = $( '#oblio_fgwoo_proforma_autogen' ).is( ':checked' );
			var proformaReceived = $( '#oblio_fgwoo_proforma_on_received' ).is( ':checked' );
			toggleRow( 'oblio_fgwoo_proforma_on_received', proformaOn );
			toggleRow( 'oblio_fgwoo_proforma_autogen_statuses', proformaOn && ! proformaReceived );

			var trigger = $( '#oblio_fgwoo_stock_sync_trigger' ).val() || '';
			toggleRow( 'oblio_fgwoo_stock_interval', 'schedule' === trigger || 'both' === trigger );
			toggleRow( 'oblio_fgwoo_webhook_stock_delay', 'webhook' === trigger || 'both' === trigger );
		}

		function showSection( name ) {
			var $sections = $body.find( '.oblio-section' );
			var $target   = $sections.filter( '[data-section="' + name + '"]' );
			if ( ! $target.length ) {
				return false;
			}
			$sections.removeClass( 'is-active' );
			$target.addClass( 'is-active' );
			$tabs.removeClass( 'active' ).attr( { 'aria-selected': 'false', tabindex: '-1' } );
			$tabs.filter( '[data-section="' + name + '"]' ).addClass( 'active' ).attr( { 'aria-selected': 'true', tabindex: '0' } );
			enhance( $target );
			return true;
		}

		$page.addClass( 'oblio-has-tabs' );

		function activate( $tab, pushHistory ) {
			if ( ! showSection( $tab.data( 'section' ) ) ) {
				return false;
			}
			if ( pushHistory && window.history && history.pushState ) {
				history.pushState( { oblioSection: $tab.data( 'section' ) }, '', $tab.attr( 'href' ) );
			}
			return true;
		}

		$tabs.on( 'click', function ( e ) {
			if ( activate( $( this ), true ) ) {
				e.preventDefault();
			}
		} );

		$nav.on( 'keydown', '.oblio-tab', function ( e ) {
			var idx  = $tabs.index( this );
			var next = null;
			if ( 37 === e.which ) {
				next = ( idx - 1 + $tabs.length ) % $tabs.length;
			} else if ( 39 === e.which ) {
				next = ( idx + 1 ) % $tabs.length;
			} else if ( 36 === e.which ) {
				next = 0;
			} else if ( 35 === e.which ) {
				next = $tabs.length - 1;
			}
			if ( null === next ) {
				return;
			}
			e.preventDefault();
			var $next = $tabs.eq( next );
			$next.trigger( 'focus' );
			activate( $next, true );
		} );

		$( window ).on( 'popstate', function ( e ) {
			var state = e.originalEvent && e.originalEvent.state;
			if ( state && state.oblioSection ) {
				showSection( state.oblioSection );
			}
		} );

		$( '#oblio_fgwoo_email_mode' ).on( 'change', function () {
			applyConditionals();
			enhance( $body.find( '.oblio-section[data-section="email"]' ) );
		} );
		$( '#oblio_fgwoo_invoice_generation' ).on( 'change', applyConditionals );
		$( '#oblio_fgwoo_stock_sync_trigger' ).on( 'change', applyConditionals );
		$( '#oblio_fgwoo_collect_mode' ).on( 'change', function () {
			applyConditionals();
			enhance( $body.find( '.oblio-section[data-section="collection"]' ) );
		} );
		$( '#oblio_fgwoo_proforma_autogen, #oblio_fgwoo_proforma_on_received' ).on( 'change', function () {
			applyConditionals();
			enhance( $body.find( '.oblio-section[data-section="documents"]' ) );
		} );

		var initial = $body.data( 'active' ) || 'connection';
		if ( ! showSection( initial ) ) {
			initial = 'connection';
			showSection( 'connection' );
		}
		if ( window.history && history.replaceState ) {
			history.replaceState( { oblioSection: initial }, '', window.location.href );
		}
		applyConditionals();

		var baseline   = $form.serialize();
		var submitting = false;
		$form.on( 'submit', function () {
			submitting = true;
		} );
		$( window ).on( 'beforeunload', function ( e ) {
			if ( submitting || $form.serialize() === baseline ) {
				return undefined;
			}
			e.preventDefault();
			e.returnValue = '';
			return '';
		} );
	} );

	$( function () {
		$( '#oblio_fgwoo_test_result, .oblio-sync-result, .oblio-import-result' )
			.attr( { role: 'status', 'aria-live': 'polite' } );
	} );
}( jQuery ) );
