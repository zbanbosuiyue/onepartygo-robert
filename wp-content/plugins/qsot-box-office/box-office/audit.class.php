<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the audit class for the box-office plugin
// the audit class keeps a detailed log of actions that have occurred on an order, from order creation to present
class qsot_bo_audit {
	// cached list of message types indexed by slug
	protected static $msg_map = array();
	// id to slug for above
	protected static $r_msg_map = array();

	// store existing order item data, for use later in audit items
	protected static $existing_oi_data = array();
	// store existing order data, for use later in audit items
	protected static $existing_o_data = array();

	// list of meta keys to ignore for an order, since they will always have changed, or ar irrelevant
	protected static $ignore = array(
		'_edit_lock' => 1,
		'_generic_errors' => 1,
		'_edit_last' => 1,
		'_order_version' => 1,
	);


	// setup the class's functionality
	public static function pre_init() {
		// allow other plugins to register their own messages
		add_action( 'plugins_loaded', array( __CLASS__, 'load_existing_messages' ), -1 );
		add_action( 'plugins_loaded', array( __CLASS__, 'register_default_messages' ), -1 );

		// filter to generate a displayable message, based on audit trail entry variables
		add_filter( 'qsot-bo-compile-msg', array( __CLASS__, 'compile_message' ), 10, 2 );
		add_filter( 'qsot-bo-audit-type-from-id', array( __CLASS__, 'type_from_id' ), 10, 2 );
		
		// intercept order save requests, and pick out information for later use in audit items
		add_action( 'save_post', array( __CLASS__, 'extract_save_order_data' ), -1, 2 );
		add_action( 'wp_ajax_woocommerce_save_order_items', array( __CLASS__, 'ajax_extract_save_order_data' ), -1, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'record_payment_user' ), PHP_INT_MAX, 2 );

