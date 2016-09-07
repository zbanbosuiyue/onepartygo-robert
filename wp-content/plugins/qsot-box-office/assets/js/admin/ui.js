var QS = QS || {};

( function( $, qt ) {
	var S = $.extend( {}, _qsot_bo_settings );

	// handle the admin payments class
	QS.AdminPayments = ( function() {
		var defs = {
					nonce: ''
				};

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
		function _aj( sa, data, func, efunc ) {
			// setup the defaults, and compile the request
			var me = this,
					func = qt.isF( func ) ? func : function() {},
					efunc = qt.isF( efunc ) ? efunc : function( r ) { _generic_efunc( r ); },
					data = $.extend( { sa:'unknown' }, data, { action:'qsot-bo-admin', n:me.o.nonce, oid:$( '#post_ID' ).val(), sa:sa } );

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

		// get the new security code just for this order, and run the success_func on success
		function _get_new_security_code( success_func ) {
			var me = this;
			_aj.call( me, 'sec', {}, success_func );
		}

		// second part of init after we obtain a new security code for this order's ajax
		function _continue_init() {
			var me = this;
			// when the accept payment button is clicked
			me.e.mb.off( 'click.bo', '[rel="accept-payment"]' ).on( 'click.bo', '[rel="accept-payment"]', function( e ) {
				e.preventDefault();
				var btn = this;

				// if there is not already a dialog created, create one
				if ( ! qt.is( me.dia ) ) {
					// create the backbone required container, which is ironically never actually used
					me.e.dia_cont = $( '<div class="modal qsot-dialog-container"></div>' ).appendTo( 'body' );
					// start the actual dialog and fill it with a loading message
					me.e.dia_cont.empty().QSOTBackboneModal( { template_element: $( '<h2>' + me.msg( 'Loading...' ) + '</h2>' ) } );
					// store the dialog object for later reference
					me.dia = me.e.dia_cont.QSOTBackboneModal( 'get' );
					var orig_adjust = me.dia.adjust_size;
					me.dia.adjust_size = function() {
						orig_adjust.apply( this, [].slice.call( arguments ) );
						var art = me.dia.$el.find( 'article' )
						// if the content is taller than the container, then scroll to the bottom
						if ( art.outerHeight() < art[0].scrollHeight ) {
							art.each( function() {
								$( this ).animate( { scrollTop:$( this )[0].scrollHeight } );
							} );
						}
					};

					// when the payment method changes, close all the other payment method fields, and open the payment method fields for this payment method
					me.dia.$el.on( 'change.bo', '[name="payment_method"]', function( e ) {
						var meth = $( this ), par = meth.closest( 'li' );
						par.siblings( 'li' ).find( '.payment_box' ).slideUp( { duration:200, complete: function() { me.dia.adjust_size(); } } );
						par.find( '.payment_box' ).slideDown( { duration:300, complete: function() { me.dia.adjust_size(); } } );
					} );

					// when the form is submitted, do it using ajax
					me.dia.$el.on( 'submit', 'form', function( e ) {
						// remove any errors for resubmitted forms
						me.dia.$el.find( 'article .errors' ).remove();

						var data = $( this ).louSerialize();

						// cover the form to avoid resubmits
						me.dia.$el.find( 'article' ).qsBlock();

						// perform the ajax call to process the payment
						_aj.call( me, 'process-payment', data, function( r ) {
							// if the processing was successful, then redirect to the supplied url
							if ( r && r.s && r.r )
								window.location.href = r.r;
							// otherwise, print any error messages that were suplied
							else {
								if ( r && qt.isA( r.e ) && r.e.length ) {
									var err = $( '<div class="errors"></div>' ).prependTo( me.dia.$el.find( 'article' ) ), i = 0;
									for ( i = 0; i < r.e.length; i++ ) {
										$( '<div class="err">' + r.e[ i ] + '</div>' ).appendTo( err );
									}
								}

								// and unblock the box so it can be resubmitted
								me.dia.$el.find( 'article' ).qsUnblock();

								// adjust the size of the form
								me.dia.adjust_size();
							}
						} );

						return false;
					} );
				}

				// open the dialog with a loading message
				var ele = $( $.trim( $( '#qsot-bo-accept-payment' ).text() ) );
				$( '<h2>' + me.msg( 'Loading...' ) + '</h2>' ).appendTo( ele.find( 'article' ) );
				me.dia.open().set_content( { target_element:ele } );

				// ajax in the form for this order
				_aj.call( me, 'accept-payment-form', {}, function( r ) {
					// and fill teh box with the resulting ajax response 
					var ele = $( $.trim( $( '#qsot-bo-accept-payment' ).text() ) ),
							inner = $( '<div></div>' );
					$( r.r ).appendTo( inner );
					// remove already included javascripts
					inner.find( 'script' ).each( function() { var src = $( this ).attr( 'src' ); if ( src && $( 'script[src="' + src + '"]' ).length ) $( this ).remove(); } );
					// remove already included styles
					inner.find( 'link[rel="stylesheet"][href]' ).each( function() { var src = $( this ).attr( 'href' ); if ( src && $( 'body' ).find( 'link[rel="stylesheet"][href="' + src + '"]' ).length ) $( this ).remove(); } );

					// add the html to the dialog inner container
					inner.appendTo( ele.find( 'article' ) );

					// add a field to the payment form that will be sniffed on the php backend for form submission. this is mainly for stripe payment types
					$( '<input type="hidden" name="admin-payment-submit" />' ).val( me.o.nonce ).appendTo( ele.find( 'form' ) );

					// set the inner content of the modal
					me.dia.set_content( { target_element:ele } );
					ele.find( '.input-radio[name="payment_method"]:checked' ).trigger( 'change' );
					me.dia.adjust_size();
				} );

			} );
		}

		// the constructor for the ui class
		function ui( e, o ) {
			this.o = $.extend( {}, defs, o );
			this.e = { mb:e }

			this.init();
		}

		// the prototype for the ui class
		ui.prototype = {
			// setup the object created by this class
			init: function() {
				if ( this.initialized ) return;
				this.initialized = true;

				var me = this;

				// get the security code made for this order
				_get_new_security_code.call( me, function( r ) {
					// save the new security code
					me.o.nonce = r.r;
					// finish up the init
					_continue_init.call( me );
					// mark the page as having the new code
					$( 'body' ).addClass( 'qsot-bo-loaded' );
				} );
			},

			// localize the strings, based on the settings generated by WP on page load
			msg: function( str ) {
				// get every arg after the string
				var args = [].slice.call( arguments, 1 ), i;

				// look up the string in the list we have. if there is an entry, then use that entry as the string instead of the string itself. used for localization
				if ( qt.is( this.o.msgs[ str ] ) )
					str = this.o.msgs[ str ];

				// do a replacement of any args that require it, based off the list of args we extracted from the function call
				for ( i = 0; i < args.length; i++ )
					str = str.replace( /%s/, args[ i ] );

				// return the resulting string
				return str;
			}
		};

		return ui;
	} )();

	$( function() {
		QS.adminPayemnts = new QS.AdminPayments( $( '#qsot-admin-payment' ), S );

		//$( document ).on( 'change.bo', '#woocommerce-order-items input, #woocommerce-order-items select, #woocommerce-order-items textarea', function( e ) { $( 'body' ).addClass( 'qsot-bo-has-changed' ); } );
		//$( document ).on( 'change.bo', '#woocommerce-order-items input, #woocommerce-order-items select, #woocommerce-order-items textarea', function( e ) { $( 'body' ).addClass( 'qsot-bo-has-changed' ); } );
		$( '#woocommerce-order-items tr' ).on( 'click.bo', '.change-ticket', function( e ) { $( 'body' ).addClass( 'qsot-bo-has-changed' ); } );
		$( '#woocommerce-order-items .wc-order-data-row' ).on( 'click.bo', '.save-action', function( e ) { $( 'body' ).addClass( 'qsot-bo-has-changed' ); } );
		$( document ).on( 'change.bo', 'form#post input, form#post select, form#post textarea', function( e ) { $( 'body' ).addClass( 'qsot-bo-has-changed' ); } );

		$( document ).on( 'click.bo', '.pagination[ajax-links] a.page-link', function( e ) {
			e.preventDefault();

			var me = $( this ), pgn = me.closest( '.pagination[ajax-links]' ), data = ( pgn.attr( 'ajax-links' ) + '&' + me.attr( 'ajax-href' ) ).replace( /^(&+)?(.*)(&+)$/, '$2' ), insd = me.closest( '.inside' );
			data = data + '&oid=' + $( '#post_ID' ).val();
			insd.qsBlock();
			// run the ajax
			$.ajax( {
				url: ajaxurl,
				data: data,
				dataType: 'json',
				error: function() { insd.qsUnblock() },
				method: 'POST',
				success: function( r ) {
					if ( r && r.r ) {
						var tb = insd.find( 'tbody:eq(0)' );
						$( r.r ).insertBefore( tb );
						tb.remove();
					}

					if ( r && r.p ) {
						$( r.p ).insertBefore( pgn );
						pgn.remove();
					}

					insd.qsUnblock();
				},
				xhrFields: { withCredentials: true }
			} );
		} );
	} );
} )( jQuery, QS.Tools );
