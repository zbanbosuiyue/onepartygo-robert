if ( 'undefined' != typeof jQuery && null !== jQuery ) ( function( $ ) {
	// when the state of a type_item changes, update the ui
	function update_ui_from_type() {
		var me = $( this ),
				type = me.attr( 'id' ),
				to_show = [],
				to_hide = [];

		// setup which items should be shown and hidden, based on box checked state
		if ( me.prop( 'checked' ) ) {
			to_show.push( '.show_if' + type );
			to_show.push( '.hide_if_not' + type );
			to_hide.push( '.hide_if' + type );
		} else {
			to_show.push( '.hide_if_not' + type );
			to_show.push( '.hide_if' + type );
			to_hide.push( '.show_if' + type );
		}

		// do the hiding and showing
		$( to_show.join( ', ' ) ).show();
		$( to_hide.join( ', ' ) ).hide();
	}

	// on page load, add our event handlers, and trigger them once
	$( function() {
		$( '.type_box input[type="checkbox"]' ).on( 'change', update_ui_from_type ).trigger( 'change' );
	} );
} )( jQuery );
