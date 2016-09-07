<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// handles the frontend display of the table service plugin
class QSOT_Table_Service_Frontend {
	// make this a singleton
	protected static $_instance = null;
	public static function instance() { return self::$_instance = ( self::$_instance instanceof self ) ? self::$_instance : new self; }
	protected function __construct() {
		// figure out the class name of the options class
		$cls = apply_filters('qsot-options-class-name', '');
		if ( empty( $cls ) )
			return;

		// create the options object, so that we can use it to register our options
		$this->options = call_user_func( array( $cls, 'instance' ) );
		if ( ! is_object( $this->options ) )
			return;

		// setup the options for this class
		$this->_setup_options();

		// register our assets
		add_action( 'init', array( &$this, 'register_assets' ) );

		// queue up the assets as needed
		add_action( 'wp_enqueue_scripts', array( &$this, 'enqueue_assets' ) );

		// add a button to the cart that forwards the user to complete any required table service purchasing
		add_filter( 'woocommerce_get_item_data', array( &$this, 'maybe_table_service_button' ), PHP_INT_MAX, 2 );

		// add a redirect url after a successful reservation request
		add_filter( 'qsot-gaea-ajax-response-reserve', array( &$this, 'add_redirect_url' ), 100000, 4 );
		add_filter( 'qsot-seating-ajax-response-reserve', array( &$this, 'add_redirect_url_seating' ), 100000, 4 );

		// if the user goes to the checkout page, then kick them to the cart page with an error if they have an incomplete table service product
		add_action( 'wp', array( &$this, 'checkout_redirect' ), -1, 1 );

		// before calculating our cart totals, kill all record of min spend tally that we have stored on cart items, so we can do a fresh calculation
		add_action( 'woocommerce_before_calculate_totals', array( &$this, 'clear_min_spend_tallies' ), -1 );
		// after we calc totals, tally the min spends for the table service items
		add_action( 'woocommerce_after_calculate_totals', array( &$this, 'tally_min_spend' ), -1 );
		// update the added to cart message with totals and shit
		add_filter( 'woocommerce_add_to_cart', array( &$this, 'add_progress_message' ), 1000000, 6 );

		// add the tooltip information section
		add_action( 'qsots-zone-info-tooltip', array( &$this, 'zone_info_tooltip' ), 10, 1 );
		add_filter( 'qsot-frontend-event-data', array( &$this, 'add_min_spend_to_edata' ), 10, 1 );

		// add the min spend to the 'price list price item' when selecting a seat price
		add_action( 'qsots-price-list-price', array( &$this, 'add_min_spend_to_price_item' ), 10 );

		// create a persistent unique id for table service items added to the cart
		add_filter( 'woocommerce_add_cart_item', array( &$this, 'add_ts_cart_id' ), 10, 2 );

		// prevent carryover of quantity
		add_filter( 'woocommerce_quantity_input_args', array( &$this, 'prevent_quantity_carryover' ), 10, 1 );
	}

	// prevent the carrying over of quantity to the next page
	public function prevent_quantity_carryover( $args ) {
		$args['input_value'] = 1;
		return $args;
	}

	// queue up the assets for render, when they are needed
	public function enqueue_assets() {
		// when on the event pages, queue up the table service javascript
		if ( is_singular( 'qsot-event' ) ) {
			wp_enqueue_script( 'qsotts-frontend' );
			wp_enqueue_style( 'qsotts-frontend' );
		}

		// if on a page that could contain a table service shortcode, add the css
		if ( is_singular() ) {
			$page_id = $this->options->qsot_table_service_page_id;
			global $post;
			if ( ( $page_id > 0 && $post->ID == $page_id ) || false !== strpos( $post->post_content, '[table-service' ) ) {
				wp_enqueue_style( 'qsotts-frontend' );
			}
		}
	}

