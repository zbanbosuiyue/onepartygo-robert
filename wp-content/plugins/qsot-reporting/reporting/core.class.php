<?php ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) ? die( header( 'Location: /' ) ) : null;

// add the core functionality of the reporting extension
class QSOT_reporting_core {
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

		// setup the tables used by this extension
		self::setup_db_tables();
		add_action( 'switch_blog', array( __CLASS__, 'setup_db_tables' ), 1000 );
		add_filter( 'qsot-upgrader-table-descriptions', array( __CLASS__, 'setup_tables' ), 10000 );
	}

	// setup the talbe names based on the current table prefix
	public static function setup_db_tables() {
		global $wpdb;

		$wpdb->qsot_reports = $wpdb->prefix . 'qsot_reporting';
		$wpdb->qsot_report_methods = $wpdb->prefix . 'qsot_reporting_payment_methods';
	}

	// setup the table descriptions for the updater
	public static function setup_tables( $tables ) {
		global $wpdb;

		// the reporting cache table
		$tables[ $wpdb->qsot_reports ] = array(
      'version' => '0.9.1',
      'fields' => array(
				'event_id' => array( 'type' => 'bigint(20) unsigned' ), // post of type qsot-event
				'product_id' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // product_id of the woo product that represents the ticket that was purchased/reserved (woocommerce)
				'order_id' => array( 'type' => 'bigint(20) unsigned' ), // post of type shop_order (woocommerce)
				'order_date' => array( 'type' => 'datetime' ), // date the order was completed
				'order_item_id' => array( 'type' => 'bigint(20) unsigned' ), // order item id of the individual item (woocommerce)
				'subtotal' => array( 'type' => 'decimal(9,2)' ), // line subtotal, before discount
				'total' => array( 'type' => 'decimal(9,2)' ), // line total
				'subtotal_tax' => array( 'type' => 'decimal(9,2)' ), // line subtotal tax, before discount
				'total_tax' => array( 'type' => 'decimal(9,2)' ), // line total tax
				'quantity' => array( 'type' => 'smallint(5) unsigned' ), // number of tickets sold for this line item
				'method_id' => array( 'type' => 'smallint(5)', 'default' => '0' ), // payment type used for the payment
      ),
      'keys' => array(
				'UNIQUE KEY oiid (order_item_id)',
        'KEY evt_id (event_id)',
        'KEY ord_id (order_id)',
        'KEY meth_id (method_id)',
			),
		);

		// lookup for the payment methods
		$tables[ $wpdb->qsot_report_methods ] = array(
			'version' => '0.9.0',
			'fields' => array(
				'id' => array( 'type' => 'smallint(5)', 'extra' => 'auto_increment' ), // unique id assigned to the payment method
				'method_slug' => array( 'type' => 'text' ), // the slug name to look up the payment method within the software
				'method_title' => array( 'type' => 'text' ), // backup method name, in case it can no longer be found in the software
			),
			'keys' => array(
				'PRIMARY KEY  (id)',
			),
		);

		return $tables;
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_reporting_core::pre_init();
