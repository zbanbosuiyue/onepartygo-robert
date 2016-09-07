<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// widget for displaying the table service status
class QSOT_Table_Service_Status_Widget extends WP_Widget {
	public static function init() { add_action( 'widgets_init', array( __CLASS__, 'register_this_widget' ) ); }
	public static function register_this_widget() { register_widget( __CLASS__ ); }
	// default settings for the widget
	protected $defaults = array(
		'title' => '',
	);

	// create the widget
	public function __construct() {
		parent::__construct( false, __( 'Table Service Status', 'qsot-table-service' ) );
		$this->defaults['title'] = sprintf( __( 'Table Service for %s', 'qsot-table-service' ), '%product_title%' );
	}

	// draw the widget
	public function widget( $args, $instance ) {
		$frontend = QSOT_Table_Service_Frontend::instance();
		$product = $item = null;
		// product to display the table service options for
		if ( isset( $_GET['qsotts'] ) ) {
			$item_id = $frontend->get_cart_item_id( array( 'ts_item_id' => $_GET['qsotts'] ) );
			$item = WC()->cart->get_cart_item( $item_id );
		}

		// if there is no item, do nothing
		if ( ! is_array( $item ) || ! isset( $item['ts_item_id'] ) )
			return;

		$product = isset( $item['variation_id'] ) && ! empty( $item['variation_id'] ) ? wc_get_product( $item['variation_id'] ) : ( isset( $item['product_id'] ) ? wc_get_product( $item['product_id'] ) : null );
		// if we do not have a product, display nothing
		if ( ! ( $product instanceof WC_Product ) || 0 == $product->id )
			return;

		// render the template
		echo QSOT_Templates::include_template( 'widgets/table-service-status.php', array(
			'widget_args' => $args,
			'instance' => $this->_normalize( $instance ),
			'table_service_product' => $product,
			'remaining' => wc_price( $frontend->remaining_min_spend( $item ) ),
			'min_spend' => wc_price( $product->qsotts_min_spend ),
		), false );
	}

	// save the widget settings
	public function update( $new, $old ) {
		$old = $this->_normalize( $old );
		return $this->_normalize( $new, $old );
	}

	// form for the widget settings
	public function form( $instance ) {
		$instance = $this->_normalize( $instance );
		?>
			<p>
				<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php _e( esc_attr( 'Title:' ) ); ?></label> 
				<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>">
			</p>
		<?php
	}

	// normalize the instance
	protected function _normalize( $instance, $defaults=false ) {
		$defaults = false == $defaults ? $this->defaults : $defaults;
		return wp_parse_args( $instance, $defaults );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) ) {
	QSOT_Table_Service_Status_Widget::init();
}
