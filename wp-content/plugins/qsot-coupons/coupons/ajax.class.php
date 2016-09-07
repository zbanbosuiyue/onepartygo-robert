<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the ajax handler for our coupons plugin
class qsot_coupons_ajax {
	// list of hooks to allow. [hook base name] => NOPRIV
	protected static $hooks = array(
		'search_events' => 0,
		'search_coupons' => 0,
		'add_coupons' => 0,
	);

	// setup the ajax class
	public static function pre_init() {
		// setup all our ajax actions and hooks
		self::_setup_ajax_hooks();
	}

	// setup all the ajax hooks for the plugin
	protected static function _setup_ajax_hooks() {
		// for each hook, setup the respective ajax actions
		foreach ( self::$hooks as $base_name => $nopriv ) {
			// create the logged in user ajax action
			add_action( 'qsot-cpaj_' . $base_name, array( __CLASS__, 'aj_' . $base_name ), 10 );

			// if the user does not have to be logged in for this, then create the non-logged in version
			if ( $nopriv )
				add_action( 'qsot-cpaj-nopriv_' . $base_name, array( __CLASS__, 'aj_' . $base_name ), 10 );
		}

		add_action( 'wp_ajax_qsot-coupons', array( __CLASS__, 'handle_ajax' ), 10, 0 );
		add_action( 'wp_ajax_nopriv_qsot-coupons', array( __CLASS__, 'nopriv_handle_ajax' ), 10, 0 );
	}

	// add the_title filter to the the 'woocommerce_coupon_code' filter, so that qtranslate does it's magic, so that the dumb wc coupon comparison does not fail when checking if the coupon is valid
	public static function apply_the_title( $code ) {
		return apply_filters( 'the_title', $code );
	}

	// handle all logged in ajax requests
	public static function handle_ajax( $nopriv=false ) {
		$resp = array(
			's' => false,
			'e' => array(),
		);

		// verify that this request is coming from our blog and not external sources
		check_ajax_referer( 'coupon-ajax', 'n' );

		// if the security token is not present, the fail automatically
		if ( ! isset( $_REQUEST['n'] ) ) {
			$resp['e'][] = __( 'Sorry your request could not be processed. n', 'qsot-coupon' );
		// if the supplied security token is not valid, then fail
		} else if ( ! wp_verify_nonce( $_REQUEST['n'], 'coupon-ajax' ) ) {
			$resp['e'][] = __( 'Sorry your request could not be processed. v', 'qsot-coupon' );
		// otherwise, pass and attempt to run the ajax
		} else {
			$prefix = $nopriv ? 'qsot-cpaj-nopriv_' : 'qsot-cpaj_';
			if ( isset( $_REQUEST['sa'] ) && ! empty( $_REQUEST['sa'] ) && has_filter( $prefix . $_REQUEST['sa'] ) ) {
				$resp = apply_filters( $prefix . $_REQUEST['sa'], $resp, !!$nopriv );
			} else {
				$resp['e'][] = __( 'Sorry your request could not be processed. f', 'qsot-coupon' );
			}
		}

		wp_send_json( $resp );
		exit;
	}

	// handle all logged out ajax requests
	public static function handle_ajax_nopriv() {
		self::handle_ajax( true );
	}

	// handle ajax requests to search events
	public static function aj_search_events( $resp ) {
		// fetch the term from the request
		$term = (string)wc_clean( stripslashes( $_REQUEST['term'] ) );

		// search the posts for the matching item
		$resp = self::_post_search( $resp, $term, array( 'qsot-event' ), array( __CLASS__, 'format_event_title' ) );

		return $resp;
	}

	// handle ajax requests to search events
	public static function aj_search_coupons( $resp ) {
		// fetch the term from the request
		$term = (string)wc_clean( stripslashes( $_REQUEST['term'] ) );

		// search the posts for the matching item
		$resp = self::_post_search( $resp, $term, array( 'shop_coupon' ), array( __CLASS__, 'format_coupon_title' ), array( __CLASS__, 'format_coupon_id' ) );

		return $resp;
	}

