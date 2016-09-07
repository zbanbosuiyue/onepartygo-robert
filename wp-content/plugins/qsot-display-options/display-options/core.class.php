<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// add the core functionality of the display_options plugin
class QSOT_display_options_core {
	// holder for otce plugin settings
	protected static $o = null;
	protected static $options = null;

	// setup the class
	public static function pre_init() {
		// first thing, load all the options, and share them with all other parts of the plugin
		$settings_class_name = apply_filters( 'qsot-settings-class-name', '' );
		if ( ! class_exists( $settings_class_name ) )
			return false;
		self::$o = call_user_func_array( array( $settings_class_name, 'instance' ), array() );

		// load all the options, and share them with all other parts of the plugin
		$options_class_name = apply_filters( 'qsot-options-class-name', '' );
		if ( ! empty( $options_class_name ) ) {
			self::$options = call_user_func_array( array( $options_class_name, 'instance' ), array() );
			self::_setup_admin_options();
		}

		// after woocommerce has loaded, run the action to load our special product type
		if ( did_action( 'woocommerce_loaded' ) )
			self::load_product_type();
		else
			add_action( 'woocommerce_loaded', array( __CLASS__, 'load_product_type' ), 1 );

		// handle product emulation on shop pages
		add_action( 'woocommerce_before_shop_loop', array( __CLASS__, 'post_class_add' ), 1 );
		add_action( 'woocommerce_after_shop_loop', array( __CLASS__, 'post_class_remove' ), 1 );

		// handle the add-to-cart action for qsot-event-product type. also handles ajaxy add-to-cart
		add_action( 'woocommerce_add_to_cart_handler_qsot-event-product', array( __CLASS__, 'event_product_add_to_cart' ), 100, 1 );
		add_filter( 'woocommerce_loop_add_to_cart_link', array( __CLASS__, 'maybe_modify_add_to_cart_link' ), 10, 2 );
		add_filter( 'wp_ajax_woocommerce_add_to_cart', array( __CLASS__, 'ajax_handle_event_product_add_to_cart' ), 9 );
		add_filter( 'wc_ajax_add_to_cart', array( __CLASS__, 'ajax_handle_event_product_add_to_cart' ), 9 );

		// add our scripts and styles
		add_action( 'init', array( __CLASS__, 'register_assets' ), 10 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'queue_assets' ), 10 );
		add_action( 'qsot-display-options-fpb-modal-templates', array( __CLASS__, 'fpb_modal_templates' ), 10 );
		add_action( 'qsot-display-options-shortcodes-modal-templates', array( __CLASS__, 'shortcodes_modal_templates' ), 10 );
	}

	// register our scripts and styles
	public static function register_assets() {
		global $wp_scripts;

		// reused vars
		$url = QSOT_display_options_launcher::plugin_url();
		$version = QSOT_display_options_launcher::version();
		$jquery_version = isset( $wp_scripts->registered['jquery-ui-core']->ver ) ? $wp_scripts->registered['jquery-ui-core']->ver : '1.11.1';

		// frontend
		wp_register_style( 'qsot-display-options-frontend', $url . 'assets/css/frontend/display-options.css', array(), $version );

		// admin utils
		wp_register_script( 'qsot-do-utils', $url . 'assets/js/admin/dopts.js', array( 'qsot-tools', 'jquery-ui-datepicker' ), $version );
		wp_register_style( 'jquery-ui-style', '//code.jquery.com/ui/' . $jquery_version . '/themes/smoothness/jquery-ui.css', array(), $jquery_version );

		// widget screen styles
		wp_register_style( 'qsot-do-admin-widgets', $url . 'assets/css/admin/widgets.css', array( 'jquery-ui-style' ), $version );

		// better find posts box
		wp_register_script( 'qsot-do-find-posts-box', $url . 'assets/js/admin/find-posts-box.js', array( 'qsot-do-utils', 'jquery-ui-sortable' ), $version );

		// admin shortcode generator
		wp_register_script( 'qsot-do-shortcode-generator', $url . 'assets/js/admin/shortcodes.js', array( 'qsot-do-find-posts-box', 'media-views', 'media-models' ), $version );
		wp_register_style( 'qsot-do-shortcode-generator', $url . 'assets/css/admin/shortcodes.css', array(), $version );
	}

	// shortcode generator modal templates
	public static function shortcodes_modal_templates() {
		$shortcodes = apply_filters( 'qsot-get-shortcodes', array() );
		?>
			<script type="text/html" id="tmpl-qsot-shortcode-generator">
				<div class="widget-settings">
					<?php if ( count( $shortcodes ) ): ?>
						<div class="field">
							<label><?php _e( 'Shortcode', 'qsot-display-options' ) ?></label>
							<select name="shortcode" class="widefat">
								<?php foreach ( $shortcodes as $tag => $options ): ?>
									<option value="<?php echo esc_attr( sanitize_title_with_dashes( $tag ) ) ?>"><?php echo $tag ?></option>
								<?php endforeach; ?>
							</select>
						</div>

						<?php foreach ( $shortcodes as $tag => $options ): ?>
							<div class="panel" id="panel-<?php echo esc_attr( sanitize_title_with_dashes( $tag ) ) ?>">
								<?php call_user_func( $options['form'] ) ?>
							</div>
						<?php endforeach; ?>
					<?php else: ?>
						<p><?php _e( 'There are no shortcodes to display.', 'qsot-display-options' ) ?></p>
					<?php endif; ?>
				</div>
			</script>
		<?php
	}

	// find post box modal templates
	public static function fpb_modal_templates() {
		?>
			<script type="text/html" id="qsot-do-find-posts">
				<div class="wc-backbone-modal qsot-do-find-posts-modal">
					<div class="wc-backbone-modal-content">
						<section class="wc-backbone-modal-main" role="main">
							<header class="wc-backbone-modal-header">
								<a class="modal-close modal-close-link" href="#"><span class="close-icon"><span class="screen-reader-text"><?php _e( 'Close', 'qsot-display-options' ) ?></span></span></a>
								<h1><?php _e( 'Find Events', 'qsot-display-options' ); ?></h1>
							</header>
							<article>
								<input type="hidden" name="post_types" value="qsot-event" />
								<table cellpadding="0" cellspacing="0"><tbody><tr>
									<td><input type="text" class="widefat" name="term" role="search-text" placeholder="<?php _e( 'Search...', 'qsot-display-options' ) ?>" /></td>
									<td width="70" align="right"><input type="button" class="button" value="<?php _e( 'Search', 'qsot-display-options' ) ?>" role="search-btn" /></td>
								</tr></tbody></table>
								<div class="results-box" role="results"><div class="novalue" style="font-style:italic; color:#999; padding:0.5em 0.8em;"><?php echo __( 'Use the search box above to find the event(s) you want to feature.', 'qsot-display-options' ) ?></div></div>
							</article>
							<footer>
								<div class="inner">
									<button id="btn-select-posts" class="button button-primary button-large"><?php _e( 'Use Selected Events', 'qsot-display-options' ); ?></button>
								</div>
							</footer>
						</section>
					</div>
				</div>
				<div class="wc-backbone-modal-backdrop qsot-do-modal-backdrop modal-close">&nbsp;</div>
			</script>
		<?php
	}

	// queue our assets for use
	public static function queue_assets() {
		wp_enqueue_style( 'qsot-display-options-frontend' );
	}

	// when adding the event product to the cart via ajax, we need to redirect the product id to an appropriate one
	public static function ajax_handle_event_product_add_to_cart() {
		// get the product id
		$product_id = apply_filters( 'woocommerce_add_to_cart_product_id', absint( $_POST['product_id'] ) );

		// if the requested product is not an event-product, then skip this logic
		$product = wc_get_product( $product_id );
		if ( 'qsot-event-product' != $product->product_type )
			return;

		$added_to_cart = false;

		// if the event-product is not single priced general admission, then redirect the user to the event page, so they can use the ticket selection UI
		if ( $product->is_single_priced() ) {
			// the product_id we received is actually the event_id. find the actual product and event ids
			$event_id = absint( $product_id );
			$event_product = $product;
			$product_id = absint( $product->pricing_options );
			$product = wc_get_product( $product_id );

			// determine the quantity, the 
			$quantity = empty( $_POST['quantity'] ) ? 1 : wc_stock_amount( $_POST['quantity'] );
			$passed_validation = apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity );
			$product_status = get_post_status( $product_id );

			// if everything looks good, then try to add the ticket to the cart
			if ( 'publish' == $product_status && $passed_validation && $quantity > 0 && is_object( $product ) && isset( $product->id ) ) {
				// add the product to the cart
				$success = apply_filters( 'qsot-zoner-reserve-current-user', false, $event_id, $product_id, 1 );

				// if the process was a success, then the item was added to the cart
				if ( $success && ! is_wp_error( $success ) )
					$added_to_cart = true;
			}
		}

		// if the item was added to the cart successfully
		if ( $added_to_cart ) {
			do_action( 'woocommerce_ajax_added_to_cart', $product_id );

			if ( get_option( 'woocommerce_cart_redirect_after_add' ) == 'yes' ) {
				wc_add_to_cart_message( $product_id );
			}

			// Return fragments
			WC_AJAX::get_refreshed_fragments();
		// otherwise, the item was not added successfully, usually becasue we need more info. direct to the event page for more info gathering
		} else {
			// add a message asking for more info
			wc_add_notice( __( 'Please select a type of ticket to purchase.', 'qsot-display-options' ), 'notice' );

			$data = array(
				'error' => true,
				'product_url' => apply_filters( 'woocommerce_cart_redirect_after_error', get_permalink( $event_id ), $event_id ),
			);

			// send the response that redirects the user
			wp_send_json( $data );
		}

		exit;
	}

	// for qsot-event-products, we need to slightly modify the add to cart links, so that they do the ajaxy goodness that simple products do
	public static function maybe_modify_add_to_cart_link( $link, $product ) {
		// if the product is an event product, and has a single, simple price, then slighly modify the link
		if ( 'qsot-event-product' == $product->product_type && $product->is_single_priced() )
			$link = str_replace( 'class="', 'class="product_type_simple ', $link );

		return $link;
	}

	// load the special product type for events, when showing events in the shop
	public static function load_product_type() {
		require_once QSOT_display_options_launcher::plugin_dir() . 'display-options/product-types/event-product.php';
	}

	// handle the add to cart action for our qsot-event-products
	public static function event_product_add_to_cart( $url=false ) {
		// make sure we have the id of the event-product we are adding
		if ( empty( $_REQUEST['add-to-cart'] ) || ! is_numeric( $_REQUEST['add-to-cart'] ) )
			return;
		$event_id = $_REQUEST['add-to-cart'];

		// load the event product
		$event_product = wc_get_product( $event_id );

		// if this is a multi priced event, then bail, because they cannot just rawly be added to the cart without the ticket selection UI
		if ( ! $event_product->is_single_priced() ) {
			wc_add_notice( __( 'You cannot add tickets for that event.', 'qsot-display-options' ), 'error' );
			return;
		}

		// find the real ticket product id, because that is what we are actually going to add to the cart
		$product_id = $event_product->pricing_options;
		$product = $product_id ? wc_get_product( $product_id ) : false;
		if ( ! $product_id || ! is_object( $product ) || ! isset( $product->id ) ) {
			wc_add_notice( __( 'Could not determine the price for that event.', 'qsot-display-options' ), 'error' );
			return;
		}

		// add the product to the cart
		$success = apply_filters( 'qsot-zoner-reserve-current-user', false, $event_id, $product_id, 1 );

		// add an appropriate message
		$was_added_to_cart = false;
		if ( is_wp_error( $success ) ) {
			wc_add_notice( $success->get_message(), 'error' );
		} else if ( is_bool( $success) && $success ) {
			$was_added_to_cart = true;
			wc_add_notice(
				sprintf(
					__( 'Successfully added a %s ticket for %s to your cart. %s', 'qsot-display-options' ),
					'<em>' . $product->get_title() . '</em>',
					'<u>' . $event_product->get_title() . '</u>',
					sprintf( '<a href="%s">%s</a>', WC()->cart->get_cart_url(), __( 'View Cart', 'qsot-display-options' ) )
				),
				'success'
			);
		} else {
			wc_add_notice(
				sprintf(
					__( 'Could not add a %s ticket for %s to your cart.', 'qsot-display-options' ),
					'<em>' . $product->get_title() . '</em>',
					'<u>' . $event_product->get_title() . '</u>'
				),
				'error'
			);
		}

		// If we added the product to the cart we can now optionally do a redirect.
		if ( $was_added_to_cart && wc_notice_count( 'error' ) == 0 ) {
			$url = apply_filters( 'woocommerce_add_to_cart_redirect', $url );

			// If has custom URL redirect there
			if ( $url ) {
				wp_safe_redirect( $url );
				exit;
			// Redirect to cart option
			} elseif ( get_option('woocommerce_cart_redirect_after_add') == 'yes' ) {
				wp_safe_redirect( WC()->cart->get_cart_url() );
				exit;
			}
		}

		// if we are here and not doing ajax, then redirect to a non-'add-to-cart' action page
		wp_safe_redirect( remove_query_arg( 'add-to-cart' ) );
		exit;
	}

	// when displaying the shop, we need to add the product classes to the post_class output for event products. this function, adds the actual hook that handles that.
	// must be added this way, otherwise the post_class augmentation will happen all the time, which is not deisrable. we only want this during the shop output
	public static function post_class_add() {
		add_filter( 'post_class', array( __CLASS__, 'post_class_event_add_product' ), 100000, 3 );
	}

	// removes the post_class augmenter hook from post_class_add() method
	public static function post_class_remove() {
		remove_filter( 'post_class', array( __CLASS__, 'post_class_event_add_product' ), 100000 );
	}

	// actual function that does the adding of the extra classes to the output of events that live in the shop interface
	public static function post_class_event_add_product( $classes, $base_class, $post_id ) {
		// only augment the post_class of our event post types
		if ( self::$o->core_post_type == get_post_type( $post_id ) ) {
			$classes[] = 'product';
			$classes[] = 'type-product';
		}

		return $classes;
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_display_options_core::pre_init();
