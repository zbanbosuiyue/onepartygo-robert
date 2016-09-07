<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to hand the new products and product options available with our plugin
class qsot_coupons_product {
	// containers for product post when generating the coupon form
	protected static $orig_post = null;
	protected static $orig_thepostid = null;
	protected static $orig_post_cc_meta = null;

	// track if the '{rand}' code format element has been used yet on a code
	protected static $rand_used = false;

	// current product that a code is being generated for. used to track the product through a preg_replace_callback
	protected static $current_code_product = null;

	// setup the coupons product class
	public static function pre_init() {
		// add the new product options
		add_filter( 'product_type_options', array( __CLASS__, 'add_product_options_checkboxes' ), 10 );

		// add the new product options tabs, if any
		add_filter( 'woocommerce_product_data_tabs', array( __CLASS__, 'add_product_options_tabs' ), 10 );

		// draw our new product options tab panels
		add_action( 'woocommerce_product_data_panels', array( __CLASS__, 'draw_new_panels' ), 10 );

		// when saving a product, save our coupon related meta data too
		add_action( 'save_post', array( __CLASS__, 'save_product' ), 10, 3 );

		// when adding a create_coupon product to an order, immediately generate the coupon code
		add_action( 'woocommerce_order_add_product', array( __CLASS__, 'on_add_order_product' ), 1000, 5 );
		add_action( 'woocommerce_ajax_add_order_item_meta', array( __CLASS__, 'on_add_order_product_from_admin' ), 1000, 2 );

		// utils
		add_filter( 'qsot-coupons-generate-code', array( __CLASS__, 'generate_code' ), 100, 3 );
		add_filter( 'qsot-get-rand', array( __CLASS__, 'get_rand' ), 1, 2 );

		// display related utils
		add_filter( 'woocommerce_hidden_order_itemmeta', array( __CLASS__, 'hide_coupon_item_meta' ), 100, 1 );
		add_action( 'woocommerce_before_order_itemmeta', array( __CLASS__, 'order_item_coupon_code' ), 100, 4 );
		add_action( 'woocommerce_order_item_meta_start', array( __CLASS__, 'order_details_coupon_code' ), 100, 3 );
	}

	// actually add the new product options checkboxes to the product page
	public static function add_product_options_checkboxes( $list ) {
		// add the 'create coupon' product option
		$list['create_coupon'] = array(
			'id' => '_create_coupon',
			'wrapper_class' => 'show_if_simple',
			'label' => __( 'Create Coupon', 'qsot-coupons' ),
			'description' => __( 'Allows this product to generate a coupon code upon purchase.', 'qsot-coupons' ),
		);

		return $list;
	}

	// add the tabs for our new product options
	public static function add_product_options_tabs( $list ) {
		// add the create coupon tab for products who have the 'create coupon' option checked
		$list['create_coupon'] = array(
			'label' => __( 'Coupon', 'qsot-coupons' ),
			'target' => 'create_coupon_settings',
			'class' => array( 'show_if_create_coupon' ),
		);

		return $list;
	}

	// draw the new product options panels
	public static function draw_new_panels() {
		self::_draw_create_coupon_panel();
	}

	// during post save, if we are saving a product, we need to additionally save the new relevant meta
	public static function save_product( $post_id, $post, $update ) {
		// if the 'save create coupon meta' mark is not present then do nothing
		if ( ! isset( $_POST['_create_coupon_save'] ) || ! wp_verify_nonce( $_POST['_create_coupon_save'], 'save-create-coupon-' . $post_id ) )
			return;

		// if this is a new post, do nothing
		if ( empty( $post_id ) || empty( $post ) )
			return;

		// if this post is not a product, do nothing
		if ( $post->post_type != 'product' )
			return;

		// if the current user does not have access to edit this product, do nothing
		if ( ! current_user_can( 'edit_post', $post_id ) )
			return;

		// if we are doing an autosave, do nothing
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE )
			return;

		// if this is a revision or autosave post, do nothing
		if ( is_int( wp_is_post_revision( $post ) ) )
			return;
		if ( is_int( wp_is_post_autosave( $post ) ) )
			return;

		// update the _create_coupon indicator for this product
		update_post_meta( $post_id, '_create_coupon', isset( $_POST['_create_coupon'] ) ? 'yes' : 'no' );

