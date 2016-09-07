( function( $, qt ) {
	// settings
	var S = $.extend( { str:{}, templates:{}, nonce:'0' }, _qsot_gamp_settings ),
			// next index for a new pricing struct
			auto_index = -1,
			H = 'hasOwnProperty';

	QS.GAMPPriceStructUI = ( function() {
		function UI( element, options ) {
			var me = this;

			// get a given template's content out of the settings object
			function _tmpl( name, fallback ) { return qt.isS( name ) && qt.is( S.templates[ name ] ) ? S.templates[ name ] : fallback; }

			// make some string replacements on the subject
			function _repl( subject, replacements ) {
				if ( qt.isS( subject ) )
					for ( i in replacements )
						if ( replacements[ H ]( i ) )
							subject = subject.replace( new RegExp( '{{' + i + '}}', 'g' ), replacements[ i ] );
				return subject;
			}

			// count the number of prices in the given struct
			function _price_count( struct ) {
				var cnt = qt.isA( struct.prices ) ? struct.prices.length : 0;
				return ' ('  + cnt + ' ' + QS._str( ( 1 == cnt ? 'price' : 'prices' ), S ) + ')';
			}

			// setup all the UI elements
			function _setup_elements() {
				// setup the main ui shell
				me.elements.shell = $( _tmpl( 'gamp-shell' ) ).appendTo( me.elements.main );

				// get the various containers adn buttons from within the shell
				me.elements.save_nonce = me.elements.shell.find( '[role="save-nonce"]' ).val( S.nonce );
				me.elements.add_btn = me.elements.shell.find( '[role="add-struct-btn"]' );
				me.elements.edit_box = me.elements.shell.find( '[role="struct-edit"]' );
				me.elements.name_box = me.elements.edit_box.find( '[role="struct-name"]' );
				me.elements.available_list = me.elements.shell.find( '[role="available-list"]' );
				me.elements.used_list = me.elements.shell.find( '[role="used-list"]' );

				// make the two price lists sortable
				me.elements.used_list.add( me.elements.available_list ).sortable( {
					dropOnEmpty: true,
					helper: 'clone',
					items: '> li',
					connectWith: '.price-list',
					containment: me.elements.main,
					scroll: true,
					update: function( ev, ui ) { _update_struct_data(); }
				} )

				var i;
				// setup the list that holds the structs themselves, and add each of the known structs to that list
				me.elements.struct_list = me.elements.shell.find( '[role="struct-list"]' );
				for ( i in S.structs ) {
					var struct = S.structs[ i ];
					$( _repl( _tmpl( 'gamp-struct-li' ), { struct_id:struct.id, struct_name:struct.name, struct_price_cnt:_price_count( struct ) } ) ).appendTo( me.elements.struct_list )
							.find( '[role="struct-settings"]' ).val( JSON.stringify( struct ) );
				}

				// setup the temp container for all the prices, and add the prices to it
				me.elements.price_holder = $( '<ul></ul>' );
				for ( i in S.tickets ) {
					var ticket = S.tickets[ i ];
					$( _repl( _tmpl( 'gamp-ticket-li' ), { ticket_id:ticket.id, ticket_name:ticket.name } ) ).appendTo( me.elements.price_holder ); 
				}
			}

			// setup all the events used by the UI elements
			function _setup_events() {
				// setup the click handler on the add button
				me.elements.add_btn.on( 'click', _add_struct );

				// handler for the edit struct button
				me.elements.struct_list.on( 'click', '[role="edit-btn"]', _load_struct );

				// handler for the remove struct button
				me.elements.struct_list.on( 'click', '[role="remove-btn"]', _remove_struct );

				// when the 'done editing' button is clicked, hide the ui
				me.elements.edit_box.on( 'click', '[role="end-edit-btn"]', _end_editing );

				// if we click on an item in either price list, move that item to the other price list
				me.elements.used_list.on( 'click', '>', _move_ticket_item );
				me.elements.available_list.on( 'click', '>', _move_ticket_item );

				// during the editing phase, any changes we make to the 
				me.elements.edit_box.on( 'keyup', 'input, select, textarea', _update_struct_data );
				me.elements.used_list.on( 'click', '>', _update_struct_data );
				me.elements.available_list.on( 'click', '>', _update_struct_data );
			}

			// move an item from one list to the other
			function _move_ticket_item( e ) {
				e.preventDefault();
				// figure out the target list
				var item = $( this ),
						list = item.closest( '.price-list' ),
						target;

				// if the current list is the used list, then the target list is the available list, and vice versa
				if ( list.is( me.elements.used_list ) )
					target = me.elements.available_list;
				else
					target = me.elements.used_list;

				// move the item
				item.appendTo( target );
			}

			// when certain things occur during struct editing, we need to update the struct with the new info we received
			function _update_struct_data() {
				// if we are not editing a struct currently, then bail
				if ( ! me.current_struct_id || ! qt.is( S.structs[ me.current_struct_id ] ) )
					return;
				var struct_id = me.current_struct_id, struct = S.structs[ struct_id ];

				// update the struct with the 
				struct.name = me.elements.name_box.val();
				struct.prices = [];
				me.elements.used_list.find( '>' ).each( function() { struct.prices.push( $( this ).data( 'ticket-id' ) ); } );

				// update the struct fields in the struct list
				var struct_item = me.elements.struct_list.find( '[role="item"][data-struct-id="' + struct_id + '"]' );
				struct_item.find( '[role="name"]' ).html( struct.name )
				struct_item.find( '[role="price-count"]' ).html( _price_count( struct ) );
				struct_item.find( '[role="struct-settings"]' ).val( JSON.stringify( struct ) );
			}

			// handles the add button logic
			function _add_struct( e ) {
				e.preventDefault();
				// if the new struct action is cancelled or the user does not provide a name, then bail
				if ( ! ( new_name = prompt( QS._str( 'What should be the name of this new pricing structure?', S ) ) ) )
					return;

				var new_index = auto_index--, struct;
				// create the new struct. start by adding it to the internal struct list
				S.structs[ new_index ] = struct = { id:new_index, name:new_name, prices:[] };

				// now add it to the displayed struct list
				$( _repl( _tmpl( 'gamp-struct-li' ), { struct_id:struct.id, struct_name:struct.name, struct_price_cnt:_price_count( struct ) } ) ).appendTo( me.elements.struct_list )
						.find( '[role="struct-settings"]' ).val( JSON.stringify( struct ) );

				// and finally, load the new struct for editing
				_load_struct_ui( struct.id );
			}

			// handle the edit struct button logic
			function _load_struct( e ) {
				e.preventDefault();
				// figure out the id of the struct we are editing
				var struct_item = $( this ).closest( '[role="item"]' ), struct_id = struct_item.data( 'struct-id' ), struct, i;

				// load the actual ui for the selected structure
				_load_struct_ui( struct_id );
			}

			// draw the actual ui for the struct
			function _load_struct_ui( struct_id ) {
				me.current_struct_id = struct_id;

				// if here is no struct id, or that struct cannot be found, then bail
				if ( ! struct_id || ! qt.is( S.structs[ struct_id ] ) ) {
					alert( QS._str( 'Could not load that struct to be edited.', S ) );
					return;
				}
				struct = S.structs[ struct_id ];

				// update the name to be edited
				me.elements.name_box.val( struct.name );

				_cleanup_ui();
				// start by adding all the prices from our holder list to the available list
				me.elements.price_holder.find( '>' ).appendTo( me.elements.available_list );

				// next, move all the prices from the available list into the used list, that are used by this price struct
				for ( i in struct.prices )
					me.elements.available_list.find( '[data-ticket-id="' + struct.prices[ i ] + '"]' ).appendTo( me.elements.used_list );

				// show the edit box
				me.elements.edit_box.show();
			}

			// handle the remove struct button logic
			function _remove_struct( e ) {
				e.preventDefault();
				// if the 'are you sure' prompt fails, then bail
				if ( ! prompt( QS._str( 'Are you sure you want to remove that pricing structure?' ) ) )
					return;

				// figure out the id of the struct we are removing
				var struct_item = $( this ).closest( '[role="item"]' ), struct_id = struct_item.data( 'struct-id' ), struct, i;

				// if here is no struct id, or that struct cannot be found, then bail
				if ( ! struct_id || ! qt.is( S.structs[ struct_id ] ) ) {
					alert( QS._str( 'Could not load that struct to be edited.', S ) );
					return;
				}

				// cleanup the ui. if we are editing a price struct now, just end that editing
				_end_editing( e );

				// first remove it from the internal list
				delete S.structs[ struct_id ];

				// now remove the displayed struct item
				struct_item.fadeOut( { duration:250, complete:function() { struct_item.remove(); } } );
			}

			// handle the end editing logic
			function _end_editing( e ) {
				if ( qt.isO( e ) && qt.isF( e.preventDefault ) )
					e.preventDefault();

				me.current_struct_id = 0;
				// clean up the ui. hide the edit box, and move all the prices back to their holding cell
				me.elements.edit_box.hide();
				_cleanup_ui();
			}

			// moves all the pricing options back to the price_holder container
			function _cleanup_ui() {
				me.elements.available_list.find( '>' ).appendTo( me.elements.price_holder );
				me.elements.used_list.find( '>' ).appendTo( me.elements.price_holder );
			}

			// initialize the ui
			me.init = function( element, options ) {
				me.current_struct_id = 0;
				me.options = {};
				me.elements = {};

				me.reinit( element, options );
			}

			// reinitialize the ui. do this by removing any existing ui elements and recreating them from scratch based on the supplied options
			me.reinit = function( element, options ) {
				// first remove all elements
				for ( i in me.elements )
					if ( 'main' !== i && me.elements[ H ]( i ) )
						me.elements[ i ].remove();

				// now merge the new options on top of the old options
				me.options = $.extend( me.options, options );

				// recreate the UI elements and event handlers
				me.elements = { main:$( element ).empty() };
				_setup_elements();
				_setup_events();
			}

			me.init( element, options );
		}

		var instance = null;
		// singleton
		UI.instance = function( element, options ) {
			var element = $( element ),
					options = $.extend( {}, options );

			// if the instance already exists, then just resetup the UI with new settings
			if ( qt.isO( instance ) && qt.isF( instance.reinit ) ) {
				instance.reinit( element, options );
				return instance;
			// otherwise create a new instance with the supplied settings
			} else {
				return ( instance = new UI( element, options ) );
			}
		}

		return UI;
	} )();

	$( function() {
		QS.GAMPPriceStructUI.instance( $( '[role="price-struct-ui"]' ) );
	} );
} )( jQuery, QS.Tools );
