<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the admin handler for the box-office plugin
class qsot_bo_admin {
	// store the current order used in the ajax requests
	protected static $order = null;
	// store the current 'posted' data used in ajax request
	protected static $posted = array();

	// setup the class's functionality
	public static function pre_init() {
		if ( is_admin() ) {
			// establish teh metaboxes
			add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_boxes' ), 1000 );

			// register and load the assets, like js and css
			add_filter( 'admin_init', array( __CLASS__, 'register_assets' ), 10 );
			add_filter( 'qsot-admin-load-assets-shop_order', array( __CLASS__, 'admin_assets' ), 10, 2 );

			// handle admin ajax requests
			add_action( 'wp_ajax_qsot-bo-admin', array( __CLASS__, 'handle_admin_ajax' ), 100 );
			add_action( 'qsot-bo-admin-ajax-accept-payment-form', array( __CLASS__, 'aj_accept_payment_form' ), 100, 2 );
			add_action( 'qsot-bo-admin-ajax-process-payment', array( __CLASS__, 'aj_process_payment' ), 100, 2 );

			// add msgs for the admin UI
			add_filter( 'qsot-bo-admin-msgs', array( __CLASS__, 'admin_msgs' ), 100, 3 );

			// handle payment box submits that were not thorugh js, like the stripe payment completion
			add_action( 'admin_init', array( __CLASS__, 'handle_payment_submit' ), 1 );
		}
	}

	// add the various relevant metaboxes
	public static function add_meta_boxes() {
		$screens = array( 'shop_order' );
		foreach ( $screens as $screen ) {
			// create the payment handler metabox
			add_meta_box(
				'qsot-admin-payment',
				__( 'Payments', 'qsot-box-office' ),
				array( __CLASS__, 'mb_admin_payment' ),
				$screen,
				'side',
				'high'
			);
		}
	}

	// register the assets used in the admin
	public static function register_assets() {
		$url = QSOT_box_office_launcher::plugin_url() . 'assets/';
		$version = QSOT_box_office_launcher::version();

		wp_register_script( 'qsot-bo-tools', $url . 'js/utils/tools.js', array( 'qsot-tools', 'underscore', 'backbone' ), $version );
		wp_register_style( 'qsot-box-office', $url . 'css/admin/ui.css', array( /* 'wp-jquery-ui-dialog' */ ), $version );
		wp_register_script( 'qsot-box-office', $url . 'js/admin/ui.js', array( 'qsot-bo-tools', 'wc-admin-order-meta-boxes' ), $version );
	}

	// load the admin assets
	public static function admin_assets( $exists, $post_id ) {
		wp_enqueue_style( 'qsot-box-office' );
		wp_enqueue_script( 'qsot-box-office' );
		wp_localize_script( 'qsot-box-office', '_qsot_bo_settings', array(
			'nonce' => wp_create_nonce( 'qsot-fetch-admin-nonce' ),
			'msgs' => apply_filters( 'qsot-bo-admin-msgs', array(), $post_id, $exists ),
		) );
	}

	// list of all the messages used in the admin ui
	public static function admin_msgs( $list, $post_id, $exists ) {
		$new_list = array(
			'Loading...' => __( 'Loading...', 'qsot-box-office' ),
		);

		return array_merge( $list, $new_list );
	}

