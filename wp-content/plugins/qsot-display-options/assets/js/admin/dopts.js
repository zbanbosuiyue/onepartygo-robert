var QS = QS || {};

( function( $, qt ) {
	var S = $.extend( { nonce:'', msgs:{} }, _qsot_do_settings );

	// holder for FindPosts related functions
	QS.DOpts = QS.DOpts || {};

	// generic error handler for error responses for ajax
	function _generic_efunc( r ) {
		// verify that the console is a valid object on this browser
		if ( ! qt.is( console ) || ! qt.isF( console.log ) ) return;

		// if the response has errors we can interpret, the print them in the console
		if ( qt.isO( r ) && qt.isA( r.e ) && r.e.length ) {
			for ( var i = 0; i < r.e.length; i++ )
				console.log( 'AJAX Error: ', r.e[ i ] );
		// otherwise, just write a generic error in the console
		} else {
			console.log( 'AJAX Error: Unexpected ajax response.', r );
		}
	}

	// handle the ajax requests
	QS.DOpts.aj = function( sa, data, func, efunc ) {
		// setup the defaults, and compile the request
		var func = qt.isF( func ) ? func : function() {},
				efunc = qt.isF( efunc ) ? efunc : function( r ) { _generic_efunc( r ); },
				data = $.extend( { sa:'unknown', n:S.nonce, action:'qsot-display-options' }, data, { sa:sa || 'unknown' } );

		// run the ajax
		$.ajax( {
			url: ajaxurl,
			data: data,
			dataType: 'json',
			error: efunc,
			method: 'POST',
			success: func,
			xhrFields: { withCredentials: true }
		} );
	}

	// fetch a message from our message list, and replace any args that are present
	QS.DOpts.msg = function( str ) {
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

	// lookup a template from our settings
	QS.DOpts.templ = function( name ) {
		return qt.is( name ) && 'string' == typeof name && qt.is( S.templ[ name ] ) ? S.templ[ name ] : '';
	}

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

	// add the i18n datepicker to an element. i18n datepicker differs from a normal datepicker, because a normal datepicker will not adjust the display date format based on locale.
	// display format is passed to this function via element data, produced by the php code
	QS.DatepickerI18n = QS.DatepickerI18n || function( context, selector ) {
		var selector = selector || '.use-i18n-datepicker', // selector to identify elements that need the datepicker
				context = qt.is( context ) && context.length ? $( context ) : $( 'body' ); // context to search for selector within

		// for every element that needs a datepicker that does not already have one, attempt to apply one
		$( selector, context ).not( '.has-datepicker' ).each( function() {
			var me = $( this ),
					display_format = me.data( 'display-format' ) || 'mm-dd-yy',
					scope = me.closest( me.attr( 'scope' ) || 'body' ),
					role = me.attr( 'role' ) || 'standard',
					mode = me.data( 'mode' ) || 'normal',
					mode = mode.split( /\s*:\s*/ ),
					d = new Date( me.val() ),
					min = me.data( 'min-date' ) || '',
					max = me.data( 'max-date' ) || '',
					def = me.data( 'default' ),
					def = '' === me.val() ? ( qt.is( def ) ? def : d ) : d;

			// if the selected element is not a hidden element, then make it so
			if ( 'hidden' !== me.attr( 'type' ).toLowerCase() ) {
				var atts = QS.AllAttrs( me );
				delete atts.type;
				var tmp = $( '<input type="hidden" />' ).attr( atts ).insertBefore( me );
				me.remove();
				me = tmp;
			}

			// create a display version above the hidden on
			var display = $( '<input type="date" />' ).insertBefore( me ).attr( { id:( me.attr( 'id' ) || me.attr( 'name' ) ) + '-display', role:role + '-display' } );
			me.data( 'display', display );

			// setup some basic settings for the datepicker
			var args = {
				altField: me,
				altFormat: 'yy-mm-dd',
				dateFormat: display_format
			};

			// if there is a min-date, then set it
			if ( min )
				args.minDate = new Date( min );

			// if there is a max-date, then set it
			if ( max )
				args.maxDate = new Date( max );

			// depending on the 'mode' we may need to add elements and modify the args
			switch ( mode[0] ) {
				case 'icon':
					args.showOn = 'button';
					args.buttonText = '<span class="dashicons ' + ( qt.is( mode[1] ) ? mode[1] : 'dashicons-calendar-alt' ) + '"></span>';
				break;
			}

			// depending on the role, we may need to add some extra logic
			switch ( role ) {
				case 'from':
					args.onSelect = function( str, obj ) {
						var d = display.datepicker( 'getDate' ),
								other = scope.find( '[role="to"]' ).data( 'display' ),
								other_d = other.length ? other.datepicker( 'getDate' ) : d

						if ( other.length && d && other_d && d.getTime() > other_d.getTime() ) {
							other.datepicker( 'setDate', d );
						}
					};
				break;

				case 'to':
					args.onSelect = function( str, obj ) {
						var d = display.datepicker( 'getDate' ),
								other = scope.find( '[role="from"]' ).data( 'display' ),
								other_d = other.length ? other.datepicker( 'getDate' ) : d

						if ( other.length && d && other_d && d.getTime() < other_d.getTime() ) {
							other.datepicker( 'setDate', d );
						}
					};
				break;
			}

			// initialize the datepicker now
			display.datepicker( args );
			display.datepicker( 'setDate', def );
			me.addClass( 'has-datepicker' );
		} );
	}

	$( function() { QS.DatepickerI18n( 'body' ); } );
} )( jQuery, QS.Tools );
