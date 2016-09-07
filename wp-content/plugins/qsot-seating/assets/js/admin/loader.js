var _qsot_seating_loader = _qsot_seating_loader || {};
// loads the admin ui for ticket selection
( function( $, q, qt ) {
	var S = $.extend( {}, _qsot_admin_seating_loader );

	$( function() {
		// test features, and load the necessary version based on what is available
		q.Features.load( [
			{
				// we require cookies to work, since woocommerce requires cookies to track the cart. if cookies are on, then proceed
				name: 'cookies',
				run: function() {
					q.Loader.js( S.assets.res, 'qsot-seating-reservations', 'head', 'append', function() {
						q.Features.load( [
							{
								// svg is the preferred method of interface. if it is available, then load the SVG interface
								name: 'svg',
								run: function() {
									if ( qt.isO( S.assets ) && qt.is( S.assets.svg ) )
										q.Loader.js( S.assets.snap, 'qsot-seating-snap', 'head', 'append', function() {
											_qsot_seating_loader = S;
											q.Loader.js( S.assets.svg, 'qsot-seating-svgui', 'head', 'append', function() {
												q.Loader.js( S.assets.ts, 'qsot-seating-ticket-selection', 'head', 'append', function() {
												} );
											} );
										} );
									else
										$( '<div class="error">Could not load the required components.</div>' ).insertBefore( tab );
								}
							},
							{
								// if svg is not available, then we can fallback to the basic 'dropdown' style, crappy interface that everyone else has
								name: 'fallback',
								run: function() {
									$( '<div class="error">Could not load a required component.</div>' ).insertBefore( tab );
								}
							}
						] );
					} )
				}
			},
			{
				// if cookies are off, then error out
				name: 'fallback',
				run: function() {
					$( '<div class="error">You do not have cookies enabled, and they are required.</div>' ).insertBefore( tab )
					alert( 'You must have cookies enabled in order to use the seat selection UI.' );
				}
			}
		] );
	} );
} )( jQuery, QS, QS.Tools );
