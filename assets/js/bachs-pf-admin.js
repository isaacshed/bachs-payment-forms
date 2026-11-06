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
	}

	document.addEventListener( 'change', function ( event ) {
		if ( event.target && /^bachs_pf\[(amount_type|recurring)\]$/.test( event.target.name || '' ) ) {
			refresh();
		}
	} );

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', refresh );
	} else {
		refresh();
	}
} )();
