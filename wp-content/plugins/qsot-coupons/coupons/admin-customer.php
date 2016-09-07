<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// security. if WC() is available, then
if ( function_exists( 'WC' ) ) {
	// funciton to load the core WC customer
	function qsot_admin_customer_load_wc_customer() {
		// base wc path
		$path = trailingslashit( WC()->plugin_path() );

		// load the required fils
		include_once( $path . 'includes/class-wc-customer.php' );
	}
	qsot_admin_customer_load_wc_customer();
}

// if the cart does not exist by now, then fail
if ( ! class_exists( 'WC_Customer' ) )
	return false;

// cart used in the admin to recalculate order totals after adding coupons
class qsot_admin_customer extends WC_Customer {
	// load the customer based on the order id
	public function __construct( $order_id=0 ) {
		// if the order_id is supplied, pull out the data from the order
		if ( $order_id > 0 ) {
			// load the order meta
			$meta = get_post_meta( $order_id, null, true );
			// 'singlize' the order meta
			foreach ( $meta as $k => $v )
				$meta[ $k ] = maybe_unserialize( end( $v ) );

			// load the billing info
			$prefix = '';
			$real_prefix = '_billing_';
			foreach ( array( 'postcode', 'city', 'address', 'address_2', 'state', 'country' ) as $suffix )
				$this->_data[ $prefix . $suffix ] = isset( $meta[ $real_prefix . $suffix ] ) ? $meta[ $real_prefix . $suffix ] : '';

			// load the shipping info
			$prefix = 'shipping_';
			$real_prefix = '_shipping_';
			foreach ( array( 'postcode', 'city', 'address', 'address_2', 'state', 'country' ) as $suffix )
				$this->_data[ $prefix . $suffix ] = isset( $meta[ $real_prefix . $suffix ] ) ? $meta[ $real_prefix . $suffix ] : '';

			// load additional meta
			$this->_data['is_vat_exempt'] = isset( $meta['is_vat_exempt'] ) && $meta['is_vat_exempt'];
			$this->_data['calculated_shipping'] = false;
		}

		if ( empty( $this->_data ) ) {
			// Defaults
			$this->_data = array(
				'postcode'            => '',
				'city'                => '',
				'address'             => '',
				'address_2'           => '',
				'state'               => '',
				'country'             => '',
				'shipping_postcode'   => '',
				'shipping_city'       => '',
				'shipping_address'    => '',
				'shipping_address_2'  => '',
				'shipping_state'      => '',
				'shipping_country'    => '',
				'is_vat_exempt'       => false,
				'calculated_shipping' => false
			);

			if ( is_user_logged_in() ) {
				foreach ( $this->_data as $key => $value ) {
					$meta_value          = get_user_meta( get_current_user_id(), ( false === strstr( $key, 'shipping_' ) ? 'billing_' : '' ) . $key, true );
					$this->_data[ $key ] = $meta_value ? $meta_value : $this->_data[ $key ];
				}
			}

			if ( empty( $this->_data['country'] ) ) {
				$this->_data['country'] = $this->get_default_country();
			}
			if ( empty( $this->_data['shipping_country'] ) ) {
				$this->_data['shipping_country'] = $this->get_default_country();
			}
			if ( empty( $this->_data['state'] ) ) {
				$this->_data['state'] = $this->get_default_state();
			}
			if ( empty( $this->_data['shipping_state'] ) ) {
				$this->_data['shipping_state'] = $this->get_default_state();
			}
		}
	}
}
