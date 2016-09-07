<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// the loads new admin only gateways, and modifies existing ones if needed
class qsot_bo_gateways {
	// setup the class's functionality
	public static function pre_init() {
		add_action( 'qsot-after-loading-modules-and-plugins', array( __CLASS__, 'load_gateways' ), 1 );
	}

	// load all the new gateways we added for the box-office users
	public static function load_gateways() {
		// make sure we have the core class loaded already
		if ( ! class_exists( 'WC_Payment_Gateway' ) ) return;
		// load our new gateways
		do_action( 'qsot-load-includes', 'gateways', '#^.*\.gateway\.php$#i' );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	qsot_bo_gateways::pre_init();
