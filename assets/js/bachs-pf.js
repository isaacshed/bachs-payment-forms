/**
 * Bachs Payment Forms: submit the form over AJAX and send the payer to the Bachs checkout.
 * The total shown here is a preview only; the server prices every payment itself.
 */
( function () {
	'use strict';

	var settings = window.bachsPf || {};
	var i18n = settings.i18n || {};

	function showMessage( form, text, isError ) {
		var box = form.querySelector( '.bachs-pf-message' );
		if ( ! box ) {
			return;
		}
		box.textContent = text || '';
		box.classList.toggle( 'is-error', !! isError );
	}

	function unitAmount( form ) {
		var fixed = form.querySelector( '.bachs-pf-fixed .bachs-pf-unit' );
		if ( fixed ) {
			return parseFloat( fixed.getAttribute( 'data-amount' ) ) || 0;
		}
		var option = form.querySelector( 'input[name="bachs_pf_option"]:checked' );
		if ( option ) {
			return parseFloat( option.getAttribute( 'data-amount' ) ) || 0;
		}
		var custom = form.querySelector( 'input[name="bachs_pf_amount"]' );
		return custom ? parseFloat( String( custom.value ).replace( /[,\s]/g, '' ) ) || 0 : 0;
	}

	function updateTotal( form ) {
		var target = form.querySelector( '.bachs-pf-total' );
		if ( ! target ) {
			return;
		}
		var qtyInput = form.querySelector( '.bachs-pf-qty' );
		var qty = qtyInput ? parseInt( qtyInput.value, 10 ) || 0 : 1;
		var total = unitAmount( form ) * qty;
		if ( ! qtyInput && ! form.querySelector( 'input[name="bachs_pf_option"], input[name="bachs_pf_amount"]' ) ) {
			target.textContent = '';
			return;
		}
		if ( total > 0 ) {
			var formatted;
			try {
				formatted = new Intl.NumberFormat( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ).format( total );
			} catch ( e ) {
				formatted = total.toFixed( 2 );
			}
			target.textContent = ( form.getAttribute( 'data-currency' ) || '' ) + ' ' + formatted;
		} else {
			target.textContent = '';
		}
	}

	function post( form, button ) {
		if ( typeof form.reportValidity === 'function' && ! form.reportValidity() ) {
			return;
		}

		var pageField = form.querySelector( 'input[name="bachs_pf_page"]' );
		if ( pageField ) {
			pageField.value = window.location.href;
		}

		button.disabled = true;
		form.classList.add( 'is-busy' );
		showMessage( form, i18n.working || '', false );

		fetch( settings.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			body: new FormData( form )
		} )
			.then( function ( response ) {
				return response.json().catch( function () {
					return { success: false, data: {} };
				} );
			} )
			.then( function ( result ) {
				if ( result && result.success && result.data && result.data.redirect ) {
					window.location.assign( result.data.redirect );
					return;
				}
				var message = result && result.data && result.data.message ? result.data.message : i18n.error;
				showMessage( form, message, true );
				button.disabled = false;
				form.classList.remove( 'is-busy' );
			} )
			.catch( function () {
				showMessage( form, i18n.error, true );
				button.disabled = false;
				form.classList.remove( 'is-busy' );
			} );
	}

	function bind( form ) {
		var button = form.querySelector( 'button[type="submit"]' );
		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();
			if ( button && ! button.disabled ) {
				post( form, button );
			}
		} );
		form.addEventListener( 'input', function () {
			updateTotal( form );
		} );
		form.addEventListener( 'change', function () {
			updateTotal( form );
		} );
		updateTotal( form );
	}

	function init() {
		var forms = document.querySelectorAll( '.bachs-pf-form, .bachs-pf-retry' );
		for ( var i = 0; i < forms.length; i++ ) {
			bind( forms[ i ] );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