		// our default audit item triggers
		add_action( 'qsot-admin-load-assets-shop_order', array( __CLASS__, 'at_viewed_order_admin' ), 10, 2 );
		add_action( 'woocommerce_order_add_product', array( __CLASS__, 'at_woocommerce_order_add_product' ), 10, 5 );
		add_action( 'woocommerce_saved_order_items', array( __CLASS__, 'at_woocommerce_saved_order_items' ), PHP_INT_MAX, 2 );
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'at_frontend_order_creation' ), PHP_INT_MAX, 2 );
		add_action( 'save_post', array( __CLASS__, 'at_update_post' ), PHP_INT_MAX, 3 );
		add_action( 'save_post', array( __CLASS__, 'at_new_post' ), 100000000, 3 );
		add_action( 'qsot-reserve-admin-order-item', array( __CLASS__, 'at_new_ticket' ), 10, 6 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'at_payment_complete' ), 10, 1 );

		// add the metabox to display the audit trail on a given order
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 1000 );

		// handle metabox pagination
		add_action( 'wp_ajax_qsot-bo-aupg', array( __CLASS__, 'pagination_ajax' ), 10 );

		// setup the db tables we will use
		self::setup_table_names();
		add_action( 'switch_blog', array( __CLASS__, 'setup_table_names' ), PHP_INT_MAX, 2 );
		add_filter( 'qsot-upgrader-table-descriptions', array( __CLASS__, 'setup_tables' ), 10 );
	}

	// load the existing message types in the db, and register an action to allow plugins to register new ones
	public static function load_existing_messages() {
		global $wpdb;

		// add action to allow registering new types
		add_action( 'qsot-bo-register-message', array( __CLASS__, 'register_message' ), 10, 1 );

		// allow plugins to trigger saving of an audit trail item
		add_action( 'qsot-bo-add-audit-item', array( __CLASS__, 'add_audit_item' ), 10, 1 );

		// load the existing types
		$list = $wpdb->get_results( 'select * from ' . $wpdb->qsot_audit_msgs );
		while ( $item = array_pop( $list ) ) {
			// normalize the item information
			$item->def_params = @json_decode( $item->def_params );
			$item->def_params = empty( $item->def_params ) || ! is_array( $item->def_params ) ? array() : $item->def_params;

			// store the item in our cache list
			self::$msg_map[ $item->slug ] = $item;
			self::$r_msg_map[ $item->id . '' ] = $item->slug;
		}
	}

	// actually register a message for later use
	public static function register_message( $args ) {
		// normalize the args
		$args = wp_parse_args( $args, array(
			'slug' => '',
			'msg_format' => '',
			'param_order' => array(),
		) );

		// fail to register if the required information is not present
		if ( empty( $args['slug'] ) || empty( $args['msg_format'] ) )
			return false;

		global $wpdb;

		// if the message is already loaded, then it already exists. the only question left is 'has it changed?'
		if ( isset( self::$msg_map[ $args['slug'] ] ) ) {
			$changes = array();

			// if the default base message has changed, then mark it to be updated
			if ( self::$msg_map[ $args['slug'] ]->def_frmt != $args['msg_format'] )
				$changes['def_frmt'] = $args['msg_format'];

			// if any of the default params changed, then mark the param list to be updated
			if ( self::$msg_map[ $args['slug'] ]->def_params != $args['param_order'] )
				$changes['def_params'] = @json_encode( $args['param_order'] );

			// if any of the information has changed, then we need to update our message record
			if ( ! empty( $changes ) ) {
				$wpdb->update(
					$wpdb->qsot_audit_msgs,
					$changes,
					array( 'id' => self::$msg_map[ $args['slug'] ]->id )
				);
			}
		// if the message is not yet loaded, then it must be a new message. we need to create it and add it to our list
		} else {
			// compile the information for the new message
			$data = array(
				'slug' => $args['slug'],
				'def_frmt' => $args['msg_format'],
				'def_params' => @json_encode( $args['param_order'] ),
			);

			// insert the new message
			$wpdb->insert(
				$wpdb->qsot_audit_msgs,
				$data
			);

			// fetch the new message id
			$data['id'] = $wpdb->insert_id;

			// add our message to the list
			self::$msg_map[ $data['slug'] ] = (object)$data;
			self::$msg_map[ $data['id'] ] = $data['slug'];
		}

		// add any special interpretation funciton that is sent
		if ( isset( $args['display_func'] ) && is_callable( $args['display_func'] ) )
			self::$msg_map[ $args['slug'] ]->display_func = $args['display_func'];
	}

	// compile a displayable message, based on message type and supplied data
	public static function compile_message( $current, $args ) {
		// normalize the args
		$args = wp_parse_args( $args, array(
			'msg' => '',
			'msg_id' => 0,
			'order_id' => 0,
			'data' => array(),
		) );
		$args['msg_id'] = absint( $args['msg_id'] );
		$args['data'] = (array)$args['data'];

		// if the msg was not supplied, but the msg_id was, then try to lookup the msg slug from the id
		if ( 0 == strlen( $args['msg'] ) && $args['msg_id'] > 0 && isset( self::$r_msg_map[ $args['msg_id'] . '' ] ) )
			$args['msg'] = self::$r_msg_map[ $args['msg_id'] . '' ];

		// if the message type is empty, or is not registered, then do nothing
		if ( 0 == strlen( $args['msg'] ) || ! isset( self::$msg_map[ $args['msg'] ] ) )
			return $current;

		$msg = self::$msg_map[ $args['msg'] ];
		$output = '';

		// if there is a special display function for this message, then use that instead of the standard vsprintf
		if ( isset( $msg->display_func ) && is_callable( $msg->display_func ) ) {
			$func = $msg->display_func;
			$output = $func( $msg, $args );
		}

		// if there was a result from a custom display func, then return that
		if ( strlen( $output ) )
			return $output;

		// based on the default param order for the message, construct an array with the required data
		$params = array();
		foreach ( $msg->def_params as $param ) {
			$func_name = false;
			// if the setting says that we need to pass the value through a function, then 
			if ( ':(' == substr( $param, 0, 2 ) && ( $end = strpos( $param, ')' ) ) ) {
				$func_name = substr( $param, 2, $end - 2 );
				$func_name = false !== strpos( $func_name, '::' ) ? explode( '::', $func_name ) : $func_name;
				$param = substr( $param, $end + 1 );
			}
			
			$prm = isset( $args['data'][ $param ] ) && is_scalar( $args['data'][ $param ] ) ? (string)$args['data'][ $param ] : '';

			// if the value needs to be passed through a function, then do so now
			if ( is_callable( $func_name ) ) {
				$prm = '*' != $param ? $func_name( $prm ) : $func_name( $args );
			}

			$params[] = '<strong>' . $prm . '</strong>';
		}

		// fill out the message based on the string format and the params we found
		return vsprintf( $msg->def_frmt, $params );
	}

	// fetch the slug of the message type, based on the message type id
	public static function type_from_id( $current, $id ) {
		return isset( self::$r_msg_map[ $id . '' ], self::$msg_map[ self::$r_msg_map[ $id . '' ] ] ) ? self::$r_msg_map[ $id . '' ] : $current;
	}

	// add the relevant meteboxes
	public static function add_meta_boxes() {
		$screens = array( 'shop_order' );
		foreach ( $screens as $screen ) {
			// create the audit trail display metabox
			add_meta_box(
				'qsot-audit-trail',
				__( 'Audit Trail', 'qsot-box-office' ),
				array( __CLASS__, 'mb_audit_trail' ),
				$screen,
				'advanced',
				'high'
			);
		}
	}

	// render the actual audit trail metabox
	public static function mb_audit_trail( $order_id ) {
		// allow a url param to specify the current page to display and how many to display
		$audit_page = isset( $_GET['aupg'] ) ? absint( $_GET['aupg'] ) : 0;
		$audit_page = $audit_page <= 0 ? 1 : $audit_page;
		$per_page = isset( $_GET['aupp'] ) ? absint( $_GET['aupp'] ) : 0;
		$per_page = $per_page <= 0 ? 20 : $per_page;

		// get a list of them messages
		$msgs = new QSOT_audit_msgs( array(
			'page' => $audit_page,
			'per_page' => $per_page,
			'order_id' => $order_id,
		) );
		?>
			<table cellspacing="0" class="qsot-audit-trail">
				<thead>
					<tr>
						<th class="index"><?php _e( '#', 'qsot-box-office' ) ?></th>
						<th class="when"><?php _e( 'When', 'qsot-box-office' ) ?></th>
						<th class="who"><?php _e( 'Who', 'qsot-box-office' ) ?></th>
						<th class="msg"><?php _e( 'Msg', 'qsot-box-office' ) ?></th>
					</tr>
				</thead>

				<?php self::_draw_msg_items( $msgs ) ?>
			</table>

			<?php self::_maybe_pagination( $msgs ) ?>
		<?php
	}

	// draw the next set of items to display
	protected static function _draw_msg_items( $msgs ) {
		?>
			<tbody timer="<?php echo number_format( $msgs->timer * 1000, 4 ) ?>ms">
				<?php if ( $msgs->total > 0 ): ?>
					<?php foreach ( $msgs as $ind => $msg ): ?>
						<tr class="trail-item <?php echo ( 1 == $ind % 2 ) ? 'alt' : '' ?>" item-type="<?php echo esc_attr( $msg->type ) ?>">
							<td class="index"><?php echo $msg->which ?></td>
							<td class="when" title="<?php echo esc_attr( $msg->when_hover ) ?>"><?php echo $msg->when_formatted ?></td>
							<td class="who" title="<?php echo esc_attr( $msg->who_hover ) ?>"><?php echo $msg->who_formatted ?></td>
							<td class="msg"><?php echo $msg->msg ?></td>
						</tr>
					<?php endforeach; ?>
				<?php else: ?>
					<tr class="trail-item" item-type="none">
						<td colspan="4"><?php _e( 'There are currently no audit trail items to display.', 'qsot-box-office' ) ?></td>
					</tr>
				<?php endif; ?>
			</tbody>
		<?php
	}

	// handle pagination ajax
	public static function pagination_ajax() {
		// validate the url params are present
		if ( ! isset( $_POST['n'], $_POST['oid'], $_POST['aupg'] ) ) {
			echo '{"s":false,"e":"missing params"}';
			exit;
		}

		// if the security code does not match, then fail
		if ( ! wp_verify_nonce( $_POST['n'], 'qsot-bo-audit-trail-pagination' ) ) {
			echo '{"s":false,"e":"security failure"}';
			exit;
		}

		// normalize the args
		$audit_page = isset( $_POST['aupg'] ) ? absint( $_POST['aupg'] ) : 0;
		$audit_page = $audit_page <= 0 ? 1 : $audit_page;
		$per_page = isset( $_POST['aupp'] ) ? absint( $_POST['aupp'] ) : 0;
		$per_page = $per_page <= 0 ? 20 : $per_page;
		$order_id = (int)$_POST['oid'];
		$order = wc_get_order( $order_id );

		// if the order is not valid, or the current user cannot edit this order, then fail
		if ( ! is_object( $order ) || $order->id !== $order_id || ! current_user_can( 'edit_shop_order', $order_id ) ) {
			echo '{"s":false,"e":"invalid order"}';
			exit;
		}

		// fetch the messages to display
		$msgs = new QSOT_audit_msgs( array(
			'page' => $audit_page,
			'per_page' => $per_page,
			'order_id' => $order_id,
		) );

		// render the results, and store it in the response
		ob_start();
		self::_draw_msg_items( $msgs );
		$resp = array( 'r' => ob_get_contents() );
		ob_end_clean();

		// update pagination also
		ob_start();
		self::_maybe_pagination( $msgs );
		$resp['p'] = ob_get_contents();
		ob_end_clean();

		// print the results and exit
		echo @json_encode( $resp );
		exit;
	}

	// maybe draw some pagination for the audit trail
	protected static function _maybe_pagination( $msgs ) {
		?>
			<div class="pagination" ajax-links="action=qsot-bo-aupg&n=<?php echo wp_create_nonce( 'qsot-bo-audit-trail-pagination' ) ?>">
				<?php if ( $msgs->total_pages > 1 ): ?>

					<?php if ( $msgs->page > 1 ): ?>
						<a href="<?php echo esc_attr( add_query_arg( array( 'aupg' => $msgs->page - 1 ) ) ) ?>" ajax-href="aupg=<?php echo esc_attr( $msgs->page - 1 ) ?>"><?php _e( '&laquo; Prev', 'qsot-box-office' ) ?></a>
					<?php endif; ?>

					<?php for ( $i = 1; $i <= $msgs->total_pages; $i++ ): ?>
						<?php if ( $i == $msgs->page ): ?>
							<span class="current-page page-link"><?php echo $i ?></span>
						<?php else: ?>
							<a class="page-link" href="<?php echo esc_attr( add_query_arg( array( 'aupg' => $i ) ) ) ?>" ajax-href="aupg=<?php echo $i ?>"><?php echo $i ?></a>
						<?php endif; ?>
					<?php endfor; ?>

					<?php if ( $msgs->page < $msgs->total_pages ): ?>
						<a href="<?php echo esc_attr( add_query_arg( array( 'aupg' => $msgs->page + 1 ) ) ) ?>" ajax-href="aupg=<?php echo esc_attr( $msgs->page + 1 ) ?>"><?php _e( 'Next &raquo;', 'qsot-box-office' ) ?></a>
					<?php endif; ?>

				<?php endif; ?>
			</div>
		<?php
	}

	// add an audit log entry
	public static function add_audit_item( $args ) {
		// normalize the input data
		$args = wp_parse_args( $args, array(
			'msg' => '',
			'order_id' => 0,
			'data' => array(),
		) );
		$args['order_id'] = (int)$args['order_id'];
		$args['data'] = is_array( $args['data'] ) ? $args['data'] : (array)$args['data'];

		// if the entry is not for a valid order, then fail
		if ( $args['order_id'] <= 0 )
			return;

		// if the msg type that was selected does not exist, then fail
		if ( empty( $args['msg'] ) || ! isset( self::$msg_map[ $args['msg'] ] ) )
			return;

		global $wpdb;

		// determine the user to use for the transaction
		if ( isset( $args['user_id'] ) && ! empty( $args['user_id'] ) ) {
			// fetch the user for the supplied user_id
			$u = get_user_by( 'id', $args['user_id'] );
		} else {
			// get the current user's information
			$u = wp_get_current_user();
		}

		// add some basic information to the stored data, which will be used in if the data is no longer available
		$data['.customer_id'] = get_post_meta( $args['order_id'], '_customer_user', true );
		$data['.user_login'] = $u->ID > 0 ? $u->user_login : __( '(guest)', 'qsot-box-office' );

		// construct the array of data that will be stored in the db for this record
		$data = array(
			'order_id' => $args['order_id'],
			'user_id' => $u->ID,
			'msg_frmt_id' => self::$msg_map[ $args['msg'] ]->id,
			'meta' => @json_encode( $args['data'] ),
		);

		// actually insert the audit item into the db
		$wpdb->insert(
			$wpdb->qsot_audit_trail,
			$data
		);
	}

	// register our default message types
	public static function register_default_messages() {
		// viewed an order in the admin
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'viewed-order',
			'msg_format' => __( 'Order #%1$s was viewed.', 'qsot-box-office' ),
			'param_order' => array( ':(absint)order_id' ),
		) );

		// added an order item
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'new-order-item',
			'msg_format' => __( 'Added %1$s x %2$s items (#%3$s), for a total of %4$s (discounted from %5$s), to order %6$s.', 'qsot-box-office' ),
			'param_order' => array( ':(absint)qty', 'item_name', ':(absint)item_id', ':(wc_price)line_total', ':(wc_price)line_subtotal', ':(absint)order_id' ),
		) );

		// updated an order
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'new-order',
			'msg_format' => __( 'New order #%1$s.', 'qsot-box-office' ),
			'param_order' => array( ':(absint)order_id' ),
		) );

		// updated an order
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'update-order',
			'msg_format' => __( 'Updated order #%1$s.', 'qsot-box-office' ),
			'param_order' => array( ':(absint)order_id' ),
			'display_func' => array( __CLASS__, 'display_update_order' ),
		) );

		// updated an order item
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'update-order-item',
			'msg_format' => __( 'Changed order item %1$s (#%2$s).', 'qsot-box-office' ),
			'param_order' => array( 'item_name', ':(absint)item_id' ),
			'display_func' => array( __CLASS__, 'display_update_order_item' ),
		) );

		// updated an order item
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'payment',
			'msg_format' => __( 'Completed a payment, using the %1$s payment gateway, for the amount of %2$s.', 'qsot-box-office' ),
			'param_order' => array( 'gateway_name', ':(wc_price)payment_total' ),
		) );

		// updated an order item
		do_action( 'qsot-bo-register-message', array(
			'slug' => 'admin-payment',
			'msg_format' => __( 'Payment was accepted in the admin, using the %1$s payment gateway, for the amount of %2$s.', 'qsot-box-office' ),
			'param_order' => array( 'gateway_name', ':(wc_price)payment_total' ),
		) );
	}

	// calculate the total discount for a line item
	public static function total_discount( $args ) {
		// if the needed information is present, then determine the discount
		if ( isset( $args['data'], $args['data']['line_total'], $args['data']['line_subtotal'] ) )
			return $args['data']['line_subtotal'] - $args['data']['line_total'];

		// otherwise return 0
		return 0;
	}

	// when an admin user views the order, record an audit trail item
	public static function at_viewed_order_admin( $exists, $post_id ) {
		// only do this on existing orders, not new ones
		if ( ! $exists || $post_id <= 0 ) return;

		// log that the order was viewed
		do_action( 'qsot-bo-add-audit-item', array(
			'msg' => 'viewed-order',
			'order_id' => $post_id,
			'data' => array(
				'order_id' => $post_id,
			),
		) );
	}

	// audit log trail when there is a new order item
	public static function at_woocommerce_order_add_product( $order_id, $item_id, $product, $qty, $args) {
		// compile the data to store in the record
		$data = array(
			'order_id' => $order_id,
			'item_id' => $item_id,
			'item_name' => $product->get_title(),
			'line_total' => wc_get_order_item_meta( $item_id, '_line_subtotal', true ),
			'line_subtotal' => wc_get_order_item_meta( $item_id, '_line_total', true ),
			'qty' => $qty,
		);

		// log that the item was added to the order
		do_action( 'qsot-bo-add-audit-item', array(
			'msg' => 'new-order-item',
			'order_id' => $order_id,
			'data' => $data,
		) );
	}

	// audit log trail when the order items are saved
	public static function at_woocommerce_saved_order_items( $order_id, $items ) {
		// first, look for changes in the order meta itself, and save those if they are present
		$o_data = self::_sniffout_order( $order_id );

		// determine any changes or deletes from the original meta
		$changes = $deletes = array();
		foreach ( $o_data as $k => $v ) {
			if ( isset( self::$ignore[ $k ] ) ) continue;
			if ( ! isset( self::$existing_o_data[ $order_id . '' ], self::$existing_o_data[ $order_id . '' ][ $k ] ) ) {
				$changes[ $k ] = array( '', $v, 'A' );
			} else if ( self::$existing_o_data[ $order_id . '' ][ $k ] != $v ) {
				$changes[ $k ] = array( self::$existing_o_data[ $order_id . '' ][ $k ], $v, 'C' );
			}
		}

		// determine if there are any deletes in order meta
		if ( isset( self::$existing_o_data[ $order_id . '' ] ) ) {
			foreach ( self::$existing_o_data[ $order_id . '' ] as $k => $v ) {
				if ( isset( self::$ignore[ $k ] ) ) continue;
				if ( ! isset( $o_data[ $k ] ) ) {
					$deletes[ $k ] = array( self::$existing_o_data[ $order_id . '' ][ $k ], '', 'D' );
				}
			}
		}

		// merge the chnages and deletes into on grouping, and save them if there are any differences
		$changes = array_merge( $changes, $deletes );
		if ( ! empty( $changes ) ) {
			// insert an update-order record
			do_action( 'qsot-bo-add-audit-item', array(
				'msg' => 'update-order',
				'order_id' => $order_id,
				'data' => $changes,
			) );
		}


		// get the final data for each order item
		$oi_data = self::_sniffout_order_items();

		$adds = $changes = array();
		// figure out if any of the order item data changed
		foreach ( $oi_data as $oiid => $data ) {
			// if this is a new order item
			if ( ! isset( self::$existing_oi_data[ $oiid . '' ] ) ) {
				$adds[ $oiid . '' ] = array(
					'order_id' => $order_id,
					'item_id' => $oiid,
					'item_name' => $oi_data['order_item_name'],
					'line_total' => $oi_data['_line_subtotal'],
					'line_subtotal' => $oi_data['_line_total'],
					'qty' => $oi_data['_qty'],
				);
				continue;
			}

			$whats_changed = array();
			// determine the changes
			foreach ( $data as $k => $v ) {
				if ( '.raw' == $k ) {
					$raw = self::$existing_oi_data[ $oiid . '' ]['.raw'];
					foreach ( $v as $rawk => $rawv )
						if ( $rawv != $raw[ $rawk ] )
							$whats_changed[ 'raw_' . $rawk ] = array( $raw[ $rawk ], $rawv, 'R' );
				} else if ( ! isset( self::$existing_oi_data[ $oiid . '' ][ $k ] ) ) {
					$whats_changed[ $k ] = array( '', $v, 'A' );
				} else if ( $v != self::$existing_oi_data[ $oiid . '' ][ $k ] ) {
					$whats_changed[ $k ] = array( self::$existing_oi_data[ $oiid . '' ][ $k ], $v, 'C' );
				}
			}

			// if anything did change, then, record it as having changed
			if ( ! empty( $whats_changed ) )
				$changes[ $oiid . '' ] = $whats_changed;

			$whats_changed = array();
			// if anything got deleted, make sure that we mark those as having been deleted
			foreach ( self::$existing_oi_data[ $oiid . '' ] as $k => $v ) {
				if ( ! isset( $data[ $k ] ) )
					$whats_changed[ $k ] = array( $v, '', 'D' );
			}

			// if anything did change, then, record it as having changed
			if ( ! empty( $whats_changed ) )
				$changes[ $oiid . '' ] = array_merge( isset( $changes[ $oiid . '' ] ) ? $changes[ $oiid . '' ] : array(), $whats_changed );
		}

		// make audit log entries for each type of change for each oi that changed
		foreach ( $oi_data as $oiid => $__ ) {
			// if this was a new order item item
			if ( isset( $adds[ $oiid . '' ] ) && ! empty( $adds[ $oiid . '' ] ) ) {
				$adds[ $oiid . '' ]['item_id'] = $oiid;
				$adds[ $oiid . '' ]['item_name'] = self::$existing_oi_data[ $oiid . '' ]['.raw']['order_item_name'];
				// log that the item was added to the order
				do_action( 'qsot-bo-add-audit-item', array(
					'msg' => 'new-order-item',
					'order_id' => $order_id,
					'data' => $adds[ $oiid . '' ],
				) );
			}

			// if there were changes for this order item
			if ( isset( $changes[ $oiid . '' ] ) && ! empty( $changes[ $oiid . '' ] ) ) {
				// record data
				$rdata = array(
					'item_id' => $oiid,
					'item_name' => self::$existing_oi_data[ $oiid . '' ]['.raw']['order_item_name'],
					'c' => $changes[ $oiid . '' ],
				);

				// log that the item was added to the order
				do_action( 'qsot-bo-add-audit-item', array(
					'msg' => 'update-order-item',
					'order_id' => $order_id,
					'data' => $rdata,
				) );
			}
		}
	}

	// when and order is created via the frontend, after all the meta and order items have been added, we need to make an audit log entry for the new order meta
	public static function at_frontend_order_creation( $order_id, $posted ) {
		$post = get_post( $order_id );
		self::at_update_post( $order_id, $post, true );
	}

	// when an order has had some meta updated, we need to record that too
	public static function at_update_post( $post_id, $post, $updated ) {
		// only do this for shop orders
		if ( 'shop_order' != $post->post_type ) return;

		// only do it on UPDATES to an order
		if ( ! $updated ) return;

		// load all the order meta, and take only the last value for each
		$final_meta = get_post_meta( $post_id, null, true );
		foreach ( $final_meta as $k => $v ) $final_meta[ $k ] = maybe_unserialize( end( $v ) );

		$changes = $deletes = array();
		// compare it to the original order meta, finding any new, chnaged, or deleted metas
		foreach ( $final_meta as $k => $v ) {
			if ( isset( self::$ignore[ $k ] ) ) continue;
			if ( ! isset( self::$existing_o_data[ $post_id . '' ], self::$existing_o_data[ $post_id . '' ][ $k ] ) ) {
				$changes[ $k ] = array( '', $v, 'A' );
			} else if ( $v != self::$existing_o_data[ $post_id . '' ][ $k ] ) {
				$changes[ $k ] = array( self::$existing_o_data[ $post_id . '' ][ $k ], $v, 'C' );
			}
		}

		// deletes
		if ( isset( self::$existing_o_data[ $post_id . '' ] ) ) {
			foreach ( self::$existing_o_data[ $post_id . '' ] as $k => $v ) {
				if ( isset( self::$ignore[ $k ] ) ) continue;
				if ( ! isset( $final_meta[ $k ] ) ) {
					$deletes[ $k ] = array( $v, '', 'D' );
				}
			}
		}

		// merge the deletes with the changes for a completel ist
		$changes = array_merge( $changes, $deletes );

		if ( ! empty( $changes ) ) {
			// insert an update-order record
			do_action( 'qsot-bo-add-audit-item', array(
				'msg' => 'update-order',
				'order_id' => $post_id,
				'data' => $changes,
			) );
		}
	}

	// when a new order post is created, we need to record audit log item for it
	public static function at_new_post( $post_id, $post, $updated ) {
		// only do this for orders
		if ( 'shop_order' != $post->post_type ) return;

		// if this is not a NEW order, then bail
		if ( $updated ) return;

		// log that the item was added to the order
		do_action( 'qsot-bo-add-audit-item', array(
			'msg' => 'new-order',
			'order_id' => $post_id,
			'data' => array( 'order_id' => $post_id ),
		) );
	}

	// when a new ticket is added in the admin, make an audit log entry
	public static function at_new_ticket( $current_item_id, $item, $order_id, $event, $product, $count ) {
		// compile the data to store in the record
		$data = array(
			'order_id' => $order_id,
			'item_id' => $current_item_id,
			'item_name' => $product->get_title(),
			'line_total' => $item['line_total'],
			'line_subtotal' => $item['line_subtotal'],
			'qty' => $item['qty'],
		);

		// log that the item was added to the order
		do_action( 'qsot-bo-add-audit-item', array(
			'msg' => 'new-order-item',
			'order_id' => $order_id,
			'data' => $data,
		) );
	}

	// when a payment is accepted, make an audit item
	public static function at_payment_complete( $order_id ) {
		$order = wc_get_order( $order_id );

		// determine the title of the payment method
		$method_title = get_post_meta( $order_id, '_payment_method_title', true );

		// if the title is not stored in the order cache, but the method short name is, then attempt to fetch the title based on the short name
		if ( empty( $method_title ) && ( $method = get_post_meta( $order_id, '_payment_method', true ) ) ) {
			$gateways = WC()->payment_gateways->get_available_payment_gateways();
			if ( isset( $gateways[ $method ] ) )
				$method_title = $gateways[ $method ]->get_title();
		}

		// get the total
		$order_total = $order->get_total();

		// determine the origin of the payment, either admin or not admin
		$type = get_post_meta( $order_id, '_payment_from', true );
		$type = 'admin' == $type ? 'admin-payment' : 'payment';

		// compile the data for the audit record
		$data = array(
			'gateway_name' => $method_title,
			'payment_total' => $order_total,
		);

		// determine the current user's id, if any
		$u = wp_get_current_user();
		if ( ! empty( $u->ID ) ) $user_id = $u->ID;
		else $user_id = absint( get_post_meta( $order_id, '_payment_user', true ) );

		// log that the payment was taken for the order order
		do_action( 'qsot-bo-add-audit-item', array(
			'msg' => $type,
			'user_id' => $user_id,
			'order_id' => $order_id,
			'data' => $data,
		) );
	}

	// when a payment is taken on the frontend, we need to recored what user is requesting the processed payment, for the autdit trail payment API response
	public static function record_payment_user( $order_id, $posted ) {
		// fetch the current user (could be id 0) and store the id in the order meta, for use when the API response comes back
		$u = wp_get_current_user();
		update_post_meta( $order_id, '_payment_user', $u->ID );
	}

	// special function used to display the 'update-order' audit items
	public static function display_update_order( $msg, $args ) {
		//	'msg_format' => __( 'Updated order #%d.', 'qsot-box-office' ) ,
		$data = $args['data'];
		$frmt = $msg->def_frmt;

		$data_pairs = array();
		// if the data we need is available
		if ( isset( $args['data'] ) ) {
			// cycle through all the changed meta, and create a formatted display of how it changed
			foreach ( $args['data'] as $k => $v ) {
				$name = ucwords( trim( str_replace( array( '-', '_' ), array( ' ', ' ' ), $k ) ) );
				$v1 = self::_normalize_display_value( $v[0] );
				$v2 = self::_normalize_display_value( $v[1] );
				$data_pairs[] = sprintf( '<strong>%s</strong>: %s => %s', $name, $v1, $v2 );
			}
		}

		return sprintf( $frmt, '<strong>' . absint( $args['order_id'] ) . '</strong>' ) . '<br/>' . implode( '<br/>', $data_pairs );
	}

	// special function used to display the 'update-order-item' audit items
	public static function display_update_order_item( $msg, $args ) {
		$data = $args['data'];
		$frmt = $msg->def_frmt;

		$data_pairs = array();
		// if the data we need is available
		if ( isset( $args['data'], $args['data']['c'] ) ) {
			// cycle through the data, and create a formatted display showing the change
			foreach ( $args['data']['c'] as $k => $v ) {
				$name = ucwords( trim( str_replace( array( '-', '_' ), array( ' ', ' ' ), $k ) ) );
				$v1 = self::_normalize_display_value( $v[0] );
				$v2 = self::_normalize_display_value( $v[1] );
				$data_pairs[] = sprintf( '<strong>%s</strong>: %s => %s', $name, $v1, $v2 );
			}
		}

		return sprintf( $frmt, '<strong>' . $args['data']['item_name'] . '</strong>', '<strong>' . $args['data']['item_id'] . '</strong>' ) . '<br/>' . implode( '<br/>', $data_pairs );
	}

	// normalize the display of the order item value
	protected static function _normalize_display_value( $value ) {
		$out = array();

		// if the value is not a complex data construction
		if ( is_scalar( $value ) ) {
			// if the value is not an empty string, then use the value for display
			if ( strlen( $value ) ) {
				$out = $value;
			// if the string is empty, then show it as empty
			} else {
				$out = '(empty)';
			}
		// or if it is an array or object
		} else {
			// if it has a value in it, show that it is an object or array
			if ( ! empty( $value ) ) {
				$out = is_array( $value ) ? '(array)' : '(object)';
			// otherwise, consider it empty
			} else {
				$out = '(empty)';
			}
		}

		return $out;
	}
	
	// when there is a partial page save in the edit order screen, we need to extract all information we can, early, so that it can be compared for changes later, for audit log items
	public static function ajax_extract_save_order_data() {
		// copy the security from the core WC func
		check_ajax_referer( 'order-item', 'security' );

		if ( isset( $_POST['order_id'] ) ) {
			// load the information about the requested order
			$post = get_post( (int)$_POST['order_id'] );
			if ( is_object( $post ) ) {
				self::extract_save_order_data( $post->ID, $post );
			}
		}
	}

	// when saving an order, we need to load the existing data for the order and it's items, so that an accurate audit trail entry can be created later
	public static function extract_save_order_data( $post_id, $post ) {
		// only shop_orders should run this code
		if ( 'shop_order' != $post->post_type ) return;

		// fetch any information about the order being saved
		self::$existing_o_data[ $post_id . '' ] = self::_sniffout_order( $post_id );

		// fetch any information about order items that is being saved
		self::$existing_oi_data = self::_sniffout_order_items();
	}

	// sniff out the order meta or the order being saved
	protected static function _sniffout_order( $post_id ) {
		// fetch and store all order meta
		$data = get_post_meta( $post_id, null, true );

		// only keep the last entry of each list
		foreach ( $data as $k => $v )
			$data[ $k ] = end( $v );

		return $data;
	}

	// sniff out the order item ids being saved, and cache the existing information about them, for later use in audit items
	protected static function _sniffout_order_items() {
		$oi_data = array();
		// for each order item that is saved, we need to store the existing information for the item, so that it can be later compared to the new data
		if ( isset( $_POST['order_item_id'] ) && ! empty( $_POST['order_item_id'] ) ) {
			// fetch all the order items being saved
			foreach ( $_POST['order_item_id'] as $oiid ) {
				// get all their meta, and store it for later use
				$oi_data[ $oiid . '' ] = wc_get_order_item_meta( $oiid, null, true );
				// and only keep the last entry of each meta key
				foreach ( $oi_data[ $oiid . '' ] as $k => $v )
					$oi_data[ $oiid . '' ][ $k ] = maybe_unserialize( end( $v ) );
			}

			global $wpdb;
			// get the basic order item data, which is not stored as meta, for each order item
			$all_data = $wpdb->get_results( 'select * from ' . $wpdb->prefix . 'woocommerce_order_items where order_item_id in (' . implode( ',', array_map( 'absint', $_POST['order_item_id'] ) ) . ')' );
			foreach ( $all_data as $row )
				$oi_data[ $row->order_item_id . '' ]['.raw'] = array( 'order_item_name' => $row->order_item_name, 'order_item_type' => $row->order_item_type );
		}

		return $oi_data;
	}

	// setup the table names based on the current blog db prefix
	public static function setup_table_names() {
		global $wpdb;
		$wpdb->qsot_audit_trail = $wpdb->prefix . 'qsot_audit_trail';
		$wpdb->qsot_audit_msgs = $wpdb->prefix . 'qsot_audit_msgs';
	}

	// setup the db table descriptions which are used to update the db tables
	public static function setup_tables( $tables ) {
		global $wpdb;

		// table to hold the message types and their related default infos
		$tables[ $wpdb->qsot_audit_msgs ] = array(
      'version' => '0.1.0',
      'fields' => array(
        'id' => array( 'type' => 'mediumint(8) unsigned', 'extra' => 'auto_increment' ),
				'slug' => array( 'type' => 'varchar(100)' ), // short name of the message
				'def_frmt' => array( 'type' => 'text' ), // default format in case the message becomes unavailable in the software
				'def_params' => array( 'type' => 'text' ), // default param order to match to the frmt, in case software handling is no longer available
      ),   
      'keys' => array(
        'PRIMARY KEY  (id)',
      ),
		);

		// table to hold all the audit log items
		$tables[ $wpdb->qsot_audit_trail ] = array(
      'version' => '0.1.0',
      'fields' => array(
        'id' => array( 'type' => 'bigint(20) unsigned', 'extra' => 'auto_increment' ),
				'order_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // order that the message is linked to
				'user_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // user_id who triggered the message
        'note' => array( 'type' => 'text', 'null' => 'yes' ), // required legacy field. entire messages used to be stored. now they are stored as ids only with a message lookup table
				'msg_frmt_id' => array( 'type' => 'mediumint(8) unsigned' ), // message format id, that links to qsot_audit_msgs table
        'meta' => array( 'type' => 'text' ), // params to use with the message format
				'record_time' => array( 'type' => 'timestamp', 'default' => 'CONST:|CURRENT_TIMESTAMP|' ), // datetime of the message trigger
      ),   
      'keys' => array(
        'PRIMARY KEY  (id)',
        'KEY order_id (order_id)',
        'KEY user_id (user_id)',
      ),
		);

		return $tables;
	}
}