		// update the create_coupon_settings submitted
		$settings = apply_filters( 'qsot-sanitize-coupon-event-settings', $_POST['_create_coupon_settings'], $post_id );
		update_post_meta( $post_id, '_create_coupon_settings', $settings );
	}

	// when adding an order item from the admin, we need to insert the coupon data
	public static function on_add_order_product_from_admin( $item_id, $item ) {
		// get the order from the admin request
		if ( ! isset( $_POST['order_id'] ) )
			return;
		$order = wc_get_order( $_POST['order_id'] );

		// load the product
		$product_id = $item['variation_id'] ? $item['variation_id'] : $item['product_id'];
		$product = wc_get_product( $product_id );

		// call the function that possibly adds the data for the coupon
		self::on_add_order_product( $order->id, $item_id, $product, $item['qty'], false );

		// immediately after adding this coupon meta, we will be displaying the item. unfortunately, that means we need another filter here, so we can tell woocommerce the extra meta needed for display
		add_action( 'woocommerce_ajax_order_item', array( __CLASS__, 'add_coupon_meta_to_order_item' ), 10, 2 );
	}

	// add the 'coupons' order item meta for the coupons
	public static function add_coupon_meta_to_order_item( $item, $item_id ) {
		// load the coupon meta
		$coupons = wc_get_order_item_meta( $item_id, '_coupons', true );

		// if we have coupons meta, then add it
		if ( $coupons )
			$item['coupons'] = $coupons;

		return $item;
	}

	// when adding a create_coupon product to an order, immediately generate the related coupon
	public static function on_add_order_product( $order_id, $item_id, $product, $qty, $args ) {
		// only do this if the product is a create_coupon product
		if ( $product->create_coupon != 'yes' )
			return;

		// load the order, for use when creating the coupon args
		$order = wc_get_order( $order_id );
		// figure out a way to limit coupon usage to specific email, based on order email

		// load the create coupon settings, and normalize them for usage below
		$settings = self::_sane_create_coupon_args( $product->create_coupon_settings, $product, $order );

		// extract the code format
		$code_format = $settings['.code_format'];
		unset( $settings['.code_format'] );

		// extract the description
		$description = $settings['.description'];
		unset( $settings['.description'] );
		$description = ( $description ? $description . ' - ' : '' ) . sprintf( __( 'Created by purchase of product #%d', 'qsot-coupons' ), $product->id );

		// create a holder for the list of coupons
		$coupons = array();

		// for each qty, create a new coupon
		for ( $i = 0; $i < absint( $qty ); $i++ ) {
			// generate a name from the format
			$code = apply_filters( 'qsot-coupons-generate-code', 'base-' . sha1( microtime( true ) . rand() . memory_get_usage( true ) . AUTH_SALT ), $code_format, $product );
			
			// create a new coupon code based on the sane args
			$coupon_id = wp_insert_post( array(
				'post_author' => 1,
				'post_type' => 'shop_coupon',
				'post_title' => $code,
				'post_content' => $description,
				'post_status' => 'publish',
			) );

			// update the new coupon meta
			update_post_meta( $coupon_id, 'discount_type', $settings['discount_type'] );
			update_post_meta( $coupon_id, 'coupon_amount', $settings['coupon_amount'] );
			update_post_meta( $coupon_id, 'individual_use', $settings['individual_use'] );
			update_post_meta( $coupon_id, 'product_ids', $settings['product_ids'] );
			update_post_meta( $coupon_id, 'exclude_product_ids', $settings['exclude_product_ids'] );
			update_post_meta( $coupon_id, 'usage_limit', $settings['usage_limit'] );
			update_post_meta( $coupon_id, 'usage_limit_per_user', $settings['usage_limit_per_user'] );
			update_post_meta( $coupon_id, 'limit_usage_to_x_items', $settings['limit_usage_to_x_items'] );
			update_post_meta( $coupon_id, 'expiry_date', $settings['expiry_date'] );
			update_post_meta( $coupon_id, 'free_shipping', $settings['free_shipping'] );
			update_post_meta( $coupon_id, 'exclude_sale_items', $settings['exclude_sale_items'] );
			update_post_meta( $coupon_id, 'product_categories', $settings['product_categories'] );
			update_post_meta( $coupon_id, 'exclude_product_categories', $settings['exclude_product_categories'] );
			update_post_meta( $coupon_id, 'minimum_amount', $settings['minimum_amount'] );
			update_post_meta( $coupon_id, 'maximum_amount', $settings['maximum_amount'] );
			update_post_meta( $coupon_id, 'customer_email', $settings['customer_email'] );
			// updaet our special coupon meta for events
			update_post_meta( $coupon_id, 'event_ids', $settings['event_ids'] );
			update_post_meta( $coupon_id, 'event_limits', $settings['event_limits'] );
			// reverse lookup from coupon to order
			update_post_meta( $coupon_id, '_generated_from_order_id', $order_id );

			// add our new coupon to the list of coupons for this order item
			$coupons[] = array( $coupon_id, $code );
		}

		// update the order item
		wc_update_order_item_meta( $item_id, '_coupons', $coupons );
		// legacy keys left here for reference
		// wc_update_order_item_meta( $item_id, '_coupon_code_original', $code );
		// wc_update_order_item_meta( $item_id, '_coupon_id', $coupon_id );
	}

	// sanitize (and partially generate) the coupon args
	protected static function _sane_create_coupon_args( $settings, $product, $order ) {
		// create some defaults if missing
		$settings = wp_parse_args( $settings, array(
			// custom keys
			'code_format' => '{rand:10}',
			'description' => '',
			'expiration_formula' => '',
			// core coupon keys
			'discount_type' => 'fixed_cart',
			'coupon_amount' => '',
			'individual_use' => 'no',
			'usage_limit' => '',
			'usage_limit_per_user' => '',
			'limit_usage_to_x_items' => '',
			'free_shipping' => 'no',
			'exclude_sale_items' => 'no',
			'minimum_amount' => '',
			'maximum_amount' => '',
			// core id lists
			'product_ids' => '',
			'exclude_product_ids' => '',
			'product_categories' => '',
			'exclude_product_categories' => '',
		) );

		// sanitize each key
		// text
		$settings['description'] = wc_clean( $settings['description'] );
		$settings['discount_type'] = wc_clean( $settings['discount_type'] );
		// ints
		$settings['usage_limit'] = empty( $settings['usage_limit'] ) ? '' : absint( $settings['usage_limit'] );
		$settings['usage_limit_per_user'] = empty( $settings['usage_limit_per_user'] ) ? '' : absint( $settings['usage_limit_per_user'] );
		$settings['limit_usage_to_x_items'] = empty( $settings['limit_usage_to_x_items'] ) ? '' : absint( $settings['limit_usage_to_x_items'] );
		// yes/no
		$settings['individual_use'] = 'yes' == strtolower( $settings['individual_use'] ) ? 'yes' : 'no';
		$settings['free_shipping'] = 'yes' == strtolower( $settings['free_shipping'] ) ? 'yes' : 'no';
		$settings['exclude_sale_items'] = 'yes' == strtolower( $settings['exclude_sale_items'] ) ? 'yes' : 'no';
		// money
		$settings['coupon_amount'] = wc_format_decimal( $settings['coupon_amount'] );
		$settings['minimum_amount'] = wc_format_decimal( $settings['minimum_amount'] );
		$settings['maximum_amount'] = wc_format_decimal( $settings['maximum_amount'] );
		// id lists
		$settings['product_ids'] = implode( ',', array_filter( wp_parse_id_list( $settings['product_ids'] ) ) );
		$settings['exclude_product_ids'] = implode( ',', array_filter( wp_parse_id_list( $settings['exclude_product_ids'] ) ) );
		$settings['product_categories'] = implode( ',', array_filter( wp_parse_id_list( $settings['product_categories'] ) ) );
		$settings['exclude_product_categories'] = implode( ',', array_filter( wp_parse_id_list( $settings['exclude_product_categories'] ) ) );

		// extract the info used to generate part of the args
		$code_format = $settings['code_format'];
		$description = $settings['description'];
		$expiration_formula = $settings['expiration_formula'];
		unset( $settings['code_format'], $settings['description'], $settings['expiration_formula'] );

		// actually do the generating where required
		// re-key the description for later use
		$settings['.description'] = $description;

		// re-key the code_format in case we need it later
		$settings['.code_format'] = $code_format;

		// generate an expiration date, based on the expiration formula
		$settings['expiry_date'] = self::_generate_expiry_date( $expiration_formula );

		// limit usage to the email on the order, if already available. could be updated later in the order creation process
		$settings['customer_email'] = $order->billing_email;

		return apply_filters( 'qsot-coupons-sane-create-coupon-args', $settings, $product, $order );
	}

	// hide coupon order item meta from display on the edit order screen in the admin
	public static function hide_coupon_item_meta( $list ) {
		// our new list of extra args to hide
		$new_list = array(
			'_coupon_id',
			'_coupon_code_original',
		);
		
		return array_unique( array_merge( $list, $new_list ) );
	}

	// do a special display of the coupon code for craete_coupon items
	public static function order_item_coupon_code( $item_id, $item, $_product, $link=true ) {
		// only do this for create_coupon items
		if ( $_product->create_coupon != 'yes' )
			return;

		// determine the correct format
		$format = '<div class="coupon-code"><strong>%1$s</strong>: <a href="%2$s"><em>%3$s</em></a></div>';
		$need_link = true;
		if ( ! $link ) {
			$format = '<div class="coupon-code"><strong>%1$s</strong>: <em>%3$s</em></div>';
			$need_link = false; // prevents get_edit_post_link from running where not needed
		}
		$format = $link
			? '<div class="coupon-code"><strong>%1$s</strong>: <a href="%2$s"><em>%3$s</em></a></div>'
			: '<div class="coupon-code"><strong>%1$s</strong>: <em>%3$s</em></div>';

		// if this is a legacy formatted coupon entry, then reformat it for the new format
		if ( ! isset( $item['coupons'] ) && isset( $item['coupon_id'], $item['coupon_code_original'] ) ) {
			$item['coupons'] = array( array( $item['coupon_id'], $item['coupon_code_original'] ) );
			wc_update_order_item_meta( $item_id, '_coupons', $item['coupons'] );
		} else {
			$item['coupons'] = maybe_unserialize( $item['coupons'] );
		}

		// draw the list of coupon codes
		echo '<div class="coupon-codes">';
		foreach ( $item['coupons'] as $coupon )
			if ( isset( $coupon[0], $coupon[1] ) )
				echo sprintf( $format, __( 'Purchased Code', 'qsot-coupons' ), ( $need_link ) ? esc_attr( get_edit_post_link( $coupon[0] ) ) : '', $coupon[1] );
		echo '</div>';
	}

	// on the order details displays (like on order completion screen and confirmation emails) show out coupon code
	public static function order_details_coupon_code( $item_id, $item, $order ) {
		// get the product
		$_product = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );

		return self::order_item_coupon_code( $item_id, $item, $_product, false );
	}

	// generate a coupon code based on the code_format
	public static function generate_code( $code, $code_format, $product ) {
		global $wpdb;
		// reset the rand tracker
		self::$rand_used = false;

		// set the product, so that the callback func knows what product we are talking about (for the {sku} code part)
		self::$current_code_product = $product;

		$code = $code_format;
		$exists = true;
		$cnt = 0;

		// continue to look for an unused coupon name, with a limit break of 15 attempts, after which a fallback plan is used
		while ( $exists && $cnt < 10 ) {
			$cnt++;
			// perform a preg_replace with a callback to replace the {} parts of the code format
			$code = preg_replace_callback( '#\{([^\}]*?)\}#s', array( __CLASS__, 'code_replacements' ), $code );

			// if there has not been a {rand} used yet, append one to the code
			if ( ! self::$rand_used )
				$code = $code . ( '' === $code ? '' : '-' ) . apply_filters( 'qsot-get-rand', '', 10 );

			// determine if the code already exists
			$exists = !!$wpdb->get_var( $wpdb->prepare( 'select id from ' . $wpdb->posts . ' where post_type = %s and post_title = %s', 'shop_coupon', $code ) );
		}

		// if we broke the loop because of iterations, then use the fallback plan
		if ( $exists )
			$code = 'auto-' . sha1( microtime( true ) . rand() . memory_get_usage( true ) . AUTH_SALT );

		return $code;
	}

	// callback used to replace {} portions of the coupon code format
	public static function code_replacements( $matches ) {
		// split the raw action into it's parts, delimited by ':'s
		$raw = $matches[1];
		$params = explode( ':', $raw );
		$action = strtolower( (string)array_shift( $params ) );

		// return something specific depending on the 'action' and it's 'params'
		$out = '';
		switch ( $action ) {

			// rand = X number of random letters and numbers
			case 'rand':
				self::$rand_used = true;
				// generate a random string of letters and numbers with a length equal to the first param
				$out = apply_filters( 'qsot-get-rand', '', absint( current( $params ) ) );
			break;

			case 'sku':
				// use the SKU of the current product
				$out = strtolower( is_object( self::$current_code_product ) ? self::$current_code_product->sku : '' );
			break;
		}

		return $out;
	}

	// generate a random string of letters and numbers $x characters long
	public static function get_rand( $out='', $x=10 ) {
		// determine what X equals. min of 6, default 10, max 100
		$x = ( 0 === $x ) ? 10 : $x;
		$x = ( $x < 6 ) ? 6 : $x;
		$x = ( $x > 100 ) ? 100 : $x;

		$out = '';

		// generate a string of $x length
		for ( $i = 0; $i < $x; $i++ ) {
			// 10 digits and 26 letters, for a total of 36 characters
			$n = rand( 0 , 35 );
			// 48 gets us to numbers, 7 more gets us to captial letters. skip symbols : to @
			$out .= chr( 48 + ( $n > 9 ? 7 : 0 ) + $n );
		}

		return strtolower( $out );
	}

	// parse the expiration formula to come up with an official expiry date
	protected static function _generate_expiry_date( $formula ) {
		// get the pieces of the formula which can be parsed
		$tokens = array_filter( array_map( 'trim', explode( ',', $formula ) ), array( __CLASS__, 'remove_zero_length_values' ) );
		if ( empty( $tokens ) ) return '';

		// start with 'now'
		$orig = $time = time();

		// cycle through the tokens and increase the expiration time based on the amount the token specifies
		foreach ($tokens as $token) {
			// determine the various parts of the token itself: sign (+ or -), value (integer), and unit (string)
			preg_match_all( '#([\+\-])?\s*(\d+)\s*([\w\-\_]+)#', $token, $matches, PREG_SET_ORDER );

			// if there were matches from the above parse, then interpret them
			if ( ! empty( $matches ) ) {
				// foreach matched sign-value-unit triplet, parse it
				while ( $parts = array_shift( $matches ) ) {
					// break the results into 
					$sign = in_array( $parts[1], array( '+', '-' ) ) ? $parts[1] : '+'; // default to +
					$magnitude = intval( $parts[2] );
					$unit = self::_determine_unit( strtolower( $parts[3] ), $magnitude );

					// if this is a special unit, then parse it differently
					if ( $unit{0} == '*' ) {
						switch ( $unit ) {
							// BOY = Beginning Of Year
							case '*BOY': $time = strtotime( $magnitude . '-01-01 00:00:00' ); break 3;
							// EOY = End Of Year
							case '*EOY': $time = strtotime( $magnitude . '-12-31 23:59:59' ); break 3;
						}
					// otherwise, if we have a valid unit, then
					} else if ( ! empty( $unit ) ) {
						// use string to time to increas the timer
						$time = strtotime( $sign . $magnitude . ' ' . $unit, $time );
					}
				}
			}
		}

		// print the final result. if there was no parseable values in the formula, then assume it never expires
		return $time == $orig ? '' : date('Y-m-d', $time);
	}

	// figure out the unit of part of a token in the formula
	protected static function _determine_unit( $input, $mag ) {
		static $map = array(
			'b(egin(ning)?)?o(f)?y(ear)?' => '*BOY', // SPECIAL: beginning of the year
			'e(nd)?o(f)?y(ear)?' => '*EOY', // SPECIAL: end of the year
			'y((ea)?r)?s?' => 'year', // year unit
			'mo?n?(th)?s?' => 'month', // month unit
			'w(ee)?k?s?' => 'week', // week unit
			'da?y?s?' => 'day', // day unit
			'h(ou)?r?s?' => 'hour', // hour unit
			'(i|m(in)?(ute)?)s?' => 'minute', // minute unit
			's(ec)?o?(nd)?s?' => 'second', // second unit
		);

		$unit = '';
		// cycle through the map until we have a match
		foreach ( $map as $match => $val ) {
			// if we find a match, then use the strtotime proper name or the SPECIAL value
			if ( preg_match( '#' . $match . '#i', $input ) ) {
				$unit = $val;
				break;
			}
		}

		// pluralize the response
		return empty( $unit ) ? $unit : ( ( $unit{0} != '*' && $mag != 1 ) ? $unit . 's' : $unit );
	}

	// remove all zero length values from an array
	public static function remove_zero_length_values( $str ) {
		return ! is_scalar( $str ) || strlen( $str ) > 0;
	}

	// draw the create coupon product panel
	protected static function _draw_create_coupon_panel() {
		global $post, $thepostid;

		// start the panel
		echo '<div class="panel woocommerce_options_panel" id="create_coupon_settings">';

		// mark the page as needing a meta save
		echo '<input type="hidden" name="_create_coupon_save" value="' . wp_create_nonce( 'save-create-coupon-' . $thepostid ) . '" />';

		// make copies of the original post, thepostid, and post meta for the product for later use
		// we have to do it this way in order to use the coupon-data metabox form from the core WC plugin
		self::$orig_post = clone $GLOBALS['post'];
		self::$orig_thepostid = $GLOBALS['thepostid'];
		self::$orig_post_cc_meta = (array)get_post_meta( self::$orig_thepostid, '_create_coupon_settings', true );

		// start the get_post_metadata takeover, so that we load the coupon settings from the product post not the fake coupon post
		add_filter( 'get_post_metadata', array( __CLASS__, 'override_product_coupon_get_meta' ), 10000, 4 );

		// get the coupon form output
		$output = self::_base_coupon_form();

		// get additional/replacement fields
		$name_format = self::_get_name_format_field();
		$description = self::_get_description_field();
		$expiration_formula = self::_get_expiration_formula_field();

		// remove un-needed elements
		$output = preg_replace( '#<input[^>]+?name="woocommerce_meta_nonce"[^>]+?'.'>#s', '', $output );
		$output = preg_replace( '#<input[^>]+?name="_wp_http_referer"[^>]+?'.'>#s', '', $output );
		$output = preg_replace( '#<p[^>]+?customer_email_field[^>]+?'.'>.*?</p>#s', '', $output );

		// replace the expiry date field with expiration formula
		$output = preg_replace( '#<p[^>]+?expiry_date_field[^>]+?'.'>.*?</p>#s', $expiration_formula, $output );

		// add the name format field
		$output = preg_replace( '#(<p[^>]+?discount_type_field[^>]+?'.'>)#', $name_format . '$1', $output );
		$output = preg_replace( '#(<p[^>]+?discount_type_field[^>]+?'.'>)#', $description . '$1', $output );

		// fix field names so that they are grouped for later saving
		$output = preg_replace( '#<([^>]+?)name=([\'"])([^\[\2]*?)(\[.*?)?\2([^>]+?)>#s', '<$1name="_create_coupon_settings[$3]$4"$5>', $output );

		// remove the filter for posterity
		remove_filter( 'get_post_metadata', array( __CLASS__, 'override_product_coupon_get_meta' ), 10000 );

		// empty the original post information for reuse
		self::$orig_post = null;
		self::$orig_thepostid = null;
		self::$orig_post_cc_meta = null;

		echo $output;

		// end the panel
		echo '</div>';
	}

	// generate the name format field
	protected static function _get_name_format_field() {
		global $thepostid;

		// capture the output from the core wc function that generates the field
		ob_start();
		woocommerce_wp_text_input( array(
			'id' => 'code_format',
			'label' => __( 'Code format', 'qsot-coupons' ),
			'placeholder' => '{rand:10}',
			'description' => '<div class="name-format-field">' . __( "The format to use when generating the coupon code. Available formats:", 'qsot-coupons' )
					. sprintf( '<br/><code><b>%s</b> = <em>%s</em></code>', '{rand}', __( '10 random numbers and letters', 'qsot-coupons' ) )
					. sprintf( '<br/><code><b>%s</b> = <em>%s</em></code>', '{rand:##}', __( '## (6 or more) random numbers and letters', 'qsot-coupons' ) )
					. sprintf( '<br/><code><b>%s</b> = <em>%s</em></code>', '{sku}', __( 'the SKU of this product', 'qsot-coupons' ) )
					. sprintf( '<br/><em><code>%s: %s</code></em>', __( 'example', 'qsot-coupons' ), 'DISCOUNT-{sku}-{rand:7}' )
					. '<br/><em>' . sprintf( __( 'If no %s parameter is specified, 10 random numbers and letters will be appended to the end of the code, for uniqueness.', 'qsot-coupons' ), '<strong>{rand}</strong>' ) . '</em></div>',
			'class' => 'short',
			//'custom_attributes' => array( 'pattern' => "[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])" ),
			'name' => 'code_format',
		) );
		$output = ob_get_contents();
		ob_end_clean();

		return $output;
	}

	// generate the description field
	protected static function _get_description_field() {
		global $thepostid;

		// capture the output from the core wc function that generates the field
		ob_start();
		woocommerce_wp_textarea_input( array(
			'id' => 'code_description',
			'label' => __( 'Code Description', 'qsot-coupons' ),
			'placeholder' => 'A generated coupon.',
			'description' => __( 'The description to use for the created coupon.', 'qsot-coupons' ),
			'class' => 'widefat',
			'name' => 'code_description',
		) );
		$output = ob_get_contents();
		ob_end_clean();

		return $output;
	}

	// generate the expiration formula field, which will replace the expiration field in the coupon form, on the create coupon product panel
	protected static function _get_expiration_formula_field() {
		global $thepostid;

		// capture the output from the core wc function that generates the field
		ob_start();
		woocommerce_wp_text_input( array(
			'id' => 'expiry_date_formula',
			'label' => __( 'Expiry date formula', 'qsot-coupons' ),
			'placeholder' => _x( 'Never expire', 'placeholder', 'qsot-coupons' ),
			'description' => __( 'Formula to calculate the date this coupon will expire. comman delimited, format example: <code>[+-]#[year,month,week,day,hour,minute,second,boy,eoy],...</code> (ex: <code>+1 year,+3 days,+4 hours</code> or <code>2019 eoy</code>[end of 2019]).', 'qsot-coupons' ),
			'class' => 'short',
			//'custom_attributes' => array( 'pattern' => "[0-9]{4}-(0[1-9]|1[012])-(0[1-9]|1[0-9]|2[0-9]|3[01])" ),
			'name' => 'expiry_date_formula',
		) );
		$output = ob_get_contents();
		ob_end_clean();

		return $output;
	}

	// get the output of the normal base coupon form
	protected static function _base_coupon_form() {
		// make sure we have the appropriate metabox class loaded
		if ( ! class_exists( 'WC_Meta_Box_Coupon_Data' ) ) {
			$file = WC()->plugin_path() . '/includes/admin/meta-boxes/class-wc-meta-box-coupon-data.php';
			if ( file_exists( $file ) )
				include_once( $file );
		}

		// if the class still does not exist, then bail
		if ( ! class_exists( 'WC_Meta_Box_Coupon_Data' ) )
			return '<p><strong><em>Could not load the form.</em></strong></p>';

		// buffer the output of the form
		ob_start();
		WC_Meta_Box_Coupon_Data::output( $GLOBALS['post'] );
		$output = ob_get_contents();
		ob_end_clean();

		return $output;
	}

	// override the get_post_meta output, so that we can fake the fake coupon post during product form generation from _base_coupon_form() method above
	public static function override_product_coupon_get_meta( $current, $object_id, $meta_key, $single ) {
		// if the request is not for our fake post, then bail
		if ( $object_id != self::$orig_thepostid ) return $current;

		// if the requested meta_key exists in our product settings, then use that, otherwise, use empty string
		$value = isset( self::$orig_post_cc_meta[ $meta_key ] ) ? self::$orig_post_cc_meta[ $meta_key ] : '';

		// always return an array, because the logic after the get_post_metadata filter always assumes that arrays returned for single values are arrays of results
		return array( $value );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_coupons_product::pre_init();
