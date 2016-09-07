var QS = QS || {};

( function( $, qt ) {
	var S = $.extend( { nonce:'', msgs:{} }, _qsot_coupons_settings );

	// fetch a message from our message list, and replace any args that are present
	function _msg( str ) {
		// get every arg after the string
		var args = [].slice.call( arguments, 1 ), i;

		// look up the string in the list we have. if there is an entry, then use that entry as the string instead of the string itself. used for localization
		if ( qt.is( S.msgs[ str ] ) )
			str = S.msgs[ str ];

		// do a replacement of any args that require it, based off the list of args we extracted from the function call
		for ( i = 0; i < args.length; i++ )
			str = str.replace( /%s/, args[ i ] );

		// return the resulting string
		return str;
	}

	// upon clicking the add limit button, add a limit
	$( document ).on( 'click', '[rel="add-limit"]', function( e ) {
		// determine all relevant elements
		var me = $( this ), tar = me.attr( 'tar' ), from = me.attr( 'from' ), par = me.closest( '.form-field' ), tar = $( tar, par.length ? par : 'body' ), from = $( from );

		// if the target or the template does not exist, then bail
		if ( ! from.length || ! tar.length )
			return;

		// create the elements from the template
		$( from.text() ).appendTo( par );

		// setup the dropdowns on the new elements
		QS.Coupons.setup_search_boxes( par )
	} );

	// when the remove button is clicked on an event limit, remove the limit from the list
	$( document ).on( 'click', '[rel="remove"]', function() {
		// determine the relevant elements
		var me = $( this ), row = me.closest( '.limitation' );

		// confirm the deletion of the row
		if ( confirm( _msg( 'Are you sure you want to remove this limitation?' ) ) ) {
			// remove the row
			row.remove();
		}
	} );

	// actually toggle parts of the page if the _create_coupon option is selected or unselected
	function toggle_create_coupon( element ) {
		if ( $( element ).is( ':checked' ) ) {
			$( '.show_if_create_coupon' ).show();
			$( '.hide_if_create_coupon' ).hide();
		} else {
			$( '.show_if_create_coupon' ).hide();
			$( '.hide_if_create_coupon' ).show();
		}
	}

	$( function() {
		// setup all post search boxes
		QS.Coupons.setup_search_boxes( 'body' );

		// add date range pickers to any elements on screen that need it
		QS.addDateRangePickers( '.use-datepicker-range' );

		// when opening the coupon tab on the edit product page, open the first subtab if none are currently open
		$( '.product_data_tabs.wc-tabs li.create_coupon_options a' ).click( function( e ) {
			var tar = $( $( this ).attr( 'href' ) ).show();
			// delay so that it happens after the other wc-tabs logic
			setTimeout( function() {
				if ( ! tar.find( '.wc-tabs .active' ).length ) {
					$( tar.find( '.wc-tabs li:visible:first a' ).attr( 'href' ) ).show();
					tar.find( '.wc-tabs li:visible:first a' ).closest( 'li' ).addClass( 'active' );
				}
			}, 100 );
		} );

		// handle the show and hide of parts of the page based on the 'create_coupon' option activation
		$( '#woocommerce-product-data .type_box' ).on( 'change', '#_create_coupon', function( e ) {
			toggle_create_coupon( this );
		} );
		// update the current value on page load
		toggle_create_coupon( $( '#woocommerce-product-data .type_box #_create_coupon' ) );
	} );
} )( jQuery, QS.Tools );
