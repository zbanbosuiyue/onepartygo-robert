<?php
/**
 * qswoo_inhouse_check
 *
 * @extends WC_Payment_Gateway
 */

class qsot_inhouse_check extends WC_Payment_Gateway {

	public static function pre_init() {
		// only allow this payment type in the admin, for admin users (aka. box-office users)
		if ( is_admin() && current_user_can( 'edit_users' ) && class_exists( __CLASS__ ) )
			add_filter( 'woocommerce_payment_gateways', array( __CLASS__, 'add_payment_method' ), 101 );
	}

	public static function add_payment_method( $methods ) {
		// only allow this payment type in the admin, for admin users (aka. box-office users)
		if ( is_admin() && current_user_can( 'edit_users' ) && class_exists( __CLASS__ ) )
			$methods[] = __CLASS__;

		return $methods;
	}

	public function __construct() { 
		$this->id = 'inhouse_check';
		$this->method_title = __( 'Inhouse Check', 'qsot-box-office' );        
		$this->icon = '';
		$this->has_fields = true;
		$this->supports = array( 'subscriptions', 'products', 'subscription_cancellation' );

		// Load the form fields
		$this->init_form_fields();

		// Load the settings.
		$this->init_settings();

		// Get setting values
		$this->enabled = $this->settings['enabled'];
		$this->title = $this->settings['title'];
		$this->description = $this->settings['description'];

		// Hooks
		add_action( 'woocommerce_receipt_' . $this->id, array( &$this, 'receipt_page' ) );
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );
	} 

	/**
	* Initialize Gateway Settings Form Fields
	*/
	public function init_form_fields() {
		$this->form_fields = array(
			'enabled' => array(
				'title' => __( 'Enable/Disable', 'qsot-box-office' ), 
				'label' => sprintf( __( 'Enable %s', 'qsot-box-office' ), $this->method_title ), 
				'type' => 'checkbox', 
				'description' => '', 
				'default' => 'no'
			), 
			'title' => array(
				'title' => __( 'Title', 'qsot-box-office' ), 
				'type' => 'text', 
				'description' => __( 'This controls the title which the user sees during checkout.', 'qsot-box-office' ), 
				'default' => sprintf( __( '%s Payment', 'qsot-box-office' ), $this->method_title )
			), 
			'description' => array(
				'title' => __( 'Description', 'qsot-box-office' ), 
				'type' => 'textarea', 
				'description' => __( 'This controls the description which the user sees during checkout.', 'qsot-box-office' ), 
				'default' => __( 'End user comes in the box office with a check payment.', 'qsot-box-office' ),
			)
		);
	}


	/**
	* Payment fields.
	**/
	public function payment_fields() {
		?>
		<fieldset>

			<p class="form-row form-row-first">
				<label for="check-info"><?php _e( 'Check Info', 'qsot-box-office' ) ?> <span class="required">*</span></label>
				<input type="text" class="input-text" id="check-info" name="check-info" />
			</p>

			<div class="clear"></div>
		</fieldset>
		<?php  
	}

	/**
	* Admin Panel Options 
	* - Options for bits like 'title' and availability on a country-by-country basis
	**/
	public function admin_options() {
		?>
			<h3><?php echo $this->method_title; ?></h3>
			<p><?php _e( 'Allows admin users to accept check payments via the inhouse box office.', 'qsot-box-office' ); ?></p>
			<table class="form-table">
				<?php $this->generate_settings_html(); ?>
			</table><!--/.form-table-->    	
		<?php
	}

	/**
	* Process the payment and return the result
	**/
	public function process_payment( $order_id ) {
		$order = wc_get_order( $order_id );

		$resp = null;

		// if this is the admin and the current user can use this payment processor
		if ( is_admin() && current_user_can( 'edit_users' ) ) {
			// record a note, indicating that this user used the processor
			$admin_user = wp_get_current_user();
			$order->add_order_note( $this->method_title . __( ' - payment complete | made by user: ', 'qsot-box-office' ) . $admin_user->user_login );

			// mark the order complete and notify plugins
			$order->payment_complete();
			do_action( 'qsot_completed_payment_' . $this->id, $order, $admin_user );

			// clean up the cart
			WC()->cart->empty_cart();

			// generate the response
			$resp = array(
				'result' => 'success',
				'redirect' => add_query_arg( array( 'key' => $order->order_key ), $this->get_return_url( $order ) ),
			);
		} else {
			// create an order note that explains that a payment failed, because the user does not have access
			$cur = wp_get_current_user();
			$order->add_order_note( $this->method_title . __( ' - payment FAILED | attempted by user: ', 'qsot-box-office' ) . ( is_object( $cur ) && isset( $cur->user_login ) ? $cur->user_login : __( '(no user)', 'qsot-box-office' ) ) );

			// create a WC error for frontend display
			wc_add_notice( __( 'Payment error: User does not have access to this payment type.', 'qsot-box-office' ), 'error' );
		}
		
		return $resp;
	}

	/**
	Validate payment form fields
	**/

	public function validate_fields() {
		global $woocommerce;

		$check_info = trim( $this->get_post( 'check-info' ) );
		if ( empty( $check_info ) ) {
			$woocommerce->add_error( sprintf( __( 'You must fill out the check info field. %s Payment Failed.', 'qsot-box-office' ), $this->method_title ) );
			return false;
		}

		return true;
	}
	
	/**
	 * Get post data if set
	 **/
	private function get_post($name) {
		if( isset( $_POST[ $name ] ) ) {
			return $_POST[ $name ];
		}

		return NULL;
	}

	/**
	* receipt_page
	**/
	public function receipt_page( $order ) {
		echo '<p>' . __( 'Thank you for your order.', 'qsot-box-office' ) . '</p>';
	}

}

if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) ) {
	qsot_inhouse_check::pre_init();
}
