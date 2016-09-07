var QS = QS || {},
		_qsot_gamp_tickets = _qsot_gamp_tickets || { ajaxurl:'/wp-admin/admin-ajax.php' };

QS.EATicketSelection = (function($, q, qt) {
	var S = $.extend({}, _qsot_gamp_tickets),
			defs = {};
			console.log( 'S = ', S );

	function aj(sa, data, func, efunc) {
		var data = $.extend({}, data, { action:'qsot-frontend-ajax', sa:sa, _n:S.nonce, event_id:S.edata.id }),
				func = func || function(){},
				efunc = efunc || function(){};

		$.ajax({
			// patch for 'force ssl admin' issues
			xhrFields: { withCredentials: true },
			url: S.ajaxurl,
			data: data,
			type: 'POST',
			dataType: 'json',
			success: function(r) {
				if (qt.isO(r)) {
					if (typeof r.e != 'undefined') console.log('ajax error: ', r.e);

					// update the nonce if it was sent
					if ( qt.is( r.n ) )
						S.nonce = r.n;

					func(r);
				} else { efunc(); }
			},
			error: efunc
		});
	}
	
	function ui(e, o) {
		var t = this;

		function _init() {
			t.initialized = t.initialized || false;
			if (t.initialized) return;
			t.initialized = true;
			if (_setup_elements()) {
				_setup_events();
				_load_ui();
			}
		}

		function compound_price_name( p ) {
			return p.product_name; // + ' (' + p.product_raw_price + ')';
		}

		function populate_price_info( d, p ) {
			d.find( '[rel="ttname"]' ).html( p.product_name );
			//d.find( '[rel="ttprice"]' ).html( p.product_raw_price );
			d.find( '[rel="ticket-type"]' ).val( p.product_id );
		}

		function _setup_elements() {
			t.e = { m:$(e) };
			t.e.msgs = $(S.templates['msgs']).appendTo(t.e.m).hide();
			t.e.ts = $(S.templates['ticket-selection']).appendTo(t.e.m).hide();

			t.e.tt_edit = { multi:'', single:'' };
			t.e.tt_display = {};
			t.e.tts = $( '<span class="list-wrapper">( </span>' );
			t.e.o = {};
			if (qt.isO(S.edata) && qt.isO(S.edata.struct) && qt.isA(S.edata.struct.prices) && S.edata.struct.prices.length && qt.is(S.edata.available)) {
				t.e.tt_edit.multi = $( S.templates['ticket-type-multi-select'] );
				for ( var i = 0; i < S.edata.struct.prices.length; i++ ) {
					var p = S.edata.struct.prices[i], pid = p.product_id;
					$( S.templates['ticket-type-multi-option'] ).val( p.product_id ).html( compound_price_name( p ) ).appendTo( t.e.tt_edit.multi );

					if ( t.e.tt_edit.single == '' )
						populate_price_info( t.e.tt_edit.single = $( S.templates['ticket-type-single'] ), p );
					populate_price_info( t.e.tt_display[ p.product_id + '' ] = $( S.templates['ticket-type-display'] ), p );

					if ( i > 0 ) t.e.tts = t.e.tts.add( $( '<span class="list-divider">, </span>' ) );
					t.e.tts = t.e.tts.add( t.e.tt_display[ p.product_id + '' ] );

					t.e.o[ p.product_id + '' ] = $( S.templates['owns'] );
					t.e.o[ p.product_id + '' ].find( '[rel="tt_display"]' ).append( t.e.tt_display[ p.product_id + '' ] );
					populate_price_info( t.e.o[ p.product_id + '' ], p );
				}
			} else {
				_show_msg('not-available');
				return false;
			}
			t.e.tts = t.e.tts.add( '<span class="list-wrapper">  )</span>' );

			var total_owned = _total_owns(), m;

			if ( S.edata.available <= 0 && 0 == total_owned ) {
				_show_msg('sold-out');
				return false;
			}

			var msg = _get_msg('available');
			t.e.m.find('.availability-message').html(_replacements(msg.msg));
			var msg = _get_msg('more-available');
			t.e.m.find('.availability-more-message').html(_replacements(msg.msg));
			t.e.m.find('[rel="tt"]').empty().each(function() {
				t.e.tts.clone().appendTo(this);
			});
			_update_availables({ available:S.edata.available, owns:S.owns, available_more:qt.toInt(S.edata.available) - qt.toInt(S.owns) });

			/*
			t.eall = t.e.ts.add(t.e.o).add(t.e.msgs).add(t.e.tt_edit.multi).add(t.e.tt_edit.single);
			for ( i in t.e.tt_display ) if ( t.e.tt_display.hasOwnProperty( i ) ) t.eall = t.eall.add( t.e.tt_display[i] );
			for ( i in t.e.o ) if ( t.e.o.hasOwnProperty( i ) ) t.eall = t.eall.add( t.e.o[i] );
			*/

			return true;
		}

		function _setup_events() {
			t.e.m.on('click', '[rel="reserve-btn"]', _ticket_reservations);
			t.e.m.on('click', '[rel="update-btn"]', _update_reservations);
			t.e.m.on('click', '[rel="remove-btn"]', _remove_reservations);
		}

		function _replacements(msg) {
			var msg = $('<span>'+msg+'</span>'), avail = qt.isO(S.edata) && qt.is(S.edata.available) ? S.edata.available : 0;
			msg.find('[rel="tt"]').empty().each(function() {
				t.e.tts.clone().appendTo(this);
			});
			msg.find('.available').html(S.edata.available);
			return msg;
		}

		function _clear_msgs() { t.e.msgs.hide().empty(); }
		function _get_msg(msg_name) { return qt.isO(S.messages[msg_name]) ? S.messages[msg_name] : { msg:'An error has occurred.', type:'error' }; }
		function _show_msg(msg_name, cmsg, ctype) {
			var msg = _get_msg(msg_name), tmpl = $(S.templates[msg.type]);
			_clear_msgs();
			if (msg_name == '_custom' && qt.isA(cmsg) && cmsg.length) {
				var ctype = ctype || 'error';
				tmpl = qt.is(S.templates[ctype]) ? $(S.templates[ctype]) : $(S.templates['error']);
				for (var i=0; i<cmsg.length; i++)
					tmpl.clone().html(_replacements(cmsg[i])).appendTo(t.e.msgs);
			} else {
				tmpl.html(_replacements(msg.msg)).appendTo(t.e.msgs);
			}
			t.e.msgs.show();
		}

		function _total_owns() {
			var tot = 0;
			if ( qt.isO( S.owns ) ) for ( i in S.owns ) if ( S.owns.hasOwnProperty( i ) ) tot += qt.toInt( S.owns[i] );
			//console.log( 'total owns', S.owns, tot );
			return tot;
		}

		function _setup_form_title() {
			var tot = _total_owns(), pre = tot > 0 ? 'two' : 'one', mid = S.edata.struct.prices.length == 1 ? 'single' : 'multi';
			$( S.templates[ pre + '-' + mid + '-title' ] ).appendTo( t.e.ts.find( '[rel="title"]' ).empty() );
		}

		function _show_owns() {
			var tot = _total_owns();

			var ohold = t.e.ts.find( '[rel="owns"]' ).empty();

			if ( tot > 0 ) {
				var ocont = $( S.templates['owns-wrap'] ).appendTo( ohold ).find( '[rel="owns-list"]' );

				if ( qt.isO( S.owns ) ) for ( i in S.owns ) if ( S.owns.hasOwnProperty( i ) ) {
					if ( qt.is( t.e.o[i] ) ) {
						var f = t.e.o[i].clone().appendTo( ocont ).find( '[rel="qty"]' );
						if ( f.is( ':input' ) )
							f.val( S.owns[i] + '' );
						else
							f.text( S.owns[i] + '' );
					}
				}
			}
		}

		function _show_tt_edits() {
			if ( S.edata.struct.prices.length == 1 ) {
				t.e.tt_edit.single.clone().appendTo( t.e.ts.find( '[rel="tt_edit"]' ).empty() )
			} else if ( S.edata.struct.prices.length > 1 ) {
				t.e.tt_edit.multi.clone().appendTo( t.e.ts.find( '[rel="tt_edit"]' ).empty() )
			}
		}

		function _only_relevant_buttons() {
			var tot = _total_owns();

			if ( tot > 0 ) {
				t.e.ts.find( '[rel="actions"]' ).show();
			} else {
				t.e.ts.find( '[rel="actions"]' ).hide();
			}
		}

		function _load_ui() {
			_setup_form_title();
			_show_owns();
			_show_tt_edits();
			_only_relevant_buttons();
			_display_form( 'ts' );
		}

		function _display_form(which) {
			//t.eall.hide();
			t.e[which].fadeIn(300);
		}

		function _update_availables(data) {
			//t.e.m.find('.availability-message .available').html(qt.is(data.available) ? data.available : '0');
			t.e.m.find('.availability-message .available').html(qt.is(data.available_more) ? data.available_more : '0');
		}

		function _is_valid_response(r, form) {
			if (!qt.isO(r)) {
				_show_msg('unexpected');
				form.qsUnblock();
				return false;
			}

			if (!r.s) {
				if (qt.isA(r.e) && r.e.length) _show_msg('_custom', r.e, 'error');
				else _show_msg('unsuccessful');
				form.qsUnblock();
				return false;
			}

			if (!qt.isO(r.data)) {
				_show_msg('invalid-response');
				form.qsUnblock();
				return false;
			}

			S.owns = r.data.owns;
			_show_owns();

			return true;
		}

		function _ticket_reservations(e) {
			e.preventDefault();
			_clear_msgs();

			var waiting = _get_msg('one-moment');
			t.e.m.qsBlock({ msg:_replacements(waiting.msg) });

			var data = t.e.ts.louSerialize();

			aj( 'gamp-reserve', data, function( r ) {
				if ( ! _is_valid_response( r, t.e.m ) ) return;
				_setup_form_title();
				_only_relevant_buttons();
				_update_availables(r.data);
				if (qt.isA(r.m) && r.m.length) _show_msg('_custom', r.m, 'msg');
				t.e.ts.qsUnblock();
			}, function() { _show_msg('unexpected'); t.e.ts.qsUnblock(); });
		}

		function _update_reservations(e) {
			e.preventDefault();
			_clear_msgs();

			var waiting = _get_msg('one-moment');
			t.e.m.qsBlock({ msg:_replacements(waiting.msg) });

			var data = t.e.ts.find( '[rel="owns-list"]' ).louSerialize();

			aj('gamp-update', data, function(r) {
				if ( ! _is_valid_response( r, t.e.m ) ) return;
				_setup_form_title();
				_only_relevant_buttons();
				_update_availables(r.data);
				if (qt.isA(r.m) && r.m.length) _show_msg('_custom', r.m, 'msg');
				t.e.ts.qsUnblock();
			}, function() { _show_msg('unexpected'); t.e.ts.qsUnblock(); });
		}

		function _remove_reservations(e) {
			e.preventDefault();
			_clear_msgs();

			var waiting = _get_msg('one-moment');
			t.e.m.qsBlock({ msg:_replacements(waiting.msg) });

			var data = { 'ticket-type': $( this ).closest( '[rel="own-item"]' ).find( '[rel="ticket-type"]').val() };

			aj('gamp-remove', data, function(r) {
				if ( ! _is_valid_response( r, t.e.m ) ) return;
				_setup_form_title();
				_only_relevant_buttons();
				_update_availables(r.data);
				if (qt.isA(r.m) && r.m.length) _show_msg('_custom', r.m, 'msg');
				t.e.ts.qsUnblock();
			}, function() { _show_msg('unexpected'); t.e.ts.qsUnblock(); });
		}

		_init();
	}

	return ui;
})(jQuery, QS, QS.Tools);

jQuery(function() {
	var ui = new QS.EATicketSelection('[rel="ticket-selection"]', {});
});
