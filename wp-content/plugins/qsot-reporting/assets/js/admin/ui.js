( function( $, qt ) {
	// when specific elements change, we need to do an intermediate form update
	$( document ).on( 'change.qsotr', '[data-onchange-sa]', function( e ) {
		var val = $( this ).data( 'onchange-sa' ) || $( this ).attr( 'data-onchange-sa' );
		$( this ).closest( 'form' ).trigger( 'submit', [ { sa:val } ] );
	} );

	QS.cbs.add( 'report-loaded', function( results, target ) {
		QS.DatepickerI18n( target );
	} );
} )( jQuery, QS.Tools );

( function( $, qt ) {
	// get all attributes of a given element
	QS.AllAttrs = QS.AllAttrs || function( ele ) {
		var output = {}, atts = ele[0].attributes, n = atts.length;
		for ( var att, i = 0; i < n; i++ ){
			att = atts[i];
			if ( 'string' === typeof att.nodeName )
				output[ att.nodeName ] = att.nodeValue;
		}
		return output;
	}

	$( function() {
		QS.DatepickerI18n( 'body' );
	} );
} )( jQuery, QS.Tools );