	// handle ajax requests to add coupons to an order
	public static function aj_add_coupons( $resp ) {
		wc_clear_notices();
		// more security, because this actually changes an order
		if ( ! current_user_can( 'edit_shop_orders' ) ) {
			$resp['e'][] = __( 'You do not have permission to do that.', 'qsot-coupons' );
			return $resp;
		}

		// verify that all required data is present
		if ( ! isset( $_POST['pid'], $_POST['coupon_ids'] ) || ! is_array( $_POST['coupon_ids'] ) || empty( $_POST['coupon_ids'] ) ) {
			$resp['e'][] = __( 'That request could not be processed', 'qsot-coupons' );
			return $resp;
		}

		// load the order
		$order = wc_get_order( $_POST['pid'] );

		// verify order exists
		if ( ! is_object( $order ) || ! ( $order instanceof WC_Order ) ) {
			$resp['e'][] = __( 'Sorry that is not a valid order.', 'qsot-coupons' );
			return $resp;
		}

		// add the coupons to the order
		// get a list of used coupons on the order, so that we do not add any dupes
		$coupons = $order->get_used_coupons();

		// for each supplied coupon
		foreach ( $_POST['coupon_ids'] as $coupon_id ) {
			// if it is not already attached to the order, then add it
			if ( false === array_search( $coupon_id, $coupons ) )
				$order->add_coupon( $coupon_id );
		}

		// fake a checkout or cart, so that calculate_totals actually comes up with a total
		add_filter( 'woocommerce_is_checkout', array( __CLASS__, 'return_true' ), PHP_INT_MAX );

		// load the admin versions of various non-admin classes
		require_once QSOT_coupons_launcher::plugin_dir() . 'coupons/admin-cart.php';
		require_once QSOT_coupons_launcher::plugin_dir() . 'coupons/admin-customer.php';

		// patch so that qtranslate-x will work with coupons from admin
		remove_filter( 'the_title_rss', 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage', 0 );
		remove_filter( 'the_title', 'qtranxf_admin_the_title', 0 );
		remove_filter( 'the_title', 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage', 0 );

		// setup the admin versions of non-admin classes
		WC()->customer = new qsot_admin_customer( $order->id );
		WC()->cart = new qsot_admin_cart();
		WC()->cart->set_from_order( $order );
		wc_clear_notices();

		// calculate all the new totals
		WC()->cart->calculate_totals();

		// update order from the admin cart
		self::_update_order_from_cart();

		// remove any stored notices
		wc_clear_notices();

		// patch so that qtranslate-x will work with coupons from admin
		add_filter( 'the_title_rss', 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage', 0 );
		add_filter( 'the_title', 'qtranxf_admin_the_title', 0 );
		add_filter( 'the_title', 'qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage', 0 );

		// update the response with the resulting order items metabox output
		$resp['s'] = true;
		$resp['r'] = self::_get_order_items_mb( wc_get_order( $order->id ) );

		// return the response
		return $resp;
	}

	// draw the order items meta box, based off the new order
	protected static function _get_order_items_mb( $order ) {
		// capture the output of the order items meta box so that it can be returned
		ob_start();

		// load the order data
		$data = get_post_meta( $order->id );

		// include the appropriate template
		include( QSOT_Templates::locate_woo_template( 'meta-boxes/views/html-order-items.php', 'admin' ) );

		// return the results
		$out = ob_get_contents();
		ob_end_clean();
		return $out;
	}

	// return a value of true, no matter what
	public static function return_true() {
		return true;
	}

	// callback to format the title of events
	public static function format_event_title( $title, $match ) {
		// get the type of the matched post
		$match_type = get_post_type_object( $match->post_type );

		// if this is a parent event, explain that selecting it will limit tickets purchased by an aggregate of all child event
		$title = 0 == $match->post_parent ? sprintf( __( 'All %s %s', 'qsot-coupon' ), '"' . $title . '"', $match_type->label ) : $title;

		return $title;
	}

	// callback to format the title of coupons
	public static function format_coupon_title( $title, $match ) {
		// get the information about the coupon
		$type = get_post_meta( $match->ID, 'discount_type', true );
		$amt = get_post_meta( $match->ID, 'coupon_amount', true );

		// normalize the type
		$types = wc_get_coupon_types();
		$coupon_type = isset( $types[ $type ] ) ? $types[ $type ] : $type;

		// return the final title
		return sprintf( '%s (%s : %s)', strtoupper( $title ), $coupon_type, $amt );
	}

	// callback to select the proper 'id' for the coupon
	public static function format_coupon_id( $id, $match ) {
		return strtolower( trim( $match->post_title ) );
	}

	// get a raw list of matching posts
	protected static function _post_search( $resp, $term, $post_types, $post_title_cb=null, $post_id_cb=null ) {
		// normalize the post_types list
		$post_types = empty( $post_types ) ? array( 'qsot-event' ) : (array)$post_types;

		// if there is no term then return no results and fail
		if ( empty( $term ) ) {
			$resp['e'][] = __( 'No search term supplied.', 'qsot-coupons' );
			return $resp;
		}

		// basic search, usign title and post_content
		$args = array(
			'post_type' => $post_types,
			'post_status' => 'publish',
			'posts_per_page' => -1,
			's' => $term,
			'fields' => 'ids'
		);

		// if a number is supplied as a search term, it could be an ID. try to look up posts based on that assumption too
		if ( is_numeric( $term ) ) {

			// look for direct matches of a post term
			$args2 = array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'post__in' => array( 0, $term ),
				'fields' => 'ids'
			);

			// look for parent posts that match the supplied id
			$args3 = array(
				'post_type' => $post_types,
				'post_status' => 'publish',
				'posts_per_page' => -1,
				'post_parent' => $term,
				'fields' => 'ids'
			);

			// get a uniquified list of ids of matches
			$posts = array_unique( array_merge( get_posts( $args ), get_posts( $args2 ), get_posts( $args3 ) ) );

		// if the term is not numeric, then just perform the title/content search
		} else {

			$posts = array_unique( array_merge( get_posts( $args ) ) );

		}

		$resp['raw'] = $posts;

		$found_matches = $parents = $pids = $titles = $starts = array();

		// if there were results
		if ( $posts ) {
			// for each result, add a record to our final, formatted list of results, matching the id to the label
			foreach ( $posts as $post ) {
				$match = get_post( $post );
				$title = apply_filters( 'the_title', $match->post_title, $match->ID );
				// if there is a callback supplied to format the display value of the result item, then pass the title through that function
				if ( is_callable( $post_title_cb ) )
					$title = call_user_func( $post_title_cb, $title, $match );
				if ( is_callable( $post_id_cb ) )
					$post = call_user_func( $post_id_cb, $post, $match );

				// track the post parent of each item, used for sorting a little later
				$parents[] = $match->post_parent;

				// track the title for each item, used for combine and sort later
				$titles[] = rawurldecode( $title );

				// track the event start dates, used for sort later
				$starts[] = strtotime( get_post_meta( $post, '_start', true ) );

				// track the post id for each item, used for sort and combine later
				$pids[] = $post;
			}

			// sort the results by post parent, then by start date, then title, then pid, so that parent events (post_parent=0) are first in the list
			array_multisort( $parents, SORT_ASC, SORT_NUMERIC, $starts, SORT_ASC, SORT_NUMERIC, $titles, SORT_ASC, SORT_STRING, $pids, SORT_ASC, SORT_NUMERIC );

			// produce the final list. must be list of arrays, because js lib sorts by object key for some dumb reason
			foreach ( $pids as $index => $pid )
				$found_matches[] = array( 'id' => $pid, 't' => $titles[ $index ] );
		}

		// add the results to our output
		$resp['r'] = apply_filters( 'qsot-search-found-posts', $found_matches, $post_types );

		return $resp;
	}

	// update the order from the current cart, which is based off an order
	protected static function _update_order_from_cart() {
		// get the cart and order
		$cart = WC()->cart;
		$order = $cart->order;

		// if there is not an order, then bail
		if ( ! is_object( $order ) || ! ( $order instanceof WC_Order ) )
			return false;

		// update every order item from the new cart values
		foreach ( $cart->get_cart() as $cart_key => $item ) {
			// get the original order_item_id and the product object
			$oiid = $item['oiid'];
			$_product = $item['data'];

			// aggregate saveable meta
			$meta = array(
				'variation' => isset( $item['variation'] ) ? $item['variation'] : array(),
				'qty' => isset( $item['quantity'] ) ? $item['quantity'] : 1,
				'tax_class' => isset( $item['tax_class'] ) ? $item['tax_class'] : '',
				'totals' => array(
					'subtotal' => isset( $item['line_subtotal'] ) ? $item['line_subtotal'] : '',
					'subtotal_tax' => isset( $item['line_subtotal_tax'] ) ? $item['line_subtotal_tax'] : '',
					'total' => isset( $item['line_total'] ) ? $item['line_total'] : '',
					'tax' => isset( $item['line_tax'] ) ? $item['line_tax'] : '',
					'tax_data' => isset( $item['line_tax_data'] ) ? $item['line_tax_data'] : '',
				),
				'item_meta' => $item,
			);

			// update the order item
			$order->update_product( $oiid, $_product, $meta );

			// update tax data since update_product does not do it
			wc_update_order_item_meta( $oiid, '_line_tax_data', $item['line_tax_data'] );
		}

		// aggregate a list of fees on the order, and index by name to oiid
		$order_fees = array();
		foreach ( $order->get_items( array( 'fee' ) ) as $oiid => $fee )
			$order_fees[ sanitize_title( $fee['name'] ) ] = $oiid;

		// update the order fees
		foreach ( $cart->get_fees() as $fee_key => $fee ) {
			// if we dont know the original order_item_id of the fee, the bail
			if ( ! isset( $order_fees[ $fee->id ] ) )
				continue;

			// aggregate the new fee meta
			$meta = array(
				'name' => $fee->name,
				'tax_class' => $fee->tax_class,
				'line_total' => $fee->amount,
				'line_tax' => $fee->tax,
				'tax_data' => $fee->tax_data,
			);

			// update the fee
			$order->update_fee( $order_fees[ $fee->id ], $meta );

			// update the tax_data since the update_fee method does not do it
			wc_add_order_item_meta( $item_id, '_line_tax_data', array( 'total' => $meta['tax_data'] ) );
		}

		// if we specified shipping, then update it
		if ( isset( $cart->packages, $cart->chosen_shipping_method, $cart->chosen_shipping_method_oiid ) && ! empty( $cart->packages ) && ! empty( $cart->chosen_shipping_method ) && $cart->chosen_shipping_method_oiid > 0 ) {
			// for each package we loaded in the cart (most likely one)
			foreach ( $cart->packages as $package_key => $package ) {
				// if the chosen method does not have rates, then bail
				if ( ! isset( $package['rates'][ $cart->chosen_shipping_method ] ) )
					continue;

				$rate = $package['rates'][ $cart->chosen_shipping_method ];
				// aggregate the changed shipping information
				$meta = array(
					'method_title' => $rate->label,
					'method_id' => $cart->chosen_shipping_method,
					'cost' => $rate->cost,
				);

				// update the shipping method information on the order
				$order->update_shipping( $cart->chosen_shipping_method_oiid, $meta );
			}
		}

		// update the order taxes
		$order->update_taxes();

		// aggregate a mapped list of order coupon codes to their oiid
		$order_coupons = array();
		foreach ( $order->get_items( array( 'coupon' ) ) as $oiid => $coupon )
			$order_coupons[ strtolower( trim( $coupon['name'] ) ) ] = $oiid;

		// update the order coupons
		foreach ( $cart->get_coupons() as $code => $coupon ) {
			$code = strtolower( trim( $code ) );
			if ( empty( $code ) )
				continue;

			// only do this for coupons we know about
			if ( ! isset( $order_coupons[ $code ] ) )
				continue;

			// aggregate the new coupon meta
			$meta = array(
				'code' => $code,
				'discount_amount' => $cart->get_coupon_discount_amount( $code ),
				'discount_amount_tax' => $cart->get_coupon_discount_tax_amount( $code ),
			);

			// update the coupon
			$order->update_coupon( $order_coupons[ $code ], $meta );
		}

		// update the order totals
		$order->set_total( $cart->shipping_total, 'shipping' );
		$order->set_total( $cart->get_cart_discount_total(), 'cart_discount' );
		$order->set_total( $cart->get_cart_discount_tax_total(), 'cart_discount_tax' );
		$order->set_total( $cart->tax_total, 'tax' );
		$order->set_total( $cart->shipping_tax_total, 'shipping_tax' );
		$order->set_total( $cart->total );

		// notify plugins
		do_action( 'qsot-coupons-updated-order-from-cart', $order, $cart );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_coupons_ajax::pre_init();
