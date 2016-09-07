<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// security. if WC() is available, then
if ( function_exists( 'WC' ) ) {
	// funciton to load the core WC cart
	function qsot_admin_cart_load_wc_cart() {
		// base wc path
		$path = trailingslashit( WC()->plugin_path() );

		// load the required fils
		include_once( $path . 'includes/wc-cart-functions.php' );
		include_once( $path . 'includes/class-wc-cart.php' );
		include_once( $path . 'includes/wc-notice-functions.php' );
		include_once( $path . 'includes/class-wc-tax.php' );
		include_once( $path . 'includes/class-wc-customer.php' );
	}
	qsot_admin_cart_load_wc_cart();
}

// if the cart does not exist by now, then fail
if ( ! class_exists( 'WC_Cart' ) )
	return false;

// cart used in the admin to recalculate order totals after adding coupons
class qsot_admin_cart extends WC_Cart {
	// holds the order that this cart was derived from
	public $order = null;

	// holds the packages that are available for use on shipping
	public $packages = array();
	// holder for the order selected chosen shipping method
	public $chosen_shipping_method = null;
	// oiid of the chosen_shipping_method
	public $chosen_shipping_method_oiid = 0;

	// holder for the order selected tax class
	public $tax_rate_id = 0;

	// setup the admin cart
	public function __construct() {
		$this->prices_include_tax = wc_prices_include_tax();
		$this->round_at_subtotal = get_option( 'woocommerce_tax_round_at_subtotal' ) == 'yes';
		$this->tax_display_cart = get_option( 'woocommerce_tax_display_cart' );
		$this->dp = wc_get_price_decimals();
		$this->display_totals_ex_tax = $this->tax_display_cart == 'excl';
		$this->display_cart_ex_tax = $this->tax_display_cart == 'excl';
		$this->packages = array();
		$this->chosen_shipping_method = null;
		$this->tax_rate_id = 0;

		// call init here, since the core cart action to do so has already passed
		$this->init();

		// overtake the tax class checks, to use the order selected class
		add_action( 'woocommerce_find_rates', array( $this, 'override_tax_class' ), PHP_INT_MAX, 2 );
	}

	// during load, setup some actions we need
	public function init() {
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_items' ), 1 );
		add_action( 'woocommerce_check_cart_items', array( $this, 'check_cart_coupons' ), 1 );
		add_action( 'woocommerce_after_checkout_validation', array( $this, 'check_customer_coupons' ), 1 );
	}

	// override set_session, so that no sessions ever get set
	public function set_session() {}

	// empty the current admin cart, without the messy cart session crap
	public function empty_cart( $clear_persistent_cart = true ) {
		$this->cart_contents = array();
		do_action( 'woocommerce_cart_emptied', 'admin' );
	}

	// set cart items, coupons, shipping, etc..., from an order
	public function set_from_order( $order ) {
		// make sure we have an order object
		$this->order = $order = wc_get_order( $order );

		// for every line item (except coupons), add an entry to the admin cart
		foreach ( $order->get_items( array( 'line_item', 'shipping', 'fee', 'tax' ) ) as $oiid => $item ) {
			// add the order item id to the item meta, so we can associate the results to an actual order item later
			$item['oiid'] = $oiid;

			// do something different depending on the item type
			switch ( $item['type'] ) {

				// for products, add a line item to the cart
				case 'line_item':
					// find the basic raw data for the item
					$product_id = isset( $item['product_id'] ) ? (int)$item['product_id'] : 0;
					$variation_id = isset( $item['variation_id'] ) ? $item['variation_id'] : '';
					$qty = isset( $item['qty'] ) ? (int)$item['qty'] : 0;
					$variation = array();

					// if this is a variation product, load the variation so that we can fetch the variation information
					if ( $variation_id ) {
						// load the variation
						$product = wc_get_product( $variation_id );

						// fetch the variation information
						$variation = $product->get_variation_attributes();
					}

					// if there is not a product_id, or non-positive quantity, then the item is invalid
					if ( $product_id <= 0 || $qty <= 0 )
						continue;

					// unset teh unneeded item data
					unset( $item['item_meta'], $item['product_id'], $item['variation_id'], $item['qty'], $item['line_tax'], $item['line_subtotal'] );
					$item['line_tax_data'] = array( 'total' => array(), 'subtotal' => array() );

					// add the item to the cart
					$this->add_to_cart( $product_id, $qty, $variation_id, $variation, $item );
				break;

				// for coupons update the chosen_shipping_method, which is used during shipping calculation emulation below. also keep trak of the shipping oiid
				case 'shipping':
					$this->chosen_shipping_method = $item['method_id'];
					$this->chosen_shipping_method_oiid = (int) $oiid;
				break;

				// for fees, add a cart fee
				case 'fee':
					// get the fee informtation
					$name = $item['name'];
					$amt = $item['line_total'];
					$taxable = $item['line_tax'] > 0;
					$tax_class = $item['tax_class'];

					// add the fee to the cart
					$this->add_fee( $name, $amt, $taxable, $tax_class );
				break;

				case 'tax':
					$this->tax_rate_id = (int)$item['rate_id'];
				break;
			}
		}

		// get a prelim totaling, so that min an max spends can be properly calculated below
		$this->calculate_totals();

		// do the coupons after all other items have been added, since they are dependent on the cart contents
		foreach ( $order->get_items( array( 'coupon' ) ) as $oiid => $item ) {
			// get the coupon code
			$code = $item['name'];

			// add the coupon to the cart
			$this->add_discount( $code );
		}
	}