	// register all the assets we use on the frontend
	public function register_assets() {
		$qsts = QSTS();
		$url = $qsts->plugin_url();
		$version = $qsts->version();

		// javascript to handle table service actions on the frontend
		wp_register_script( 'qsotts-frontend', $url . 'assets/js/frontend/table-service.js', array( 'jquery', 'qsot-tools' ), $version );
		wp_localize_script( 'qsotts-frontend', '_qsotts_frontend', array(
			'cart_url' => wc_get_cart_url(),
			'def_min' => wc_price( 0 ),
		) );

		// register the table-service css for the frontend
		wp_register_style( 'qsotts-frontend', $url . 'assets/css/frontend/table-service.css', array(), $version );
	}

	// create a unique id for each table service item added to the cart, which does not change from page to page (like the cart item_id does
	public function add_ts_cart_id( $data, $cart_item_id ) {
		$cp = $data;
		unset( $cp['data'] );
		$ts_id = md5( @json_encode( $cp ) );
		$data['ts_item_id'] = $ts_id;
		return $data;
	}

	// find a cart item id, given a list of criteria
	public function get_cart_item_id( $criteria ) {
		$match_id = '';
		$cart = WC()->cart->get_cart();
		// cycle through the items
		foreach ( $cart as $item_id => $item ) {
			// if any of the criteria is not set for this item, or it does not match for this item, skip this item
			foreach ( $criteria as $k => $v ) {
				// if missing, skip
				if ( ! isset( $item[ $k ] ) )
					continue 2;

				// if not matching, skip
				if ( 'product_id' == $k ) {
					if ( $item['product_id'] != $v && $item['variation_id'] != $v )
						continue 2;
				} else if ( $item[ $k ] != $v ) {
					continue 2;
				}
			}

			// this is the match
			$match_id = $item_id;
			break;
		}

		return $match_id;
	}

	// check if the given item is a table service product
	public function is_table_service( $product ) {
		// normalize numbers to products
		if ( is_numeric( $product ) )
			$product = wc_get_product( $product );

		return ( $product instanceof WC_Product ) && 'yes' == $product->table_service;
	}

	// get the appropriate redirect url for a product
	public function get_redirect_url( $ts_id ) {
		$item_id = $this->get_cart_item_id( array( 'ts_item_id' => $ts_id ) );
		$item = WC()->cart->get_cart_item( $item_id );
		// if the item does not exist, then bail
		if ( empty( $item ) || empty( $item_id ) )
			return '';

		$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
		// if this is not a table service product, skip it
		if ( ! $this->is_table_service( $product ) )
			return '';

		// otherwise, set the redirect url
		$page_id = $this->options->qsot_table_service_page_id;
		$url = $page_id ? get_permalink( $page_id ) : wc_get_cart_url();
		$url = add_query_arg( array( 'qsotts' => $ts_id ), $url );

		return $url;
	}

	// when the user goes to checkout and hs a table service product in the cart that has not met it's requirements, boot them to the cart
	public function checkout_redirect( $wp ) {
		// if this is not the checkout page, bail early
		if ( ! is_checkout() )
			return;

		$cart = WC()->cart;
		// cycle through each item in the cart, and if it is a table serivce item, check if it meets the requirements. if not, create a message and boot the user to the cart
		foreach ( $cart->cart_contents as $idx => $item ) {
			$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
			// if this is not a table service product, skip it
			if ( ! $this->is_table_service( $product ) || ! isset( $item['ts_item_id'] ) )
				continue;

			// figure out any remaining spend for this product
			$remaining_spend = $this->remaining_min_spend( $item );

			// if there is no remaining spend, skip this item
			if ( $remaining_spend <= 0 )
				continue;

			// otherwise create an error message for it
			wc_add_notice(
				sprintf(
					__( 'The %s product requires you to %scomplete an action%s, before you can checkout. Please complete this action before proceeding.', 'qsot-table-service' ),
					'<u>' . $product->get_title() . '</u>',
					sprintf( '<a href="%s" title="%s">', esc_url( $this->get_redirect_url( $item['ts_item_id'] ) ), __( 'Continue product selection', 'qsot-table-service' ) ),
					'</a>'
				),
				'error'
			);
		}

		// if there are any cart errors, boot the user to the cart now
		if ( wc_notice_count( 'error' ) ) {
			if ( is_ajax() ) {
				add_action( 'woocommerce_before_checkout_process', array( &$this, 'abort_checkout_ajax' ) );
			} else {
				wp_safe_redirect( wc_get_cart_url() );
				exit;
			}
		}
	}

