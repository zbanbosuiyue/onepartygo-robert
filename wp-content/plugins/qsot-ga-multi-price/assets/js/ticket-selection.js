var QS = QS || {},
		_qsot_gamp_ticket_selection = _qsot_gamp_ticket_selection || {};

// container to hold all the special logic for each event area type
QS.eventAreaTypes = QS.eventAreaTypes || {};

// special logic for the general admission event area type
QS.eventAreaTypes['gamp'] = ( function( $, qs, qt) {
	// constructor for the general admission event area type handler
	function gamp_ui() {
		var me = this;
		me.initialized = false;

		/* private methods */

		// setup the elements used by this area type
		function _setup_elements() {
			// get the template list for this area type
			var templates = me.S.templates['gamp'] || {};

			// setup the main containers for the ticket selection UI on this event area type
			me.e = {
				main: $( '<div class="gamp-event"></div>' ).hide().appendTo( me.ui.e.info ),
				actions: $( '<div class="gamp-event"></div>' ).hide().appendTo( me.ui.e.actions ),
				event_wrap: $( '<div class="gamp-event"></div>' ).hide().appendTo( me.ui.e.event_wrap ),
				holder:$( '<div></div>' )
			};

			// add the various parts used by the event area type UI
			me.e.info = $( templates['info'] ).appendTo( me.e.main );
			me.e.actions_change = $( templates['actions-change'] ).appendTo( me.e.holder );
			me.e.actions_add = $( templates['actions-add'] ).appendTo( me.e.holder );
			me.e.inner_change = $( templates['inner-change'] ).appendTo( me.e.holder );
			me.e.inner_add = $( templates['inner-add'] ).appendTo( me.e.holder );
			me.e.owned_list = me.e.inner_add.find( '[rel="owned-list"]' );
			me.e.ticket_type_list = me.e.inner_add.find( '[rel="ticket-type-list"]' );
			me.e.owned_none = $( templates['owned-none'] ).appendTo( me.e.holder );
			me.e.owned_item = $( templates['owned-item'] ).appendTo( me.e.holder );
			me.e.ticket_type_option = $( templates['inner-ticket-type-option'] ).appendTo( me.e.holder );
			console.log( 'gamp templates', me.e, templates, me.S );
		}

		// setup the events that this area type makes use of
		function _setup_events() {
			me.e.actions.on( 'click', '[rel="change-btn"]', me.ui.switch_events );
			me.e.actions.on( 'click', '[rel="use-btn"]', me.update_ticket );
			me.e.event_wrap.on( 'click', '[rel="add-btn"]', me.add_tickets );
		}

		// setup state UI, depending on the current ui state
		function _setup_state_ui() {
			// clear out the event image
			me.e.event_wrap.find( '[rel="image-wrap"]' ).empty();

			// move the existing actions and ui int the holding area
			me.e.event_wrap.find( '>' ).appendTo( me.e.holder );
			me.e.actions.find( '>' ).appendTo( me.e.holder );

			// move the appropriate ui elements in the actions and event_wrap container
			me.e[ 'actions_' + me.ui.state ].appendTo( me.e.actions );
			me.e[ 'inner_' + me.ui.state ].appendTo( me.e.event_wrap );

			// update the text on the button, based on state
			switch ( me.ui.state ) {
				case 'add': me.e.event_wrap.find( '[rel="add-btn"]' ).val( qs._str( 'Add Tickets', me.S ) ); break;
				case 'change': me.e.event_wrap.find( '[rel="add-btn"]' ).val( qs._str( 'Change Ticket Count', me.S ) ); break;
				default: me.e.event_wrap.find( '[rel="add-btn"]' ).val( qs._str( 'Save', me.S ) ); break;
			}
		}

		// create an indexed list of all the ticket types for this event
		function _create_ticket_type_index( event_obj ) {
			var i;
			event_obj.valid_types = {};
			// create an indexed list of valid ticket types for this event
			if ( qt.isO( event_obj._struct ) && qt.isA( event_obj._struct.prices ) )
				for ( i = 0; i < event_obj._struct.prices.length; i++ )
					event_obj.valid_types[ event_obj._struct.prices[ i ].product_id ] = event_obj._struct.prices[ i ];

			return event_obj;
		}

		// update the list that shows the current reservations held by this order, for the selected event
		function _update_current_reservations( event_obj ) {
			// empty the list of any residual items
			me.e.owned_list.empty();

			// if the order does not have any reservations for this event, then show a message that says that, and bail
			if ( ! qt.isA( event_obj._owns ) || event_obj._owns.length <= 0 ) {
				me.e.owned_none.clone().appendTo( me.e.owned_list );
				return;
			}

			var i, name;
			// otherwise, add one item for each owned item
			for ( i = 0; i < event_obj._owns.length; i++ ) {
				var own = event_obj._owns[ i ], item;
				if ( ! qt.isO( event_obj.valid_types[ own.ticket_type_id ] ) )
					continue;
				name = '"' + event_obj.valid_types[ own.ticket_type_id ].product_name + '"'; //' (' + event_obj.valid_types[ own.ticket_type_id ].product_raw_price + ')"'
				item = me.e.owned_item.clone().appendTo( me.e.owned_list );

				// update the name and quantity of the new item
				item.find( '[rel="name"]' ).html( name );
				item.find( '[rel="quantity"]' ).html( own.quantity );
			}
		}

		// update the list of available prices for this event
		function _update_ticket_type_list( event_obj ) {
			// empty the target list of any residual entries
			me.e.ticket_type_list.empty();

			var i, name, valid_type;
			// add each valid ticket type to the list of available ticket types for this event
			if ( qt.isO( event_obj._struct ) && qt.isA( event_obj._struct.prices ) ) {
				for ( i = 0; i < event_obj._struct.prices.length; i++ ) {
					valid_type = event_obj._struct.prices[ i ];
					name = valid_type.product_name; // + ' (' + valid_type.product_raw_price + ')';
					me.e.ticket_type_option.clone().val( valid_type.product_id ).html( name ).appendTo( me.e.ticket_type_list );
				}
			}
		}

		// block up the dialog box
		function _block_dialog() {
			var true_dialog = me.ui.e.dialog.closest( '.ui-dialog' );
			true_dialog.qsBlock( { msg:'<h1>' + qs._str( 'Processing...', me.S ) + '</h1>', css:{ zIndex:999999 }, msgcss:{ zIndex:1000000 } } );
		}

		// block up the dialog box
		function _unblock_dialog() {
			var true_dialog = me.ui.e.dialog.closest( '.ui-dialog' );
			true_dialog.qsUnblock();
		}

		// initialize this object inside the modal
		me.initialize = function( ui, S ) {
			// only do this once
			if ( me.initialized )
				return;
			me.initialized = true;

			// set the internal reference to the ui and settings
			me.ui = ui;
			me.S = S;

			// setup the elements and events
			_setup_elements();
			_setup_events();
		}

		// handle the load event logic for this area type
		me.load_event = function( event_obj ) {
			// setup the state buttons and interface
			_setup_state_ui();

			// show whether there are enough seats or not
			me.e.info[ me.ui.quantity <= event_obj._available ? 'removeClass' : 'addClass' ]( 'no-enough' );

			// add the event name, with the edit link on it
			$( event_obj._link ).appendTo( me.e.info.find( '[rel="name"]' ).empty() );

			// and the date range for the event
			$( event_obj._html_date ).appendTo( me.e.info.find( '[rel="date"]' ).empty() );

			// fill out the capacity and availability indicators
			me.e.info.find( '[rel="capacity"] [rel="total"]' ).text( event_obj._capacity );
			me.e.info.find( '[rel="capacity"] [rel="available"]' ).text( event_obj._available );

			// create an indexed list of the valid ticket types for this event
			_create_ticket_type_index( event_obj );

			// show a list of the current reservations
			_update_current_reservations( event_obj );

			// fill out the list of available ticket types for the user to choose from
			_update_ticket_type_list( event_obj );

			// update the event image
			if ( qt.isO( event_obj._imgs ) && qt.isO( event_obj._imgs.full ) && qt.is( event_obj._imgs.full.url ) )
				$( '<div class="event-area-image"><img src="' + qt.esc( event_obj._imgs.full.url ) + '" title="' + qt.esc( event_obj.name ) + '" /></div>' ).appendTo( me.e.event_wrap.find( '[rel="image-wrap"]' ).empty() );

			// hide all actions, event_wrap, and info data for all area_types
			me.ui.e.info.find( '>' ).hide();
			me.ui.e.actions.find( '>' ).hide();
			me.ui.e.event_wrap.find( '>' ).hide();

			// now show all the ui elements for this event area type
			me.e.main.show();
			me.e.actions.show();
			me.e.event_wrap.show();
		};

		// handle the clicking of the 'add-btn'
		me.add_tickets = function( e ) {
			e.preventDefault();
			// obtain the form data
			var btn = $( this ), form = btn.closest( '.ticket-form' ), data = form.louSerialize( { event_id:me.ui.event_obj.id } );

			// if there quantity is not present or too low, then bail
			if ( ! qt.is( data.qty ) || data.qty < 1 ) {
				alert( qs._str( 'You must specify a quantity greater than 1.', me.S ) );
				return;
			}

			// block the dialog
			_block_dialog();

			// process the add ticket request
			me.ui.aj( 'gamp-add-tickets', data, function( r ) {
				// if the response is not valid, then bail
				if ( ! qt.isO( r ) ) {
					_unblock_dialog();
					me.ui.dialog_msgs( [ qs._str( 'Invalid response.', me.S ) ], 'error' );
					return;
				}

				// if the response was not successful, then bail
				if ( ! qt.is( r.s ) || ! r.s ) {
					_unblock_dialog();
					// show any passed errors, or a default error
					me.ui.dialog_msgs( qt.isA( r.e ) ? r.e : [ qs._str( 'There was a problem adding thos tickets.', me.S ) ], 'error' );
					return;
				}

				// refresh the event information box
				if ( r.data )
					me.load_event( r.data );

				// otherwise, update the order items list
				me.ui.update_order_items( function() { _unblock_dialog(); } );
			}, function() { _unblock_dialog(); } )
		};

		me.update_ticket = function( e ) {
			e.preventDefault();

			// aggregate a list of the data needed for the ajax request
			var data = { event_id:me.ui.event_obj.id, oiid:me.ui.order_item.data( 'order_item_id' ) };

			// block the dialog
			_block_dialog();

			// process the update ticket request
			me.ui.aj( 'gamp-update-ticket', data, function( r ) {
				// if the response is not valid, then bail
				if ( ! qt.isO( r ) ) {
					_unblock_dialog();
					me.ui.dialog_msgs( [ qs._str( 'Invalid response.', me.S ) ], 'error' );
					return;
				}

				// if the response was not successful, then bail
				if ( ! qt.is( r.s ) || ! r.s || ! qt.isO( r.data ) || ! qt.isO( r.event ) ) {
					_unblock_dialog();
					// show any passed errors, or a default error
					me.ui.dialog_msgs( qt.isA( r.e ) ? r.e : [ qs._str( 'There was a problem adding thos tickets.', me.S ) ], 'error' );
					return;
				}

				// update the event display in the order item
				// first update the event id on the button, so on the next click to change the event, the whole process works as expected
				me.ui.order_item.find( '.change-ticket' ).attr( 'event-id', r.event.ID );

				// add a new link to the new event in the order item meta, and remove the old one
				var event_link = me.ui.order_item.find( '.ticket-info [rel="edit-event"]' );
				$( r.event._edit_link ).insertBefore( event_link );
				event_link.remove();

				// mark the item as having been updated, which highlights it for confirmation
				me.ui.order_item.find( '.ticket-info' ).addClass( 'updated' );

				// clearup and close the dialog
				_unblock_dialog();
				me.ui.e.dialog.dialog( 'close' );
			}, function() {
				me.ui.dialog_msgs( [ qs._str( 'Could not update those tickets.', me.S ) ], 'error' );
				_unblock_dialog();
			} )
		};
	}

	return new gamp_ui();
} )( jQuery, QS, QS.Tools );
