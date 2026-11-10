/**
 * Form editor: show only the settings that apply to the chosen amount and recurring options.
 */
( function () {
	'use strict';

	function value( name ) {
		var checked = document.querySelector( 'input[name="' + name + '"]:checked' );
		return checked ? checked.value : '';
	}

	function toggle( selector, show ) {
		var nodes = document.querySelectorAll( selector );
		for ( var i = 0; i < nodes.length; i++ ) {
			nodes[ i ].hidden = ! show;
		}
	}

	function refresh() {
		var amountType = value( 'bachs_pf[amount_type]' );
		var recurring = value( 'bachs_pf[recurring]' );
		toggle( '.bachs-pf-show-fixed', 'fixed' === amountType );
		toggle( '.bachs-pf-show-custom', 'custom' === amountType );
		toggle( '.bachs-pf-show-options', 'options' === amountType );
		toggle( '.bachs-pf-show-recurring-fixed', 'fixed' === recurring );
		toggle( '.bachs-pf-show-recurring-optional', 'optional' === recurring );

		// Currency choice: the table only when enabled, the right columns for the amount
		// type, and no row for the main currency.
		var multi = document.querySelector( '.bachs-pf-multi-toggle' );
		toggle( '.bachs-pf-show-multi', !! ( multi && multi.checked ) );
		toggle( '.bachs-pf-col-fixed', 'fixed' === amountType );
		toggle( '.bachs-pf-col-custom', 'custom' === amountType );
		var main = document.getElementById( 'bachs-pf-currency' );
		var rows = document.querySelectorAll( '.bachs-pf-currency-table tr[data-currency]' );
		for ( var i = 0; i < rows.length; i++ ) {
			rows[ i ].hidden = !! main && rows[ i ].getAttribute( 'data-currency' ) === main.value;
		}
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && /^bachs_pf\[(amount_type|recurring|multi_currency|currency)\]$/.test( event.target.name || '' ) ) {
			refresh();
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', refresh );
	} else {
		refresh();
	}
} )();
