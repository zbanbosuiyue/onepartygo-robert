( function( $, qt ) {
	// check dependencies, and console log any problems before bail
	if ( ! qt.isO( wp ) ) return console.log( 'wp not yet set' );
	if ( ! qt.is( wp.media ) ) return console.log( 'wp.media not yet set' );
	if ( ! qt.is( wp.media.View ) ) return console.log( 'wp.media.View not yet set' );
	if ( ! qt.is( wp.media.controller ) ) return console.log( 'wp.media.controller not yet set' );
	if ( ! qt.is( wp.media.controller.Library ) ) return console.log( 'wp.media.controller.Library not yet set' );

	var media = wp.media, Library = media.controller.Library, View = media.View, oldMediaFrame = wp.media.view.MediaFrame.Post;

	QS.ShortcodeGen = {
		// controller for the frame
		controller: wp.media.controller.QSOTShortcode = wp.media.controller.State.extend( {
			initialize: function() {
				// why is this needed? im confused
				this.set( 'selection', '' );
				// this model contains all the relevant data needed for the application
				this.props = new Backbone.Model( {
					qsots_data: ''
				} );
				this.props.on( 'change:qsots_data', this.refresh, this );
			},
			// called when modal changes
			refresh: function() {
				// update the toolbar
				this.frame.toolbar.get().refresh();
			},
			// button is clicked
			customAction: function(){
				var val = this.frame.views.view.qsotsView.getGeneratedTag();
				wp.media.editor.insert( val );
			}
		} ),

		// toolbar to contain the buttons for our modal
		toolbar: wp.media.view.Toolbar.QSOTShortcode = wp.media.view.Toolbar.extend( {
			initialize: function() {
				_.defaults( this.options, {
					event: 'qsots_event',
					close: true,
					items: {
						qsots_event: {
							text: wp.media.view.l10n.qsotsButton, // added via 'media_view_strings' filter,
							style: 'primary',
							priority: 80,
							requires: false,
							click: this.customAction
						}
					}
				});

				wp.media.view.Toolbar.prototype.initialize.apply( this, arguments );
			},

			// called each time the model changes
			refresh: function() {
				// you can modify the toolbar behaviour in response to user actions here
				// disable the button if there is no custom data
				//this.get( 'qsots_event' ).model.set( 'disabled', true );

				// call the parent refresh
				wp.media.view.Toolbar.prototype.refresh.apply( this, arguments );
			},

			// triggered when the button is clicked
			customAction: function(){
				this.controller.state().customAction();
			}
		}),
 
		// panel UI
		view: wp.media.view.QSOTShortcode = wp.media.View.extend( {
			className: 'media-qsot-shortcode',

			selectionContent: '',
			selectionData: {},

			template:  wp.template( 'qsot-shortcode-generator' ),
			
			// bind view events
			events: {
				'input': 'qsots_update',
				'keyup': 'qsots_update',
				'change': 'qsots_update'
			},
		 
			// initialize the form once
			initialize: function() {
				var options = _.defaults( this.model.toJSON(), {}, this.options ), me = this;

				this.$el.html( this.template( options ) );

				this.$el.find( '.use-sortable' ).not( '.ui-sortable' ).sortable();

				this.$el.off( 'change', '[name="shortcode"]' ).on( 'change', '[name="shortcode"]', function( e ) {
					me.$el.find( '.panel' ).not( '#panel-' + $( this ).val() ).removeClass( 'active' );
					me.$el.find( '#panel-' + $( this ).val() ).addClass( 'active' );
				} );

				// add the events to the find-posts-btn elements
				QS.DOpts.FindPostsBtns( this.$el, {
					// when clicking the select posts button
					select: function( selected, data, par, btn ) {
						console.log( 'selected', selected);
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
				QS.DatepickerI18n( this.$el );
			},
			
			// render the form when the appropriate mediabox tab is selected
			render: function() {
				this.$el.find( '[name="shortcode"]' ).trigger( 'change' );
				return this;
			},

			// update the content from the selection in the editor
			setSelectionContent: function( content ) {
				this.selectionContent = content;
				this._parse_content();
				this._update_fields();
			},

			// get the tag that the form settings will produce
			getGeneratedTag: function() {
				// get all the data from the form, and find the tag name to use
				var data = this.$el.find( '.widget-settings .panel.active' ).louSerialize(),
						tagname = this.$el.find( '[name="shortcode"]' ).val(),
						atts = [];

				// start aggregating a list of attributes
				this.$el.find( '.widget-settings .panel.active .field-val' ).each( function() {
					var me = $( this );
							k = me.attr( 'name' ) || me.data( 'field' ),
							def = me.data( 'default' ),
							is_dp = me.hasClass( 'has-datepicker' );

					// if the attribute value is not set, or it is equal to the default, then skip the attribute
					if ( ! qt.is( data[ k ] ) || data[ k ] == def )
						return;

					// otherwise construct the attr
					atts.push( k + '="' + ( '' + data[ k ] ).replace( /"/g, '&quot;' ) + '"' );
				} );

				return '[' + tagname + ( atts.length ? ' ' + atts.join( ' ' ) : '' ) + ']' + "\n";
			},

			// parse out the shortcode if it exists
			_parse_content: function() {
				// reset the data about the shortcode
				this.selectionData = {};

				var me = this,
						main_regex = /\[([^\s]+)(.*)\](?=([^"]*"[^"]*")*[^"]*$)/i, // regex to find the tag
						attr_regex = /\s+([^\s]+)="([^"]*?)"/gi, // regex to find the attributes
						match = this.selectionContent.match( main_regex ), // match the whole tag
						i;

				// if we found a tag, then
				if ( qt.isA( match ) ) {
					// set the tag name
					this.selectionData._tag = match[1];

					// find all the attributes
					while ( attr = attr_regex.exec( match[2] ) ) {
						this.selectionData[ attr[1] ] = attr[2];
					}
				}
			},

			// update the form with the information from the selection, if any. otherwise return form to defaults
			_update_fields: function() {
				var me = this;
				// activate the appropriate panel
				var sc = this.$el.find( '[name="shortcode"]' );
				if ( qt.is( this.selectionData._tag ) && this.selectionData._tag )
					sc.val( this.selectionData._tag ).trigger( 'change' );
				else {
					sc.find( 'option:eq(0)' ).prop( 'selected' );
					sc.find( 'option' ).not( ':eq(0)' ).removeProp( 'selected' );
				}

				// cycle through the fields, and update the value to either what was in the selection, or to the default value for the field
				this.$el.find( '.panel.active .field-val' ).each( function() {
					var el = $( this ), id = el.attr( 'id' ), type = el.attr( 'type' ), eltag = el[0].tagName.toLowerCase(), is_list = el.hasClass( 'posts-list' ), def = el.data( 'default' );
					
					// depending on the type of field, do somethign different
					if ( 'input' == eltag ) {
						// for inputs, there are sub types that have their own logic needs
						switch ( type ) {
							// radios and checkboxes are on or off
							case 'checkbox':
							case 'radio':
								var val = qt.is( me.selectionData[ id ] ) ? !!me.selectionData[ id ] : def;
								el[ val ? 'prop' : 'removeProp' ]( 'checked' );
							break;
							
							// everything else has a value associated
							default:
								el.val( qt.is( me.selectionData[ id ] ) ? me.selectionData[ id ] : def )
							break;
						}
					// selects have to select an option within
					} else if ( 'select' == eltag ) {
						el.val( qt.is( me.selectionData[ id ] ) ? me.selectionData[ id ] : def );
					// some other containers can be the field
					} else {
						// if this is a a list container
						if ( is_list ) {
							// empty the list and determine the current value
							el.empty();
							var val = qt.is( me.selectionData[ id ] ) ? me.selectionData[ id ] : def;

							// if there is a value to set, then run some ajax to fetch the info
							if ( '' != val ) {
								el.qsBlock();

								// aggregate the ajax data
								var data = { sa: 'load_posts', name: 'id', id: val };

								// run the ajax
								QS.DOpts.aj( data.sa, data, function( r ) {
									if ( r.s && r.r ) {
										$( r.r ).appendTo( el );
									}
									el.qsUnblock();
								}, function() { el.qsUnblock() } );
							}
						}
					}
				} );
			},
			
			qsots_update: function( event ) {
				this.model.set( 'qsots_data', event.target.value );
			}
		} ),
 
		// supersede the default MediaFrame.Post view
		media_frame: wp.media.view.MediaFrame.Post = oldMediaFrame.extend( {
			initialize: function() {
				oldMediaFrame.prototype.initialize.apply( this, arguments );

				this.states.add( [
					new wp.media.controller.QSOTShortcode( {
						id: 'qsot-shortcode-action',
						menu: 'default', // menu event = menu:render:default
						content: 'qsot-shortcode-generator',
						title: wp.media.view.l10n.qsotsMenuTitle, // added via 'media_view_strings' filter
						priority: 200,
						toolbar: 'main-qsots-shortcode-action', // toolbar event = toolbar:create:main-my-action
						type: 'link'
					} )
				] );

				this.on( 'content:render:qsot-shortcode-generator', this.qsotsContent, this );
				this.on( 'toolbar:create:main-qsots-shortcode-action', this.createQSOTSToolbar, this );
				this.on( 'toolbar:render:main-qsots-shortcode-action', this.renderQSOTSToolbar, this );
			},

			createQSOTSToolbar: function(toolbar) {
				console.log('toolbar.create');
				toolbar.view = new wp.media.view.Toolbar.QSOTShortcode( {
					controller: this
				} );
			},

			open: function() {
				oldMediaFrame.prototype.open.apply( this, [].slice.call( arguments ) );
				if ( qt.is( this.qsotsView ) )
					this.qsotsView.setSelectionContent( tinyMCE.activeEditor.selection.getContent( { format:'text' } ) );
			},

			qsotsContent: function() {
				// this view has no router
				this.$el.addClass( 'hide-router' );

				// custom content view
				this.qsotsView = new wp.media.view.QSOTShortcode( {
					controller: this,
					model: this.state().props
				} );
				this.qsotsView.setSelectionContent( tinyMCE.activeEditor.selection.getContent( { format:'text' } ) );

				this.content.set( this.qsotsView );
			}
		})
	};
} )( jQuery, QS.Tools );
