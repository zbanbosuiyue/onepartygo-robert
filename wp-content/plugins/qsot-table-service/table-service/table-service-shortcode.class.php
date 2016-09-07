<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// handles the shortcode for the frontend display of the page that shows the products that a table service min_spend can be validated wtih
class QSOT_Table_Service_Shortcode {
	// make this a singleton
	protected static $_instance = null;
	public static function instance() { return self::$_instance = ( self::$_instance instanceof self ) ? self::$_instance : new self; }
	protected function __construct() {
		add_shortcode( $this->code, array( &$this, 'render' ) );
	}

	// slug for the shortcode
	protected $code = 'table-service';

	// render the shortcode
	public function render() {
		$frontend = QSOT_Table_Service_Frontend::instance();
		$product = $item = null;
		// product to display the table service options for
		if ( isset( $_GET['qsotts'] ) ) {
			$item_id = $frontend->get_cart_item_id( array( 'ts_item_id' => $_GET['qsotts'] ) );
			$item = WC()->cart->get_cart_item( $item_id );
		}

		// if there is no item, do nothing
		if ( ! is_array( $item ) || ! isset( $item['ts_item_id'] ) )
			return '';

		$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
		// if we do not have a product, display nothing
		if ( ! ( $product instanceof WC_Product ) || 0 == $product->id )
			return '';

		// get the list of valid table service min_spend products to require
		$pool = $product->qsotts_product_pool;
		$pool = array_filter( wp_parse_id_list( $pool ) );

		// if there are no products linked to this table service product, then show nothing
		if ( empty( $pool ) )
			return '';
		$pool[] = '0';

		// the args used to get a list of products to show
		$args = array(
			'post_type' => 'product',
			'post_status' => 'public',
			'posts_per_page' => -1,
			'post__in' => $pool,
		);

		// get the list of products
		$products = get_posts( $args );

		// if there are no matching products, then bail
		if ( empty( $products ) )
			return '';

		// render the template
		return QSOT_Templates::include_template( 'shortcodes/table-service.php', array(
			'table_service_product' => $product,
			'products' => $products,
		), false );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Table_Service_Shortcode::instance();
