( function( $, EventUI, EditSetting, qt, undefined ) {
	EventUI.callbacks.add( 'add_event', function( args, orig ) {
		args['price_struct'] = '';
	} );

	EventUI.callbacks.add( 'before_submit_event_item', function( ev, evdata, defaults ) {
		ev['price_struct'] = evdata['price-struct'];
	} );

	EditSetting.callbacks.add( 'update', function( data, adjust ) {
		if ( this.tag == 'event-area' && ( $.inArray( typeof data['event-area'], [ 'string', 'number' ] ) != -1 || ( qt.isO( data['event-area'] ) && qt.isF( data['event-area'].toString ) ) ) ) {
			console.log( 'data', data );
			var test = $.inArray( typeof data['event-area'], [ 'string', 'number' ] ) != -1 ? data['event-area'] : data['event-area'].toString(),
			    p = this.elements.main_form.find( '[tag="price-struct"]' ), pool = p.find( '[rel="pool"]' ), vis_list = p.find( '[rel="vis-list"]' );
			if ( p.length ) {
				p = p.qsEditSetting( 'get' );
				if ( qt.isO( p ) && p.initialized ) {
					vis_list.empty();
					var at_least_one = false; // number of pricing structs for this specific event area
					pool.find( 'option' ).each( function() {
						var me = $( this ), ea = me.attr( 'event-area-id' );
						// add any generic options, which should only be 'none' most of the time. these do not count as 'at_least_one'
						if ( ! qt.is( ea ) ) {
							me.clone().appendTo( vis_list );
						// add any pricing structs that are specifically for this event area
						} else if ( ea == test ) {
							at_least_one = true;
							console.log( 'at least one item', me, me.val(), me.text() );
							me.clone().appendTo( vis_list );
						}
					} );
					// if we added at least one specific pricing struct, then show the pricing structs. otherwise, hide them
					p.elements.main[ at_least_one ? 'show' : 'hide' ]();
				}
			}
		}
	} );
} )( jQuery, QS.EventUI, QS.EditSetting, QS.Tools );