	// over take the shipping calculation
	public function calculate_shipping() {
		if ( $this->needs_shipping() && $this->show_shipping() ) {
			$this->_calculate_shipping( $this->get_shipping_packages() );
		}
	}

	// override the tax class selection with the one designated on the order, if there is one
	public function override_tax_class( $classes, $args ) {
		$classes = array();

		// if there is an order specified tax rate id, use it
		if ( $this->tax_rate_id > 0 ) {
			global $wpdb;
			// fetch the tax rate record based on the id
			$q = $wpdb->prepare( 'select * from ' . $wpdb->prefix . 'woocommerce_tax_rates where tax_rate_id = %d', $this->tax_rate_id );
			$rate = $wpdb->get_row( $q );

			// if the rate exists, then
			if ( is_object( $rate ) ) {
				$classes[ $rate->tax_rate_id . '' ] = array(
					'rate' => $rate->tax_rate,
					'label' => $rate->tax_rate_name,
					'shipping' => $rate->tax_rate_shipping ? 'yes' : 'no',
					'compound' => $rate->tax_rate_compound ? 'yes' : 'no',
				);
			}
		}

		return $classes;
	}

	// emulate WP_Shipping->calculate_shipping()
	protected function _calculate_shipping( $packages = array() ) {
		// only do this if the shipping is enabled and there are packages available to process
		if ( get_option('woocommerce_calc_shipping') == 'no' || empty( $packages ) ) {
			return;
		}

		$this->shipping_total 	= null;
		$this->shipping_taxes 	= array();
		$this->packages 		= array();

		// Calculate costs for passed packages
		$package_keys 		= array_keys( $packages );
		$package_keys_size 	= sizeof( $package_keys );

		for ( $i = 0; $i < $package_keys_size; $i ++ ) {
			$this->packages[ $package_keys[ $i ] ] = WC()->shipping->calculate_shipping_for_package( $packages[ $package_keys[ $i ] ] );
		}

		// Get chosen methods for each package
		foreach ( $this->packages as $i => $package ) {

			$_cheapest_cost   = false;
			$_cheapest_method = false;
			$chosen_method    = false;
			$method_count     = false;

			if ( ! empty( $chosen_methods[ $i ] ) ) {
				$chosen_method = $chosen_methods[ $i ];
			}

			if ( ! empty( $method_counts[ $i ] ) ) {
				$method_count = $method_counts[ $i ];
			}

			// Get available methods for package
			$_available_methods = $package['rates'];

			if ( sizeof( $_available_methods ) > 0 ) {

				// Store total costs
				if ( $this->chosen_shipping_method && isset( $_available_methods[ $this->chosen_shipping_method ] ) ) {
					$rate = $_available_methods[ $this->chosen_shipping_method ];

					// Merge cost and taxes - label and ID will be the same
					$this->shipping_total += $rate->cost;

					foreach ( array_keys( $this->shipping_taxes + $rate->taxes ) as $key ) {
					  $this->shipping_taxes[ $key ] = ( isset( $rate->taxes[$key] ) ? $rate->taxes[$key] : 0 ) + ( isset( $this->shipping_taxes[$key] ) ? $this->shipping_taxes[$key] : 0 );
					}
				}
			}
		}
	}
}