	// draw the accept payments metabox
	public static function mb_admin_payment( $post ) {
		// load the order
		$order = wc_get_order( $post->ID );

		// determine the date of the last payment
		$last = get_post_meta( $order->id, '_qsot_last_payment', true );
		$last = empty( $last ) && 'completed' == $order->get_status() ? strtotime( $order->post->post_date_gmt ) : $last;

		// statuses in which payment can be accepted completely
		$valid_order_statuses = apply_filters( 'woocommerce_valid_order_statuses_for_payment_complete', array( 'on-hold', 'pending', 'failed', 'cancelled', 'completed' ), $order );
		?>
			<div class="loading">
				<strong><em><?php _e( 'Loading. Please Wait...', 'qsot-box-office' ) ?></em></strong>
			</div>

			<div class="unchanged">
				<div class="last">
					<?php if ( $order->needs_payment() ): ?>
						<div class="bad"><?php _e( 'This order still needs a payment.', 'qsot-box-office' ) ?></div>
					<?php else: ?>
						<div class="good">
							<?php if ( $last > 0 ): ?>
								<?php echo sprintf( __( 'The last payment was received: %s', 'qsot-box-office' ), '<br/><strong>' . date( __( 'F j, Y g:ia', 'qsot-box-office' ), $last ) . '</strong>' ) ?>
							<?php elseif ( ! $order->get_total() ): ?>
								<?php echo __( 'The order has never received a payment, but it also has a zero balance, so it does not need one.', 'qsot-box-office' ) ?>
							<?php else: ?>
								<?php echo __( 'No payment has been received for this order, but the order has a status that does not require one currently.', 'qsot-box-office' ) ?>
							<?php endif; ?>
						</div>
					<?php endif; ?>
				</div>
				<?php if ( in_array( $order->get_status(), $valid_order_statuses ) ): ?>
					<div class="actions">
						<input type="button" rel="accept-payment" value="<?php _e( 'Accept Payment', 'qsot-box-office' ) ?>" class="button accept-payment" />
					</div>
				<?php else: ?>
					<div class="actions">
						<div class="bad"><?php _e( 'Payment cannot be accepted while the order is in this status. Please change the status to On-Hold or Pending Payment to enable this feature.', 'qsot-seating' ) ?></div>
					</div>
				<?php endif; ?>
				<?php /*
				<div class="extra">
					<a href="#" rel="payment-history" class="payment-history"><?php _e( 'payment history', 'qsot-box-office' ) ?></a>
				</div>
				*/ ?>
			</div>

			<div class="changed">
				<p><?php _e( 'You have made changes to this order. Before you can take a payment, you must save those changes.', 'qsot-box-office' ) ?></p>
			</div>

			<script type="text/template" id="qsot-bo-accept-payment">
				<div class="wc-backbone-modal qsot-bo-accept-payment">
					<div class="wc-backbone-modal-content">
						<section class="wc-backbone-modal-main" role="main">
							<header class="wc-backbone-modal-header">
								<a class="modal-close modal-close-link" href="#"><span class="close-icon"><span class="screen-reader-text"><?php _e( 'Close', 'qsot-box-office' ) ?></span></span></a>
								<h1><?php _e( 'Accept Payment', 'qsot-box-office' ); ?></h1>
							</header>
							<article>
							</article>
						</section>
					</div>
				</div>
				<div class="wc-backbone-modal-backdrop modal-close">&nbsp;</div>
			</script>
		<?php
	}

	// if the payment submission was not submitted through ajax, then process that now
	public static function handle_payment_submit() {
		$order_id = isset( $_REQUEST['post'] ) ? $_REQUEST['post'] : 0;
		//die(var_dump( $order_id, $post, current_user_can( 'edit_shop_order', $order_id ), wp_create_nonce( 'qsot-bo-admin-' . $order_id ), $_POST ));
		// if the current user cannot edit the order, then bail
		if ( $order_id <= 0 || ! current_user_can( 'edit_shop_order', $order_id ) )
			return;

		// if the nonce does not match, then bail
		if ( ! isset( $_POST['admin-payment-submit'] ) || ! wp_verify_nonce( $_POST['admin-payment-submit'], 'qsot-bo-admin-' . $order_id ) )
			return;

		// fake a frontend request, because of the 'cart empty' call in some gateways, like stripe
		include_once( WC()->plugin_path() . '/includes/abstracts/abstract-wc-session.php' );
		include_once( WC()->plugin_path() . '/includes/class-wc-session-handler.php' );
		WC()->frontend_includes();
		$session_class = apply_filters( 'woocommerce_session_handler', 'WC_Session_Handler' );
		WC()->session = new $session_class();
		WC()->cart = new WC_Cart();
		WC()->customer = new WC_Customer();

		// get the gateways
		$gateways = WC()->payment_gateways()->payment_gateways();

		// if the selected gateway is not present, then bail
		if ( ! isset( $_POST['payment_method'] ) || ! is_string( $_POST['payment_method'] ) || ! isset( $gateways[ $_POST['payment_method'] ] ) )
			return;
		$gateway = $gateways[ $_POST['payment_method'] ];

		// process the payment for that gateway
		if ( is_callable( array( $gateway, 'process_payment' ) ) )
			$gateway->process_payment( $order_id );

		// redirect back to the post edit
		wp_safe_redirect( remove_query_arg( 'updated' ) );
		exit;
	}