	// if the errors above are thrown during checkout ajax, abort checkout the right way
	public function abort_checkout_ajax() {
		throw new Exception( '', 0 );
	}

	// after the cart total calculations have happened, tally the min spends
	public function tally_min_spend() {
		$cart = WC()->cart;
		// cycle through each item in the cart, and if it is a table serivce item, check if it meets the requirements. if not, create a message and boot the user to the cart
		foreach ( $cart->cart_contents as $idx => $item ) {
			$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
			// if this is not a table service product, skip it
			if ( ! $this->is_table_service( $product ) )
				continue;

			// figure out any remaining spend for this product
			$remaining_spend = $this->remaining_min_spend( $item );

			// update the remaining spend value
			$cart->cart_contents[ $idx ]['qsotts-remaining-spend'] = $remaining_spend;
		}
	}

	// before we initiate a calclate totals on the cart, blow out all the cache of the min_spend calculations for table service items on all cart items
	public function clear_min_spend_tallies() {
		$cart = WC()->cart;

		// cycle through all the items, and remove the min spends
		foreach ( $cart->cart_contents as $idx => $item ) {
			unset( $item['qsotts-spend'], $item['qsotts-remaining-spend'] );
			$cart->cart_contents[ $idx ] = $item;
		}
	}

	// update the message that shows an item was added to cart
	public function add_progress_message( $cart_item_key, $product_id, $quantity, $variation_id, $variation, $cart_item_data ) {
		// update the cart min_spend tallies
		WC()->cart->calculate_totals();

		// get this cart item, so we can figure out the table service item it counts towards
		$cart_items = WC()->cart->get_cart( $cart_item_key );
		$cart_item = $cart_items[ $cart_item_key ];

		// if this item does not count towards any table service item, skip it
		if ( ! isset( $cart_item['qsotts-spend'] ) || empty( $cart_item['qsotts-spend'] ) )
			return;
		$look_for_ts_id = array_keys( $cart_item['qsotts-spend'] );
		$look_for_ts_id = current( $look_for_ts_id );

		$ts_item = $ts_item_key = null;
		// find the cart item that this item counts towards
		foreach ( $cart_items as $item_key => $item ) {
			if ( isset( $item['ts_item_id'] ) && $item['ts_item_id'] == $look_for_ts_id ) {
				$ts_item_key = $item_key;
				$ts_item = $item;
				break;
			}
		}

		// if there is no table service item associated, then bail
		if ( ! is_array( $ts_item ) )
			return;

		$msg = '';
		// add a message based on the remaining spend required
		if ( $ts_item['qsotts-remaining-spend'] > 0 ) {
			$msg = sprintf( __( '%s has been added. %s more is required to Checkout.', 'qsot-table-service' ), wc_price( $cart_item['line_subtotal'] ), wc_price( $ts_item['qsotts-remaining-spend'] ) );
		} else {
			$msg = __( 'The table minimum has been reached. You may now Checkout.', 'qsot-table-service' );
		}

		// add the message to the display
		wc_add_notice( apply_filters( 'qsotts-table-service-progress-message', $msg, $cart_item_key, $ts_item_key ) );
	}

