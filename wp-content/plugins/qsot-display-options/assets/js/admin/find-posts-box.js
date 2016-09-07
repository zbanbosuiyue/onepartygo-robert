var QS = QS || {};

( function( $, qt ) {
	var last_protect = '';
	// protect an event by comparing the last to the current rand val X ms from now
	function _protect( e, ms, func, args ) {
		var me = this, mine = ( Math.random() * 1000000 ) + ':' + ( Math.random() * 10000 ), args = args || [];
		args.unshift( e );
		last_protect = mine;
		setTimeout( function() {
			if ( mine !== last_protect )
				return;
			func.apply( me, args );
		}, ms );
	}

	// container for the add coupon dialog
	var dia, dia_cont;

	QS.DOpts = QS.DOpts || {};

	// add the find-posts functionality to all find-posts-btn elements
	QS.DOpts.FindPostsBtns = function( context, on_actions ) {
		var context = context || $( 'body' ),
				on_actions = $.extend( {
					select: function() {}
				}, on_actions );

		// when the add coupon button is clicked
		$( context ).off( 'click.qsotdofpb', '[role="find-posts-btn"]' ).on( 'click.qsotdofpb', '[role="find-posts-btn"]', function( e ) {
			e.preventDefault();
			var btn = this, par = $( this ).closest( 'form' ), par = par.length ? par : $( this ).parents( 'div:eq(0)' );

			// if there is not already a dialog created, create one
			if ( ! qt.is( dia ) ) {
				// create the backbone required container, which is ironically never actually used
				dia_cont = $( '<div class="modal qsot-dialog-container"></div>' ).appendTo( 'body' );
				// start the actual dialog and fill it with a loading message
				dia_cont.empty().QSOTBackboneModal( { template:'#qsot-do-find-posts' } );
				// store the dialog object for later reference
				dia = dia_cont.QSOTBackboneModal( 'get' );

				function find_posts( e, force_empty ) {
					force_empty = force_empty || false;
					// block the order items metabox while we do our processing
					dia.$el.find( '.wc-backbone-modal-main' ).qsBlock();

					// get the form data
					var data = dia.$el.louSerialize();
					if ( dia.btn.data( 'only-parents' ) )
						data.post_parent = '0';

					// add the coupon to the order via ajax
					QS.DOpts.aj( 'search_posts', data, function( r ) {
						// if the ajax request was successful, then replace the contents of the order items meta box with the resulting html
						if ( r && r.s && r.r ) {
							var resbox = dia.$el.find( '.results-box' );
							if ( resbox.length < 1 ) {
								// unblcok the order items metabox
								dia.$el.find( '.wc-backbone-modal-main' ).qsUnblock();
								console.log( 'no resbox' );
								return;
							}

							// remove all non selected items from the existing list
							if ( force_empty )
								resbox.find( '.item,.novalue' ).remove();
							else
								resbox.find( '.item' ).not( '.selected' ).remove();

							// get the template, and bail if it does not exist
							var tmpl = QS.DOpts.templ( 'result-item' );
							if ( '' === tmpl ) {
								// unblcok the order items metabox
								dia.$el.find( '.wc-backbone-modal-main' ).qsUnblock();
								console.log( 'no template' );
								return;
							}

							// cycle through the results and add each to the list
							for ( var i = 0; i < r.r.length; i++ ) {
								var item = $( tmpl ).appendTo( resbox );
								item.data( {
									id: r.r[ i ].id,
									title: r.r[ i ].t,
									thumb: r.r[ i ].u,
									'post-type': r.r[ i ].y,
								} );
								item.find( '[role="item-title"]' ).html( r.r[ i ].t );
								item.find( '[role="item-thumb"]' ).html( r.r[ i ].u );
								item.find( '[role="item-type"]' ).html( r.r[ i ].y );
							}
						} else {
							console.log( 'no results' );
						}

						// unblcok the order items metabox
						dia.$el.find( '.wc-backbone-modal-main' ).qsUnblock();
					}, function() { dia.$el.find( '.wc-backbone-modal-main' ).qsUnblock(); } );
				}

				// get data about the selected items
				function _get_selected() {
					var list = [];
					// find all the selected items, and aggregate the information about them
					dia.$el.find( '[role="results"] .item.selected' ).each( function() {
						var ele = $( this );
						list.push( {
							id: ele.data( 'id' ),
							title: ele.data( 'title' ),
							thumb: ele.data( 'thumb' ),
							post_type: ele.data( 'post-type' )
						} );
					} );

					return list;
				}

				// when the ok button is clicked, add the coupons to the order via ajax, and then refresh the order items
				dia.$el.on( 'click.qsotdofpb', '#btn-select-posts', function( e ) {
					// aggregate the dialog data
					var data = dia.$el.louSerialize(),
							selected = _get_selected();

					// call the selection callback
					if ( qt.isF( on_actions.select ) )
						on_actions.select( selected, data, dia.par, dia.btn );

					// close the dialog
					dia.close();
				} );

				dia.$el.on( 'keyup.qsotdofpb', '[role="search-text"]', function( e ) { _protect.apply( this, [ e, 350, find_posts, [true] ] ) } );
				dia.$el.on( 'click.qsotdofpb', '[role="search-btn"]', function( e ) { e.preventDefault(); find_posts.call( this, e, true ); } );
				dia.$el.on( 'click.qsotdofpb', '[role="item"]', function( e ) { $( this ).toggleClass( 'selected' ); } );
				dia.$el.on( 'click.qsotdofpb', '.modal-close', function( e ) { e.preventDefault(); dia.close(); } );
				dia.$el.on( 'find-posts.qsotdofpb', find_posts );

				// setup the search boxes
				dia.adjust_size();
			}

			// open the add coupon dialog
			dia.btn = $( btn );
			dia.par = $( par );
			//dia.$el.trigger( 'find-posts', [ true ] );
			dia.open();
		} );
	}

	$( document ).on( 'click.qsotdofpb', '[role="remove-btn"]', function( e ) { $( this ).closest( '[role="item"]' ).remove(); } );

	$( function() {
		// add the events to the find-posts-btn elements
		QS.DOpts.FindPostsBtns( document, {
			// when clicking the select posts button
			select: function( selected, data, par, btn ) {
				// if the selected posts list is not an array, then bail
				if ( ! qt.isA( selected ) )
					return;

				// get the list item template. if there is none, bail
				var tmpl = QS.DOpts.templ( 'list-item' );
				if ( '' == tmpl )
					return;

				var field = btn.attr( 'selected-name' ) || 'post_id',
						scope = btn.closest( btn.attr( 'scope' ) || 'body' ),
						list = scope.find( btn.attr( 'list' ) || '[role="post-list"]' );

				// cycle through the selected list, and 
				for ( var i = 0; i < selected.length; i++ ) {
					var item = $( tmpl ).appendTo( list );
					item.find( '[role="item-id"]' ).attr( 'name', field ).val( selected[ i ].id );
					item.find( '[role="item-title"]' ).html( selected[ i ].title ).attr( 'title', selected[ i ].title );
					item.find( '[role="item-thumb"]' ).html( selected[ i ].thumb );
				}
			}
		} );
	} );
} )( jQuery, QS.Tools );
