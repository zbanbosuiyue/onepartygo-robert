var QS = QS || {};

( function( $, qt ) {
	var S = $.extend( { nonce:'', msgs:{} }, _qsot_coupons_tools_settings );

	// holder for Coupon related functions
	QS.Coupons = {};

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
	QS.Coupons.aj = function( sa, data, func, efunc ) {
		// setup the defaults, and compile the request
		var func = qt.isF( func ) ? func : function() {},
				efunc = qt.isF( efunc ) ? efunc : function( r ) { _generic_efunc( r ); },
				data = $.extend( { sa:'unknown', n:S.nonce, action:'qsot-coupons' }, data, { pid:$( '#post_ID' ).val(), sa:sa } );

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
	QS.Coupons.frmtStr = {
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

	QS.Coupons.setup_search_boxes = function( context ) {
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
						return {
							term: term,
							action: $( this ).data( 'action' ) || 'qsot-coupons',
							sa: $( this ).data( 'sa' ) || 'search_events',
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
			select2_args = $.extend( select2_args, QS.Coupons.frmtStr );

			// initialize the select2 lib, and mark the element as having been initialized
			$( this ).select2( select2_args ).addClass( 'enhanced' );
		});
	}

	// add range datepickers where needed
	QS.addDateRangePickers = function( selector, scope ) {
		var scope = $( scope || 'body' ), elements = $( selector || '.use-datepicker-range', scope );

		// only assign a new datepicker to elements that have not already been assigned one
		elements.filter( ':not(.has-datepicker)' ).each( function() {
			var me = $( this ), display_frmt = me.data( 'frmt' ) || 'yy-mm-dd', frmt_pattern = me.data( 'frmt-pattern' ), frmt_placeholder = me.data( 'frmt-placeholder' ), linked = $( me.attr( 'linked' ) ), cur = me.val(),
					// create the real date container after the display version in the dom
					real = $( '<input type="hidden"/>' ).attr( 'name', me.attr( 'name' ) ).val( me.val() ).insertAfter( me );

			// change the name of the display version, so that it does not conflict with the real version, update the placeholder and patterns, and mark it as having a datepicker
			me.attr( { name:'display-' + me.attr( 'name' ), pattern:frmt_pattern, placeholder:frmt_placeholder } ).addClass( 'has-datepicker' );

			me
				// setup the datepicker
				.datepicker( {
					altField: real,
					altFormat: 'yy-mm-dd',
					dateFormat: display_frmt
				} )
				// when the date changes, possibly update the linked element
				.on( 'change.qsot', function( e ) {
					var me_val = me.datepicker( 'getDate' ), linked_val = linked.datepicker( 'getDate' );
					// if the date of the linked element or the me element is not set, then skip this update
					if ( null == linked_val || null == me_val )
						return;

					// if me is the from date
					if ( me.hasClass( 'from' ) ) {
						// if the new me date is after the linked date, then update the linked date to the me date. prevents an end date that is before a start date
						if ( linked_val - me_val < 0 ) {
							linked.datepicker( 'setDate', me_val );
						}
					// otherwise me has to be the to date
					} else {
						// if the new me date is before the linked date, then update the linked date to the me date. prevents an end date that is before a start date
						if ( me_val - linked_val < 0 ) {
							linked.datepicker( 'setDate', me_val );
						}
					}
				} );

			// if there was a current date, then use it
			if ( cur.length )
				me.datepicker( 'setDate', new Date( cur ) );
		} );
	}

} )( jQuery, QS.Tools );

/*global jQuery, Backbone, _ */
( function( $, Backbone, _, qt ) {
	'use strict';

	if ( qt.isF( $.fn.QSOTBackboneModal ) )
		return;

	/**
	 * QSOT Backbone Modal plugin
	 *
	 * @param {object} options
	 */
	$.fn.QSOTBackboneModal = function( options ) {
		if ( 'get' == options )
			return this.data( 'qsot-dialog' );
		return this.each( function() {
			( new $.QSOTBackboneModal( $( this ), options ) );
		});
	};

	/**
	 * Initialize the Backbone Modal
	 *
	 * @param {object} element [description]
	 * @param {object} options [description]
	 */
	$.QSOTBackboneModal = function( element, options ) {
		// Set settings
		var settings = $.extend( {}, $.QSOTBackboneModal.defaultOptions, options );

		if ( settings.template || settings.template_element ) {
			element.data( 'qsot-dialog', 
				new $.QSOTBackboneModal.View({
					target: settings.template,
					target_element: $( settings.template_element )
				})
			);
		}
	};

	/**
	 * Set default options
	 *
	 * @type {object}
	 */
	$.QSOTBackboneModal.defaultOptions = {
		template: '',
		template_element: $()
	};

	/**
	 * Create the Backbone Modal
	 *
	 * @return {null}
	 */
	$.QSOTBackboneModal.View = Backbone.View.extend({
		tagName: 'div',
		id: 'wc-backbone-modal-dialog',
		_target: undefined,
		events: {
			'click .modal-close': 'closeButton',
			'keydown':            'keyboardActions'
		},

		initialize: function( data ) {
			this._target = data.target;
			this._target_element = data.target_element;
			_.bindAll( this, 'render' );
			this.render();
			this.open();
		},

		render: function() {
			this.$el.hide().attr( 'tabindex' , '0' ).append( this._target_element.length > 0 ? $( this._target_element ) : $( this._target ).html() );

			$( 'body' ).css({
				'overflow': 'hidden'
			}).append( this.$el );

			this.adjust_size();

			$( 'body' ).trigger( 'qsot_backbone_modal_loaded', this._target );

			return this;
		},

		adjust_size: function() {
			var $content  = $( '.wc-backbone-modal-content' ).find( 'article' ),
					cur_h = ( 0 === $content.height() ) ? 90 : $content.height();
			$content.css( { height:'auto' } );
			var content_h = ( 0 === $content.height() ) ? 90 : $content.height(),
					max_h     = $( window ).height() - 250;
			$content.css( { height:cur_h } );

			if ( content_h > max_h ) {
				$content.css({
					'overflow': 'auto',
					height: max_h + 'px'
				});
			} else {
				$content.css({
					'overflow': 'visible',
					height: content_h
				});
			}

			$( '.wc-backbone-modal-content' ).css({
				'margin-top': '-' + ( $( '.wc-backbone-modal-content' ).height() / 2 ) + 'px'
			});

			return this;
		},

		set_content: function( settings ) {
			this._target = settings.target;
			this._target_element = settings.target_element;

			this.$el.empty().attr( 'tabindex' , '0' ).append( this._target_element.length > 0 ? $( this._target_element ) : $( this._target ).html() );
			this.adjust_size();

			return this;
		},

		open: function( e ) {
			this.$el.show();

			return this;
		},

		close: function( e ) {
			// fix scroll... sigh
			this.undelegateEvents();
			$( document ).off( 'focusin' );
			$( 'body' ).css({ 'overflow': 'auto' });

			this.$el.hide();

			return this;
		},

		closeButton: function( e ) {
			e.preventDefault();
			this.close();
		},

		addButton: function( e ) {
			$( 'body' ).trigger( 'qsot_backbone_modal_response', [ this._target, this.getFormData() ] );
			this.closeButton( e );
		},

		getFormData: function() {
			var data = {};

			$.each( $( 'form', this.$el ).serializeArray(), function( index, item ) {
				if ( data.hasOwnProperty( item.name ) ) {
					data[ item.name ] = $.makeArray( data[ item.name ] );
					data[ item.name ].push( item.value );
				}
				else {
					data[ item.name ] = item.value;
				}
			});

			return data;
		},

		keyboardActions: function( e ) {
			var button = e.keyCode || e.which;

			// ESC key
			if ( 27 === button ) {
				this.closeButton( e );
			}
		}
	});

}( jQuery, Backbone, _, QS.Tools ));
