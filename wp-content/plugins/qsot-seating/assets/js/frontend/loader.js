// loads the frontend ui for ticket selection
( function( $, q, qt ) {
	var S = $.extend( { messages:{} }, _qsot_seating_loader );

	function __( name ) {
		var args = [].slice.call( arguments, 1 ), str = qt.is( S.messages[ name ] ) ? S.messages[ name ] : name, i;
		for ( i = 0; i < args.length; i++ ) str = str.replace( '%s', args[ i ] );
		return str;
	}

	$( function() {
		// the UI container
		var sel = $( '[rel="ticket-selection"]' );

		// test features, and load the necessary version based on what is available
		q.Features.load( [
			{
				// we require cookies to work, since woocommerce requires cookies to track the cart. if cookies are on, then proceed
				name: 'cookies',
				run: function() {
					q.Loader.js( S.assets.res, 'qsot-seating-reservations', 'head', 'append', function() {
						$( qt.is( S.templates['loading'] ) ? S.templates['loading'] : '' ).appendTo( '.event-area-image' ).show();
						/*
						$.ajax( {
							url: S.ajaxurl,
							data: { action:'qsots-ajax', sa:'chart-data', n:S.nonce, ei:S.event_id },
							dataType: 'json',
							error: function() { console.log( 'ERROR: Unexpected Error loading chart' ); },
							method: 'POST',
							success: function( r ) {
								if ( r.s && qt.isO( r.r ) ) {
									S = $.extend( true, {}, S, r.r );
									console.log( 'final settings', S );
									*/
									S.resui = new QS.Reservations( $( '[rel="ticket-selection"]' ), S );

									var modes = [];
									if ( qt.is( S.event_id ) && qt.toInt( S.event_id ) > 0 ) {
										modes.push( {
											// svg is the preferred method of interface. if it is available, then load the SVG interface
											name: 'svg',
											run: function() {
												if ( qt.isO( S.assets ) && qt.is( S.assets.svg ) )
													q.Loader.js( S.assets.snap, 'qsot-seating-snap', 'head', 'append', function() {
														q.Loader.js( S.assets.svg, 'qsot-seating-svgui', 'head', 'append', function() {
															$( '.event-area-image' ).empty();
															var ticket_selection = $( '[rel="ticket-selection"]' ).empty();
															if ( ticket_selection.length )
																QS.svgui( ticket_selection, S );
														} );
													} );
												else
													$( '<div class="error">' + __( 'Could not load the required components.' ) + '</div>' ).appendTo( sel.empty() );
											}
										} );
									}

									modes.push( {
										// if svg is not available, then we can fallback to the basic 'dropdown' style, crappy interface that everyone else has
										name: 'fallback',
										run: function() {
											if ( qt.isO( S.assets ) && qt.is( S.assets.svg ) )
												q.Loader.js( S.assets.nosvg, 'qsot-seating-nosvgui', 'head', 'append', function() {
													var ticket_selection = $( '[rel="ticket-selection"]' ).empty();
													if ( ticket_selection.length )
														QS.nosvgui( ticket_selection, S );
												} );
											else
												$( '<div class="error">' + __( 'Could not load a required component.' ) + '</div>' ).appendTo( sel.empty() );
										}
									} );

									q.Features.load( modes );
									/*
								} else if ( r.e && r.e.length ) {
									console.log( 'ERROR: ', r.e );
								} else {
									console.log( 'ERROR: Unexpected Error loading chart' );
								}
							},
							xhrFields: { withCredentials: true }
						} );
						*/
					} )
				}
			},
			{
				// if cookies are off, then error out
				name: 'fallback',
				run: function() {
					$( '<div class="error">' + __( 'You do not have cookies enabled, and they are required.' ) + '</div>' ).appendTo( sel.empty() );
					alert( __( 'You must have cookies enabled to purchase tickets.' ) );
				}
			}
		] );
	} );
} )( jQuery, QS, QS.Tools );
