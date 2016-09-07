( function( $, qt ) {
	var S = _qsot_price_struct || {}, defs = {}, has = 'hasOwnProperty';
	if ($.ui && $.ui.dialog && $.ui.dialog.prototype._allowInteraction) {
		var ui_dialog_interaction = $.ui.dialog.prototype._allowInteraction;
		$.ui.dialog.prototype._allowInteraction = function(e) {
			if ($(e.target).closest('.select2-dropdown').length) return true;
			return ui_dialog_interaction.apply(this, arguments);
		};
	}

	function _str( name ) {
		return qt.isO( S.strings ) && qt.isS( S.strings[ name ] ) ? S.strings[ name ] : name;
	}

	function _format_result( r ) { return r.html; }

	function pui( el, o ) {
		var me = this;
		me.ind = -1;
		me.e = { main:$( el ) };
		me.o = $.extend( {}, defs, o );

		function _edit_struct( option ) {
			var cur = option.text(), id = option.val(), name = prompt( _str( 'change_name' ).replace( /%s/, cur ), cur );

			if ( qt.isS( name ) && ( name = $.trim( name ) ) ) {
				S.data.structs[ id ].name = name;
				_fill_elements();
				_load_struct( S.data.structs[ id ].id );
			}
		}

		function _new_struct() {
			var name = prompt( _str( 'what_name' ) );

			if ( qt.isS( name ) && ( name = $.trim( name ) ) ) {
				var key = ( me.ind-- ) + '';
				S.data.structs[ key ] = { id:key, name:name, prices:{ '0':[] } };
				_fill_elements();
				_load_struct( key );
			}
		}

		function _remove_struct() {
			var name = qt.isO( S.data.structs[ me.e.structs_list.val() ] ) ? S.data.structs[ me.e.structs_list.val() ].name : '';
			if ( confirm( _str( 'Are you sure you want to remove the %s pricing structure?' ).replace( /%s/, '"' + name + '"' ) ) ) {
				delete S.data.structs[ me.e.structs_list.val() ];
				_fill_elements();
			}
		}

		function _load_struct( struct_id ) {
			me.e.structs_list.select2( 'val', struct_id );
			if ( qt.is( S.data.structs[ struct_id + '' ] ) )
				me.e.tickets_list.select2( 'val', S.data.structs[ struct_id + '' ].prices['0'] );
			else
				me.e.tickets_list.select2( 'val', {} );
		}

		function _update_struct( struct_id, val ) {
			if ( ! qt.is( S.data.structs[ struct_id + '' ] ) )
				S.data.structs[ struct_id + '' ] = { '0':[] };
			if ( ! qt.isA( val ) )
				val = val.split( /,/ );
			S.data.structs[ struct_id ].prices['0'] = val;
		}

		function _setup_elements() {
			var div = $( '<div class="field"></div>' ).appendTo( me.e.main ), lbl = $( '<label for="struct-list">' + _str( 'structs' ) + '</label>' ).appendTo( div ), flt = $( '<span class="actions"></span>' ).appendTo( lbl );
			// create the edit struct button
			me.e.edit_link = $( '<a class="action edit-struct" href="#" title="' + qt.esc( _str( 'edit' ) ) + '"><span class="dashicons dashicons-edit"></span></a>' )
				.on( 'click.pui', function( e ) { e.preventDefault(); _edit_struct( me.e.structs_list.find( 'option:selected' ) ); } ).appendTo( flt );

			// create the new struct button
			me.e.new_link = $( '<a class="action new-struct" href="#" title="' + qt.esc( _str( 'new' ) ) + '"><span class="dashicons dashicons-plus"></span></a>' )
				.on( 'click.pui', function( e ) { e.preventDefault(); _new_struct(); } ).appendTo( flt );

			// create the new struct button
			me.e.delete_link = $( '<a class="action delete-struct" href="#" title="' + qt.esc( _str( 'remove' ) ) + '"><span class="dashicons dashicons-no"></span></a>' )
				.on( 'click.pui', function( e ) { e.preventDefault(); _remove_struct(); } ).appendTo( flt );

			// create the container for the list of available structures
			me.e.structs_list = $( '<input type="hidden" class="structs-list widefat use-select2" id="struct-list"/>' )
				.on( 'change.pui', function( e ) { _load_struct( $( this ).val() ); } ).appendTo( div );

			var div = $( '<div class="field"></div>' ).appendTo( me.e.main ), lbl = $( '<label for="ticket-list">' + _str( 'tickets' ) + '</label>' ).appendTo( div );
			me.e.tickets_list = $( '<input type="hidden" class="tickets-list widefat use-select2" id="ticket-list"/>' )
				.on( 'change.pui', function( e ) { _update_struct( me.e.structs_list.val(), $( this ).val() ); } ).appendTo( me.e.main );
			
			$( '<div class="helper">' + _str( 'struct_msg' ) + '</div>' ).appendTo( me.e.main );
		}

		function _fill_elements() {
			me.e.structs_list.empty();
			me.e.tickets_list.empty();

			var data = { results:[] }, first;
			for ( i in S.data.structs ) if ( S.data.structs[ has ]( i ) ) {
				data.results.push( { id:S.data.structs[ i ].id, text:S.data.structs[ i ].name } );
				if ( ! qt.is( first ) )
					first = S.data.structs[ i ].id;
			}
			me.e.structs_list.select2( { data:data } );

			var data = { results:[] };
			for ( i in S.data.tickets ) if ( S.data.tickets[ has ]( i ) )
				data.results.push( { id:S.data.tickets[ i ].id, text:S.data.tickets[ i ].name, html:S.data.tickets[ i ].name } );
			me.e.tickets_list.select2( { width:'resolve', data:data, multiple:true, formatResult:_format_result, formatSelection:_format_result } ).select2( 'container' ).find( 'ul.select2-choices' ).sortable( {
				containment: 'parent',
				start: function() { me.e.tickets_list.select2( 'onSortStart' ); },
				update: function() { me.e.tickets_list.select2( 'onSortEnd' ); }
			} );

			_load_struct( first );
		}

		function init() {
			_setup_elements();
			_fill_elements();
		}

		init();
	}

	QS.cbs.add( 'seating-chart-settings-to-be-saved', function( settings, ui, form ) {
		var custom_pricing = S.data.structs || {}, i, j;
		for ( i in custom_pricing ) if ( custom_pricing[ has ]( i ) )
			for ( j in custom_pricing[ i ].prices ) if ( custom_pricing[ i ].prices[ has ]( j ) )
				if ( qt.toInt( j ) > 0 )
					delete custom_pricing[ i ].prices[ j ];
		$( '[zid][pricing]' ).each( function() {
			var zid = $( this ).attr( 'zid' ),
					pricing = $( this ).attr( 'pricing' ), i;
			if ( pricing ) {

				for ( i in pricing ) if ( pricing[ has ]( i ) && qt.is( custom_pricing[ i ] ) )
					custom_pricing[ i ].prices[ zid ] = pricing;
			}
		} );
		settings.pricing = custom_pricing;
	} );

	QS.cbs.add( 'settings-box-fields', function( fields, paper, ui ) {
		fields._all['pricing'] = { type:'none', name:'Pricing', attr:[ 'pricing' ], hidden:true };
	} );

	QS.cbs.add( 'settings-box-setup-elements', function( sb ) {
		var sbsect = $( '<div class="inner pricing-options"></div>' ).insertAfter( sb.e.aBoxIn ),
				has_opened = false,
				indexed = [],
				shared = {},
				dia, div, customize, zone_list, struct_list, ticket_list;

		function _reset() {
			indexed = [];
			shared = {};
		}

		function _get_popup() {
			if ( ! qt.isO( dia ) || ! qt.is( dia.dialog ) ) {
				dia = $( '<div class="qsot qsot-dialog customize-pricing"></div>' ).appendTo( 'body' );

				div = $( '<div class="field"><h4>' + _str( 'zones' ) + '</h4></div>' ).appendTo( dia );
				zone_list = $( '<div class="zone-list"></div>' ).appendTo( div );
				zone_list.on( 'click', '.show-more', function() { zone_list.find( '.more' ).toggle(); } );

				div = $( '<div class="field"></div>' ).appendTo( dia );
				$( '<div><strong>' + _str( 'sure' ) + '</strong></div>' ).appendTo( div );
				customize = $( '<input type="checkbox" value="1" />' ).appendTo( div ).on( 'change', function( e ) { _toggle_fields( $( this ).is( ':checked' ) ); } );
				$( '<span class="cb-text">' + _str( 'yes' ) + '</span>' ).appendTo( div );
				
				div = $( '<div class="field"><h4>' + _str( 'structs' ) + '</h4></div>' ).appendTo( dia );
				struct_list = $( '<input type="hidden" class="widefat price-structs-list"/>' ).appendTo( div ).on( 'change', function( e ) { _load_shared( $( this ).val() ); } );
				
				div = $( '<div class="field"><h4>' + _str( 'tickets' ) + '</h4></div>' ).appendTo( dia );
				ticket_list = $( '<input type="hidden" class="widefat price-structs-list"/>' ).appendTo( div ).on( 'change', function( e ) { _update_shared( struct_list.val(), $( this ).val() ); } );

				$( '<div class="helper">' + _str( 'customize_msg' ) + '</div>' ).appendTo( dia );

				dia.dialog( {
					autoOpen: false,
					modal: true,
					width: 300,
					height: 'auto',
					closeOnEscape: true,
					title: _str( 'customize' ),
					position: { my:'center', at:'center', of:window, collision:'fit' }
				} );
			}

			return dia;
		}

		function _fill_elements() {
			var zone_str = { show:[], hide:[] },
					target = 'show',
					need_check = true,
					max = 25,
					start = 0,
					i;
			// if there are more than the 'max' number of elements, then only show the first 'max' elements by default, and hide the reset, with a toggle to show
			if ( sb.ui.canvas.Selection.items.length > max ) {
				// draw the remaining elements
				for ( i = start; i < max; i++ ) {
					// get the index of the new element we are adding
					var new_ind = indexed.length;

					// add the pricing element to the index list of zones with customized pricing
					indexed[ new_ind ] = {
						item: sb.ui.canvas.Selection.items[ i ], // the actual selected zone
						structs: JSON.parse( unescape( sb.ui.canvas.Selection.items[ i ].attr( 'pricing' ) || '{}' ) ) // structure prices for this zone
					};

					// if there are structures in the list, then we do not need a check
					if ( Object.keys( indexed[ new_ind ].structs ).length )
						need_check = false;

					// add the zone the target list of zones to display
					var name = sb.ui.canvas.Selection.items[ i ].attr( 'zone' );
					zone_str[ target ].push( '<span title="' + sb.ui.canvas.Selection.items[ i ].node.tagName + '">' + ( qt.is( name ) ? name : '<span class="empty-name">' + _str( 'empty' ) + '</span>' ) + '</span>' );
				}

				start = max;
				target = 'hide';
			}

			// draw the remaining elements
			for ( i = start; i < sb.ui.canvas.Selection.items.length; i++ ) {
				// get the index of the new element we are adding
				var new_ind = indexed.length;

				// add the pricing element to the index list of zones with customized pricing
				indexed[ new_ind ] = {
					item: sb.ui.canvas.Selection.items[ i ], // the actual selected zone
					structs: JSON.parse( unescape( sb.ui.canvas.Selection.items[ i ].attr( 'pricing' ) || '{}' ) ) // structure prices for this zone
				};

				// if there are structures in the list, then we do not need a check
				if ( Object.keys( indexed[ new_ind ].structs ).length )
					need_check = false;

				// add the zone the target list of zones to display
				var name = sb.ui.canvas.Selection.items[ i ].attr( 'zone' );
				zone_str[ target ].push( '<span title="' + sb.ui.canvas.Selection.items[ i ].node.tagName + '">' + ( qt.is( name ) ? name : '<span class="empty-name">' + _str( 'empty' ) + '</span>' ) + '</span>' );
			}

			// figure out if there are 'hidden' elements to display
			var more = sb.ui.canvas.Selection.items.length > max
					? '<a href="#" class="show-more" data-target="more"> ' + _str( '(and %s more)' ).replace( /%s/, '' + ( zone_str['hide'].length ) ) + '</a><div class="more">' + zone_str['hide'].join( ', ' ) + '</div>'
					: '';
			zone_list.html( '<span class="shown">' + zone_str['show'].join( ', ' ) + '</span>' + more );
			
			customize[ need_check ? 'removeProp' : 'prop' ]( 'checked', 'checked' );
			struct_list.empty().removeProp( 'disabled' );
			ticket_list.empty().removeProp( 'disabled' );

			var data = { results:[] };
			for ( i in S.data.structs ) if ( S.data.structs[ has ]( i ) )
				data.results.push( { id:S.data.structs[ i ].id, text:S.data.structs[ i ].name } );
			struct_list.select2( { data:data } ).select2( 'val', '' );

			var data = { results:[] };
			for ( i in S.data.tickets ) if ( S.data.tickets[ has ]( i ) )
				data.results.push( { id:S.data.tickets[ i ].id, text:S.data.tickets[ i ].name, html:S.data.tickets[ i ].name } );
			ticket_list.select2( { data:data, multiple:true, formatResult:_format_result, formatSelection:_format_result } ).select2( 'container' ).find( 'ul.select2-choices' ).sortable( {
				containment: 'parent',
				start: function() { ticket_list.select2( 'onSortStart' ); },
				update: function() { ticket_list.select2( 'onSortEnd' ); }
			} );

			struct_list.trigger( 'change' );
			
			_toggle_fields( ! need_check );
		}

		function _toggle_fields( chkd ) {
			if ( ! chkd ) {
				var i;
				for ( i = 0; i < indexed.length; i++ ) {
					indexed[ i ].structs = {}
					indexed[ i ].item.attr( 'pricing', '{}' );
				}
			}
			ticket_list[ ! chkd ? 'prop' : 'removeProp' ]( 'disabled', 'disabled' ).trigger( 'change' );
			struct_list[ ! chkd ? 'prop' : 'removeProp' ]( 'disabled', 'disabled' ).trigger( 'change' );
		}

		function _load_shared( struct_id ) {
			if ( struct_id && ! qt.is( shared[ struct_id ] ) ) {
				var first = '';
				for ( i in indexed ) if ( indexed[ has ]( i ) ) {
					if ( qt.is( indexed[ i ].structs[ struct_id ] ) ) {
						if ( '' == first ) first = indexed[ i ].structs[ struct_id ].join( ',' );
						else if ( first != indexed[ i ].structs[ struct_id ].join( ',' ) ) {
							first = false;
							break;
						}
					}
				}

				if ( '' === first && qt.is( S.data.structs[ struct_id ] ) ) {
					shared[ struct_id ] = qt.is( S.data.structs[ struct_id ].prices['0'] ) ? S.data.structs[ struct_id ].prices['0'].slice() : [];
				} else if ( false === first ) {
					shared[ struct_id ] = [];
				} else {
					shared[ struct_id ] = first.split( /,/ );
				}
			}

			ticket_list.select2( 'val', shared[ struct_id ] );
		}

		function _update_shared( struct_id, val ) {
			if ( ! struct_id )
				return;
			if ( ! qt.isA( val ) )
				val = val.split( /,/ );
			shared[ struct_id ] = val;
			for ( i = 0; i < indexed.length; i++ ) {
				indexed[ i ].structs[ struct_id ] = val;
				indexed[ i ].item.attr( 'pricing', escape( JSON.stringify( indexed[ i ].structs ) ) );
			}
		}

		function _pricing_popup() {
			var dia = _get_popup();
			_reset();
			_fill_elements();
			dia.dialog( 'open' );
			if ( ! has_opened ) {
			}
			has_opened = true;
		}

		sb.e.pricing_btn = $( '<a href="#" class="zone-price-options">' + _str( 'customize pricing' ) + '</a>' ).on( 'click', function( e ) { e.preventDefault(); _pricing_popup(); } ).appendTo( sbsect );
	} );

	function add_specific_pricing_to_zone( save, ui ) {
		if ( ! qt.is( save.id ) ) return;

		var ele = $( qt.is( save._ele ) ? save._ele.node : ( qt.is( save.abbr ) && save.abbr ? $( '#' + save.abbr ).get( 0 ) : undefined ) ),
				ps = {};

		for ( s in S.data.structs )
			if ( S.data.structs[ has ]( s ) )
				if ( S.data.structs[ s ].prices[ has ]( save.id ) )
					ps[ s ] = S.data.structs[ s ].prices[ save.id ];

		if ( Object.keys( ps ).length )
			ele.attr( 'pricing', escape( JSON.stringify( ps ) ) );

		ele.attr( 'zid', save.id );
	}

	QS.cbs.add( 'create-from-save-circle', add_specific_pricing_to_zone )( 1000 );
	QS.cbs.add( 'create-from-save-square', add_specific_pricing_to_zone )( 1000 );
	QS.cbs.add( 'create-from-save-rectangle', add_specific_pricing_to_zone )( 1000 );
	QS.cbs.add( 'create-from-save-image', add_specific_pricing_to_zone )( 1000 );

	$( function() {
		( new pui( '[rel="price-ui"]' ) );
	} );
} )( jQuery, QS.Tools );