	// check if a given item meets its table service requirements
	public function remaining_min_spend( $item ) {
		$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
		// normalize numbers to products
		if ( is_numeric( $product ) )
			$product = wc_get_product( $product );

		// if the product is not a table service product, bail early
		if ( ! $this->is_table_service( $product ) )
			return 0;

		// figure out the the valid product ids and required amount of spend
		$products = $product->qsotts_product_pool;
		$min_spend = $product->qsotts_min_spend;

		// load the cart because we are going to need to check it for the required items
		$cart = WC()->cart;

		$total_spend = 0;
		// cycle through the items in the cart
		foreach ( $cart->cart_contents as $idx => $cart_item ) {
			// if this item is the item in question, skip it
			if ( $item == $cart_item )
				continue;

			// if we have already reached the max for this item's min spend check, then bail now
			if ( $total_spend >= $min_spend )
				break;

			// if this items is not on the valid item list for the item currently being checked, then skip it
			if ( ! in_array( $cart_item['product_id'], $products ) || ( isset( $cart_item['variation_id'] ) && ! empty( $cart_item['variation_id'] ) && ! in_array( $cart_item['variation_id'], $products ) ) )
				continue;

			// if this item already counts towards the min spend of the table service item (from a previous check), then add that to the aggregate total now, and move on
			if ( isset( $cart_item['qsotts-spend'], $cart_item['qsotts-spend'][ $item['ts_item_id'] ] ) ) {
				$total_spend += $cart_item['qsotts-spend'][ $item['ts_item_id'] ];
				continue;
			}

			$taken = 0;
			// if we have not yet counted this product's entire price towards min_spend checks, then add what we need now, for this table service product
			if ( ! isset( $cart_item['qsotts-spend'] ) || ( ! isset( $cart_item['qsotts-spend'][ $item['ts_item_id'] ] ) && ( $taken = array_sum( array_values( $cart_item['qsotts-spend'] ) ) ) < $cart_item['line_subtotal'] ) ) {
				$take = min( $cart_item['line_subtotal'] - $taken, max( 0, $min_spend - $total_spend ) );
				$cart_item['qsotts-spend'] = isset( $cart_item['qsotts-spend'] ) && is_array( $cart_item['qsotts-spend'] ) ? $cart_item['qsotts-spend'] : array();
				$cart_item['qsotts-spend'][ $item['ts_item_id'] ] = $take;
				$total_spend += $take;
				$cart->cart_contents[ $idx ] = $cart_item;
			}
		}

		return max( 0, $min_spend - $total_spend );
	}

	// check a given item in the cart. if the item has not yet met it's purchase quota, then add a button to indicate it needs to be completed
	public function maybe_table_service_button( $meta, $item ) {
		$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
		// normalize numbers to products
		if ( is_numeric( $product ) )
			$product = wc_get_product( $product );

		// if the product is not a table service product, bail early
		if ( ! $this->is_table_service( $product ) || ! isset( $item['ts_item_id'] ) )
			return $meta;

		// determine if the min_spend requirement has been met
		$remaining_spend = $this->remaining_min_spend( $item );

		// if we have NOT reached the required min_spend, then add a button
		if ( $remaining_spend > 0 ) {
			$meta[] = array(
				'key' => 'Action Required',
				'value' => sprintf(
					'<a class="button" href="%s" title="%s">%s</a>',
					esc_url( $this->get_redirect_url( $item['ts_item_id'] ) ),
					esc_attr( __( 'Complete Reservation', 'qsot-table-service' ) ),
					__( 'Continue', 'qsot-table-service' )
				)
			);
		}

		return $meta;
	}

	// upon successful reservation of non-seating tickets, add a redirect url if the reserved item is a table service item
	public function add_redirect_url( $response, $event, $event_area, $ticket_type ) {
		// if the response is not successful, bail
		if ( ! isset( $response['s'] ) || ! $response['s'] )
			return $response;

		$item_id = $this->get_cart_item_id( array(
			'event_id' => $event->ID,
			'product_id' => $ticket_type->id,
		) );
		$item = WC()->cart->get_cart_item( $item_id );
		// if the item was not found, bail
		if ( ! is_array( $item ) || ! isset( $item['ts_item_id'] ) )
			return $response;

		// get the redirect url for this product
		$url = $this->get_redirect_url( $item['ts_item_id'] );

		// if there is not a redirect url, bail
		if ( ! $url )
			return $response;

		return $response;
	}

