var QS = QS || {};

( function( $, qt ) {
	var S = $.extend( { nonce:'', msgs:{}, templates:{} }, _qsot_order_coupons_settings );

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

	// fetch a template from our template list, based on the template name
	function _tmpl( name ) {
		// if there is a template by the supplied name, then send it
		if ( qt.is( S.templates[ name ] ) )
			return S.templates[ name ];

		// otherwise return nothing
		return '';
	}

	// copied methods from meta-boxes-order.js WC2.3.9
	var wc_meta_boxes_order_items = {
		block: function() {
			$( '#woocommerce-order-items' ).block({
				message: null,
				overlayCSS: {
					background: '#fff',
					opacity: 0.6
				}
			});
		},

		unblock: function() {
			$( '#woocommerce-order-items' ).unblock();
		},

		init_tiptip: function() {
			$( '#tiptip_holder' ).removeAttr( 'style' );
			$( '#tiptip_arrow' ).removeAttr( 'style' );
			$( '.tips' ).tipTip({
				'attribute': 'data-tip',
				'fadeIn': 50,
				'fadeOut': 50,
				'delay': 200
			});
		},

		stupidtable: {
			init: function() {
				$( '.woocommerce_order_items' ).stupidtable().on( 'aftertablesort', this.add_arrows );
			},

			add_arrows: function( event, data ) {
				var th    = $( this ).find( 'th' );
				var arrow = data.direction === 'asc' ? '&uarr;' : '&darr;';
				var index = data.column;

				if ( 1 < index ) {
					index = index - 1;
				}

				th.find( '.wc-arrow' ).remove();
				th.eq( index ).append( '<span class="wc-arrow">' + arrow + '</span>' );
			}
		}
	};

	// container for the add coupon dialog
	var dia, dia_cont;

	function _add_coupon_btn( context ) {
		var context = context || $( 'body' );

		// when the add coupon button is clicked
		context.off( 'click.qsotcp', '[rel="add-coupon"]' ).on( 'click.qsotcp', '[rel="add-coupon"]', function( e ) {
			e.preventDefault();
			var btn = this;

			// if there is not already a dialog created, create one
			if ( ! qt.is( dia ) ) {
				// create the backbone required container, which is ironically never actually used
				dia_cont = $( '<div class="modal qsot-dialog-container"></div>' ).appendTo( 'body' );
				// start the actual dialog and fill it with a loading message
				dia_cont.empty().QSOTBackboneModal( { template:'#qsot-add-coupon' } );
				// store the dialog object for later reference
				dia = dia_cont.QSOTBackboneModal( 'get' );

				// when the ok button is clicked, add the coupons to the order via ajax, and then refresh the order items
				dia.$el.on( 'click', '#btn-add-selected-coupons', function( e ) {
					// aggregate the dialog data
					var data = dia.$el.louSerialize();

					// block the order items metabox while we do our processing
					wc_meta_boxes_order_items.block();
					dia.$el.find( '.wc-backbone-modal-main' ).block();

					// add the coupon to the order via ajax
					QS.Coupons.aj( 'add_coupons', data, function( r ) {
						// if the ajax request was successful, then replace the contents of the order items meta box with the resulting html
						if ( r && r.s && r.r ) {
							$( '#woocommerce-order-items .inside' ).empty();
							$( '#woocommerce-order-items .inside' ).append( r.r );
							wc_meta_boxes_order_items.init_tiptip();
							wc_meta_boxes_order_items.stupidtable.init();

							// clear the selection
							dia.$el.find( '.enhanced' ).select2( 'data', null );

							// close the dialog
							dia.close();
						}

						// unblcok the order items metabox
						wc_meta_boxes_order_items.unblock();
						dia.$el.find( '.wc-backbone-modal-main' ).unblock();
					}, function() { wc_meta_boxes_order_items.unblock(); } );
				} );

				// setup the search boxes
				QS.Coupons.setup_search_boxes( dia.$el );
				dia.adjust_size();
			}

			// open the add coupon dialog
			dia.open();
		} );
	}

	$( function() {
		// on load, if there is not a container for the 'used coupons', make one
		if ( 0 == $( '.wc-used-coupons' ).length ) {
			// insert the used-coupons in the right location
			var uc = $( _tmpl( 'used-coupons' ) );
			if ( uc.length )
				uc.insertBefore( $( '.wc-order-totals' ) );
		}

		// register the add coupon button
		_add_coupon_btn();
	} );
} )( jQuery, QS.Tools );
