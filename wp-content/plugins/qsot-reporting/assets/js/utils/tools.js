var QS = QS || {};

( function( $, qt ) {
	var S = $.extend( { nonce:'', msgs:{} }, _qsot_reporting_settings );

	// holder for Coupon related functions
	QS.Reports = {};

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
	QS.Reports.aj = function( sa, data, func, efunc ) {
		// setup the defaults, and compile the request
		var func = qt.isF( func ) ? func : function() {},
				efunc = qt.isF( efunc ) ? efunc : function( r ) { _generic_efunc( r ); },
				data = $.extend( { sa:'unknown', _n:S.nonce, action:'qsot-admin-report-ajax' }, data, { sa:sa } );

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

	// format functions used by select2
	QS.Reports.frmtStr = {
		// formats the display of the number of matches
		formatMatches: function( matches ) {
			if ( 1 === matches )
				return _msg( 'One result is available. Press enter to select it.' );
			return _msg( '%s results are available. Use up and down arrow keys to navigate.', matches );
		},
		// formatted display of no matches found
		formatNoMatches: function() {
			return _msg( 'No matches found' );
		},
		// format the display of ajax errors
		formatAjaxError: function( jq_xhr, text_status, error ) {
			return _msg( 'Loading failed' );
		},
		// format of the too short error message
		formatInputTooShort: function( input, min ) {
			var num = min - input.length;

			if ( 1 == num )
				return _msg( 'Please enter 1 or more characters.' );

			return _msg( 'Please enter %s or more characters.', num );
		},
		// format of the too long error message
		formatInputTooLong: function( input, max ) {
			var num = input.length - max;

			if ( 1 == num )
				return _msg( 'Please delete 1 character.' );

			return _msg( 'Please delete %s characters.', num );
		},
		// format of the too many selected message
		formatSelectionTooBig: function( limit ) {
			if ( 1 == limit )
				return _msg( 'You can only select 1 item.' );

			return _msg( 'You can only select %s items.' );
		},
		// formatted loading more message
		formatLoadMore: function( pageNumber) {
			return _msg( 'Loading more results&hellip;' );
		},
		// formatted message indicating that searching is taking place
		formatSearching: function() {
			return _msg( 'Searching&hellip;' );
		}
	};

	QS.Reports.setup_search_boxes = function( context ) {
		var context = context || 'body';
		// Ajax event search box
		$( ':input.qsot-post-search', context ).filter( ':not(.enhanced)' ).each( function() {
			// load the select2 args, some of which are embeded in the element using the tool
			var select2_args = {
				allowClear:  $( this ).data( 'allow_clear' ) ? true : false,
				placeholder: $( this ).data( 'placeholder' ),
				minimumInputLength: $( this ).data( 'minimum_input_length' ) ? $( this ).data( 'minimum_input_length' ) : '2',
				escapeMarkup: function( m ) {
					return m;
				},
				ajax: {
					url: ajaxurl,
					dataType:    'json',
					quietMillis: 250,
					data: function( term, page ) {
						var pp = $.trim( $( this ).data( 'post-parent' ) );
						return {
							term: term,
							action: $( this ).data( 'action' ) || 'qsot-reporting',
							sa: $( this ).data( 'sa' ) || 'search_posts',
							post_types: $( this ).data( 'post-type' ) || 'qsot-event',
							post_parent: pp.length ? pp : '',
							n: S.nonce
						};
					},
					results: function( data, page ) {
						var terms = [];
						if ( data && data.r ) {
							$.each( data.r, function( ind, val ) {
								terms.push( { id:val.id, text:val.t } );
							});
						}
						return { results: terms };
					},
					cache: true
				}
			};

			// if the search box is a multi select, then 
			if ( $( this ).data( 'multiple' ) === true ) {
				select2_args.multiple = true;
				// setup the currently selected values
				select2_args.initSelection = function( element, callback ) {
					var data = $.parseJSON( element.attr( 'data-selected' ) );
					var selected = [];

					$( element.val().split( "," ) ).each( function( i, val ) {
						selected.push( { id: val, text: data[ val ] } );
					});
					return callback( selected );
				};
				// display format for selected items
				select2_args.formatSelection = function( data ) {
					return '<div class="selected-option" data-id="' + data.id + '">' + data.text + '</div>';
				};
			// if it is a single select, then
			} else {
				select2_args.multiple = false;
				// setup the currently selected value
				select2_args.initSelection = function( element, callback ) {
					var data = { id: element.val(), text: element.attr( 'data-selected' ) };
					return callback( data );
				};
			}

			// merge our select2 settings for this element with the formatter function list
			select2_args = $.extend( select2_args, QS.Reports.frmtStr );

			// initialize the select2 lib, and mark the element as having been initialized
			$( this ).select2( select2_args ).addClass( 'enhanced' );
		});
	}
} )( jQuery, QS.Tools );