	// upon successful reservation of non-seating tickets, add a redirect url if the reserved item is a table service item
	public function add_redirect_url_seating( $response, $event, $event_area, $items ) {
		// if the response is not successful, bail
		if ( ! isset( $response['s'] ) || ! $response['s'] )
			return $response;

		$ts_id = 0;
		// cycle through the response items. if there are any that were successful, then check if they need a table service redirect. only redirect the first one if there are multiple
		if ( isset( $response['r'] ) && is_array( $response['r'] ) && count( $response['r'] ) ) foreach ( $response['r'] as $item ) {
			// if the item was not successful, skip it
			if ( ! isset( $item['s'], $item['t'] ) || ! $item['s'] )
				continue;

			// load the product for the item
			$product = wc_get_product( $item['t'] );

			// if that product is not a table service product, then skip it
			if ( ! ( $product instanceof WC_Product ) || ! $this->is_table_service( $product ) )
				continue;

			$item_id = $this->get_cart_item_id( array(
				'event_id' => $event->ID,
				'product_id' => $product->id,
				'zone_id' => $item['z'],
			) );
			$item = WC()->cart->get_cart_item( $item_id );
			// if the item was not found, bail
			if ( ! is_array( $item ) || ! isset( $item['ts_item_id'] ) )
				continue;

			// set the id of the product we need to redirect for table service on
			$ts_id = $item['ts_item_id'];
			break;
		}

		// if there was no product that needs a table service redirect, then bail now
		if ( ! $ts_id )
			return $response;

		// otherwise, set the redirect url
		$response['forward_url'] = $this->get_redirect_url( $ts_id );

		return $response;
	}

	// add the min_spend to the price list price items for price selection ui
	public function add_min_spend_to_price_item() {
		?><span class="spend"> (<?php _e( 'Min Spend:', 'qsot-table-service' ) ?><span class="min-spend value"></span>)</span><?php 
	}

	// add a section to the zone info tooltip
	public function zone_info_tooltip( $is_admin=false ) {
		?>
			<div class="table-fee price"><span class="qslabel"><?php _e( 'Table Fee:', 'qsot-seating' ) ?></span> <span class="zone-price value"></span></div>
			<div class="spend"><span class="qslabel"><?php _e( 'Bottle Min:', 'qsot-table-service' ) ?></span> <span class="min-spend value"></span></div>
			<div class="total"><span class="qslabel"><?php _e( 'Total:', 'qsot-table-service' ) ?></span> <span class="item-total value"></span></div>
		<?php
	}

	// add the min spend data to all the edata
	public function add_min_spend_to_edata( $edata ) {
		// add the min_spend value to the ticket information
		foreach ( $edata['ticket_types'] as $id => $tt ) {
			// get the ticket product
			$product = wc_get_product( $id );

			// default to 0
			$min_spend = 0.0;

			// if we have a product, calc the min_spend
			if ( $product instanceof WC_Product )
				$min_spend = floatval( $product->qsotts_min_spend );

			// get the price of the table itself
			$price = $product->get_price();

			// add the min_spend
			$tt->min_spend = wc_price( $min_spend );
			$tt->min_spend_f = $min_spend;
			$tt->total = wc_price( $price + $min_spend );
			$tt->total_f = $price + $min_spend;
			$edata['ticket_types'][ $id ] = $tt;
		}

		return $edata;
	}

	// setup the options related to this plugin
	protected function _setup_options() {
		// register our options
		// create the table service header
		$this->options->add( array(
			'order' => 700,
			'type' => 'title',
			'title' => __( 'Table Service', 'qsot-table-service' ),
			'id' => 'heading-frontend-table-service',
			'page' => 'frontend',
		) );

		// option to select the 'table service' target page
		$this->options->add( array(
			'order' => 705,
			'title' => __( 'Table Service Page', 'qsot-table-service' ),
			'desc' => '<br/>' . __( 'This it the page that contains the [table-service] shortcode, where users will be sent to complete their table service requirements, before being allowed to checkout.', 'qsot-table-service' ),
			'id' => 'qsot_table_service_page_id',
			'type' => 'single_select_page',
			'default' => '',
			'class' => 'wc-enhanced-select-nostd',
			'css' => 'min-width:300px;',
			'desc_tip' => __( 'The page where users will find the list of products they can choose from, to complete a min_spend table service requirement.', 'qsot-table-service' ),
			'page' => 'frontend',
		) );

		// end the table service header
		$this->options->add( array(
			'order' => 799,
			'type' => 'sectionend',
			'id' => 'heading-frontend-table-service',
			'page' => 'frontend',
		) );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Table_Service_Frontend::instance();
