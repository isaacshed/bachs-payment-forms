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

	function money( amount, currency ) {
		var value = parseFloat( amount ) || 0;
		var formatted;
		try {
			formatted = new Intl.NumberFormat( undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 } ).format( value );
		} catch ( e ) {
			formatted = value.toFixed( 2 );
		}
		return ( currency || '' ) + ' ' + formatted;
	}

	function pricing( form ) {
		var raw = form.getAttribute( 'data-pricing' );
		if ( ! raw ) {
			return null;
		}
		try {
			return JSON.parse( raw );
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Show the chosen currency's prices. Display only: the server prices the payment.
	 */
	function applyCurrency( form ) {
		var select = form.querySelector( '.bachs-pf-currency' );
		var all = pricing( form );
		if ( ! select || ! all || ! all[ select.value ] ) {
			return;
		}
		var code = select.value;
		var price = all[ code ];
		var i;

		form.setAttribute( 'data-currency', code );

		var fixed = form.querySelector( '.bachs-pf-fixed .bachs-pf-unit' );
		if ( fixed ) {
			fixed.setAttribute( 'data-amount', price.amount );
			fixed.textContent = money( price.amount, code );
		}

		var radios = form.querySelectorAll( 'input[name="bachs_pf_option"]' );
		for ( i = 0; i < radios.length; i++ ) {
			var amount = price.options[ i ];
			radios[ i ].setAttribute( 'data-amount', amount );
			var label = form.querySelector( '.bachs-pf-price[data-index="' + i + '"]' );
			if ( label ) {
				label.textContent = money( amount, code );
			}
		}

		var custom = form.querySelector( 'input[name="bachs_pf_amount"]' );
		if ( custom ) {
			custom.min = price.min || '0.01';
			if ( price.max ) {
				custom.max = price.max;
			} else {
				custom.removeAttribute( 'max' );
			}
			var amountLabel = form.querySelector( '.bachs-pf-amount-label' );
			if ( amountLabel && i18n.amount ) {
				amountLabel.textContent = i18n.amount.replace( '%s', code );
			}
			var hint = form.querySelector( '.bachs-pf-hint' );
			if ( hint ) {
				var parts = [];
				if ( price.min && i18n.minimum ) {
					parts.push( i18n.minimum.replace( '%s', money( price.min, code ) ) );
				}
				if ( price.max && i18n.maximum ) {
					parts.push( i18n.maximum.replace( '%s', money( price.max, code ) ) );
				}
				hint.textContent = parts.join( ', ' );
			}
		}

		// Recurring needs a currency Bachs subscriptions can bill.
		var schedule = form.querySelector( 'select[name="bachs_pf_cadence"]' );
		var note = form.querySelector( '.bachs-pf-recurring-note' );
		if ( schedule ) {
			for ( i = 0; i < schedule.options.length; i++ ) {
				if ( 'once' !== schedule.options[ i ].value ) {
					schedule.options[ i ].disabled = ! price.recurring;
				}
			}
			if ( ! price.recurring ) {
				schedule.value = 'once';
			}
			if ( note ) {
				note.hidden = !! price.recurring;
			}
		}
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
			target.textContent = money( total, form.getAttribute( 'data-currency' ) );
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
		form.addEventListener( 'change', function ( event ) {
			if ( event.target && event.target.classList && event.target.classList.contains( 'bachs-pf-currency' ) ) {
				applyCurrency( form );
			}
			updateTotal( form );
		} );
		applyCurrency( form );
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