if ( ! class_exists( 'QSOT_audit_msgs' ) ):
	// query the audit trail log for messages
	class QSOT_audit_msgs implements Iterator {
		// valid fields that can be sorted by
		private static $valid_sortable = array(
			'id' => 1,
			'order_id' => 1,
			'user_id' => 1,
			'msg_frmt_id' => 1,
			'record_time' => 1,
			'rand()' => 1,
		);

		// current position in the items list
		private $position = 0;
		// the items list
		private $items = array();

		// timer for profiling
		public $timer = 0;

		// query properties
		public $total = 0;
		public $total_found = 0;
		public $query = '';
		public $args = array();
		public $offset = 0;
		public $per_page = 20;
		public $page = 1;
		public $total_pages = 1;

		// create an instance of the query object. if the args are passed here, then autorun a query with those args
		public function __construct( $args='' ) {
			if ( ! empty( $args ) )
				$this->query( $args );
		}

		// actually run the query
		public function query( $args ) {
			$timer = microtime( true );
			global $wpdb;

			// normalize the args
			$this->args = $args = $this->_parse_args( $args );
			$this->per_page = $this->args['per_page'];
			$this->page = $this->args['page'];

			// construct the actual sql to fetch the requested list
			$fields = array( '*' );
			$where = $order = array();
			$limit = '';

			// if the order_id was supplied then add it to the query. this is always sanitized to an array
			if ( ! empty( $args['order_id'] ) && is_array( $args['order_id'] ) && ! empty( $args['order_id'] ) )
				$where[] = ' and order_id in(' . implode( ',', $args['order_id']) . ')';

			// if the user_id was supplied then add it to the query. this is always sanitized to an array
			if ( ! empty( $args['user_id'] ) && is_array( $args['user_id'] ) && ! empty( $args['user_id'] ) )
				$where[] = ' and user_id in(' . implode( ',', $args['user_id']) . ')';

			// if we are looking for all entries after a specific date, then add that to the query
			if ( $args['after'] > 0 ) {
				$where[] = $wpdb->prepare( ' and since >= %s', date( 'Y-m-d H:i:s', $args['after'] ) );
			}

			// if we are looking for all entries before a specific date, then add that to the query
			if ( $args['before'] > 0 ) {
				$where[] = $wpdb->prepare( ' and since <= %s', date( 'Y-m-d H:i:s', $args['before'] ) );
			}

			// pass through the sanitized order argument
			$order = $args['orderby'];

			// figure out the page and offset based on the supplied params
			$this->offset = ( $this->per_page * ( $this->page - 1 ) );
			$limit = sprintf( 'limit %d offset %d', $this->per_page, $this->offset );

			// allow plugins to modify the query pieces
			$parts = array( 'fields', 'where', 'order', 'limit' );
			$pieces = apply_filters( 'qsot-bo-audit-trail-query-parts', compact( $parts ) );
			foreach ( $parts as $part ) $$part = $pieces[ $part ];

			// finalize the pieces
			$fields = ( is_array( $fields ) && ! empty( $fields ) ) ? implode( ', ', $fields ) : '*';
			$where = ( is_array( $where ) && ! empty( $where ) ) ? ' where 1=1 ' . implode( ' ', $where ) : '';
			$order = ( is_array( $order ) && ! empty( $order ) ) ? ' order by ' . implode( ', ', $order ) : 'order by record_time desc, id desc';

			// store the query for later reference, and allow external modification
			$this->query = $q = apply_filters( 'qsot-bo-audit-trail-query', 'select SQL_CALC_FOUND_ROWS ' . $fields . ' from ' . $wpdb->qsot_audit_trail . ' ' . $where . ' ' . $order . ' ' . $limit, $pieces, $this );

			// fetch the results
			$items = $wpdb->get_results( $this->query );
			$this->total = count( $items );
			$this->position = 0;

			// determine the total number of records
			$this->total_found = $wpdb->get_var( 'select found_rows()' );
			$this->total_pages = ceil( $this->total_found / $this->per_page );

			// santize the items
			$this->items = $this->_sane_items( $items );

			$this->timer = microtime( true ) - $timer;
		}

		// parse the args, normalizing them in the process
		private function _parse_args( $args ) {
			// normalize the args
			$args = wp_parse_args( $args, array(
				'per_page' => 20,
				'page' => 1,
				'order_id' => 0,
				'user_id' => 0,
				'after' => '',
				'before' => '',
				'orderby' => '',
			) );
			
			// sanitize the args
			$args['per_page'] = absint( $args['per_page'] );
			$args['per_page'] = 0 == $args['per_page'] ? 20 : $args['per_page'];
			$args['page'] = absint( $args['page'] );
			$args['page'] = 0 == $args['page'] ? 1 : $args['page'];
			$args['order_id'] = $this->_to_id_or_array_ids( $args['order_id'], 'id' );
			$args['user_id'] = $this->_to_id_or_array_ids( $args['user_id'], 'ID' );
			$args['after'] = is_numeric( $args['after'] ) ? $args['after'] : @strtotime( $args['after'] );
			$args['before'] = is_numeric( $args['before'] ) ? $args['before'] : @strtotime( $args['before'] );
			$args['orderby'] = $this->_sane_orderby( $args['orderby'] );

			// allow plugins to modify the query args
			return apply_filters( 'qsot-bo-audit-trail-query-args', $args );
		}

		// parse an id, list of ids, or list of objects -> list of ids
		private function _to_id_or_array_ids( $id, $field='id' ) {
			$out = array();

			// normalize all values to an array of values
			$id = (array)$id;

			// for each item in the list
			foreach ( $id as $item ) {
				// if it is an object, then pluck the $field
				if ( is_object( $item ) )
					$out[] = $item->$field;
				// if it is scalar, then cast it to (int)
				else if ( is_scalar( $item ) )
					$out[] = (int)$item;
			}

			// filter out any 0s and empty values
			return array_filter( $out );
		}

		// sanitize the orderby
		private function _sane_orderby( $orderby ) {
			$out = array();

			// make sure we are dealing with an array
			$orderby = (array)$orderby;

			// cycle through each orderby clause, and make sure it is valid
			foreach ( $orderby as $clause ) {
				if ( ! is_string( $clause ) ) continue;

				// split it into it's words
				$clause = array_map( 'strtolower', array_filter( preg_split( '#\s+#', urldecode( $clause ) ) ) );
				// filter any obviously improperly formatted clauses
				if ( count( $clause ) < 1 || count( $clause ) > 2 ) continue;

				// validate the first word is a valid sortable field
				if ( ! isset( self::$valid_sortable[ $clause[0] ] ) ) continue;

				// normalize the second word to be a valid sort order
				$clause[1] = isset( $clause[1] ) && in_array( $clasue[1], array( 'asc', 'desc' ) ) ? $clause[1] : 'asc';
				if ( 'rand()' == $clause[0] ) unset( $clause[1] );

				// construct a final formatted version of the order statement
				$out[] = implode( ' ', $clause );
			}

			return $out;
		}

		// sanitize each item, and add the required formatted versions of each item for display purposes
		private function _sane_items( &$items ) {
			$final = array();

			// allow plugins to make their changes
			$items = apply_filters( 'qsot-bo-audit-trail-sane-items', $items, $this );

			// foreach item in the list, add the formatted display versions of some info, and add it to the final list
			$cnt = 1;
			while ( $item = array_shift( $items ) ) {
				// unserialize the meta
				$item->_meta = $item->meta;
				$item->meta = @json_decode( $item->meta );

				// fetch the message type based on the message type id
				$item->type = apply_filters( 'qsot-bo-audit-type-from-id', $item->msg_frmt_id, $item->msg_frmt_id );

				// assign the item a proper index
				$item->which = $this->offset + ( $cnt++ );

				// add formatted versions of the date and time
				$st = strtotime( $item->record_time );
				$item->when_formatted = date( __( 'n-j-y g:ia', 'qsot-box-office' ), $st );
				$item->when_hover = date( __( 'D, M jS, Y @ g:i:sa', 'qsot-box-office' ), $st ) ;

				// normalize who the item belongs to
				$this->_sane_who( $item );
				
				// normalize the message to display
				$this->_sane_msg( $item );

				$final[] = $item;
			}

			// return the final formatted list
			return $final;
		}

		// normalize who the record belongs to
		private function _sane_who( &$item ) {
			// normalize the username to display. default to guest user with order billing email
			$item->who_formatted = __( '(guest)', 'qsot-box-office' );
			$item->who_hover = get_post_meta( $item->order_id, '_billing_email', true );

			// if the user_id was stored
			if ( $item->user_id > 0 ) {
				// change the hover to a more descriptive value that can be looked up in the admin
				$item->who_hover = __( 'User #', 'qsot-box-office' ) . $item->user_id;
				
				// attempt to load the data for the user who made the entry
				$user = get_user_by( 'id', $item->user_id );

				// if the user still exists
				if ( is_object( $user ) && isset( $user->user_login ) ) {
					// use their username as the display name
					$item->who_formatted = $user->user_login;
				// if they no longer exist, lookup the backup username if it was stored
				} else if ( isset( $item->meta['.user_login'] ) && ! empty( $item->meta['.user_login'] ) ) {
					$item->who_formatted = $item->meta['.user_login'];
				// otherwise mark that the user is not logner valid
				} else {
					$item->who_formatted = __( '(removed user)', 'qsot-box-office' );
				}
			}
		}

		// sanitize teh message. be sure to allow legacy style messages
		private function _sane_msg( &$item ) {
			// if a legacy message exists, then always use that and do not do any additional processing
			if ( isset( $item->note ) && strlen( $item->note ) > 0 ) {
				$item->msg = $item->note;
				return;
			}

			// construct the message based on the supplied database data
			$item->msg = apply_filters( 'qsot-bo-compile-msg', $item->_meta, array( 'msg' => $item->type, 'order_id' => $item->order_id, 'data' => $item->meta ) );
		}

		// get the current item
		public function current() { return $this->items[ $this->position ]; }

		// get the current item key
		public function key() { return $this->position; }

		// inc the position
		public function next() { ++$this->position; }

		// reset the position to 0
		public function rewind() { $this->position = 0; }

		// determine if the current item isset
		public function valid() { return isset( $this->items[ $this->position ] ); }
	}
endif;

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_bo_audit::pre_init();
