<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to handle the basic general admission event area type
class QSOT_Seating_Zoner extends QSOT_Base_Event_Area_Zoner {
	// container for the singleton instance
	protected static $instance = null;

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Seating_Zoner )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new QSOT_Seating_Zoner();
	}

	// constructor. handles instance setup, and multi instance prevention
	public function __construct() {
		// if there is already an instance of this object, then bail now
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Seating_Zoner )
			throw new Exception( sprintf( __( 'There can only be one instance of the %s object at a time.', 'opentickets-community-edition' ), __CLASS__ ), 12000 );

		// otherwise, set this as the known instance
		self::$instance = $this;

		// defaults from parent
		parent::__construct();

		// and call the intialization function
		$this->initialize();
	}

	// destructor. handles instance destruction
	public function __destruct() {
		$this->deinitialize();
	}


	// setup the object
	public function initialize() {
		// setup the tables and table names used by this event area type
		$this->setup_table_names();
		add_action( 'switch_blog', array( &$this, 'setup_table_names' ), PHP_INT_MAX, 2 );
		add_filter( 'qsot-upgrader-table-descriptions', array( &$this, 'setup_tables' ), 1000000 );

		// add the filter to enforce the purchase limit on this zoner type
		add_filter( 'qsot-can-add-tickets-to-cart', array( &$this, 'enforce_purchase_limit' ), 10, 3 );

		// load the plugin options
		$options = QSOT_Options::instance();

		// add our new ticket state
		$this->stati['i'] = array( 'interest', 900, __( 'Interested', 'qsot-seating' ), __( 'No Price Selected', 'qsot-seating' ), 900 );
		$this->_post_initialize_setup_options();
		$this->stati['i'][1] = intval( $options->{'qsot-interest-state-timer'} );
	}

	// destroy the object
	public function deinitialize() {
		remove_filter( 'qsot-can-add-tickets-to-cart', array( &$this, 'enforce_purchase_limit' ), 10 );
	}

	// get a valid state, based on a supplied desired state and a default
	public function valid_state( $desired, $default ) {
		$state_found = false;
		foreach ( $this->stati as $state ) {
			if ( $state[0] == $desired ) {
				$state_found = true;
				break;
			}
		}

		return $state_found ? $desired : $default;
	}

	// obtain lock on a certain number of tickets
	protected function _obtain_lock( $data=array() ) {
		global $wpdb;
		// normalize the lock data
		$data = apply_filters( 'qsot-lock-data', wp_parse_args( $data, array(
			'event_id' => 0,
			'ticket_type_id' => 0,
			'quantity' => 0,
			'customer_id' => '',
			'order_id' => '',
			'zone_id' => '0',
			'state' => '',
		) ) );

		// if there is not at least basic required info, then bail
		if ( $data['event_id'] <= 0 || $data['quantity'] <= 0 || $data['zone_id'] < 0 )
			return new WP_Error( 'missing_data', __( 'Some or all of the required data is missing', 'opentickets-community-edition' ) );

		// create a unique id for this temporary lock, so that we can easily id the lock and remove it after the lock has passed inspection
		$uniq_lock_id = uniqid( 'temp-lock-', true );

		// obtain a temporary lock of the requested quantity. this will be used in a moment to determine if the user has the ability to reserve this number of tickets
		$wpdb->insert(
			$wpdb->qsot_event_zone_to_order,
			array(
				'event_id' => $data['event_id'],
				'ticket_type_id' => $data['ticket_type_id'],
				'state' => $this->valid_state( $data['state'], $this->stati['r'][0] ),
				'mille' => QSOT::mille(),
				'quantity' => $data['quantity'],
				'session_customer_id' => $uniq_lock_id,
				'order_id' => $data['order_id'],
				'zone_id' => $data['zone_id'],
			)
		);
		return $wpdb->get_row( $wpdb->prepare( 'select * from ' . $wpdb->qsot_event_zone_to_order . ' where session_customer_id = %s', $uniq_lock_id ) );
	}

	// remove a previously obtained lock
	protected function _remove_lock( $lock ) {
		// if the supplied data is not a lock, then bail
		if ( ! is_object( $lock ) || ! isset( $lock->session_customer_id, $lock->mille ) )
			return new WP_Error( 'invalid_lock', __( 'The supplied lock, was not valid.', 'opentickets-community-edition' ) );

		global $wpdb;
		// remove the lock
		$wpdb->delete( $wpdb->qsot_event_zone_to_order, (array) $lock ); //array( 'session_customer_id' => $lock->session_customer_id, 'mille' => $lock->mille ) );
	}

	// we may need to enforce a per-event ticket limit. check that here
	public function enforce_purchase_limit( $current, $deprecated_event=null, $args='' ) {
		// deprecate arg
		if ( null !== $deprecated_event )
			_deprecated_argument( __FUNCTION__, 'OpenTickets 2.0', __( 'Argument #2 is obsolete.', 'opentickets-community-edition' ) );

		// normalize the args
		$args = wp_parse_args( $args, array(
			'event_id' => 0,
			'ticket_type_id' => 0,
			'order_id' => 0,
			'customer_id' => '',
			'quantity' => 1,
			'state' => $this->stati['r'][0]
		) );
		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		$args['ticket_type_id'] = is_numeric( $args['ticket_type_id'] ) && $args['ticket_type_id'] > 0
				? $args['ticket_type_id']
				: ( is_object( $args['ticket_type_id'] ) && isset( $args['ticket_type_id']->id ) ? $args['ticket_type_id']->id : false );

		// if the basic information is not present, then bail
		if ( $args['event_id'] <= 0 )
			return new WP_Error( 'invalid_event', __( 'Could not find that event.', 'opentickets-community-edition' ) );

		// figure out the event ticket limit, if any
		$limit = apply_filters( 'qsot-event-ticket-purchase-limit', 0, $args['event_id'] );

		// if there is no limit, then bail
		if ( $limit <= 0 )
			return $current;

		// determine how many tickets they currently have for this event
		$find_args = $args;
		$find_args['fields'] = 'total';
		unset( $find_args['quantity'] );
		$total_for_event_for_zone = $this->find( $find_args );
		unset( $find_args['zone_id'], $find_args['ticket_type_id'] );
		$total_for_event = $this->find( $find_args );

		$max = $args['quantity'];
		// determine the maximal quantity that the user can have
		if ( $total_for_event_for_zone > 0 ) {
			$max = max( 0, $limit - $total_for_event + $total_for_event_for_zone );
		} else {
			$max = max( 0, $limit - $total_for_event );
		}

		// figure out the actual quantity that the user can acquire
		$final_qty = min( $max, $args['quantity'] );

		// if the user cannot get any more tickets, then bail with an error
		if ( $final_qty <= 0 )
			return new WP_Error( 10, __( 'You have reached the ticket limit for this event.', 'opentickets-community-edition' ) );

		return $final_qty;
	}

	// find some rows, based on some search criteria
	public function find( $args ) {
		return QSOT_Zoner_Query::instance()->find( $args );
	}

	// method to show interest in some seats
	public function interest( $success, $args ) {
		$cur_order_id = absint( WC()->session->order_awaiting_payment );
		// normalize input data
		$args = wp_parse_args( $args, array(
			'event_id' => false,
			'ticket_type_id' => 0,
			'quantity' => 1,
			'customer_id' => '',
			'order_id' => $cur_order_id,
			'zone_id' => '0',
		) );

		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		$args['zone_id'] = is_numeric( $args['zone_id'] ) && $args['zone_id'] > 0 ? (int) $args['zone_id'] : ( is_object( $args['zone_id'] ) && isset( $args['zone_id']->id ) ? $args['zone_id']->id : '0' );
		$args['quantity'] = max( 0, $args['quantity'] );
		$args['customer_id'] = empty( $args['customer_id'] ) ? $this->current_user() : $args['customer_id'];

		// if we are being asked to remove the reservations, then call our removal func
		if ( $args['quantity'] <= 0 )
			return $this->remove( $args );

		// figure out the limit of the number of tickets a user can get for this event
		$hard_limit = apply_filters( 'qsot-event-ticket-purchase-limit', 0, $args['event_id'] );

		$lock_args = $args;
		// user that limit and the requested quantity to find out the actual quantity the user can select
		$lock_args['quantity'] = $hard_limit <= 0 ? $args['quantity'] : max( 0, min( $hard_limit, $args['quantity'] ) );
		$lock_args['state'] = $this->stati['i'][0];

		// obtain the lock
		$lock = $this->_obtain_lock( $lock_args );

		// if there was a problem obtaining the lock, then bail
		if ( is_wp_error( $lock ) || ! is_object( $lock ) )
			return apply_filters( 'qsot-seating-zoner-interest-results', is_wp_error( $lock ) ? $lock : false, $args );

		// store the qty we are using for the lock, for later comparison/use
		$lock_for = $lock->quantity;

		// determine the capacity for the event
		$ea_id = get_post_meta( $args['event_id'], '_event_area_id', true );
		$capacity = $ea_id > 0 ? get_post_meta( $ea_id, '_capacity', true ) : 0;

		// tally all records for this event before this lock.
		$total_before_lock = $this->find( array(
			'event_id' => $args['event_id'],
			'state' => '*',
			'fields' => 'total',
			'before' => $lock->since . '.' . $lock->mille,
			'zone_id' => $args['zone_id'],
		) );

		// figure out the total available for the event, at the point of the lock. if there is no capacity, then default to the amount in the lock
		$remainder = $capacity > 0 ? $capacity - $total_before_lock : $lock_for;

		// if the total is greater than or equal to the max capacity for this event, then we do not have enough tickets to issue, so bail
		if ( $capacity > 0 && $remainder <= 0 ) {
			$this->_remove_lock( $lock );
			return apply_filters( 'qsot-seating-zoner-interest-results', new WP_Error( 5, __( 'There are no tickets available to reserve for this zone.', 'qsot-seating' ) ), $args );
		}

		// figure out the final value for the quantity. this will be checked below and adjusted accordingly
		$final_qty = max( 0, min( $remainder, $lock_for ) );

		// check if this amount can be added to the cart. could run into purchase limit
		$can_add_to_cart = apply_filters( 'qsot-can-add-tickets-to-cart', true, null, $lock_args );

		// handle the response from our check
		// if there was an error, cleanup and bail
		if ( is_wp_error( $can_add_to_cart ) ) {
			$this->_remove_lock( $lock );
			return apply_filters( 'qsot-seating-zoner-interest-results', $can_add_to_cart, $args );
		// if there was a fail but no error, then cleanup and bail
		} else if ( ! $can_add_to_cart ) {
			$this->_remove_lock( $lock );
			return apply_filters( 'qsot-seating-zoner-interest-results', new WP_Error( 6, __( 'Could not reserve those tickets.', 'opentickets-community-edition' ) ), $args );
		// if there was a change in the quantity to use, then adjust the quantity accordingly
		} else if ( is_numeric( $can_add_to_cart ) && $can_add_to_cart < $lock_for ) {
			$final_qty = $can_add_to_cart;
		}

		// store the final quantity we used
		$args['final_qty'] = $final_qty;

		global $wpdb;

		// at this point the user has obtained a valid lock, and can now actaully have the tickets. proceed with the reservation process
		// first, remove any previous rows that this user had for this event/ticket_type/zone_id combo. this will eliminate the 'double counting' of this person's reservations moving forward
		$wpdb->delete(
			$wpdb->qsot_event_zone_to_order,
			array(
				'session_customer_id' => $args['customer_id'],
				'event_id' => $args['event_id'],
				'ticket_type_id' => $args['ticket_type_id'],
				'state' => $this->stati['i'][0],
				'order_id' => $args['order_id'],
				'zone_id' => $args['zone_id'],
			)
		);

		// now update the lock record with our new reservation info, transforming it into the new reservation row for this user
		$wpdb->update(
			$wpdb->qsot_event_zone_to_order,
			array(
				'session_customer_id' => $args['customer_id'],
				'event_id' => $args['event_id'],
				'ticket_type_id' => $args['ticket_type_id'],
				'state' => $this->stati['i'][0],
				'order_id' => $args['order_id'],
				'quantity' => $final_qty,
				'zone_id' => $args['zone_id'],
			),
			array(
				'session_customer_id' => $lock->session_customer_id
			)
		);

		return apply_filters( 'qsot-seating-zoner-interest-results', true, $args );
	}

	// method to reserve some tickets
	public function reserve( $success, $args ) {
		$cur_order_id = absint( WC()->session->order_awaiting_payment );
		// normalize input data
		$args = wp_parse_args( $args, array(
			'event_id' => false,
			'ticket_type_id' => 0,
			'quantity' => 0,
			'customer_id' => '',
			'order_id' => $cur_order_id,
			'zone_id' => '0',
		) );

		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		$args['zone_id'] = is_numeric( $args['zone_id'] ) && $args['zone_id'] > 0 ? (int) $args['zone_id'] : ( is_object( $args['zone_id'] ) && isset( $args['zone_id']->id ) ? $args['zone_id']->id : '0' );
		$args['ticket_type_id'] = is_numeric( $args['ticket_type_id'] ) && $args['ticket_type_id'] > 0
				? $args['ticket_type_id']
				: ( is_object( $args['ticket_type_id'] ) && isset( $args['ticket_type_id']->id ) ? $args['ticket_type_id']->id : false );
		$args['quantity'] = max( 0, $args['quantity'] );
		$args['customer_id'] = empty( $args['customer_id'] ) ? $this->current_user() : $args['customer_id'];

		// if we are being asked to remove the reservations, then call our removal func
		if ( $args['quantity'] <= 0 )
			return $this->remove( $args );

		// figure out the limit of the number of tickets a user can get for this event
		$hard_limit = apply_filters( 'qsot-event-ticket-purchase-limit', 0, $args['event_id'] );

		// find the existing interest row that this reservation should be derived from, if we are updating an interest row. the only other option is to update an existing reserve row, which is handled in a moment
		$find_args = $args;
		$find_args['state'] = $this->stati['i'][0];
		unset( $find_args['quantity'], $find_args['ticket_type_id'] );
		$interests = $this->find( $find_args );

		$orig_row = null;
		$total_prior = 0;
		// if there were interest rows, then use the last row as the base for this reservation request
		if ( is_array( $interests ) && count( $interests ) ) {
			$orig_row = array_pop( $interests );

			// find the total number of claimed tickets prior to the interest row we matched
			$new_find_args = $find_args;
			$new_find_args['state'] = '*';
			$new_find_args['fields'] = 'total';
			$new_find_args['before'] = $orig_row->since . '.' . $orig_row->mille;
			unset( $new_find_args['ticket_type_id'], $new_find_args['customer_id'], $new_find_args['order_id'] );
			$total_prior = $this->find( $new_find_args );
		// otherwise, try to find an existing reservation row to update
		} else {
			$find_args['state'] = array( $this->stati['c'][0], $this->stati['r'][0] );
			$find_args['ticket_type_id'] = $args['ticket_type_id'];
			$reserves = $this->find( $find_args );

			// if there is at least one reserved row already that matches, use the last one, settings permitted
			if ( is_array( $reserves ) && count( $reserves ) ) {
				// use the last matched row as the original row
				$orig_row = array_pop( $reserves );

				// if the settings is set that forces users to stick with their original selection, and the row we are updating is a row that is already reserved tickets, then account for that now
				if ( 'yes' == apply_filters( 'qsot-get-option-value', 'no', 'qsot-locked-reservations' ) ) {
					$args['final_qty'] = $orig_row->quantity;
					return apply_filters(
						'qsot-seating-zoner-reserve-results',
						new WP_Error( 9, __( 'You are not allowed to modify your reservations, except to delete them, after you have chosen them initially.', 'opentickets-community-edition' ) ),
						$args
					);
				}
			}

			// find the total number of claimed tickets including the matched row
			$new_find_args = $find_args;
			$new_find_args['state'] = '*';
			$new_find_args['fields'] = 'total';
			unset( $new_find_args['ticket_type_id'], $new_find_args['customer_id'], $new_find_args['order_id'] );
			$total_prior = $this->find( $new_find_args );
		}

		// figure out the total prior quantity value for any rows matching this new row in the past, so that we can use this as the maximum amount they can reserve, if there are no tickets left already
		$find_args['state'] = '*';
		$find_args['fields'] = 'total';
		$find_args['ticket_type_id'] = $args['ticket_type_id'];
		if ( is_object( $orig_row ) )
			$find_args['before'] = $orig_row->since . '.' . $orig_row->mille;
		$mine = $this->find( $find_args );
		$max_plus = $mine;
		if ( is_object( $orig_row ) )
			$max_plus = max( $orig_row->state == $this->stati['i'][0] ? 0 : $orig_row->quantity, $mine );

		// determine the capacity for the event
		$zone = $this->get_zone_info( $args['zone_id'] );
		$capacity = is_object( $zone ) && isset( $zone->capacity ) ? $zone->capacity : 0;

		// adjust the requested quantity to be a maximum of the available capacity
		$args['orig_qty'] = $args['quantity'];
		$args['quantity'] = min( $args['quantity'], $capacity - $total_prior + $max_plus );

		// if there is no original row to update, then bail
		if ( ! is_object( $orig_row ) )
			return new WP_Error( 'no_reservations', __( 'Could not update your reservations.', 'qsot-seating' ) );

		// create a list of args to update the selected row to
		$update_args = apply_filters( 'qsot-seating-reserve-update-args', array(
			'state' => $this->stati['r'][0],
			'ticket_type_id' => $args['ticket_type_id'],
			'quantity' => ( $hard_limit <= 0 ) ? max( 0, $args['quantity'] ) : min( $hard_limit, max( 0, $args['quantity'] ) )
		), $args );

		// check if this amount can be added to the cart. could run into purchase limit
		$can_add_to_cart = apply_filters( 'qsot-can-add-tickets-to-cart', true, null, array_merge( $args, $update_args ) );

		// handle the response from our check
		// if there was an error, cleanup and bail
		if ( is_wp_error( $can_add_to_cart ) ) {
			$this->_remove_lock( $orig_row );
			return apply_filters( 'qsot-seating-zoner-reserve-results', $can_add_to_cart, $args );
		// if there was a fail but no error, then cleanup and bail
		} else if ( ! $can_add_to_cart ) {
			$this->_remove_lock( $orig_row );
			return apply_filters( 'qsot-seating-zoner-reserve-results', new WP_Error( 6, __( 'Could not reserve those tickets.', 'opentickets-community-edition' ) ), $args );
		// otherwise, $can_add_to_cart contains the maximal number of tickets the user can reserve
		} else {
			$update_args['quantity'] = min( $update_args['quantity'], $can_add_to_cart );
		}

		global $wpdb;

		// at this point we have identified an existing row to update and validated that we can update to a given number of tickets.
		// now update the record with our new reservation info, transforming it into the new reservation row for this user
		$res = $wpdb->update(
			$wpdb->qsot_event_zone_to_order,
			$update_args,
			(array)$orig_row
		);
		$args['final_qty'] = $update_args['quantity'];

		// also remove all rows before this new row, that match the new data
		$wpdb->query( $wpdb->prepare(
			'delete from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %s and order_id = %s and state in (%s, %s) and ( ( session_customer_id = %s and order_id = 0 ) or ( %d > 0 and order_id = %d ) ) '
					. 'and ticket_type_id = %s and zone_id = %s and since < %s',
			$args['event_id'],
			$args['order_id'],
			$this->stati['r'][0],
			$this->stati['c'][0],
			$args['customer_id'],
			$args['order_id'],
			$args['order_id'],
			$args['ticket_type_id'],
			$args['zone_id'],
			$orig_row->since
		) );

		return apply_filters( 'qsot-seating-zoner-reserve-results', $args['final_qty'], $args );
	}

	// remove a reservation based on specified criteria
	public function remove( $success, $args ) {
		// normalize input data
		$args = wp_parse_args( $args, array(
			'event_id' => false,
			'ticket_type_id' => '',
			'quantity' => '',
			'customer_id' => '',
			'order_id' => '',
			'order_item_id' => '',
			'zone_id' => '',
			'state' => '',
		) );

		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		$args['ticket_type_id'] = is_numeric( $args['ticket_type_id'] ) && $args['ticket_type_id'] > 0
				? $args['ticket_type_id']
				: ( is_object( $args['ticket_type_id'] ) && isset( $args['ticket_type_id']->id ) ? $args['ticket_type_id']->id : false );


		// if there is not at least the basic information and some level of specificity, then bail
		if ( $args['event_id'] <= 0 || ( ( empty( $args['customer_id'] ) || empty( $args['zone_id'] ) ) && empty( $args['order_id'] ) && empty( $args['order_item_id'] ) ) )
			return apply_filters( 'qsot-gaea-zoner-remove-results', new WP_Error( 'missing_data', __( 'Missing some or all required data.', 'opentickets-community-edition' ) ), $args );

		// find all matching records
		$records = $this->find( $args );

		global $wpdb;
		// delete all matching records
		foreach ( $records as $record )
			$wpdb->delete( $wpdb->qsot_event_zone_to_order, (array)$record );

		return apply_filters( 'qsot-seating-zoner-remove-results', true, $args );
	}

	// mark some reserved tickets as confirmed
	public function confirm( $success, $args ) {
		// normalize input data
		$args = wp_parse_args( $args, array(
			'event_id' => false,
			'ticket_type_id' => 0,
			'quantity' => 0,
			'customer_id' => '',
			'order_id' => '',
			'zone_id' => 0,
		) );

		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		$args['zone_id'] = is_numeric( $args['zone_id'] ) && $args['zone_id'] > 0 ? (int) $args['zone_id'] : ( is_object( $args['zone_id'] ) && isset( $args['zone_id']->id ) ? $args['zone_id']->id : '0' );
		$args['ticket_type_id'] = is_numeric( $args['ticket_type_id'] ) && $args['ticket_type_id'] > 0
				? $args['ticket_type_id']
				: ( is_object( $args['ticket_type_id'] ) && isset( $args['ticket_type_id']->id ) ? $args['ticket_type_id']->id : false );
		$args['quantity'] = max( 0, $args['quantity'] );
		$args['customer_id'] = empty( $args['customer_id'] ) ? $this->current_user() : $args['customer_id'];

		// find the matching reserved row
		$find_args = $args;
		$find_args['state'] = $this->stati['r'][0];
		$row = $this->find( $find_args );
		$row = current( $row );

		// if there is no matching row, then bail
		if ( empty( $row ) || ! is_object( $row ) )
			return apply_filters( 'qsot-seating-zoner-confirm-results', new WP_Error( 'missing_data', __( 'We could not find those reservations. Could not confirm them.', 'opentickets-community-edition' ) ), $args );

		global $wpdb;
		// otherwise, update the row to be confirmed
		$wpdb->update( $wpdb->qsot_event_zone_to_order, array( 'state' => $this->stati['c'][0] ), (array)$row );

		return apply_filters( 'qsot-seating-zoner-confirm-results', true, $args );
	}

	// mark some confirmed tickets as occupied
	public function occupy( $success, $args ) {
		// normalize input data
		$args = wp_parse_args( $args, array(
			'event_id' => false,
			'ticket_type_id' => 0,
			'quantity' => 1,
			'customer_id' => '',
			'order_id' => '',
			'order_item_id' => '',
			//'zone_id' => 0,
		) );
		// REMOVED zone_id BECAUSE IT MESSES UP CHECKIN

		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		//$args['zone_id'] = is_numeric( $args['zone_id'] ) && $args['zone_id'] > 0 ? (int) $args['zone_id'] : ( is_object( $args['zone_id'] ) && isset( $args['zone_id']->id ) ? $args['zone_id']->id : '0' );
		$args['ticket_type_id'] = is_numeric( $args['ticket_type_id'] ) && $args['ticket_type_id'] > 0
				? $args['ticket_type_id']
				: ( is_object( $args['ticket_type_id'] ) && isset( $args['ticket_type_id']->id ) ? $args['ticket_type_id']->id : false );
		$args['quantity'] = max( 0, intval( $args['quantity'] ) );

		// find the matching confirmed row
		$find_args = $args;
		unset( $find_args['quantity'] );
		$find_args['state'] = $this->stati['c'][0];
		$confirmed = $this->find( $find_args );
		$confirmed = current( $confirmed );

		// if there is no matching row, then bail
		if ( empty( $confirmed ) || ! is_object( $confirmed ) )
			return apply_filters( 'qsot-seating-zoner-occupy-results', new WP_Error( 'missing_data', __( 'We could not find those reservations. Could not check them in.', 'opentickets-community-edition' ), $args ), $args );

		// find matching occupied rows
		$find_args['state'] = $this->stati['o'][0];
		$occupied = $this->find( $find_args );
		$occupied = is_array( $occupied ) && count( $occupied ) ? current( $occupied ) : null;

		global $wpdb;
		// if there is no occupied row, then create an empty one for this record
		if ( null === $occupied ) {
			$occupied = clone $confirmed;
			$occupied->state = $this->stati['o'][0];
			$occupied->quantity = 0;
			$wpdb->insert( $wpdb->qsot_event_zone_to_order, (array) $occupied );
		}

		// update both rows with their new quantities. if the confirm row should have quantity 0, then remove it instead
		$wpdb->update( $wpdb->qsot_event_zone_to_order, array( 'quantity' => $occupied->quantity + $args['quantity'] ), (array)$occupied );
		$new_confirmed = $confirmed->quantity - $args['quantity'];
		if ( $new_confirmed <= 0 )
			$wpdb->delete( $wpdb->qsot_event_zone_to_order, (array)$confirmed );
		else
			$wpdb->update( $wpdb->qsot_event_zone_to_order, array( 'quantity' => $new_confirmed ), (array)$confirmed );

		return apply_filters( 'qsot-seating-zoner-occupy-results', true, $args );
	}

	// update reservation records based on supplied criteria
	public function update( $result, $args, $set ) {
		// normalize input data
		$args = wp_parse_args( $args, array(
			'event_id' => false,
			'ticket_type_id' => 0,
			'quantity' => '',
			'customer_id' => '',
			'order_id' => '',
			'order_item_id' => '',
			'zone_id' => 0,
			'state' => '',
			'where__extra' => '',
		) );

		$args['event_id'] = is_numeric( $args['event_id'] ) && $args['event_id'] > 0 ? (int) $args['event_id'] : ( is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : false );
		$args['zone_id'] = is_numeric( $args['zone_id'] ) && $args['zone_id'] > 0 ? (int) $args['zone_id'] : ( is_object( $args['zone_id'] ) && isset( $args['zone_id']->id ) ? $args['zone_id']->id : '' );
		$args['ticket_type_id'] = is_numeric( $args['ticket_type_id'] ) && $args['ticket_type_id'] > 0
				? $args['ticket_type_id']
				: ( is_object( $args['ticket_type_id'] ) && isset( $args['ticket_type_id']->id ) ? $args['ticket_type_id']->id : false );
		$args['quantity'] = $args['quantity'] ? max( 0, $args['quantity'] ) : $args['quantity'];
		$args['customer_id'] = empty( $args['customer_id'] ) ? '' : $args['customer_id'];

		// find the matching confirmed row
		$find_args = $args;
		$find_args['state'] = '' === $args['state'] ? $this->stati['r'][0] : $args['state'];
		$row = $this->find( $find_args );
		$row = current( $row );

		// if there is no matching row, then bail
		if ( empty( $row ) || ! is_object( $row ) )
			return apply_filters( 'qsot-seating-zoner-update-results', new WP_Error( 'missing_data', __( 'We could not find those reservations. No update made.', 'opentickets-community-edition' ), array( $args, $find_args ) ), $args );

		// normalize the supplied set data
		$data = array();
		foreach ( apply_filters( 'qsot-seating-update-valid-set-args', array( 'quantity', 'state', 'event_id', 'order_id', 'zone_id', 'order_item_id', 'ticket_type_id', 'session_customer_id', 'since' ), $set ) as $key )
			if ( isset( $set[ $key ] ) )
				$data[ $key ] = $set[ $key ];

		// if there is no data to set, then bail
		if ( empty( $data ) )
			return apply_filters( 'qsot-seating-zoner-update-results', new WP_Error( 'missing_data', __( 'There was nothing to update.', 'opentickets-community-edition' ) ), $args );

		global $wpdb;
		// update the row with the supplied data
		$q = 'update ' . $wpdb->qsot_event_zone_to_order . ' set ';
		$comma = '';
		foreach ( $data as $k => $v ) {
			if ( '[:NOW():]' == $v )
				$q .= $comma . $k . ' = NOW()';
			else
				$q .= $comma . $k . $wpdb->prepare( ' = %s', $v );
			$comma = ',';
		}
		$q .= ' where 1=1';
		foreach ( $row as $k => $v ) {
			if ( is_array( $v ) ) {
				$q .= ' and ' . $k . ' in  (\'' . implode( "','", array_map( 'esc_sql', $v ) ) . '\')';
			} else {
				$q .= ' and ' . $k . $wpdb->prepare( ' = %s', $v );
			}
		}
		$wpdb->query( $q );

		return apply_filters( 'qsot-seating-zoner-update-results', true, $args );
	}

	// find out how many tickets are available for a given event
	public function get_availability( $event, $event_area ) {
		// maintain an internal cache for this function, which definitely blows out after this request is over
		static $cache = array();

		// if the saught value is cached, then use it
		if ( isset( $cache[ $event->ID ] ) )
			return $cache[ $event->ID ];

		// otherwise, calculate and store it
		// start by grabbing the capacity
		$capacity = intval( isset( $event_area->meta['_capacity'] ) ? $event_area->meta['_capacity'] : 0 );

		// if there is no capacity, then there is an infinite number of tickets left, which we will cap at 1000000 at a time
		if ( $capacity <= 0 )
			return 1000000;

		// otherwise, lookup how manu have been taken so far
		$taken = $this->find( array( 'fields' => 'total', 'event_id' => $event->ID ) );

		return $capacity - $taken;
	}

	// get the information about a specific zone
	public function get_zone_info( $zone_id ) {
		$zone_id = (int)$zone_id;
		// first see if the data is cached
		$cache = wp_cache_get( $zone_id . ':zone', 'qsot-seating' );

		// if it is not cached, build the cache
		if ( ! is_object( $cache ) ) {
			global $wpdb;
			if ( $zone_id > 0 ) {
				// fetch teh basic zone information
				$cache = $wpdb->get_row( $wpdb->prepare( 'select * from ' . $wpdb->qsot_seating_zones . ' where id = %d', $zone_id ) );
			} else {
				$cache = (object)array(
					'id' => 0,
					'seating_chart_id' => 0,
					'name' => 'General Admission',
					'zone_type' => 1,
					'abbr' => 'GA',
					'capacity' => 0,
				);
			}
			// if there is such a zone
			if ( is_object( $cache ) ) {
				// load the meta data for the zone and attach it to the object
				$cache->meta = array();
				if ( $cache->id > 0 ) {
					$indexed = $this->get_indexed_zones_meta( array( $cache->id ) );
					$cache->meta = isset( $indexed[ $cache->id ] ) ? $indexed[ $cache->id ] : array();
				} else {
					$cache->meta = array();
				}
				// store the resulting object in cache
				wp_cache_set( $zone_id . ':zone', $cache, 'qsot-seating', 0 );
			}
			// if there was no zone fetched, then dont store any cache
		}

		// return the resulting object
		return $cache;
	}

	// get a list of all the meta for zones matching the supplied ids
	public function get_indexed_zones_meta( $ids ) {
		$found = null;
		$result = $needs_lookup = array();
		// first, fetch any meta list from cache for any ids that have a cache already, and compile a list of the zones without a cache
		foreach ( $ids as $id ) {
			$cache = wp_cache_get( $id . ':zmeta', 'qsot-seating', false, $found );
			if ( ( isset( $found ) && $found ) || ( ! isset( $found ) && false !== $cache ) )
				$result[ $id ] = $cache;
			else
				$needs_lookup[] = $id;
		}

		// if there are any zones that need their meta fetched, then grab all the meta for all the zones all at once, and index it appropriately
		if ( count( $needs_lookup ) ) {
			global $wpdb;
			$all_meta = $wpdb->get_results( 'select qsot_seating_zones_id, meta_key, meta_value from ' . $wpdb->qsot_seating_zonemeta . ' where qsot_seating_zones_id in( ' . implode( ',', $needs_lookup ) . ')' );

			$looked_up = array();
			// index the results by zone_id
			while ( $row = array_pop( $all_meta ) ) {
				if ( isset( $looked_up[ $row->qsot_seating_zones_id ] ) )
					$looked_up[ $row->qsot_seating_zones_id ][ $row->meta_key ] = $this->_maybe_json_decode( $row->meta_value );
				else
					$looked_up[ $row->qsot_seating_zones_id ] = array( $row->meta_key => $this->_maybe_json_decode( $row->meta_value ) );
				if ( 'hidden' == $row->meta_key )
					$looked_up[ $row->qsot_seating_zones_id ]['hidden'] = !!$row->meta_value ? '1' : '';
			}

			// update the meta caches for each of these zones
			// also, merge the recently looked up list with the already known list, for a final list. cannot use array_merge() because these are numerical keys
			// do this using the least memory intensive method, while loop + unset
			while ( count( $looked_up ) ) {
				// get zone_id and meta
				reset( $looked_up );
				$zone_id = key( $looked_up );
				$meta = current( $looked_up );
				unset( $looked_up[ $zone_id ] );

				// update the local cache
				wp_cache_set( $zone_id . ':zmeta', $meta, 'qsot-seating', 3600 );

				// fill the results
				$result[ $zone_id ] = $meta;
			}
		}

		return $result;
	}

	// get a list of all the zone idss for a seating chart, either regular zones, zoom zones, or both
	public function get_zone_ids( $args='' ) {
		// normalize the input data
		$args = wp_parse_args( $args, array(
			'event_area_id' => 0,
			'type' => '*',
		) );
		$args['event_area_id'] = is_scalar( $args['event_area_id'] ) ? intval( $args['event_area_id'] ) : 0;
		$args['type'] = is_numeric( $args['type'] ) || '*' == $args['type'] ? $args['type'] : '*';

		$chart_id = $args['event_area_id'];
		$type = $args['type'];

		// determine the cache key
		$key = $chart_id . ':zids';

		$found = null;
		// attempt to load the list from cache
		$cache = wp_cache_get( $key, 'qsot-seating', false, $found );

		// if the cache was not found, then generate it now
		if ( false === $found || ( null === $found && false === $cache ) ) {
			global $wpdb;
			$q = $wpdb->prepare( 'select id, zone_type from ' . $wpdb->qsot_seating_zones . ' where seating_chart_id = %d ', $chart_id );
			$res = $wpdb->get_results( $q );

			$cache = array();
			// index the list by zone type
			while ( $row = array_pop( $res ) ) {
				if ( isset( $cache[ $row->zone_type ] ) )
					$cache[ $row->zone_type ][] = $row->id;
				else
					$cache[ $row->zone_type ] = array( $row->id );
			}

			// update the cache
			wp_cache_set( $key, $cache, 'qsot-seating', 3600 );
		}

		// if they are asking for all types, then compile a complete list and return it
		if ( '*' == $type ) {
			$final = array();
			// use least memory intensive method, while + unset
			while ( count( $cache ) ) {
				// get the zones for this type
				reset( $cache );
				$zone_type = key( $cache );
				$zone_ids = current( $cache );
				unset( $cache[ $zone_type ] );

				// combine this sub-list with the main list
				$final = array_merge( $final, $zone_ids );
			}
			return $final;
		}

		// otherwise, only return the list of zones that was requested, if they exist
		return apply_filters( 'qsot-get-seating-zone-ids', isset( $cache[ $type ] ) ? $cache[ $type ] : array(), $args, $cache );
	}

	// get a list of all the zones for a seating chart, either regular zones, zoom zones, or both
	public function get_zones( $args='' ) {
		// normalize the input data
		$args = wp_parse_args( $args, array(
			'event' => null,
			'event_area_id' => 0,
			'type' => '*',
		) );
		$args['event_area_id'] = is_scalar( $args['event_area_id'] ) ? intval( $args['event_area_id'] ) : 0;
		$args['type'] = is_numeric( $args['type'] ) || '*' == $args['type'] ? $args['type'] : '*';
		if ( $args['event_area_id'] <= 0 && ! empty( $args['event'] ) ) {
			$event_id = is_scalar( $args['event'] ) ? intval( $args['event'] ) : ( is_object( $args['event'] ) && isset( $args['event']->ID ) ? $args['event']->ID : 0 );
			$args['event_area_id'] = $event_id > 0 ? get_post_meta( $event_id, '_event_area_id', true ) : 0;
		}

		$seating_chart_id = $args['event_area_id'];
		$type = $args['type'];

		// if there is no seating chart id, then bail
		if ( empty( $seating_chart_id ) )
			return array();;

		// attempt to fetch the seating zone info from cache
		$cacher = QSOT_Seating_Cacher::instance();
		$cache = $cacher->get( 'all-zone-' . $type . '-data', 'total-zones-' . $seating_chart_id );

		// if the cache was not fetched or empty, then regen
		if ( empty( $cache ) ) {
			// fetch a list of all the zones of the supplied type for this chart
			$cache = $this->_get_zones_from_seating_chart( $seating_chart_id, $type );

			$ids = array();
			// extrapolate the list of ids of zones without meta
			foreach ( $cache as $zone )
				if ( ! isset( $zone->meta ) || empty( $zone->meta ) )
					$ids[] = $zone->id;

			// if there are zones in the list with no set meta, then fetch all the meta for those zones
			if ( count( $ids ) ) {
				$indexed_meta = $this->get_indexed_zones_meta( $ids );
				foreach ( $indexed_meta as $zone_id => $meta )
					$cache[ $zone_id ]->meta = $meta;
			}

			$cacher->set( 'all-zone-' . $type . '-data', $cache, 'total-zones-' . $seating_chart_id, 3600, true );
		}

		return apply_filters( 'qsot-get-seating-zones', $cache, $args );
	}
	
	// delete zone meta based on a list of wheres
	protected function _delete_zone_meta( &$where ) {
		global $wpdb;
		return $wpdb->query( 'delete from ' . $wpdb->qsot_seating_zonemeta . ' where 0 = 1 ' . $where );
	}
	
	// delete zones based on a list of wheres
	protected function _delete_zones( &$where ) {
		global $wpdb;
		return $wpdb->query( 'delete from ' . $wpdb->qsot_seating_zones . ' where 0 = 1 ' . $where );
	}

	// update zone meta based on case statement
	protected function _update_zone_meta( &$statements ) {
		global $wpdb;
		// figure out the current autocommit status
		$current = $wpdb->get_var( $wpdb->prepare( 'show variables like %s', 'autocommit' ), 1, 0 );

		// override that status
		$wpdb->query( $wpdb->prepare( 'SET autocommit = %s', 'off' ) );

		// create a transaction with all the updates
		$wpdb->query( 'start transaction' );
		foreach ( $statements as $stmt )
			$wpdb->query( $stmt );

		// run the transaction
		$res = $wpdb->query( 'commit' );

		// restore the original autocommit state
		$wpdb->query( $wpdb->prepare( 'SET autocommit = %s', $current ) );
		return $res;
	}

	// update zones based on complex update matrix
	protected function _update_zones( &$updates ) {
		// normalize the keys
		$ids = trim( $updates['ids'], ',' );
		unset( $updates['ids'] );
		$updates['name'] = ! empty( $updates['name'] ) ? 'name = case ' . $updates['name'] . ' end' : '';
		$updates['abbr'] = ! empty( $updates['abbr'] ) ? 'abbr = case ' . $updates['abbr'] . ' end' : '';
		$updates['capacity'] = ! empty( $updates['capacity'] ) ? 'capacity = case ' . $updates['capacity'] . ' end' : '';
		$updates = implode( ', ', array_filter( array_values( $updates ) ) );

		// if there are no updates, bail
		if ( empty( $updates ) )
			return false;

		global $wpdb;
		// run the udpate
		return $wpdb->query( "update {$wpdb->qsot_seating_zones} set {$updates} where id in ( {$ids} )" );
	}

	// insert zone meta based on values
	protected function _insert_zone_meta( &$values ) {
		global $wpdb;
		return $wpdb->query( 'insert into ' . $wpdb->qsot_seating_zonemeta . ' ( qsot_seating_zones_id, meta_key, meta_value ) values ' . $values );
	}

	// insert the new zones and their metas
	protected function _insert_new_zones_and_meta( &$inserts_new, &$meta_inserts_new, $seating_chart_id ) {
		global $wpdb;
		// insert all the new zones now
		$q = 'insert into ' . $wpdb->qsot_seating_zones . ' (`abbr`,`name`,`capacity`,`seating_chart_id`,`zone_type`) values ' . rtrim( $inserts_new, ',' );
		$wpdb->query( $q );

		// grab a list of all the zones we just inserted
		$abbr_list = "'" . implode( "','", array_keys( $meta_inserts_new ) ) . "'";
		$q = $wpdb->prepare( 'select abbr, id from ' . $wpdb->qsot_seating_zones . ' where seating_chart_id = %d and abbr in (', $seating_chart_id ) . $abbr_list . ')';
		$raw_list = $wpdb->get_results( $q );
		$list_map = array();
		while ( $row = array_pop( $raw_list ) )
			$list_map[ $row->abbr ] = $row->id;

		$at_least_one = false;
		// now make all the meta inserts
		$q = 'insert into ' . $wpdb->qsot_seating_zonemeta . ' ( qsot_seating_zones_id, meta_key, meta_value ) values ';
		foreach ( $meta_inserts_new as $abbr => $sql ) {
			if ( isset( $list_map[ $abbr ] ) ) {
				$q .= str_replace( '%NEW_ZONE_ID%', $list_map[ $abbr ], $sql );
				$at_least_one = true;
			}
		}
		if ( $at_least_one )
			$wpdb->query( rtrim( $q, ',' ) );

		return $list_map;
	}

	// update all the zones for a seating chart, based on the supplied data
	public function update_zones( $seating_chart_id, $zones, $type=1 ) {
		// normalize input data
		if ( is_object( $zones ) )
			$zones = array( $zones );

		// if there are no zones, then bail
		if ( ! is_array( $zones ) )
			return false;

		global $wpdb;

		$new_map = $abbr_to_temp_id = array();
		$next_insert_meta = $deletes = $meta_deletes = $meta_updates = $meta_updates_ids = $meta_inserts = $next_inserts_new = $inserts_new = '';
		$meta_updates = array();
		$meta_inserts_new = array();
		$updates = array( 'name' => '', 'abbr' => '', 'capacity' => '', 'ids' => '' );
		$deletes_len = $meta_deletes_len = $meta_updates_len = $meta_inserts_len = $updates_len = $inserts_new_len = $meta_inserts_new_len = 0;

		$ids = $all_meta_keys = $meta_keys = array();
		// get a list of zone ids that were submitted
		foreach ( $zones as $__ => $zone )
			if ( isset( $zone->id ) )
				$ids[] = $zone->id;
		$ids = array_filter( array_map( 'absint', $ids ) );

		// get a complete list of all keys that are for all zones in the submitted list
		$all_meta_keys = count( $ids ) ? $wpdb->get_results( 'select * from ' . $wpdb->qsot_seating_zonemeta . ' where qsot_seating_zones_id in ( ' . implode( ',', $ids ) . ' )' ) : array();

		// index all meta keys by zone_id
		while ( $row = array_pop( $all_meta_keys ) ) {
			// if there is no list for this zone_id yet, then make one
			$meta_keys[ $row->qsot_seating_zones_id ] = isset( $meta_keys[ $row->qsot_seating_zones_id ] ) ? $meta_keys[ $row->qsot_seating_zones_id ] : array();
			$meta_keys[ $row->qsot_seating_zones_id ][ $row->meta_key ] = 1;
		}

		// find the max packet we are allowed to send, to the 90% mark
		$max_packet = floor( QSOT::max_packet() * 0.75 );

		// cycle throught he submitted list of zones
		while ( count( $zones ) ) {
			reset( $zones );
			$key = key( $zones );
			$zone = current( $zones );
			unset( $zones[ $key ] );

			// reset the new data collectors
			$new_deletes = $new_meta_deletes = $new_meta_updates_ids = $new_meta_inserts = $new_inserts_new = $new_meta_inserts_new = '';
			$new_updates = $new_meta_updates = array();
			$new_updates = array( 'name' => '', 'abbr' => '', 'capacity' => '', 'ids' => '' );
			$new_deletes_len = $new_meta_deletes_len = $new_meta_updates_len = $new_meta_inserts_len = $new_updates_len = $new_inserts_new_len = $new_meta_inserts_new_len = 0;

			// if this is a zone update or delete
			if ( isset( $zone->id ) ) {
				if ( isset( $zone->_delete ) && $zone->_delete ) {
					$new_deletes .= $wpdb->prepare( ' or ( id = %d )', $zone->id );
					$new_meta_deletes .= $wpdb->prepare( ' or ( qsot_seating_zones_id = %d )', $zone->id );
				} else {
					$existing_keys = isset( $meta_keys[ $zone->id ] ) ? $meta_keys[ $zone->id ] : array();

					$new_updates['name'] .= $wpdb->prepare( ' when id = ' . $zone->id . ' then %s', $zone->name );
					$new_updates['abbr'] .= $wpdb->prepare( ' when id = ' . $zone->id . ' then %s', $zone->abbr );
					$new_updates['capacity'] .= $wpdb->prepare( 'when id = ' . $zone->id . ' then %s', $zone->capacity );
					$new_updates['ids'] .= ','.$zone->id;
					$meta_case = '';

					foreach ( $zone->meta as $k => $v ) {
						if ( strlen( $v ) ) {
							if ( isset( $existing_keys[ $k ] ) ) {
								$existing_keys[ $k ] = 1;
								$meta_case .= $wpdb->prepare( ' when meta_key = %s then %s', $k, $v );
							} else {
								$new_meta_inserts .= $wpdb->prepare( $next_insert_meta . '(%d, %s, %s)', $zone->id, $k, $v );
								$next_insert_meta = ',';
							}
						} else {
							$new_meta_deletes .= $wpdb->prepare( ' or ( qsot_seating_zones_id = %d and meta_key = %s )', $zone->id, $k );
						}
					}
					$meta_case = trim( $meta_case );
					if ( ! empty( $meta_case ) )
						$new_meta_updates[] = 'update ' . $wpdb->qsot_seating_zonemeta . ' set meta_value = case ' . $meta_case . ' else meta_value end ' . $wpdb->prepare( 'where qsot_seating_zones_id = %d', $zone->id );

					foreach ( $existing_keys as $k => $used ) {
						if ( $used ) continue;
						$new_meta_deletes .= $wpdb->prepare( ' or ( qsot_seating_zones_id = %d and meta_key = %s )', $zone->id, $k );
					}
				}

				// get the new strlens of all the new data
				$new_deletes_len = strlen( $new_deletes );
				$new_meta_deletes_len = strlen( $new_meta_deletes );
				$new_meta_updates_len = strlen( implode( '', $new_meta_updates ) );
				$new_meta_inserts_len = strlen( $new_meta_inserts );
				$new_updates_len = strlen( $new_updates['ids'] ) + strlen( $new_updates['name'] ) + strlen( $new_updates['abbr'] ) + strlen( $new_updates['capacity'] );

				// if the meta deletes are reaching their max packet size, do them now
				if ( $meta_deletes_len + $new_meta_deletes_len > $max_packet ) {
					$this->_delete_zone_meta( $meta_deletes );
					$meta_deletes = $new_meta_deletes;
					$meta_deletes_len = $new_meta_deletes_len;
				} else {
					$meta_deletes .= $new_meta_deletes;
					$meta_deletes_len += $new_meta_deletes_len;
				}

				// if the zone deletes are reaching their max packet size, do them now
				if ( $deletes_len + $new_deletes_len > $max_packet ) {
					$this->_delete_zones( $deletes );
					$deletes = $new_deletes;
					$deletes_len = $new_deletes_len;
				} else {
					$deletes .= $new_deletes;
					$deletes_len += $new_deletes_len;
				}

				// if the meta updates are reaching their max packet size, do them now
				if ( $meta_updates_len + $new_meta_updates_len > $max_packet ) {
					$this->_update_zone_meta( $meta_updates );
					$meta_updates = $new_meta_updates;
					$meta_updates_ids = $new_meta_updates_ids;
					$meta_updates_len = $new_meta_updates_len;
				} else {
					$meta_updates = array_merge( $meta_updates, $new_meta_updates );
					$meta_updates_ids .= $new_meta_updates_ids;
					$meta_updates_len += $new_meta_updates_len;
				}

				// if the meta inserts are reaching their max packet size, do them now
				if ( $meta_inserts_len + $new_meta_inserts_len > $max_packet ) {
					$this->_insert_zone_meta( $meta_inserts );
					$meta_inserts = $new_meta_inserts;
					$meta_inserts_len = $new_meta_inserts_len;
				} else {
					$meta_inserts .= $new_meta_inserts;
					$meta_inserts_len += $new_meta_inserts_len;
				}

				if ( $updates_len + $new_updates_len > $max_packet * 0.9 ) {
					$this->_update_zones( $updates );
					$updates = $new_updates;
					$updates_len = $new_updates_len;
				} else {
					$updates['ids'] .= $new_updates['ids'];
					$updates['name'] .= $new_updates['name'];
					$updates['abbr'] .= $new_updates['abbr'];
					$updates['capacity'] .= $new_updates['capacity'];
					$updates_len += $new_updates_len;
				}
			// otherwise it is an insert
			} else {
				$abbr_to_temp_id[ $zone->abbr ] = $key;

				// aggregate the new insert row
				$new_inserts_new = $wpdb->prepare( '(%s,%s,%d,%d,%s),', $zone->abbr, $zone->name, $zone->capacity, $seating_chart_id, $type );
				$new_inserts_new_len = strlen( $new_inserts_new );

				// aggregate a list of the new meta that needs inserting for this new row
				foreach ( $zone->meta as $k => $v )
					$new_meta_inserts_new .= '(%NEW_ZONE_ID%,' . $wpdb->prepare( '%s,%s),', $k, $this->_maybe_json_encode( $v ) );
				$new_meta_inserts_new_len = strlen( $new_meta_inserts_new );

				// if it is time to do either insert, then do it now
				if ( ( $inserts_new_len + $new_inserts_new_len > $max_packet ) || ( $meta_inserts_new_len + $new_meta_inserts_new_len > $max_packet) ) {
					// insert the new zones and thier meta
					$abbr_to_new_id = $this->_insert_new_zones_and_meta( $inserts_new, $meta_inserts_new, $seating_chart_id );

					// update the new zone map $temp_id => $new_id
					while ( count( $abbr_to_new_id ) ) {
						reset( $abbr_to_new_id );
						$abbr = key( $abbr_to_new_id );
						$new_id = current( $abbr_to_new_id );
						unset( $abbr_to_new_id[ $abbr ] );
						$temp_id = isset( $abbr_to_temp_id[ $abbr ] ) ? $abbr_to_temp_id[ $abbr ] : null;
						if ( null !== $temp_id )
							$new_map[ $temp_id ] = $new_id;
					}

					// now reset all counters and lists to that of the new items
					$inserts_new = $new_inserts_new;
					$inserts_new_len = $new_inserts_new_len;
					$meta_inserts_new = array( $zone->abbr => $new_meta_inserts_new );
					$meta_inserts_new_len = $new_meta_inserts_new_len;
				// otherwise add to the list of inserts to do
				} else {
					$inserts_new .= $new_inserts_new;
					$inserts_new_len += $new_inserts_new_len;
					$meta_inserts_new[ $zone->abbr ] = $new_meta_inserts_new;
					$meta_inserts_new_len += $new_meta_inserts_new_len;
				}
			}
		}

		// if there are still changes to be made, then do them now
		if ( ! empty( $meta_deletes ) )
			$this->_delete_zone_meta( $meta_deletes );
		if ( ! empty( $deletes ) )
			$this->_delete_zones( $deletes );
		if ( ! empty( $meta_updates ) )
			$this->_update_zone_meta( $meta_updates );
		if ( ! empty( $meta_inserts ) )
			$this->_insert_zone_meta( $meta_inserts );
		if ( ! empty( $updates['ids'] ) )
			$this->_update_zones( $updates );
		if ( ! empty( $inserts_new ) ) {
			// insert the new zones and thier meta
			$abbr_to_new_id = $this->_insert_new_zones_and_meta( $inserts_new, $meta_inserts_new, $seating_chart_id );

			// update the new zone map $temp_id => $new_id
			while ( count( $abbr_to_new_id ) ) {
				reset( $abbr_to_new_id );
				$abbr = key( $abbr_to_new_id );
				$new_id = current( $abbr_to_new_id );
				unset( $abbr_to_new_id[ $abbr ] );
				$temp_id = isset( $abbr_to_temp_id[ $abbr ] ) ? $abbr_to_temp_id[ $abbr ] : null;
				if ( null !== $temp_id )
					$new_map[ $temp_id ] = $new_id;
			}
		}

		return $new_map;
	}

	// get a list of the seating zone ids for a given seating chart
	protected function _get_zones_from_seating_chart( $chart_id, $type='*' ) {
		// determine the cache key
		$key = $chart_id . ':zones';

		$found = null;
		// attempt to load the list from cache
		$cache = wp_cache_get( $key, 'qsot-seating', false, $found );

		// if the local cache is empty, then try to fetch it from our seatign cache table
		if ( false === $found || ( null === $found && false == $cache ) ) {
			$cacher = QSOT_Seating_Cacher::instance();
			$cache = $cacher->get( 'chart-' . $chart_id, 'zones' );

			// if there is a cache there, then update our local cache
			if ( false !== $cache ) {
				$found = true;
				wp_cache_set( $key, $cache, 'qsot-seating', 3600 );
			// otherwise reset, and force the regen below
			} else {
				$cache = false;
				$found = false;
			}
		}

		// if the cache was not found, then generate it now
		if ( false === $found || ( null === $found && false == $cache ) ) {
			global $wpdb;
			$q = $wpdb->prepare( 'select * from ' . $wpdb->qsot_seating_zones . ' where seating_chart_id = %d ', $chart_id );
			$res = $wpdb->get_results( $q );

			$cache = array();
			// index the list by zone type
			while ( $row = array_pop( $res ) ) {
				if ( isset( $cache[ $row->zone_type ] ) )
					$cache[ $row->zone_type ][ '' . $row->id ] = $row;
				else
					$cache[ $row->zone_type ] = array( '' . $row->id => $row );
			}

			// if there was a value fetched, then store it for later reuse
			if ( ! empty( $cache ) ) {
				// update the local cache
				wp_cache_set( $key, $cache, 'qsot-seating', 3600 );

				// update the seating cacher cache
				$cacher = QSOT_Seating_Cacher::instance();
				$cacher->set( 'chart-' . $chart_id, $cache, 'zones', 3600, true );
			}
		}

		// if they are asking for all types, then compile a complete list and return it
		if ( '*' == $type ) {
			$final = array();
			// cannot use array_merge() here, because these are associative numberic keys
			// use least memory intensive method, while + unset
			while ( count( $cache ) ) {
				// get the zones for this type
				reset( $cache );
				$zone_type = key( $cache );
				$zones = current( $cache );
				unset( $cache[ $zone_type ] );

				// add each zone from this sub list, to the resulting list
				// use while + unset
				while ( count( $zones ) ) {
					reset( $zones );
					$zone_id = key( $zones );
					$zone = current( $zones );
					unset( $zones[ $zone_id ] );

					$final[ $zone_id ] = $zone;
				}
			}
			return $final;
		}

		// otherwise, only return the list of zones that was requested, if they exist
		return isset( $cache[ $type ] ) ? $cache[ $type ] : array();
	}

	// method to get the availability of a zone for an event
	public function get_event_zone_availability( $args='' ) {
		// normalize the input data
		$args = wp_parse_args( $args, array(
			'event_id' => 0,
			'zone_id' => 0,
			'ticket_type_id' => 0,
			'all_states' => false,
		) );
		$args['event_id'] = is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : $args['event_id'];

		// if any of the required information is missing, then bail
		if ( ! is_numeric( $args['event_id'] ) || $args['event_id'] <= 0 )
			return new WP_Error( 'no_such_event', __( 'Could not find that event.', 'qsot-seating' ) );
		if ( ! is_numeric( $args['zone_id'] ) || $args['zone_id'] <= 0 )
			return new WP_Error( 'no_such_zone', __( 'That zone does not belong to this event.', 'qsot-seating' ) );

		// make sure that the given zone is part of the supplied event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $args['event_id'] );
		if ( ! is_object( $event_area ) || is_wp_error( $event_area ) )
			return new WP_Error( 'no_such_event', __( 'Could not load that event.', 'qsot-seating' ), $event_area );
		$zone = $this->get_zone_info( $args['zone_id'] );
		if ( $zone->seating_chart_id != $event_area->ID )
			return new WP_Error( 'no_such_zone', __( 'That zone does not belong to this event.', 'qsot-seating' ) );

		// get all reservations for the given zone
		$find_args = array( 'event_id' => $args['event_id'], 'zone_id' => $args['zone_id'], 'fields' => 'total-zone' );
		if ( ! $args['all_states'] )
			$find_args['state'] = array( $this->stati['r'][0], $this->stati['c'][0], $this->stati['o'][0] );
		$all = $this->find( $find_args );
		$all = isset( $all[ $args['zone_id'] ] ) ? $all[ $args['zone_id'] ] : 0;

		return $zone->capacity - $all;
	}

	// determine if the given event-zone has enough availability to handle the given request
	public function is_event_zone_available( $args='' ) {
		// normalize the input data
		$args = wp_parse_args( $args, array(
			'event_id' => 0,
			'zone_id' => 0,
			'ticket_type_id' => 0,
			'quantity' => 0,
			'method' => 'reserve',
		) );
		$args['event_id'] = is_object( $args['event_id'] ) && isset( $args['event_id']->ID ) ? $args['event_id']->ID : $args['event_id'];

		// if any of the required information is missing, then bail
		if ( ! is_numeric( $args['event_id'] ) || $args['event_id'] <= 0 )
			return new WP_Error( 'no_such_event', __( 'Could not find that event.', 'qsot-seating' ) );
		if ( ! is_numeric( $args['zone_id'] ) || $args['zone_id'] <= 0 )
			return new WP_Error( 'no_such_zone', __( 'That zone does not belong to this event.', 'qsot-seating' ) );
		if ( 'remove' !== $args['method'] && ( ! is_numeric( $args['quantity'] ) || $args['quantity'] <= 0 ) )
			return new WP_Error( 'no_quantity', __( 'The quantity must be greater than zero.', 'qsot-seating' ) );

		// make sure that the given zone is part of the supplied event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $args['event_id'] );
		if ( ! is_object( $event_area ) || is_wp_error( $event_area ) )
			return new WP_Error( 'no_such_event', __( 'Could not load that event.', 'qsot-seating' ), $event_area );
		$zone = $this->get_zone_info( $args['zone_id'] );
		if ( $zone->seating_chart_id != $event_area->ID )
			return new WP_Error( 'no_such_zone', __( 'That zone does not belong to this event.', 'qsot-seating' ) );

		// do something different, based on the method specified
		switch ( $args['method'] ) {
			case 'reserve':
			case 'interest':
				// find out how many reservations there are for this zone already
				$all = $this->find( array(
					'event_id' => $args['event_id'],
					'zone_id' => $args['zone_id'],
					'state' => array( $this->stati['r'][0], $this->stati['c'][0], $this->stati['o'][0] ),
					'fields' => 'total',
				) );

				// also find out how many of those are for the current user
				$mine = $this->find( array(
					'event_id' => $args['event_id'],
					'zone_id' => $args['zone_id'],
					'state' => '*',
					'fields' => 'total',
				) );

				// if the total number of requested seats is higher than the capacity, then error out
				if ( $zone->capacity < $all - $mine + $args['quantity'] )
					return new WP_Error(
						'not_enough_tickets',
						__( 'There are not enough tickets available for that zone.', 'qsot-seating' ),
						array(
							'max' => max( 0, $zone->capacity - $all + $mine ),
							'c' => $zone->capacity,
							'a' => $all - $mine + $args['quantity'],
							'q' => $args['quantity'],
							'f' => $this->find( array( 'event_id' => $args['event_id'], 'zone_id' => $args['zone_id'], 'state' => array( $this->stati['r'][0], $this->stati['c'][0], $this->stati['o'][0] ), 'fields' => 'objects' ) ),
							'z' => $args,
						)
					);
			break;

			case 'remove':
			break;

			default:
				return new WP_Error( 'no_such_method', __( 'The context you supplied was not valid.', 'qsot-seating' ) );
			break;
		}

		return true;
	}

	public function _maybe_json_encode($data) {
		return @json_encode($data);
	}

	public function _maybe_json_decode($str) {
		$d = @json_decode($str);
		return $d !== null || $str === 'null' ? $d : $str;
	}

	// 1.0.1 has an upgrade to table indexes, which core wp DB upgrader does not handle very well. this function does a pre-update that prevents the problem
	public function version_1_0_1_upgrade() {
		global $wpdb;

		// list of indexes to drop
		$indexes_by_table = array(
			$wpdb->qsot_seating_zones => array( 'sc_id' ),
			$wpdb->qsot_seating_zonemeta => array( 'et_id', 'mk' ),
			$wpdb->qsot_price_structs => array( 'psid', 'ea' ),
			$wpdb->qsot_price_struct_prices => array( 'ps2p', 'pid', 'sb_id' ),
		);
		$tables = $wpdb->get_col( 'show tables' );
		$tables = array_combine( $tables, array_fill( 0, count( $tables ), 1 ) );

		// for each table with indexes to drop
		foreach ( $indexes_by_table as $table => $indexes ) {
			// if the table exists
			if ( isset( $tables[ $table ] ) ) {
				// foreach index on that table
				foreach ( $indexes as $index ) {
					// if the index exists
					$exists = $wpdb->get_row( $wpdb->prepare( 'show index from ' . $table . ' where Key_name = %s', $index ) );
					if ( $exists ) {
						// drop it
						$q = 'alter ignore table ' . $table . ' drop index `' . $index . '`';
						$r = $wpdb->query( $q );
					}
				}
			}
		}
	}

	// setup the table names used by the general admission area type, for the current blog
	public function setup_table_names() {
		global $wpdb;
		$wpdb->qsot_seating_zones = $wpdb->prefix . 'qsot_seating_zones';
		$wpdb->qsot_seating_zonemeta = $wpdb->prefix . 'qsot_seating_zonemeta';
	}

	public function setup_tables( $tables ) {
    global $wpdb;
		
		// skip this if the func is called before the needed vars are set yet (like in a late OTCE activation)
		if ( ! isset( $wpdb->qsot_event_zone_to_order ) )
			return $tables;

		// if the opentickets plugin is at a version before we improved the db updater, then run the upgrae manually
		if ( class_exists( 'QSOT' ) && version_compare( QSOT::version(), '1.10.6' ) <= 0 ) {
			// maybe remove index if structs table is out of date, since the unique key gets updated. unfortunately this is not handled gracefully in wp.... yet
			$versions = get_option( '_qsot_upgrader_db_table_versions', array() );
			if ( ! isset( $versions[ $wpdb->qsot_price_struct_prices ] ) || version_compare( $versions[ $wpdb->qsot_price_struct_prices ], '0.1.6' ) < 0 )
				$this->version_1_0_1_upgrade();
		}

		// add a field and key to the primary plugin table
		$tables[ $wpdb->qsot_event_zone_to_order ]['version'] .= '.1';
		$tables[ $wpdb->qsot_event_zone_to_order ]['fields']['zone_id'] = array( 'type' => 'bigint(20) unsigned', 'default' => '0' ); // the id of the zone this reservation is for
		$tables[ $wpdb->qsot_event_zone_to_order ]['keys'][] = 'KEY z_id (zone_id)';

		// define the zones table
    $tables[ $wpdb->qsot_seating_zones ] = array(
      'version' => '0.2.0',
      'fields' => array(
        'id' => array( 'type' => 'bigint(20) unsigned', 'extra' => 'auto_increment' ),
        'seating_chart_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // post of type qsot-seating
        'name' => array( 'type' => 'varchar(100)' ),
        'zone_type' => array( 'type' => 'tinyint(3)', 'default' => '1' ), // 1 = QSOT_Seating_Area_Type::ZONES; 2 = QSOT_Seating_Area_Type::ZOOM_ZONES
        'abbr' => array( 'type' => 'varchar(100)' ),
				'capacity' => array( 'type' => 'int(10) unsigned', 'default' => '0' )
      ),
      'keys' => array(
        'PRIMARY KEY  (id)',
        'KEY sc_id (seating_chart_id)',
      ),
			'pre-update' => array(
				'when' => array(
					'version <' => array(
						'1.0.1' => array( __CLASS__, 'version_1_0_1_upgrade' ),
					),
				),
			),
    );

		// define the zone meta table
    $tables[ $wpdb->qsot_seating_zonemeta ] = array(
      'version' => '0.2.0',
      'fields' => array(
        'meta_id' => array( 'type' => 'bigint(20) unsigned', 'extra' => 'auto_increment' ),
        'qsot_seating_zones_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ),
        'meta_key' => array( 'type' => 'varchar(255)' ),
        'meta_value' => array( 'type' => 'text' ),
      ),
      'keys' => array(
        'PRIMARY KEY  (meta_id)',
        'KEY et_id (qsot_seating_zones_id)',
        'KEY mk (meta_key)',
      ),
			'pre-update' => array(
				'when' => array(
					'version <' => array(
						'1.0.1' => array( __CLASS__, 'version_1_0_1_upgrade' ),
					),
				),
			),
    );

    return $tables;
	}

	// setup the options for allowing timers to be set
	protected function _post_initialize_setup_options() {
		// the the plugin settings object
		$options = QSOT_Options::instance();

		// setup the default values, based on the default timers already established
		$options->def( 'qsot-interest-state-timer', $this->stati['i'][4] );

		// update db option if it was reset to a blank value
		if ( '' === get_option( 'qsot-interest-state-timer', '' ) )
			update_option( 'qsot-interest-state-timer', $this->stati['i'][4] );

		// Interest timer
		$options->add( array(
			'order' => 504, 
			'id' => 'qsot-interest-state-timer',
			'default' => $options->{'qsot-interest-state-timer'},
			'type' => 'text',
			'title' => __( '"Interest" timer (in seconds)', 'opentickets-community-edition' ),
			'desc' => __( 'The maximum length of time between the moment the user selects seat/zone, until the moment they choose a quantity and price to pay for that selection. If this time expires before the user chooses the quantity and price, then the ticket will be released back into the ticket pool.', 'opentickets-community-edition' ),
			'page' => 'general',
			'section' => 'reservations',
		) ); 
	}
}