	// handle admin ajax requests
	public static function handle_admin_ajax() {
		// fetch the Sub-Action and the order from the data sent
		$sa = $_POST['sa'];
		$order = isset( $_POST['oid'] ) && is_numeric( $_POST['oid'] ) && $_POST['oid'] > 0 ? wc_get_order( $_POST['oid'] ) : false;

		// initialize the response
		$resp = array( 's' => false, 'e' => array() );

		// if this is a request to get an updated security token, and they pass security on that request, then generate a new security code
		if ( 'sec' == $sa && is_object( $order ) && isset( $order->id ) && wp_verify_nonce( $_POST['n'], 'qsot-fetch-admin-nonce' ) ) {
			$resp = self::_get_post_nonce( $order );
		// if this is a different ajax request that we have a handler for, and the security on that passes, then try to process it
		} else if ( has_action( 'qsot-bo-admin-ajax-' . $sa ) && isset( $_POST['n'] ) && is_object( $order ) && isset( $order->id ) && ! empty( $_POST['n'] ) && wp_verify_nonce( $_POST['n'], 'qsot-bo-admin-' . $order->id ) ) {
			$resp = apply_filters( 'qsot-bo-admin-ajax-' . $sa, $resp, $order );
		// otherwise error out
		} else {
			$resp['e'][] = __( 'Sorry your request could not be processed. Try refreshing the page, and attempting it again.', 'qsot-box-office' );
		}

		// print the results 
		echo @json_encode( $resp );
		exit;
	}

	// get the new security code for this order page. this is needed because on new orders, we do not know the order id before the page load, so a proper one cannot be made
	protected static function _get_post_nonce( $order ) {
		// verify that the current user has permissions to do this
		if ( ! current_user_can( 'edit_shop_order', $order->id ) ) {
			$resp['e'][] = __( 'Sorry you do not have permission to perform that action.', 'qsot-box-office' );
			return $resp;
		}

		// create an nonce for this order specifically
		$resp['r'] = wp_create_nonce( 'qsot-bo-admin-' . $order->id );
		$resp['s'] = true;

		return $resp;
	}

	// trick the order into thinking it needs a payment, so that it will dislay the payment types in the admin accept payment metabox
	public static function trick_needs_payment( $current, $order, $valid_stati ) {
		return true;
	}

	// return true
	public static function ret_yes() { return true; }

