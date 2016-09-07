var QS = QS || { popMediaBox:function(){} };
( function( $ ) {
	$( document ).on( 'click', '[role="upload-export"]', function( ev ) {
		var me = $( this ), scope = me.closest( me.data( 'scope' ) || '.field' ), preview = scope.find( me.data( 'preview' ) || '.filename-preview' ), id_field = scope.find( me.data( 'id' ) || '.import-field-id' );

		QS.popMediaBox.apply( this, [ ev, {
			types: '*',
			with_selection: function( attachment ) {
				preview.text( attachment.filename );
				id_field.val( attachment.id );
			}
		} ] );
	} );
} )( jQuery );
