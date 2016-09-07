var QS = QS || {},
		_qsot_seating_ticket_selection = _qsot_seating_ticket_selection || {};

// container to hold all the special logic for each event area type
QS.eventAreaTypes = QS.eventAreaTypes || {};

// special logic for the general admission event area type
QS.eventAreaTypes['seating'] = ( function( $, qs, qt) {
	// constructor for the general admission event area type handler
	function seating_ui() {
		var me = this;
		me.initialized = false;

		/* private methods */

		// setup the elements used by this area type
		function _setup_elements() {
			// get the template list for this area type
			var templates = me.S.templates['seating'] || {};

			// setup the main containers for the ticket selection UI on this event area type
			me.e = {
				main: $( '<div class="seating-event qsot-event-area-ticket-selection"></div>' ).hide().appendTo( me.ui.e.info ),
				actions: $( '<div class="seating-event qsot-event-area-ticket-selection"></div>' ).hide().appendTo( me.ui.e.actions ),
				event_wrap: $( '<div class="seating-event qsot-event-area-ticket-selection"></div>' ).hide().appendTo( me.ui.e.event_wrap ),
				holder:$( '<div></div>' )
			};

			// add the various parts used by the event area type UI
			me.e.info = $( templates['info'] ).appendTo( me.e.main );
			me.e.actions_change = $( templates['actions-change'] ).appendTo( me.e.holder );
			me.e.actions_add = $( templates['actions-add'] ).appendTo( me.e.holder );
			me.e.inner_change = $( templates['inner-change-zones'] ).appendTo( me.e.holder );
			me.e.inner_add = $( templates['inner-add-zones'] ).appendTo( me.e.holder );
		}

		// setup the events that this area type makes use of
		function _setup_events() {
			me.e.actions.on( 'click', '[rel="change-btn"]', me.ui.switch_events );
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

		// block up the dialog box
		function _block_dialog() {
			var true_dialog = me.ui.e.dialog.closest( '.ui-dialog' );
			true_dialog.qsBlock( { msg:'<h1>' + qs._str( 'Processing...', me.S ) + '</h1>', css:{ zIndex:999999 }, msgcss:{ zIndex:1000000 } } );
		}
		me._block_dialog = _block_dialog;

		// block up the dialog box
		function _unblock_dialog() {
			var true_dialog = me.ui.e.dialog.closest( '.ui-dialog' );
			true_dialog.qsUnblock();
		}
		me._unblock_dialog = _unblock_dialog;

		// initialize this object inside the modal
		me.initialize = function( ui, S ) {
			// only do this once
			if ( me.initialized )
				return;
			me.initialized = true;

			// set the internal reference to the ui and settings
			me.ui = ui;
			me.S = S;
			me.S.tsui = this;
			
			// setup the reservation ui
			if ( ! qt.isO( me.S.resui ) ) {
				// get the order items table as the primary element. we will use this as a reference point throughout the ui code
				var tab = $( '.woocommerce_order_items' );
				tab.wrap( '<div class="qsot-event-area-ticket-selection"></div>' );

				// start the reservation ui
				me.S.resui = new QS.AdminReservations( tab, S );
			}

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

			// update the event image
			me.S.resui.update_options( $.extend( true, {}, me.S, event_obj ), me.e[ 'inner_' + me.ui.state ] );
			if ( ! qt.isO( me.svgui ) ) {
				me.svgui = QS.svgui( me.e[ 'inner_' + me.ui.state ], $.extend( true, {}, me.S, event_obj ) );
			} else {
				me.svgui.reinit( me.e[ 'inner_' + me.ui.state ], $.extend( true, {}, me.S, event_obj ) );
			}

			// hide all actions, event_wrap, and info data for all area_types
			me.ui.e.info.find( '>' ).hide();
			me.ui.e.actions.find( '>' ).hide();
			me.ui.e.event_wrap.find( '>' ).hide();

			// now show all the ui elements for this event area type
			me.e.main.show();
			me.e.actions.show();
			me.e.event_wrap.show();
		};
	}

	return new seating_ui();
} )( jQuery, QS, QS.Tools );
