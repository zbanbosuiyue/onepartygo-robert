<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// handles the shortcode for the frontend display of the page that shows the products that a table service min_spend can be validated wtih
class QSOT_Table_Service_Status_Shortcode {
	// make this a singleton
	protected static $_instance = null;
	public static function instance() { return self::$_instance = ( self::$_instance instanceof self ) ? self::$_instance : new self; }
	protected function __construct() {
		add_shortcode( $this->code, array( &$this, 'render' ) );
	}

	// slug for the shortcode
	protected $code = 'table-service-status';

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

		// render the template
		return QSOT_Templates::include_template( 'shortcodes/table-service-status.php', array(
			'table_service_product' => $product,
			'remaining' => wc_price( $frontend->remaining_min_spend( $item ) ),
			'min_spend' => wc_price( $product->qsotts_min_spend ),
		), false );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Table_Service_Status_Shortcode::instance();
