// encapsulate jquery and alias to $
if ( 'object' == typeof QS && 'undefined' !== typeof jQuery ) ( function( $, qt ) {
	var S = $.extend( { cart_url:'' }, _qsotts_frontend );

	// add callback for reservation success, that redirects the user to the appropriate table-service page
	QS.cbs.add( 'qsots-reserve-success', function( resp, req ) {
		window.location.href = resp.forward_url || S.cart_url;
		return false;
	} );

	// find the price range of the available prices
	function get_price_range( ticket_types, prices, key ) {
		var display_key = key,
				float_key = key + '_f',
				min = null,
				max = null,
				min_display = null,
				max_display = null,
				pi = 0, pl = prices.length,
				p;

		// cycle through all the prices, and figure out the min and max min_spend value
		for ( pi = 0; pi < pl; pi++ ) {
			p = qt.is( ticket_types[ prices[ pi ].product_id ] ) ? ticket_types[ prices[ pi ].product_id ] : false;
			// if there is no product, skip this price
			if ( ! qt.isO( p ) )
				continue;

			p[ float_key ] = qt.toFloat( p[ float_key ] );
			// if this price does not have a 'float_key', then make the min 0
			if ( ! qt.is( p[ float_key ] ) ) {
				min = 0;
				min_display = S.def_min;
				if ( null === max )
					( max = min ), ( max_display = min_display );
				continue;
			}

			// otherwise, figure out the min and max spends including this item
			if ( null === min || p[ float_key ] < min ) {
				min = p[ float_key ];
				min_display = p[ display_key ];
			}
			if ( null === max || p[ float_key ] > max ) {
				max = p[ float_key ];
				max_display = p[ display_key ];
			}
		}

		// if the min and max are the same value
		if ( min == max ) {
			// if they are both empty, then return a blank string
			if ( ! min )
				return '';
			// otherwise, just use the one price for display, not a range
			else
				return min_display;
		// otherwise, display a range for the min spend field
		} else {
			return min_display + ' - ' + max_display;
		}
	}

	// add the min spend information to the seat tooltips
	QS.cbs.add( 'qsots-tooltip', function( TT, zone, prices, ticket_types, available ) {
		var min_spend = get_price_range( ticket_types, prices, 'min_spend' ),
				total = get_price_range( ticket_types, prices, 'total' );

		// if the min_spend is blank, remove it from the tooltip
		if ( '' == min_spend )
			TT.find( '.spend' ).remove();
		// otherwise update the value
		else
			TT.find( '.spend .value' ).html( min_spend );

		// if the total is blank, remove it from the tooltip
		if ( '' == min_spend )
			TT.find( '.total' ).remove();
		// otherwise update the value
		else
			TT.find( '.total .value' ).html( total );

		// remove the old price
		TT.find( '.price' ).not( '.table-fee' ).remove();
	} );

	// add the min_spend to the price list for seat selection
	QS.cbs.add( 'qsots-price-list-item', function( price, ele, ticket_types ) {
		// get the ticket type for this price
		var tt = qt.is( ticket_types[ price.product_id ] ) ? ticket_types[ price.product_id ] : false;

		// if there is no ticket type, bail now, after removing the min-spend section of the item
		if ( ! qt.isO( tt ) ) {
			ele.find( '.spend' ).remove();
			return;
		}

		// otherwise, add the min_spend to the price item
		ele.find( '.spend .value' ).html( tt.min_spend );
	} );
} )( jQuery, QS.Tools );