	// render the accept payment form for the admin
	public static function aj_accept_payment_form( $resp, $order ) {
		// verify that the current user has permissions to do this
		if ( ! current_user_can( 'edit_shop_order', $order->id ) ) {
			$resp['e'][] = __( 'Sorry you do not have permission to perform that action.', 'qsot-box-office' );
			return $resp;
		}

		// trick all gateways into thinking this is the checkout page
		add_filter( 'woocommerce_is_checkout', array( __CLASS__, 'ret_yes' ), 1000 );

		// trick the template into rendering the payment method list
		add_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'trick_needs_payment' ), 1000000, 3 );

		// filter out gateways with known issues
		add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'filter_out_some_gateways' ), 1000000, 1 );

		// setup the local vas we need inside the template
		$checkout = WC()->checkout();
		$available_gateways = WC()->payment_gateways()->get_available_payment_gateways();
		$order_button_text = apply_filters( 'woocommerce_order_button_text', __( 'Place order', 'woocommerce' ) );

		// render the form, and capture the output
		ob_start();

		// add any special checkout header scripts and styles
		wp_enqueue_scripts();
		wp_print_styles();
		wp_print_head_scripts();

		// draw the template itself
		$template = QSOT_Templates::locate_woo_template( 'checkout/form-pay.php' );
		include $template;

		// print the footer scripts
		wp_print_footer_scripts();

		$out = ob_get_contents();
		ob_end_clean();

		// remove the theme stylsheet if present, because it effes up the admin styles
		$uri = get_stylesheet_uri();
		$out = preg_replace( '#<link[^>]*?href=(["\'])[^\1"\']*' . preg_quote( $uri, '#' ) . '[^\1"\']*\1[^>]*?'.'>#', '', $out );

		// remove the tricks, just in case it messes with other output as well
		remove_filter( 'woocommerce_order_needs_payment', array( __CLASS__, 'trick_needs_payment' ), 1000000 );
		remove_filter( 'woocommerce_is_checkout', array( __CLASS__, 'ret_yes' ), 1000 );

		// generate the response
		$resp['r'] = $out;
		$resp['s'] = true;

		return $resp;
	}

	// some gateways will not work with this admin payments setup. filter the known ones out here
	public static function filter_out_some_gateways( $list ) {
		$new_list = array();
		// cycle through the list and filter out any that match our known issue criteria
		foreach ( $list as $key => $item ) {
			// if the entry is a string, match against known classes
			if ( is_string( $item ) && in_array( $item, array( 'WC_Nelnet_Payments' ) ) )
				continue;

			$new_list[] = $item;
		}

		return $new_list;
	}

	// handle ajax requests to process a payment
	public static function aj_process_payment( $resp, $order ) {
		// verify that the current user has permissions to do this
		if ( ! current_user_can( 'edit_shop_order', $order->id ) ) {
			$resp['e'][] = __( 'Sorry you do not have permission to perform that action.', 'qsot-box-office' );
			return $resp;
		}

		// obtain and validate the selected payment method
		$gateways = WC()->payment_gateways->get_available_payment_gateways();
		if ( ! isset( $_POST['payment_method'], $gateways[ $_POST['payment_method'] ] ) ) {
			$resp['e'][] = __( 'Sorry the selected payment method is not available.', 'qsot-box-office' );
			return $resp;
		}
		$gateway = $gateways[ $_POST['payment_method'] ];

		// validate all the checkout fields. if there are any errors, add them to the response and fail
		if ( ! self::_validate_checkout_fields( $order ) ) {
			$resp['e'] = wc_get_notices( 'error' );
			wc_clear_notices();
			return $resp;
		}

		// validate the payment method fields
		$gateway->validate_fields();
		if ( wc_notice_count( 'error' ) > 0 ) {
			$resp['e'] = wc_get_notices( 'error' );
			wc_clear_notices();
			return $resp;
		}

		// Action after validation
		do_action( 'woocommerce_after_checkout_validation', self::$posted );

		// change the payment return url
		self::$order = $order;
		add_filter( 'woocommerce_get_return_url', array( __CLASS__, 'admin_payment_return_url' ), PHP_INT_MAX );

		// set the order's payment method, and mark it as being from the admin
		$order->set_payment_method( $gateway );
		update_post_meta( $order->id, '_payment_from', 'admin' );

		// fetch the current user (could be id 0) and store the id in the order meta, for use when the API response comes back
		$u = wp_get_current_user();
		update_post_meta( $order->id, '_payment_user', $u->ID );

		// process the payment
		$result = $gateway->process_payment( $order->id );

		// restore payment return url
		remove_filter( 'woocommerce_get_return_url', array( __CLASS__, 'admin_payment_return_url' ), PHP_INT_MAX );

		// upon successful processing, respond to the ajax with a response it expects
		if ( 'success' == $result['result'] ) {
			$result = apply_filters( 'woocommerce_payment_successful_result', $result, $order->id );
			$resp['s'] = true;
			$resp['r'] = isset( $result['redirect'] ) && ! empty( $result['redirect'] ) ? $result['redirect'] : get_edit_post_link( $order->id );
		// otherwise respond with a series of errors that describe the problem
		} else {
			$resp['e'] = wc_get_notices( 'error' );
			wc_clear_notices();
			return $resp;
		}

		// clear out any remaining notices
		wc_clear_notices();

		return $resp;
	}

	// override the return url, if any, when taking an order in the admin, so that the user returns to the edit order page
	public static function admin_payment_return_url( $url ) {
		if ( isset( self::$order ) && is_object( self::$order ) )
			$url = get_edit_post_link( self::$order->id, 'url' );
		return $url;
	}

	// validate all the checkout fields, to emulate a real checkout
	protected static function _validate_checkout_fields( $order ) {
		// determine the country to validate the fields for
		$billing_country = get_post_meta( $order->id, '_billing_country', true );
		$shipping_country = get_post_meta( $order->id, '_shipping_country', true );
		$shipping_country = empty( $shipping_country ) ? $billing_country : $shipping_country;

		// Define all relevant Checkout fields
		$checkout_fields['billing'] 	= WC()->countries->get_address_fields( $billing_country, 'billing_' );
		$checkout_fields['shipping'] 	= WC()->countries->get_address_fields( $shipping_country, 'shipping_' );
		$checkout_fields = apply_filters( 'woocommerce_checkout_fields', $checkout_fields );
	
		// get the user_id of the owner of the order if it exists
		$user_id = get_post_meta( $order->id, '_customer_id', true );

		// gather all checkout fields from order meta
		$posted = array();
		$shipping_empty = true;
		// for each group of fields
		foreach ( $checkout_fields as $fieldset => $fields ) {
			// for each field in the group
			foreach ( $fields as $field_name => $field ) {
				// load the value for the field
				if ( isset( $_POST[ $field_name ] ) )
					$posted[ $field_name ] = $_POST[ $field_name ];
				else if ( $meta_value = get_post_meta( $order->id, '_' . $field_name, true ) )
					$posted[ $field_name ] = $meta_value;
				else if ( $meta_value = get_post_meta( $order->id, $field_name, true ) )
					$posted[ $field_name ] = $meta_value;
				else
					$posted[ $field_name ] = '';

				// keep track of any shipping fields that may be filled out
				if ( 'shipping_' == substr( $field_name, 0, 9 ) && '' != $posted[ $field_name ] )
					$shipping_empty = false;
			}
		}

		$posted = apply_filters( 'qsot-bo-admin-checkout-posted', $posted, $order, $checkout_fields );

		// fill the shipping fields with the billing information, if they are all empty
		if ( $shipping_empty ) {
			foreach ( $posted as $field_name ) {
				if ( 'shipping_' == substr( $field_name, 0, 9 ) ) {
					$posted[ $field_name ] = $posted[ 'billing_' . substr( $field_name, 9 ) ];
				}
			}
		}

		// actually validate all the fields
		foreach ( $checkout_fields as $fieldset => $fields ) {
			if ( 'account' == $fieldset )
			// for each field in the group
			foreach ( $fields as $key => $field ) {
				// Hooks to allow modification of value
				$posted[ $key ] = apply_filters( 'woocommerce_process_checkout_' . sanitize_title( $field['type'] ) . '_field', $posted[ $key ] );
				$posted[ $key ] = apply_filters( 'woocommerce_process_checkout_field_' . $key, $posted[ $key ] );

				// Validation: Required fields
				if ( isset( $field['required'] ) && $field['required'] && empty( $posted[ $key ] ) ) {
					wc_add_notice( '<strong>' . $field['label'] . '</strong> ' . __( 'is a required field.', 'woocommerce' ), 'error' );
				}

				if ( ! empty( $posted[ $key ] ) ) {

					// Validation rules
					if ( ! empty( $field['validate'] ) && is_array( $field['validate'] ) ) {
						foreach ( $field['validate'] as $rule ) {
							switch ( $rule ) {
								case 'postcode' :
									$posted[ $key ] = strtoupper( str_replace( ' ', '', $posted[ $key ] ) );

									if ( ! WC_Validation::is_postcode( $posted[ $key ], $_POST[ $fieldset_key . '_country' ] ) ) :
										wc_add_notice( __( 'Please enter a valid postcode/ZIP.', 'woocommerce' ), 'error' );
									else :
										$posted[ $key ] = wc_format_postcode( $posted[ $key ], $_POST[ $fieldset_key . '_country' ] );
									endif;
								break;
								case 'phone' :
									$posted[ $key ] = wc_format_phone_number( $posted[ $key ] );

									if ( ! WC_Validation::is_phone( $posted[ $key ] ) )
										wc_add_notice( '<strong>' . $field['label'] . '</strong> ' . __( 'is not a valid phone number.', 'woocommerce' ), 'error' );
								break;
								case 'email' :
									$posted[ $key ] = strtolower( $posted[ $key ] );

									if ( ! is_email( $posted[ $key ] ) )
										wc_add_notice( '<strong>' . $field['label'] . '</strong> ' . __( 'is not a valid email address.', 'woocommerce' ), 'error' );
								break;
								case 'state' :
									// Get valid states
									$valid_states = WC()->countries->get_states( isset( $_POST[ $fieldset_key . '_country' ] ) ? $_POST[ $fieldset_key . '_country' ] : ( 'billing' === $fieldset_key ? WC()->customer->get_country() : WC()->customer->get_shipping_country() ) );

									if ( ! empty( $valid_states ) && is_array( $valid_states ) ) {
										$valid_state_values = array_flip( array_map( 'strtolower', $valid_states ) );

										// Convert value to key if set
										if ( isset( $valid_state_values[ strtolower( $posted[ $key ] ) ] ) ) {
											 $posted[ $key ] = $valid_state_values[ strtolower( $posted[ $key ] ) ];
										}
									}

									// Only validate if the country has specific state options
									if ( ! empty( $valid_states ) && is_array( $valid_states ) && sizeof( $valid_states ) > 0 ) {
										if ( ! in_array( $posted[ $key ], array_keys( $valid_states ) ) ) {
											wc_add_notice( '<strong>' . $field['label'] . '</strong> ' . __( 'is not valid. Please enter one of the following:', 'woocommerce' ) . ' ' . implode( ', ', $valid_states ), 'error' );
										}
									}
								break;
							}
						}
					}
				}
			}
		}
	
		self::$posted = $posted;

		// if there are any errors, then fail validation
		if ( wc_notice_count( 'error' ) > 0 )
			return false;

		return true;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_bo_admin::pre_init();
