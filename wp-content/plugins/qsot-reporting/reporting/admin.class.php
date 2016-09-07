<?php ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) ? die( header( 'Location: /' ) ) : null;

// add the admin functionality of the reporting extension
class QSOT_reporting_admin {
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

		// register our assets and localization functions
		add_action( 'admin_init', array( __CLASS__, 'register_assets' ), 100 );
		add_filter( 'qsot-reporting-tools-msgs', array( __CLASS__, 'get_tools_js_msgs' ), 100, 1 );
		add_filter( 'qsot-reporting-tools-templates', array( __CLASS__, 'get_tools_js_templates' ), 100, 1 );

		// load our assets
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ), 100 );

		// during activation, we need to update some data
		add_action( 'qsot-reporting-activation', array( __CLASS__, 'on_activation' ), 100 );

		// during load of the admin, see if we need to pop an admin notice about a required update
		add_action( 'admin_init', array( __CLASS__, 'check_required_data_update' ), 10 );

		// handle the new tools 
		add_action( 'qsot-system-status-tools', array( __CLASS__, 'sys_status_draw_tools' ), 100 );
		add_action( 'qsot-ss-performed-failed-reporting-update', array( __CLASS__, 'sys_status_performed' ), 10, 1 );
		add_action( 'qsot-ss-performed-updated-reporting', array( __CLASS__, 'sys_status_performed' ), 10, 1 );
		add_filter( 'qsot-ss-tool-RdataUp', array( __CLASS__, 'sys_status_r_tool' ), 10, 2 );
	}

	// register all our assets
	public static function register_assets() {
		// determine the root assets dir and plugin version
		$uri = QSOT_reporting_launcher::plugin_url() . 'assets/';
		$version = QSOT_reporting_launcher::version();

		// register the select2 initializer script
		wp_register_script( 'qsot-reporting-tools', $uri . 'js/utils/tools.js', array( 'qsot-tools', 'wc-enhanced-select' ), $version );

		// basic reporting screen tools and styles
		wp_register_script( 'qsot-reporting-report-ui', $uri . 'js/admin/ui.js', array( 'qsot-reporting-tools' ), $version );
		wp_register_style( 'qsot-reporting-report-ui', $uri . 'css/admin/ui.css', array(), $version );
	}

	// load our assets when appropriate
	public static function enqueue_assets( $hook ) {
		wp_enqueue_script( 'qsot-reporting-report-ui' );
		wp_enqueue_style( 'qsot-reporting-report-ui' );

		wp_localize_script( 'qsot-reporting-tools', '_qsot_reporting_settings', array(
			'nonce' => wp_create_nonce( 'do-qsot-admin-report-ajax' ),
			'msgs' => apply_filters( 'qsot-reporting-tools-msgs', array() ),
			'templates' => apply_filters( 'qsot-reporting-tools-templates', array() ),
		) );
	}

	// generate a list of msgs that are used in the js ui
	public static function get_tools_js_msgs( $list ) {
		// normalize the list
		$list = is_array( $list ) ? $list : array();

		// generate a list of new messages
		$msgs = array(
			'One result is available. Press enter to select it.' => __( 'One result is available. Press enter to select it.', 'qsot-reporting' ),
			'%s results are available. Use up and down arrow keys to navigate.' => __( '%s results are available. Use up and down arrow keys to navigate.', 'qsot-reporting' ),
			'No matches found' => __( 'No matches found', 'qsot-reporting' ),
			'Loading failed' => __( 'Loading failed', 'qsot-reporting' ),
			'Please enter 1 or more characters.' => __( 'Please enter 1 or more characters.', 'qsot-reporting' ),
			'Please enter %s or more characters.' => __( 'Please enter %s or more characters.', 'qsot-reporting' ),
			'Please delete 1 character.' => __( 'Please delete 1 character.', 'qsot-reporting' ),
			'Please delete %s characters.' => __( 'Please delete %s characters.', 'qsot-reporting' ),
			'You can only select 1 item.' => __( 'You can only select 1 item.', 'qsot-reporting' ),
			'You can only select %s items.' => __( 'You can only select %s items.', 'qsot-reporting' ),
			'Loading more results&hellip;' => __( 'Loading more results&hellip;', 'qsot-reporting' ),
			'Searching&hellip;' => __( 'Searching&hellip;', 'qsot-reporting' ),
		);

		return array_merge( $list, $msgs );
	}

	// generate a list of templates that are used in the js ui
	public static function get_tools_js_templates( $list ) {
		// normalize the list
		$list = is_array( $list ) ? $list : array();

		return $list;
	}

	// during admin_init, check if there is a data update required. if so, then add a message indicating so
	public static function check_required_data_update() {
		$check = get_option( '_qsotr_needs_update', 0 );

		// if the update is required, then add a admin notice stating so
		if ( $check )
			add_action( 'admin_notices', array( __CLASS__, 'notice_needs_data_update' ), 10 );
	}

	// pop an admin notice saying that we need a data update
	public static function notice_needs_data_update() {
		$args = array( 'page' => 'qsot-system-status', 'tab' => 'tools', 'qsot-tool' => 'RdataUp', 'n' => wp_create_nonce( 'RdataUp' ) );
		$url = add_query_arg( $args, apply_filters( 'qsot-get-menu-page-uri', '', 'main', true ) );
		?>
			<div class="error"><p><?php echo sprintf(
				__( 'The %s extension has detected that it needs to update your reporting data. Would you like to %s?', 'qsot-reporting' ),
				'<b><em>' . QSOT_reporting_launcher::name() . '</em></b>',
				sprintf( '<a class="button" href="%s">%s</a>', $url, __( 'Do it now', 'qsot-reporting' ) )
			) ?></p></div>
		<?php
	}

	// draw the data update tool
	public static function sys_status_draw_tools() {
		$url = remove_query_arg( array( 'updated', 'performed', 'qsot-tool', 'qsotn' ) );
		$nonce = wp_create_nonce( 'RdataUp' );
		?>
			<tr class="tool-item">
				<td>
					<a class="button" href="<?php echo esc_attr( add_query_arg( array( 'qsot-tool' => 'RdataUp', 'n' => $nonce ), $url ) ) ?>"><?php
						_e( 'Update Reporting Data', 'qsot-reporting' )
					?></a>
				</td>
				<td>
					<span class="helper"><?php echo sprintf( __( 'The %s requires a reporting data update. Clicking this button performs that update.', 'qsot-reporting' ), QSOT_reporting_launcher::name() ) ?></span>
				</td>
			</tr>
		<?php
	}

	// draw the "performed" tool message
	public static function sys_status_performed( $performed ) {
		switch ( $performed ) {
			case 'updated-reporting':
				echo sprintf(
					'<div class="updated"><p>%s</p></div>',
					__( 'Updated all reporting data.', 'qsot-reporting' )
				);
			break;

			case 'failed-reporting-update':
				echo sprintf(
					'<div class="error"><p>%s</p></div>',
					__( 'Some of the reporting data could not be updated.', 'qsot-reporting' )
				);
			break;
		}
	}

	// handle the tool logic
	public static function sys_status_r_tool( $performed, $args ) {
		if ( ! isset( $_GET['n'] ) || ! wp_verify_nonce( $_GET['n'], 'RdataUp' ) )
			return array( $performed, $args );

		global $wpdb, $wp_object_cache;

		// get all payment methods we have registered, and organize them by the slug, so they can be looked up later
		$raw = $wpdb->get_results( 'select * from ' . $wpdb->qsot_report_methods );
		$methods = array();
		foreach ( $raw as $row )
			$methods[ $row->method_slug ] = $row;

		$per = 500;
		$offset = 0;
		// setup a query to grab a list of order_items that do not yet have an entry in reporting table
		$q = 'select oi.order_item_id, oi.order_id from ' . $wpdb->prefix . 'woocommerce_order_items oi '
				. 'inner join ' . $wpdb->posts . ' p on oi.order_id = p.id '
				. 'left join ' . $wpdb->qsot_reports . ' r on r.order_item_id = oi.order_item_id where oi.order_item_type = "line_item" and r.order_id is null limit %d offset %d';

		// while there are order_items to process
		while ( $raw_ids = $wpdb->get_results( $wpdb->prepare( $q, $per, $offset ) ) ) {
			$offset += $per;

			// organize the results so they can be used
			$ids = array();
			foreach ( $raw_ids as $row )
				$ids[ $row->order_item_id ] = $row->order_id;
			$order_ids = array_unique( array_values( $ids ) );

			// find all the order item meta
			$all = $wpdb->get_results( 'select order_item_id, meta_key, meta_value from ' . $wpdb->prefix . 'woocommerce_order_itemmeta where order_item_id in (' . implode( ',', array_map( 'absint', array_keys( $ids ) ) ) . ')' );
			$indexed = array();
			foreach ( $all as $row ) {
				if ( ! isset( $indexed[ $row->order_item_id ] ) ) $indexed[ $row->order_item_id ] = array();
				$indexed[ $row->order_item_id ][ $row->meta_key ] = $row->meta_value;
			}
			// normalize the meta
			foreach ( $indexed as $oiid => $row ) {
				$indexed[ $oiid ] = wp_parse_args( $row, array(
					'_event_id' => 0,
					'_product_id' => 0,
					'_line_subtotal' => 0,
					'_line_total' => 0,
					'_line_subtotal_tax' => 0,
					'_line_tax' => 0,
					'_qty' => 1,
				) );
			}

			// get all the order data we need to finish out our data row
			$raw_o = $wpdb->get_results(
				'select id, post_date, meta_key, meta_value from ' . $wpdb->posts . ' left join ' . $wpdb->postmeta . ' on post_id = id and post_type = "shop_order" '
						. 'and ( meta_key in ( "_payment_method", "_payment_method_title" ) ) and id in (' . implode( ',', $order_ids ) . ')'
			);
			$order_ids = array();
			foreach ( $raw_o as $row ) {
				if ( ! isset( $order_ids[ $row->id ] ) ) $order_ids[ $row->id ] = array( 'order_date' => $row->post_date );
				if ( ! $row->meta_key ) continue;
				$order_ids[ $row->id ][ $row->meta_key ] = $row->meta_value;
			}

			// insert any methods that do not already exist, and update our list of methods
			foreach ( $order_ids as $order_id => $order_data ) {
				if ( ! isset( $order_data['_payment_method'] ) ) continue;
				if ( isset( $methods[ $order_data['_payment_method'] ] ) ) continue;

				// do the insert
				$data = array( 'method_slug' => $order_data['_payment_method'], 'method_title' => isset( $order_data['_payment_method_title'] ) ? $order_data['_payment_method_title'] : $order_data['_payment_method'] );
				$wpdb->insert( $wpdb->qsot_report_methods, $data );

				// update the running list
				$methods[ $order_data['_payment_method'] ] = (object)array(
					'id' => $wpdb->insert_id,
					'method_slug' => $data['method_slug'],
					'method_title' => $data['method_title'],
				);
			}

			// construct the insert statement
			$sql = 'insert into ' . $wpdb->qsot_reports . ' (event_id, product_id, order_id, order_date, order_item_id, subtotal, total, subtotal_tax, total_tax, quantity, method_id) values ';
			$sql2 = 'event_id = values( event_id ), '
					. 'product_id = values( product_id ), '
					. 'order_id = values( order_id ), '
					. 'order_date = values( order_date ), '
					. 'subtotal = values( subtotal ), '
					. 'total = values( total ), '
					. 'subtotal_tax = values( subtotal_tax ), '
					. 'total_tax = values( total_tax ), '
					. 'quantity = values( quantity ), '
					. 'method_id = values( method_id )';
			$qs = array();
			foreach ( $indexed as $oiid => $order_item ) {
				// if the order no longer exists for this item, bail
				if ( ! isset( $order_ids[ $ids[ $oiid ] ] ) )
					continue;

				// contruct the insert row
				$qs[] = $wpdb->prepare(
					'(%s, %s, %s, %s, %s, %s, %s, %s, %s, %s, %s)',
					$order_item['_event_id'],
					$order_item['_product_id'],
					$ids[ $oiid ],
					$order_ids[ $ids[ $oiid ] ]['order_date'],
					$oiid,
					$order_item['_line_subtotal'],
					$order_item['_line_total'],
					$order_item['_line_subtotal_tax'],
					$order_item['_line_tax'],
					$order_item['_qty'],
					isset( $order_ids[ $ids[ $oiid ] ]['_payment_method'] ) ? $methods[ $order_ids[ $ids[ $oiid ] ]['_payment_method'] ]->id : 0
				);
			}

			if ( count( $qs ) )
				$wpdb->query( $sql . implode( ',', $qs ) . ' on duplicate key update ' . $sql2 );

			// flush the caches
			$wpdb->flush();
			if ( isset( $wp_object_cache->cache ) ) // dont blow out memcache plugin cache, only local array based cache from core wp
				$wp_object_cache->cache = array();
		}

		// we performed the task
		$performed = true;

		// update the option with a new check of the absence of prices
		self::_check_data_update();

		// if we still need an update, then mark the action as having failed
		$needs = get_option( '_qsotr_needs_update', 0 );
		if ( $needs ) {
			$args['performed'] = 'failed-reporting-update';
		// otherwise we passed
		} else {
			$args['performed'] = 'updated-reporting';
		}

		return array( $performed, $args );
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
	}

	// internal check to see if we need a data update
	protected static function _check_data_update() {
		global $wpdb;

		$table_exists = $wpdb->get_var( $wpdb->prepare( 'show tables like %s', $wpdb->qsot_reports ) );
		// if the reports table does not exist yet, then go ahead and assume there are rows in the order items table that are not indexed in the reports table
		if ( empty( $table_exists ) ) {
			update_option( '_qsot_do_needs_update', 1 );
		// otherwise, check if there are any order items that are not in the reporting tables
		} else {
			$exists = $wpdb->get_var(
				'select count(oi.order_item_id) from ' . $wpdb->prefix . 'woocommerce_order_items oi '
						. 'inner join ' . $wpdb->posts . ' p on oi.order_id = p.id '
						. 'left join ' . $wpdb->qsot_reports . ' r on r.order_item_id = oi.order_item_id where oi.order_item_type = "line_item" and r.order_id is null'
			);

			// if there are any that exist without a price designation, then update our option to indicate that
			update_option( '_qsot_do_needs_update', $exists );
		}
	}

	// during activation, update some data
	public static function on_activation() {
		self::_check_data_update();
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_reporting_admin::pre_init();
