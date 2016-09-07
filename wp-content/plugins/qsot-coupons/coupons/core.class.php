<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the core coupons plugin. loads the basic stuff we need for the entire qsot-coupons plugin to function
class qsot_coupons_core {
	// none of the events in the cart are valid for this coupon
	const E_QSOT_NO_ITEM_FOR_VALID_EVENTS = 21;
	// none of the tickets in the cart keep us below the established coupon event limits
	const E_QSOT_NOT_UNDER_EVENT_LIMIT = 22;

	// tally of coupon usages within the current cart. for use in the is_valid_for_product function
	protected static $tally = array();

	// keep track of final validity tallies
	protected static $valid_for = array(
		'by-coupon' => array(),
		'coupon-count' => array(),
		'by-item' => array(),
		'code-event' => array(),
	);

	// order coupon usages, used to prevent admin-order coupon adds from returning false negatives on coupon validity, based on usage per event
	protected static $order_usages = array();
	protected static $orig_order_usages = array();
	protected static $order_usages_by_event = array();

	// setup the class's functionality
	public static function pre_init() {
		add_action( 'qsot-box-office-activation', array( __CLASS__, 'on_activation' ) );

		// when a coupon is loaded, load all our additional coupon settings with it
		add_action( 'woocommerce_coupon_loaded', array( __CLASS__, 'load_coupon' ), 10000, 1 );

		// validate the coupon is for the events selected, and that it is not past the event limit
		add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'valid_coupon' ), 1, 2 );

		// upon custom coupon error, attempt to use one of our plugin's error messages
		add_filter( 'woocommerce_coupon_error', array( __CLASS__, 'custom_coupon_errors' ), 10000, 3 );

		// when checking if a coupon is valid for a specific item in the cart, we need to factor in our coupon logic too
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'is_ticket_for_coupon_event' ), 10000, 4 );
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'is_ticket_under_event_limit' ), 10001, 4 );
		// before calculating totals, we need to refresh our tally list
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'reset_tallies' ), 10000 );

		// add our coupon related cart item meta that should be carried from session to session and save on order item creation
		add_filter( 'qsot-zoner-item-data-keys', array( __CLASS__, 'preserved_item_meta' ), 1000, 1 );
		add_action( 'woocommerce_order_edit_product', array( __CLASS__, 'woocommerce_order_edit_product' ), 1000, 4 );
		// and hide those keys from being displayed in the admin (or anywhere)
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'preserved_item_meta' ), 1000, 1 );

		// update the order coupon usages
		add_action( 'woocommerce_checkout_update_order_meta', array( __CLASS__, 'update_order_coupon_usages' ), 10, 2 );
		add_action( 'qsot-coupons-updated-order-from-cart', array( __CLASS__, 'update_order_coupon_usages_on_admin_update' ), 10, 2 );

		// register some hooks as late as possible, so we can determin final values
		add_action( 'wp_loaded', array( __CLASS__, 'extra_late_hooks' ), PHP_INT_MAX );
	}

	// extra deferred hooks
	public static function extra_late_hooks() {
		// attempt to get the final verdict on a cart item's coupon validity
		add_filter( 'woocommerce_coupon_is_valid_for_product', array( __CLASS__, 'tally_coupon_validity' ), PHP_INT_MAX, 4 );

		// record the final tally on each cart item
		add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'store_item_coupon_usage' ), PHP_INT_MAX, 1 );
	}

	// when starting the calculate_totals process, we need to reset out tallies, to avoid tallying cart item tests more than once per calc
	public static function reset_tallies() {
		// in the admin, when editing an order and adding a coupon, we do not want coupon usages on the current order to count against us on new coupon validity calculations.
		// first load the order based usages for each coupon
		self::$order_usages = self::$order_usages_by_event = array();
		if ( isset( WC()->cart->order ) && WC()->cart->order instanceof WC_Order ) {
			self::$orig_order_usages = self::$order_usages = (array)get_post_meta( WC()->cart->order->id, '_coupon_usages', true );
			// tally up the original usages by event, by coupon
			foreach ( WC()->cart->order->get_items() as $item ) {
				// if this item does not use coupons, skip it
				if ( ! isset( $item['_coupons_used'] ) )
					continue;

				// unseraialize the list
				$item['_coupons_used'] = maybe_unserialize( $item['_coupons_used'] );

				// get an indexable event
				$event_id = isset( $item['event_id'] ) ? $item['event_id'] : 0;
				if ( ! isset( self::$order_usages_by_event[ $event_id ] ) )
					self::$order_usages_by_event[ $event_id ] = array();

				// update the running tallys
				foreach ( $item['_coupons_used'] as $coupon )
					self::$order_usages_by_event[ $event_id ][ $coupon ] = isset( self::$order_usages_by_event[ $event_id ][ $coupon ] ) ? self::$order_usages_by_event[ $event_id ][ $coupon ] + $item['qty'] : $item['qty'];
			}
		}

		self::$tally = array();
	}

	// when the coupon is loaded, we need to load all our additional settings for it
	public static function load_coupon( $coupon ) {
		// if this is not a virtual coupon, and actually lives in the db with its own settings, then
		if ( $coupon->id ) {
			// load the list of event ids that this coupon is valid for. and empy list means all events
			$coupon->event_ids = get_post_meta( $coupon->id, 'event_ids', true );
			$coupon->event_ids = array_filter( wp_parse_id_list( $coupon->event_ids ) );

			// load the event start range and end range
			$coupon->event_range_start = get_post_meta( $coupon->id, 'event_range_start', true );
			$coupon->event_range_end = get_post_meta( $coupon->id, 'event_range_end', true );
			// load the dates in timestamp form for comparisons
			$coupon->event_range_start_ts = strtotime( $coupon->event_range_start );
			$coupon->event_range_end_ts = strtotime( $coupon->event_range_end );

			// load the by event limitations
			$coupon->event_limits = get_post_meta( $coupon->id, 'event_limits', true );
			$coupon->event_limits = '' == $coupon->event_limits ? array() : array_filter( (array)$coupon->event_limits );

			// load the current event usages
			$coupon->event_usages = get_post_meta( $coupon->id, 'event_usages', true );
			// handle legacy meta
			if ( '' == $coupon->event_usages && ( $legacy = get_post_meta( $coupon->id, 'usage_record', true ) ) ) {
				$coupon->event_usages = array_filter( (array)$legacy );
				update_post_meta( $coupon->id, 'event_usages', $coupon->event_usages );
				delete_post_meta( $coupon->id, 'usage_record' );
			}
			$coupon->event_usages = '' == $coupon->event_usages ? array() : array_filter( (array)$coupon->event_usages );
		}
	}

	// during the cart phase, we need to track certain non-standard order item meta. we also need to save that meta when an order item is created. this function handles that for our coupon data
	public static function preserved_item_meta( $list ) {
		// new keys for coupons
		$new_list = array(
			'_coupons_used',
		);

		return array_unique( array_merge( $list, $new_list ) );
	}

	// update _coupon_used upon order update_product
	public static function woocommerce_order_edit_product( $order_id, $item_id, $args, $product ) {
		// update _coupon_used
		if ( isset( $args['item_meta'], $args['item_meta']['_coupons_used'] ) )
			wc_update_order_item_meta( $item_id, '__coupons_used', $args['item_meta']['_coupons_used'] );
	}

	// during admin order update, we need to possibly update the order coupon usages
	public static function update_order_coupon_usages_on_admin_update( $order, $cart ) {
		self::update_order_coupon_usages( $order->id, false );
	}

	// at the end of checkout, figure out the total coupon usages on the order, and store it in meta
	public static function update_order_coupon_usages( $order_id, $posted ) {
		// if there is no cart, or if it is empty, then bail
		if ( ! isset( WC()->cart ) || empty( WC()->cart->cart_contents ) )
			return;

		$tally = array();

		// cycle through all cart items, and tally up the total uses of each coupon
		foreach ( WC()->cart->get_cart() as $item_key => $item ) {
			// if this item does not use any coupons, then skip it
			if ( ! isset( $item['_coupons_used'] ) || empty( $item['_coupons_used'] ) )
				continue;

			// tally up the coupon usages
			foreach ( $item['_coupons_used'] as $code )
				$tally[ $code ] = isset( $tally[ $code ] ) ? $tally[ $code ] + $item['quantity'] : $item['quantity'];
		}

		// update the order meta to reflect the total coupon usages
		update_post_meta( $order_id, '_coupon_usages', $tally );
		//update_post_meta( $order_id, '_coupon_last', self::$valid_for['code-event'] );
		//update_post_meta( $order_id, '_coupon_orig_use', self::$order_usages_by_event );

		// now, update the official coupon tallies
		foreach ( WC()->cart->get_coupons() as $coupon ) {
			// only do this for coupons with a tally to record
			if ( ! isset( self::$valid_for['code-event'][ $coupon->code ] ) )
				continue;

			// for each new tally item, merge the results with the existing list of usages on the coupon
			foreach ( self::$valid_for['code-event'][ $coupon->code ] as $event_id => $qty ) {
				$orig_use = isset( self::$order_usages_by_event[ $event_id ], self::$order_usages_by_event[ $event_id ][ $coupon->code ] ) ? self::$order_usages_by_event[ $event_id ][ $coupon->code ] : 0;
				$coupon->event_usages[ $event_id . '' ] = isset( $coupon->event_usages[ $event_id . '' ] ) ? $coupon->event_usages[ $event_id . '' ] + $qty - $orig_use : $qty;
			}

			// if the coupon has an id, then update it's corresponding meta entry for usage stats
			if ( isset( $coupon->id ) && $coupon->id > 0 )
				update_post_meta( $coupon->id, 'event_usages', $coupon->event_usages );
		}
	}

	// determine if the coupon is still valid, after comparing if it is for the current event, and if the limit for the current event has not yet been reached
	public static function valid_coupon( $valid, $coupon ) {
		// make sure that the coupon is for at least one event in the cart. throw exception if not
		self::_valid_for_events_in_cart( $coupon );

		// make sure that the coupone is not over the limit for at least one event in the cart. throw exception if not
		self::_valid_under_event_limits_in_cart( $coupon );

		// return whatever the current value is
		return $valid;
	}

	// verify that the coupon is valid for at least one event in the cart. if it is not, then throw an exception, which will be caught in the coupon class
	protected static function _valid_for_events_in_cart( $coupon ) {
		// if this coupon is not for specific events, then auto pass this test
		if ( empty( $coupon->event_ids ) && empty( $coupon->event_range_start_ts ) && empty( $coupon->event_range_end_ts ) )
			return true;

		// cycle through all cart items and validate if the item is for an event that this coupon is valid for
		foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
			// if the item is for an event
			if ( isset( $item['event_id'] ) ) {
				// get the start date of the event, used for testing event range
				$start_date = strtotime( get_post_meta( $item['event_id'], '_start', true ) );

				// if the event is before any effective start date, then bail
				if ( ! empty( $coupon->event_range_start_ts ) && $start_date < $coupon->event_range_start_ts )
					continue;
				
				// if the event is after any effective end date, then bail
				if ( ! empty( $coupon->event_range_end_ts ) && $start_date > $coupon->event_range_end_ts )
					continue;

				// if this coupon is valid for all events, then pass
				if ( empty( $coupon->event_ids ) )
					return true;

				// otherwise, only pass if the event, or it's parent event is in our list of acceptable events
				else if ( ( $parent = wp_get_post_parent_id( $item['event_id'] ) ) && ( in_array( $item['event_id'], $coupon->event_ids ) || in_array( $parent, $coupon->event_ids ) ) )
					return true;
			}
		}

		// if we got this far, then the coupon is not valid for the cart, because none of the items in the cart are for events that are acceptable by this coupon
		throw new Exception( self::E_QSOT_NO_ITEM_FOR_VALID_EVENTS );
	}

	// test that at least one item in the cart is for an event that is not over it's event limit for this coupon
	protected static function _valid_under_event_limits_in_cart( $coupon ) {
		// if there are no event limits, then skip this check
		if ( empty( $coupon->event_limits ) )
			return;

		$valid_for_one = false;

		// for each cart item, check if it puts us over an event limit. if it does not, then it is valid
		foreach ( WC()->cart->get_cart() as $cart_key => $item ) {
			// only perform the check on tickets
			if ( isset( $item['event_id'] ) ) {
				$parent = wp_get_post_parent_id( $item['event_id'] ) . '';
				$event_id = $item['event_id'] . '';

				// find the limits for this specific event, or it's parent event, which ever exists
				$limit = false;
				$usages = 0;
				// also adjust for any order usages that were previously recorded. this will prevent false failure when recalculating the order totals in the admin edit order screen. start by loading the order usages if present
				$minus_order_used = isset( self::$order_usages[ $coupon->code ] ) && self::$order_usages[ $coupon->code ] > 0 ? self::$order_usages[ $coupon->code ] : 0;

				// if there are limits for this specific event, test against those limits
				if ( isset( $coupon->event_limits[ $event_id ] ) ) {
					$limit = $coupon->event_limits[ $event_id ];
					$usages = isset( $coupon->event_usages[ $event_id ] ) ? $coupon->event_usages[ $event_id ] : 0;
				// or if there are limits for the parent event, test against those limits
				} else if ( isset( $coupon->event_limits[ $parent ] ) ) {
					$limit = $coupon->event_limits[ $parent ];
					$usages = isset( $coupon->event_usages[ $parent ] ) ? $coupon->event_usages[ $parent ] : 0;
				}
				
				// if there is not a limit established for this event -OR- if the quantity of this item does not put us over the established limit, then PASS
				if ( false === $limit || $usages + $item['quantity'] - $minus_order_used <= $limit ) {
					$valid_for_one = true;
					break;
				}
			}
		}

		// if none of the tickets in the cart keep us below the active event limits on the coupon, then fail
		if ( ! $valid_for_one )
			throw new Exception( self::E_QSOT_NOT_UNDER_EVENT_LIMIT );
	}

	// attempt to parse our coupon error codess into coupon error strings
	public static function custom_coupon_errors( $msg, $code, $coupon ) {
		// only attempt to interpret the code if the msg is already empty
		if ( '' === $msg ) {
			// if the code is one in our list of custom messages, then swap the blank msg for a proper one
			switch ( $code ) {
				// none of the events in the cart are valid for the coupon
				case self::E_QSOT_NO_ITEM_FOR_VALID_EVENTS:
					$msg = sprintf( __( 'Sorry, the coupon "%s" is not valid, because it is only valid for certain events.', 'qsot-coupons' ), $coupon->code );
				break;

				// none of the items in the cart keep us under the established event limits for this coupon
				case self::E_QSOT_NOT_UNDER_EVENT_LIMIT:
					$msg = sprintf( __( 'Sorry, the coupon "%s" is not valid, because it is only valid for a certain number of uses, for specific events. That number of uses has been exceeded.', 'qsot-coupons' ), $coupon->code );
				break;
			}
		}

		return $msg;
	}

	// determine if a ticket is for an event that the given coupon sees as valid
	public static function is_ticket_for_coupon_event( $valid, $product, $coupon, $item ) {
		// only perform this check if the item is still considered valid
		if ( false === $valid )
			return $valid;

		// only perform this check if there are specified valid events, or a specified valid date range, for this coupon
		if ( empty( $coupon->event_ids ) && empty( $coupon->event_range_start_ts ) && empty( $coupon->event_range_end_ts ) )
			return $valid;

		// if this item is not a ticket, then auto fail, because this coupon is only valid for tickets for events at this point
		if ( ! isset( $item['event_id'] ) )
			return false;

		$start_date = strtotime( get_post_meta( $item['event_id'], '_start', true ) );

		// if the start date of the event is before any effective start time, then fail
		if ( ! empty( $coupon->event_range_start_ts ) && $start_date < $coupon->event_range_start_ts )
			return false;

		// if the start date of the event is after any effective end time, then fail
		if ( ! empty( $coupon->event_range_end_ts ) && $start_date > $coupon->event_range_end_ts )
			return false;

		// if the event, or it's parent, are not in our list of acceptable events, then fail
		$parent = wp_get_post_parent_id( $item['event_id'] );
		if ( ! empty( $coupon->event_ids ) && ! in_array( $item['event_id'], $coupon->event_ids ) && ! in_array( $parent, $coupon->event_ids ) )
			return false;

		// otherwise, we pass this test
		return true;
	}

	// determine if this ticket puts us over an event limit for this coupon, based on historical tallies and tallies within this cart
	public static function is_ticket_under_event_limit( $valid, $product, $coupon, $item ) {
		// only perform this check if the item is still considered valid
		if ( false === $valid )
			return $valid;

		// only perform this check if there are specified valid events for this coupon
		if ( empty( $coupon->event_limits ) )
			return $valid;

		// if this item is not a ticket, then auto fail, because this coupon is only valid for tickets for events at this point
		if ( ! isset( $item['event_id'] ) )
			return false;

		// if this item already has coupon applied that has event limits, then skip this check
		$key = self::_item_key( $item );
		if ( isset( self::$valid_for['by-item'][ $key ] ) ) {
			$applied = false;

			// check every applied coupon for this item to see if it has an event limit
			foreach ( self::$valid_for['by-item'][ $key ] as $code ) {
				$el_coupon = new WC_Coupon( $code );
				if ( isset( $coupon->id, $coupon->event_limits ) && ! empty( $coupon->id ) && ! empty( $coupon->event_limits ) ) {
					$applied = true;
					break;
				}
			}

			// if any currently applied coupons for this item have event limits, then this item cannot have another coupon of the same type
			if ( $applied )
				return $valid;
		}

		$event_id = $item['event_id'] . '';
		$parent = wp_get_post_parent_id( $event_id ) . '';

		// determine the limit to compare against and the appropriate historical usage to use
		$limit = false;
		$limit_for = $event_id;
		$usages = 0;
		// adjust for any order usages that were previously recorded. this will prevent false failure when recalculating the order totals in the admin edit order screen. start by loading the order usages if present
		$minus_order_used = isset( self::$order_usages[ $coupon->code ] ) && self::$order_usages[ $coupon->code ] > 0 ? self::$order_usages[ $coupon->code ] : 0;

		// if the event it self has a set limit, then test against that limit
		if ( isset( $coupon->event_limits[ $event_id ] ) ) {
			$limit = $coupon->event_limits[ $event_id ];
			$usages = isset( $coupon->event_usages[ $event_id ] ) ? $coupon->event_usages[ $event_id ] : 0;
		// the parent event could have a set limit, which would include all child events. if the parent has a limit, but not the exact event, use the parent for comparison
		} else if ( $parent && isset( $coupon->event_limits[ $parent ] ) ) {
			$limit = $coupon->event_limits[ $parent ];
			$limit_for = $parent;
			$usages = isset( $coupon->event_usages[ $parent ] ) ? $coupon->event_usages[ $parent ] : 0;
		}

		// if there is no limit to compare against, then there is no possible way that this item can put us over it, so skip the remaining tests
		if ( false !== $limit ) {
			// if the current coupon does not have a tally entry, then make one
			if ( ! isset( self::$tally[ $coupon->code ] ) )
				self::$tally[ $coupon->code ] = array();
			// if the tally for this event does not yet exist, then create it
			if ( ! isset( self::$tally[ $coupon->code ][ $limit_for ] ) )
				self::$tally[ $coupon->code ][ $limit_for ] = 0;
			// add our ticket's quantity to the current tally for this cart
			self::$tally[ $coupon->code ][ $limit_for ] += $item['quantity'];

			// if the cart tally and historical tally for this event is over the event limit, then fail
			if ( self::$tally[ $coupon->code ][ $limit_for ] + $usages - $minus_order_used > $limit )
				return false;
		}

		// during the validation process, gradually account for any order usages we had, making subsequent item checks not double count orer usages
		if ( $minus_order_used > 0 )
			self::$order_usages[ $coupon->code ] -= $item['quantity'];

		// otherwise, pass
		return true;
	}

	// track the final validity value for a coupon's usage
	public static function tally_coupon_validity( $valid, $product, $coupon, $item ) {
		// only do this tally for coupons that are still valid
		if ( ! $valid )
			return $valid;

		// determine a unique item key to track by, since WC does not give us the cart_key
		$key = self::_item_key( $item );

		// make sure that the by-item key is present and is an array
		if ( ! isset( self::$valid_for['by-item'][ $key ] ) || ! is_array( self::$valid_for['by-item'][ $key ] ) )
			self::$valid_for['by-item'][ $key ] = array();

		// make sure that the by-coupon key is present and is an array
		if ( ! isset( self::$valid_for['by-coupon'][ $coupon->code ] ) || ! is_array( self::$valid_for['by-coupon'][ $coupon->code ] ) )
			self::$valid_for['by-coupon'][ $coupon->code ] = array();

		// initialize the coupon-count key for this coupon
		if ( ! isset( self::$valid_for['coupon-count'][ $coupon->code ] ) )
			self::$valid_for['coupon-count'][ $coupon->code ] = 0;

		// add our tallies to the lists
		self::$valid_for['by-coupon'][ $coupon->code ][] = $key;
		self::$valid_for['by-item'][ $key ][] = $coupon->code;
		self::$valid_for['coupon-count'][ $coupon->code ] += $item['quantity'];

		// update the official usage count, by event per coupon
		$event_id = ( isset( $item['event_id'] ) ? $item['event_id'] : 0 ) . '';

		// if the code-event key is not present, then fix it
		if ( ! isset( self::$valid_for['code-event'][ $coupon->code ] ) )
			self::$valid_for['code-event'][ $coupon->code ] = array();

		// update the index
		self::$valid_for['code-event'][ $coupon->code ][ $event_id ] = isset( self::$valid_for['code-event'][ $coupon->code ][ $event_id ] )
				? self::$valid_for['code-event'][ $coupon->code ][ $event_id ] + $item['quantity']
				: $item['quantity'];
		
		return $valid;
	}

	// store the coupon usage for each item in the cart, based on our tallies from tally_coupon_validity
	public static function store_item_coupon_usage( $cart ) {
		// foreach item in the cart
		foreach ( $cart->get_cart() as $item_key => $values ) {
			// determine a unique item key we previously used to track by
			$key = self::_item_key( $values );

			// see if there is a record of the coupons we used. if there is update the 
			if ( isset( self::$valid_for['by-item'][ $key ] ) )
				$cart->cart_contents[ $item_key ]['_coupons_used'] = self::$valid_for['by-item'][ $key ];
			// otehrwise, indicate that the item does not use any coupons
			else
				unset( $cart->cart_contents[ $item_key ]['_coupons_used'] );
		}
	}

	// generate an item key based on the order_item meta, since we are not given an item_key in any of these hooks
	protected static function _item_key( $item ) {
		// container for the parts of the key
		$parts = array( $item['product_id'] );

		// if the variation id is present, then add it
		if ( isset( $item['variation_id'] ) )
			$parts[] = $item['variation_id'];

		// if the variation data is present, add it
		if ( isset( $item['variation_id'] ) && is_array( $item['variation'] ) && ! empty( $item['variation'] ) ) {
			$variation_key = '';
			foreach ( $item['variation'] as $k => $v )
				$variation_key .= trim( $k ) . trim( $v );
			$parts[] = $variation_key;
		}

		// cycle through cart data and add any that are not yet part of the key
		$item_data_key = '';
		foreach ( $item as $k => $v ) {
			// if the key is a line_* key, then skip it, cause that most def changed
			if ( substr( $k, 0, 4 ) == 'line' )
				continue;

			// if the key is for product, quantity, variaion, or a special one we need for admin magic, skip it
			if ( in_array( $k, array( 'variation', 'variation_id', 'product_id', 'quantity', 'qty', 'name', 'type', 'data', 'tax_class', 'item_meta', 'oiid', '_coupons_used' ) ) )
				continue;

			// if we got this far, then add the pair to the list
			$v = is_scalar( $v ) ? $v : http_build_query( (array) $v );
			$item_data_key .= trim( $k ) . trim( $v );
		}
		if ( '' != $item_data_key )
			$parts[] = $item_data_key;

		// generate the key from the remaining data
		$key = md5( implode( '_', $parts ) ) . ':' . $item['quantity'];

		return $key;
	}

	// during the plugin activation process, run our setup code
	public static function on_activation() {
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_coupons_core::pre_init();
