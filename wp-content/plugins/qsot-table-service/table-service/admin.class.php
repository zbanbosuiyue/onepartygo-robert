<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// handles the frontend display of the table service plugin
class QSOT_Table_Service_Admin {
	// make this a singleton
	protected static $_instance = null;
	public static function instance() { return self::$_instance = ( self::$_instance instanceof self ) ? self::$_instance : new self; }
	protected function __construct() {
		// register our assets
		add_action( 'admin_init', array( &$this, 'register_assets' ) );

		// queue up the assets at the appropriate time
		add_action( 'admin_enqueue_scripts', array( &$this, 'enqueue_assets' ) );

		// add product data boxes and tabs
		add_filter( 'product_type_options', array( &$this, 'add_product_type' ), 100000 );
		add_filter( 'woocommerce_product_data_tabs', array( &$this, 'add_table_service_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( &$this, 'draw_table_service_panel' ) );

		// handle saving of the product meta
		add_action( 'woocommerce_process_product_meta', array( &$this, 'save_table_service_settings' ), 1000, 2 );
	}

	// register our assets
	public function register_assets() {
		$qsts = QSTS();
		$url = $qsts->plugin_url();
		$version = $qsts->version();

		// order edit page
		wp_register_script( 'qsotts-order-edit-page', $url . 'assets/js/admin/order.js', array( 'jquery' ), $version );
	}

	// queue up the assets depending on the page
	public function enqueue_assets( $hook ) {
		// load different assets based on the current admin page
		switch ( $hook ) {
			// on the EDIT post page
			case 'post.php':
				$post = get_post();
				// if this is a product page
				if ( $post instanceof WP_Post && 'product' == $post->post_type ) {
					wp_enqueue_script( 'qsotts-order-edit-page' );
				}
			break;
		}
	}

	// product_type checkbox
	public function add_product_type( $types ) {
		$types['table_service'] = array(
			'id' => '_table_service',
			'wrapper_class' => 'show_if_simple',
			'label' => __( 'Table Service', 'qsot-table-service' ),
			'description' => __( 'Table Service will force the purchase of a minimum amount of extra products, from a set list of products, before checkout is allowed..', 'woocommerce' ),
			'default' => 'no'
		);
		return $types;
	}

	// add the table service tab to the list of tabs to be rendered
	public function add_table_service_tab( $tabs ) {
		$tabs['qsotts-table-service'] = array(
			'label'  => __( 'Table Service', 'qsot-table-service' ),
			'target' => 'qsotts_table_service_panel',
			'class'  => array( 'show_if_table_service' ),
		);

		return $tabs;
	}

	// add the ticket settings section to the general product data tab
	public function draw_table_service_panel() {
		global $post;
		?><div id="qsotts_table_service_panel" class="panel woocommerce_options_panel hidden">
			<div class="options_group">
				<?php
					// nonce to show that the product data is being saved
					wp_nonce_field( 'save-table-service-data', '_qsotts_n' );

					// minimum spend price required to validate this product
					woocommerce_wp_text_input( array( 'id' => '_qsotts_min_spend', 'label' => __( 'Min. Spend', 'qsot-table-service' ) . ' (' . get_woocommerce_currency_symbol() . ')', 'data_type' => 'price', 'desc_tip' => true, 'description' => __( 'The minimum amount of money the user must spend on products from the "Product Pool" below, before they are allowed to checkout with this product in their cart.', 'qsot-table-service' ) ) );
				?>

				<p class="form-field">
					<label for="qsotts_product_pool"><?php _e( 'Product Pool', 'qsot-table-service' ); ?></label>
					<input type="hidden" class="wc-product-search" style="width: 50%;" id="qsotts_product_pool" name="qsotts_product_pool" data-placeholder="<?php esc_attr_e( 'Search for a product&hellip;', 'woocommerce' ); ?>" data-action="woocommerce_json_search_products" data-multiple="true" data-exclude="<?php echo intval( $post->ID ); ?>" data-selected="<?php
						$product_ids = array_filter( array_map( 'absint', (array) get_post_meta( $post->ID, '_qsotts_product_pool', true ) ) );
						$json_ids    = array();

						foreach ( $product_ids as $product_id ) {
							$product = wc_get_product( $product_id );
							if ( is_object( $product ) ) {
								$json_ids[ $product_id ] = wp_kses_post( html_entity_decode( $product->get_formatted_name(), ENT_QUOTES, get_bloginfo( 'charset' ) ) );
							}
						}

						echo esc_attr( json_encode( $json_ids ) );
					?>" value="<?php echo implode( ',', array_keys( $json_ids ) ); ?>" /> <?php echo wc_help_tip( __( 'List of products to display to the customer. The customer must choose products from this list. The total price of all the products chosen from this list will be compared against the "Min. Spend" value above, before the customer will be allowed to checkout.', 'qsot-table-service' ) ); ?>
				</p>
			</div>
		</div><?php
	}

	// save the settings from the metabox
	public function save_table_service_settings( $post_id, $post ) {
		// if the nonce does not verify, then bail early
		if ( ! isset( $_POST['_qsotts_n'] ) || ! wp_verify_nonce( $_POST['_qsotts_n'], 'save-table-service-data' ) )
			return;

		// sanitize and save the product meta
		update_post_meta( $post_id, '_table_service', isset( $_POST['_table_service'] ) && 'on' == $_POST['_table_service'] ? 'yes' : '' );
		update_post_meta( $post_id, '_qsotts_min_spend', '' === $_POST['_qsotts_min_spend'] ? '' : wc_format_decimal( $_POST['_qsotts_min_spend'] ) );
		$product_ids = array_filter( array_map( 'absint', (array) wp_parse_id_list( $_POST['qsotts_product_pool'] ) ) );
		update_post_meta( $post_id, '_qsotts_product_pool', $product_ids );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Table_Service_Admin::instance();
