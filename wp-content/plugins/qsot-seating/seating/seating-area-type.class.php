<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to handle the Seating event area type
class QSOT_Seating_Area_Type extends QSOT_General_Admission_Area_Type {
	// container for the singleton instance
	protected static $instance = array();

	// internal constants for zome types
	const ZONES = 1;
	const ZOOM_ZONES = 2;

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Seating_Area_Type )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new QSOT_Seating_Area_Type();
	}

	// constructor. handles instance setup, and multi instance prevention
	public function __construct() {
		// if there is already an instance of this object, then bail now
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Seating_Area_Type )
			throw new Exception( sprintf( __( 'There can only be one instance of the %s object at a time.', 'opentickets-community-edition' ), __CLASS__ ), 12000 );

		// otherwise, set this as the known instance
		self::$instance = $this;

		// and call the intialization function
		$this->initialize();
	}

	// destructor. handles instance destruction
	public function __destruct() {
		$this->deinitialize();
	}


	// setup the object
	public function initialize( $ajax=true ) {
		// defaults from parent
		parent::initialize( false );

		// setup the object description
		$this->priority = 10;
		$this->find_priority = 100;
		$this->slug = 'seating';
		$this->name = __( 'Seating Chart', 'qsot-seating' );

		// after all the plugins have loaded, register this type
		if ( ! did_action( 'plugins_loaded' ) )
			add_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ), 10 );
		else {
			require_once QSOT_seating_launcher::plugin_dir() . 'seating/price-struct.class.php';
			$this->plugins_loaded();
		}

		// actions to help sync the cart with the actions we take in the event ticket UI
		add_filter( 'qsot-seating-zoner-reserve-results', array( &$this, 'add_tickets_to_cart' ), 10, 2 );
		add_action( 'woocommerce_before_cart_item_quantity_zero', array( &$this, 'delete_ticket_from_cart' ), 10, 1 );
		add_action( 'woocommerce_remove_cart_item', array( &$this, 'delete_ticket_from_cart' ), 10, 1 );
		add_action( 'woocommerce_after_cart_item_quantity_update', array( &$this, 'update_reservations_on_cart_update' ), 10, 3 );

		// augment the zoner search
		add_filter( 'qsot-zoner-query-find-query-parts', array( &$this, 'find_query_for_custom_fields' ), 10, 2 );
		add_filter( 'qsot-zoner-query-return-total-zone-ticket-type-state', array( &$this, 'find_query_return_zone_ticket_type_state' ), 10, 2 );
		add_filter( 'qsot-zoner-query-return-total-zone-ticket-type-state-flat', array( &$this, 'find_query_return_zone_ticket_type_state_flat' ), 10, 2 );
		add_filter( 'qsot-zoner-query-return-total-zone', array( &$this, 'find_query_return_total_zone' ), 10, 2 );

		// maintain extra meta about our ticket products when they are added to a cart for a seated event
		add_filter( 'qsot-ticket-item-meta-keys', array( &$this, 'meta_keys_maintained' ), 10, 1 );
		add_filter( 'qsot-ticket-item-hidden-meta-keys', array( &$this, 'hide_meta_keys' ), 10, 1 );
		add_action( 'qsot-ticket-item-meta', array( &$this, 'add_zone_to_order_item_display' ), 10, 3 );
		add_action( 'woocommerce_order_item_meta_start', array( &$this, 'add_zone_to_order_item_display_end_user' ), 1000, 3 );
		add_action( 'woocommerce_get_item_data', array( &$this, 'add_zone_name_to_cart' ), 10, 2 );

		// stub image for the event on page load
		add_action( 'qsot-draw-event-area-image', array( &$this, 'draw_event_area_image' ), 100, 3 );

		// add the seat to the ticket display
		add_action( 'qsot-ticket-information', array( &$this, 'ticket_zone_display' ), 10000, 2 );
		add_filter( 'qsot-compile-ticket-info', array( &$this, 'compile_ticket_info' ), 10000, 3 );

		// advanced tools helpers
		add_action( 'qsot-system-status-adv-tools-table-headers', array( &$this, 'adv_tools_draw_zone_column_header' ), 10 );
		add_action( 'qsot-system-status-adv-tools-table-row-columns', array( &$this, 'adv_tools_draw_zone_column' ), 10, 1 );
		add_filter( 'qsot-system-status-adv-tools-table-row-id', array( &$this, 'adv_tools_add_zone_id_to_code' ), 10, 2 );
		add_action( 'qsot-system-status-adv-tools-add-ticket-fields', array( &$this, 'adv_tools_draw_zone_field' ), 10, 1 );
		add_filter( 'qsot-system-status-adv-tools-add-ticket-data', array( &$this, 'adv_tools_add_ticket_data' ), 10, 2 );
		add_filter( 'qsot-system-status-adv-tools-release-seat-id', array( &$this, 'adv_tools_parse_ticket_row_code' ), 10, 2 );
		add_filter( 'qsot-system-status-adv-tools-release-seat', array( &$this, 'adv_tools_handle_release_request' ), 10, 3 );

		// system-status tools resync injection
		add_filter( 'qsot-system-status-tools-RsOi2Tt-update-data', array( &$this, 'sstools_add_zone_id' ), 10, 4 );

		// report field updates
		add_filter( 'qsot-seating-report-csv-columns', array( &$this, 'report_csv_seating_seat_column' ), 10, 2 );
		add_filter( 'qsot-seating-report-html-columns', array( &$this, 'report_html_seating_seat_column' ), 10, 2 );
		add_filter( 'qsot-seating-report-data-row', array( &$this, 'report_html_seating_seat_column_value' ), 10, 4 );

		// change the message we leave when removing a ticket from an order due to order cancellation
		add_filter( 'qsot-removing-cancelled-order-ticket-msg', array( &$this, 'remove_cancelled_order_ticket_msg' ), 100, 3 );

		// certain filters should only exist in the admin
		if ( is_admin() ) {
			// add the list of valid state types to the list that the seating chart will use to pull records
			add_filter( 'qsot-seating-report-state-map', array( &$this, 'add_state_types_to_report' ), 10, 2 );
		}

		if ( $ajax ) {
			// add the gaea ajax handlers
			$aj = QSOT_Ajax::instance();
			$aj->register( 'seating-interest', array( &$this, 'aj_interest' ), array(), null, 'qsot-frontend-ajax' );
			$aj->register( 'seating-reserve', array( &$this, 'aj_reserve' ), array(), null, 'qsot-frontend-ajax' );
			$aj->register( 'seating-remove', array( &$this, 'aj_remove' ), array(), null, 'qsot-frontend-ajax' );

			// register our admin ajax functions
			$aj->register( 'seating-admin-interest', array( &$this, 'admin_ajax_interest' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
			$aj->register( 'seating-admin-reserve', array( &$this, 'admin_ajax_reserve' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
			$aj->register( 'seating-admin-update-ticket', array( &$this, 'admin_ajax_update_ticket' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
			$aj->register( 'seating-admin-remove', array( &$this, 'admin_ajax_remove' ), array( 'edit_shop_orders' ), null, 'qsot-admin-ajax' );
		}

		// setup the admin settings pag options for this plugin
		add_filter( 'qsot-settings-general-sections', array( &$this, 'add_seating_charts_subtab' ), 10 );
		add_action( 'woocommerce_admin_field_qsot-single-image', array( $this, 'image_id_setting' ), 1000, 1 );
		$this->_setup_admin_options();
	}

	// destroy the object
	public function deinitialize() {
		remove_action( 'switch_blog', array( &$this, 'setup_table_names' ), PHP_INT_MAX );
		remove_action( 'plugins_loaded', array( &$this, 'plugins_loaded' ), 10 );
		remove_filter( 'qsot-gaea-zoner-reserve-results', array( &$this, 'add_tickets_to_cart' ), 10 );
		remove_filter( 'qsot-ticket-item-meta-keys', array( &$this, 'meta_keys_maintained' ), 10 );
		remove_filter( 'qsot-ticket-item-hidden-meta-keys', array( &$this, 'meta_keys_hidden' ), 10 );
	}

	// register this area type after all plugins have loaded
	public function plugins_loaded() {
		// register this as an event area type
		do_action_ref_array( 'qsot-register-event-area-type', array( &$this ) );

		// load the pricing structure handler
		$this->price_struct = QSOT_Seating_Price_Struct::instance();
	}

	// add a seat column to the csv attendee report
	public function report_csv_seating_seat_column( $columns, $event ) {
		// load the area type for this event
		$area_type = apply_filters( 'qsot-event-area-type-for-event', false, $event->ID );

		// if this event's area type is a seating area, then add the seat column
		if ( is_object( $area_type ) && ! is_wp_error( $area_type ) && $area_type->get_slug() == $this->get_slug() ) {
			$tmp = $columns;
			$columns = array();
			foreach ( $tmp as $col => $label ) {
				$columns[ $col ] = $label;
				if ( 'order_id' == $col )
					$columns['zone'] = __( 'Seat/Zone', 'qsot-seating' );
			}
		}

		return $columns;
	}

	// add a seat column to the attendee report
	public function report_html_seating_seat_column( $columns, $event ) {
		// load the area type for this event
		$area_type = apply_filters( 'qsot-event-area-type-for-event', false, $event->ID );

		// if this event's area type is a seating area, then add the seat column
		if ( is_object( $area_type ) && ! is_wp_error( $area_type ) && $area_type->get_slug() == $this->get_slug() ) {
			$tmp = $columns;
			$columns = array();
			foreach ( $tmp as $col => $label ) {
				$columns[ $col ] = $label;
				if ( 'order_id' == $col )
					$columns['zone'] = array( 'title' => __( 'Seat/Zone', 'qsot-seating' ) );
			}
		}

		return $columns;
	}

	// add the seat column to the data of the attendee report
	public function report_html_seating_seat_column_value( $values, $row, $event, $meta ) {
		$zone = $this->get_zoner()->get_zone_info( $row->zone_id );

		// if the zone exists, add the data to the report
		if ( is_object( $zone ) && ! is_wp_error( $zone ) )
			$values['zone'] = empty( $zone->name ) ? $zone->abbr : $zone->name;

		return $values;
	}

	// when an order is cancelled, we need to leave a message saying what tickets were removed. this function handles the message for zone based tickets
	public function remove_cancelled_order_ticket_msg( $msg, $event, $item ) {
		// if there is no event or zone id on the order item, use the standard message
		if ( ! isset( $item['event_id'], $item['zone_id'] ) )
			return $msg;

		// get the zoner, so we can fetch the zone info
		$zoner = $this->get_zoner();
		if ( ! is_object( $zoner ) || is_wp_error( $zoner ) )
			return $msg;

		// fetch the zone information
		$zone = $zoner->get_zone_info( $item['zone_id'] );
		if ( ! is_object( $zone ) || is_wp_error( $zone ) )
			return $msg;

		$product_name = __( 'Unknown Ticket Type', 'opentickets-community-edition' );
		// load the product for the ticket we are removing, so we can use the title in the message
		$product = wc_get_product( $item['product_id'] );
		if ( is_object( $product ) && ! is_wp_error( $product ) )
			$product_name = $product->get_title();

		$event_start = get_post_meta( $event->ID, '_start', true );
		$event_date_time = date_i18n( get_option( 'date_format', __( 'Y-m-d', 'opentickets-commnunity-edition' ) ), strtotime( $event_start ) ) . ' '
				. date_i18n( get_option( 'time_format', __( 'H:i:s', 'opentickets-commnunity-edition' ) ), strtotime( $event_start ) );
		// constuct the final message that includes the zone information
		return sprintf(
			__( 'Removed (%d) "%s" [T#%d] tickets for event "%s" [E#%d] for seat/zone "%s" [Z#%d] from the order, because the order was cancelled. This released those tickets back into the ticket pool.', 'opentickets-community-edition' ),
			$item['qty'],
			$product_name,
			$item['product_id'],
			apply_filters( 'the_title', $event->post_title . ' @ ' . $event_date_time ),
			$event->ID,
			'' == $zone->name ? $zone->abbr : $zone->name,
			$zone->id
		);
	}

	// add meta keys that should be maintained in the cart and saved into order items
	public function meta_keys_maintained( $list ) {
		$list[] = 'zone_id';
		return $list;
	}

	// during frontend display, we need to hide certain meta values from being displayed the normal editable way
	public function hide_meta_keys( $list ) {
		$list[] = '_zone_id';
		return array_unique( $list );
	}

	// when displaying order items, we need to make the zone_id a formatted display
	public function add_zone_to_order_item_display( $item_id, $item, $product ) {
		// if there is no zone id, then bail
		if ( ! isset( $item['zone_id'] ) || $item['zone_id'] <= 0 )
			return;

		// get the zone, but if that fails, bail
		$zoner = $this->get_zoner();
		$zone = $zoner->get_zone_info( $item['zone_id'] );
		if ( ! is_object( $zone ) || is_wp_error( $zone ) )
			return;

		// render the zone id in a readable format
		?>
			<div class="info">
				<strong><?php _e( 'Zone:', 'qsot-seating' ) ?></strong>
				<?php echo sprintf( '%s (#%d)', ! empty( $zone->name ) ? $zone->name : $zone->abbr, $zone->id ) ?>
			</div>
		<?php
	}

	// also display the zone information when displaying the order line items to the end user
	public function add_zone_to_order_item_display_end_user( $item_id, $item, $order ) {
		// if there is no zone id, then bail
		if ( ! isset( $item['zone_id'] ) || $item['zone_id'] <= 0 )
			return;

		// get the zone, but if that fails, bail
		$zoner = $this->get_zoner();
		$zone = $zoner->get_zone_info( $item['zone_id'] );
		if ( ! is_object( $zone ) || is_wp_error( $zone ) )
			return;

		// render the zone id in a readable format
		?>
			<br/><small>
				<strong><?php _e( 'Zone', 'qsot-seating' ) ?></strong>:
				<?php echo sprintf( '%s (#%d)', ! empty( $zone->name ) ? $zone->name : $zone->abbr, $zone->id ) ?>
			</small>
		<?php
	}

	// add the zone info to the items in the cart
	public function add_zone_name_to_cart( $list, $item ) {
		// if there is no zone id, then bail
		if ( ! isset( $item['zone_id'] ) || $item['zone_id'] <= 0 )
			return $list;

		// get the zone, but if that fails, bail
		$zoner = $this->get_zoner();
		$zone = $zoner->get_zone_info( $item['zone_id'] );
		if ( ! is_object( $zone ) || is_wp_error( $zone ) )
			return $list;

		// add the zone info to the displayed data
		$list[] = array(
			'name' => __( 'Zone', 'qsot-seating' ),
			'display' => ! empty( $zone->name ) ? $zone->name : $zone->abbr,
		);

		return $list;
	}

	// if the ticket has a zone designation, then print out the relevant zone information
	public function ticket_zone_display( $ticket, $multiple ) {
		if ( isset( $ticket->zone, $ticket->zone->name ) ) {
			?><li><span class="seat label">Seat:</span> <?php echo apply_filters( 'the_title', ! empty( $ticket->zone->name ) ? $ticket->zone->name : $ticket->zone->abbr ) ?></li><?php
		}
	}

	// if the ticket has a zone designation, load the zone information for use on the ticket display
	public function compile_ticket_info( $info, $oiid, $order_id ) {
		// fetch the zone_id for this ticket
		$zone_id = isset( $info->order_item, $info->order_item['zone_id'] ) ? (int)$info->order_item['zone_id'] : 0;
	
		if ( $zone_id > 0 ) {
			// load the zone information based on the zone id
			$zoner = $this->get_zoner();
			$zone = $zoner->get_zone_info( $zone_id );

			// if the zone exists, add it to the zone information
			if ( is_object( $zone ) )
				$info->zone = $zone;
		}

		return $info;
	}

	// fetch the object that is handling the registrations for this event_area type
	public function get_zoner() {
		return QSOT_Seating_Zoner::instance();
	}

	// register the assets we may need in either the admin or the frontend, for this area_type
	public function register_assets() {
		// reusable data
		$version = QSOT_seating_launcher::version();
		$url = QSOT_seating_launcher::plugin_url() . 'assets/';
		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// svg lib
		wp_register_script( 'snapsvg', $url . 'js/libs/snapsvg/snap.svg' /*. $debug */. '.js', array(), 'v0.3.0' );
		// additional tools to use, similar to core OTCE tools.js
		wp_register_script( 'qsot-seating-tools', $url . 'js/utils/tools.js', array( 'qsot-tools', 'jquery-ui-dialog' ), $version );
		// core loader for our scripts needed on the frontend
		wp_register_script( 'qsot-seating-loader', $url . 'js/frontend/loader.js', array( 'qsot-seating-tools', 'jquery-color' ), $version );

		// frontend seating ui
		wp_register_script( 'qsot-seating-event-frontend', $url . 'js/frontend/ui.js', array( 'qsot-seating-tools' ), $version );
		wp_register_style( 'qsot-seating-event-frontend', $url . 'css/frontend/ui.css', array( 'qsot-gaea-event-frontend' ), $version );

		// no longer restricted to the admin, because of Event Managers
		// if ( is_admin() ) {
			// seating chart drawing ui
			wp_register_script( 'qsot-browser-storage', $url . 'js/admin/browser-storage.js', array( 'qsot-seating-tools' ), $version );
			wp_register_script( 'qsot-seating-admin-draw', $url . 'js/admin/draw.js', array( 'snapsvg', 'wp-color-picker', 'qsot-browser-storage', 'jquery-ui-dialog' ), $version );
			wp_register_style( 'qsot-seating-admin', $url . 'css/admin/base.css', array( 'qsot-base-admin' ), $version );

			// admin pricing tool
			wp_register_script( 'qsot-admin-price-struct', $url . 'js/admin/price.js', array( 'qsot-tools', 'select2', 'jquery-ui-dialog', 'jquery-ui-sortable' ), $version );

			// integrate into the events page
			wp_register_script( 'qsot-seating-event-settings', $url . 'js/admin/event-settings.js', array( 'qsot-events-admin-edit-page', 'qsot-admin-tools' ), $version );

			// order admin
			wp_register_script( 'qsot-seating-admin-seat-selection-loader', $url . 'js/admin/loader.js', array( 'qsot-admin-ticket-selection' ), $version );
		//}
	}

	// enqueue the appropriate assets for the frontend
	public function enqueue_assets( $event ) {
		// if we do not have the required info, then bail
		if ( ! is_object( $event ) || ! isset( $event->event_area ) || ! is_object( $event->event_area ) )
			return;

		// if this event is not using an event area of this type, then bail now
		if ( ! isset( $event->event_area->area_type ) || ! is_object( $event->event_area->area_type ) || $this->slug !== $event->event_area->area_type->get_slug() )
			return;

		// base url to our plugin assets
		$url = QSOT_seating_launcher::plugin_url() . 'assets/';
		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		// include the base styling
		wp_enqueue_style('qsot-seating-event-frontend');

		// get the zoner for this area type
		$zoner = $this->get_zoner();

		// get the valid stati for that zoner
		$stati = $zoner->get_stati();

		// enqueue the frontend event ui scrit
		wp_enqueue_script( 'qsot-seating-loader' );

		// are we allowed to show the available quantity?
		$show_qty = 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-show-available-quantity' );

		// get the price struct used by this event
		$prices = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'unique-ids' ) );
		$prices[] = 0;

		$icons = array();
		// load the custom icons for the buttons
		foreach ( array( 'zoom-in', 'zoom-out', 'zoom-reset' ) as $icon_key ) {
			$icons[ $icon_key ] = '';
			$icon_id = apply_filters( 'qsot-get-option-value', '', 'qsot-seating-' . $icon_key . '-icon-id' );
			if ( 'noimg' == $icon_id ) {
				$icons[ $icon_key ] = 'remove';
			} else if ( intval( $icon_id ) ) {
				list( $icon_url ) = wp_get_attachment_image_src( intval( $icon_id ), array( 30, 30 ) );
				if ( $icon_url )
					$icons[ $icon_key ] = $icon_url;
			}
		}

		// setup the settings we need for that script to run
		wp_localize_script( 'qsot-seating-loader', '_qsot_seating_loader', apply_filters( 'qsot-seating-event-frontend-settings', array(
			'assets' => array(
				'snap' => $url . 'js/libs/snapsvg/snap.svg' . $debug . '.js',
				'svg' => $url . 'js/frontend/ui.js',
				'nosvg' => $url . 'js/frontend/ui-nosvg.js',
				'res' => $url . 'js/frontend/reservations.js',
			),
			'nonce' => wp_create_nonce( 'do-qsot-frontend-ajax' ),
			'options' => array(
				'one-click' => 'yes' == apply_filters( 'qsot-get-option-value', 'no', 'qsot-seating-one-click-single-price' ),
				'chart-position' => apply_filters( 'qsot-get-option-value', 'above', 'qsot-seating-chart-position' ),
				'icons' => $icons,
			),
			'edata' => $this->_get_frontend_event_data( $event ),
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'templates' => $this->get_templates( $event ),
			'messages' => $this->get_messages( $event ),
			'event_id' => $event->ID,
			'ssl' => is_ssl(),
			'owns' => $zoner->find( array(
				'fields' => 'total-zone-ticket-type-state',
				'state' => array( $stati['i'][0], $stati['r'][0] ),
				'event_id' => $event->ID,
				'ticket_type_id' => $prices,
				'customer_id' => $zoner->current_user(),
			) ),
		), $event ) );
	}

	// enqueue the assets we need in the admin for this area type
	public function enqueue_admin_assets( $type=null, $exists=false, $post_id=0 ) {
		// base url to our plugin assets
		$url = QSOT_seating_launcher::plugin_url() . 'assets/';
		$debug = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';

		switch ( $type ) {
			case 'qsot-event-area':
				$ea = $area_type = false;
				// load the event area object
				if ( $exists && $post_id > 0 ) {
					$ea = apply_filters( 'qsot-get-event-area', false, $post_id );
					$area_type = is_object( $ea ) && ! is_wp_error( $ea ) ? $ea->area_type : false;
				}

				$structs = array();
				// get a list of the structs used by this event_area
				if ( is_object( $area_type ) && $area_type->get_slug() == $this->slug && $post_id > 0 )
					$structs = $this->price_struct->get_by_event_area_id( $post_id, array( 'price_list_format' => 'ids' ) );

				// get a list of sll ticket products
				$all = apply_filters( 'qsot-get-all-ticket-products', array() );

				$tickets = array();
				// convert that list into a name and id list
				while ( count( $all ) ) {
					$ticket = array_shift( $all );
					$tickets[] = array( 'id' => $ticket->id, 'name' => sprintf( '%s (%s)', $ticket->post->proper_name, $ticket->post->meta['price_html'] ) );
				}

				// get the zoner
				$zoner = $this->get_zoner();
				if ( ! is_object( $zoner ) || is_wp_error( $zoner ) )
					return;

				// add media js and base seating styles
				wp_enqueue_media();
				wp_enqueue_style( 'wp-jquery-ui-dialog' );
				wp_enqueue_style( 'qsot-seating-admin' );

				// enqueue our core UI drawing tool
				wp_enqueue_script( 'qsot-seating-admin-draw' );
				wp_localize_script( 'qsot-seating-admin-draw', '_qsot_seating_draw', array(
					'data' => array(
						'zones' => $zoner->get_zones( array( 'event_area_id' => $post_id, 'type' => self::ZONES ) ),
						'zoom_zones' => $zoner->get_zones( array( 'event_area_id' => $post_id, 'type' => self::ZOOM_ZONES ) ),
					),
					'strings' => array(
						'Bounding box must be a rectangle.' => __( 'Bounding box must be a rectangle.', 'qsot-seating' ),
						'Yes' => __( 'Yes', 'qsot-seating' ),
						'pattern' => __( 'pattern', 'qsot-seating' ),
						'replace' => __( 'replace', 'qsot-seating' ),
						'What is the naming pattern you would like to use?' => __( 'What is the naming pattern you would like to use?', 'qsot-seating' ),
						"^ = A-Z<br/>@ = a-z<br/># = 0-9<br/>[x,y] = start from value 'x', alternate every 'y'<br/>(ex: 'north-@[b]-#[3,2]' = odd numbered seats starting at 'north-b-3')" =>
								__( "^ = A-Z<br/>@ = a-z<br/># = 0-9<br/>[x,y] = start from value 'x', alternate every 'y'<br/>(ex: 'north-@[b]-#[3,2]' = odd numbered seats starting at 'north-b-3')", 'qsot-seating' ),
						'Draw a line in the direction of numbers lowest to highest.' => __( 'Draw a line in the direction of numbers lowest to highest.', 'qsot-seating' ),
						'Draw a line in the direction of letters "a" to "z".' => __( 'Draw a line in the direction of letters "a" to "z".', 'qsot-seating' ),
						'Draw a line in the direction of letters "A" to "Z".' => __( 'Draw a line in the direction of letters "A" to "Z".', 'qsot-seating' ),
						'the line must be at least 5px long.' => __( 'the line must be at least 5px long.', 'qsot-seating' ),
						'What text would you like to find, within each name (regex without delimiters is accepted)?' => __( 'What text would you like to find, within each name (regex without delimiters is accepted)?', 'qsot-seating' ),
						'What would you like to replace "%s" with, within each name?' => __( 'What would you like to replace "%s" with, within each name?', 'qsot-seating' ),
						'What to find:' => __( 'What to find:', 'qsot-seating' ),
						'Replace it with what?' => __( 'Replace it with what?', 'qsot-seating' ),
						'show advanced' => __( 'show advanced', 'qsot-seating' ),
						'hide advanced' => __( 'hide advanced', 'qsot-seating' ),
						'Zoom-In' => __( 'Zoom-In', 'qsot-seating' ),
						'Zoom-Out' => __( 'Zoom-Out', 'qsot-seating' ),
						'Button' => __( 'Button', 'qsot-seating' ),
						'Distraction Free' => __( 'Distraction Free', 'qsot-seating' ),
						'Undo' => __( 'Undo', 'qsot-seating' ),
						'Redo' => __( 'Redo', 'qsot-seating' ),
						'True ID' => __( 'True ID', 'qsot-seating' ),
						'Unique ID' => __( 'Unique ID', 'qsot-seating' ),
						'Think of this as the "slug" to identify this zone uniquely from the others, like a post would have.' =>
								__( 'Think of this as the "slug" to identify this zone uniquely from the others, like a post would have.', 'qsot-seating' ),
						'Name' => __( 'Name', 'qsot-seating' ),
						'The proper name of this zone, displayed in most locations that this zone needs to be identified, like on tickets, carts, or ticket selection UIs.' =>
								__( 'The proper name of this zone, displayed in most locations that this zone needs to be identified, like on tickets, carts, or ticket selection UIs.', 'qsot-seating' ),
						'Capacity' => __( 'Capacity', 'qsot-seating' ),
						'The maximum number of tickets that can be sold for this zone, on a given event.' => __( 'The maximum number of tickets that can be sold for this zone, on a given event.', 'qsot-seating' ),
						'Fill Color' => __( 'Fill Color', 'qsot-seating' ),
						'What color should the inside of the shape for this zone be?' => __( 'What color should the inside of the shape for this zone be?', 'qsot-seating' ),
						'Hidden on Frontend' => __( 'Hidden on Frontend', 'qsot-seating' ),
						'If yes, then this element does not get displayed to the end user.' => __( 'If yes, then this element does not get displayed to the end user.', 'qsot-seating' ),
						'Locked in Place' => __( 'Locked in Place', 'qsot-seating' ),
						'If yes, then attempts to drag this element will not work.' => __( 'If yes, then attempts to drag this element will not work.', 'qsot-seating' ),
						'Fill Transparency' => __( 'Fill Transparency', 'qsot-seating' ),
						'Transparency of the inside of the zone.' => __( 'Transparency of the inside of the zone.', 'qsot-seating' ),
						'Unavailable Color' => __( 'Unavailable Color', 'qsot-seating' ),
						'What color should the inside of the zone be when it has reached capacity?' => __( 'What color should the inside of the zone be when it has reached capacity?', 'qsot-seating' ),
						'Unavailable Transparency' => __( 'Unavailable Transparency', 'qsot-seating' ),
						'Transparency of the inside of the zone, when at capacity' => __( 'Transparency of the inside of the zone, when at capacity', 'qsot-seating' ),
						'Angle' => __( 'Angle', 'qsot-seating' ),
						'Show Level' => __( 'Show Level', 'qsot-seating' ),
						'Show this zoom zone when zoom level is less than or equal to this number.' => __( 'Show this zoom zone when zoom level is less than or equal to this number.', 'qsot-seating' ),
						'Image ID' => __( 'Image ID', 'qsot-seating' ),
						'Source' => __( 'Source', 'qsot-seating' ),
						'Image Width' => __( 'Image Width', 'qsot-seating' ),
						'Image Height' => __( 'Image Height', 'qsot-seating' ),
						'Image Offset X' => __( 'Image Offset X', 'qsot-seating' ),
						'Image Offset Y' => __( 'Image Offset Y', 'qsot-seating' ),
						'Backdrop Image' => __( 'Backdrop Image', 'qsot-seating' ),
						'If yes, then the displayed canvas on the frontend will use this image as the background image of the interface' =>
								__( 'If yes, then the displayed canvas on the frontend will use this image as the background image of the interface', 'qsot-seating' ),
						'X Center' => __( 'X Center', 'qsot-seating' ),
						'Y Center' => __( 'Y Center', 'qsot-seating' ),
						'Radius' => __( 'Radius', 'qsot-seating' ),
						'X Center' => __( 'X Center', 'qsot-seating' ),
						'Y Center' => __( 'Y Center', 'qsot-seating' ),
						'Radius X' => __( 'Radius X', 'qsot-seating' ),
						'Radius Y' => __( 'Radius Y', 'qsot-seating' ),
						'Color on Hover' => __( 'Color on Hover', 'qsot-seating' ),
						'Background color when element is hovered.' => __( 'Background color when element is hovered.', 'qsot-seating' ),
						'Opacity on Hover' => __( 'Opacity on Hover', 'qsot-seating' ),
						'Background opacity when element is hovered.' => __( 'Background opacity when element is hovered.', 'qsot-seating' ),
						'Show Max Zoom Level' => __( 'Show Max Zoom Level', 'qsot-seating' ),
						'Only show when the zoom is equal to or less than this value.' => __( 'Only show when the zoom is equal to or less than this value.', 'qsot-seating' ),
						'X Upper Left' => __( 'X Upper Left', 'qsot-seating' ),
						'Y Upper Left' => __( 'Y Upper Left', 'qsot-seating' ),
						'Width' => __( 'Width', 'qsot-seating' ),
						'Height' => __( 'Height', 'qsot-seating' ),
						'X Upper Left' => __( 'X Upper Left', 'qsot-seating' ),
						'Y Upper Left' => __( 'Y Upper Left', 'qsot-seating' ),
						'Path Points' => __( 'Path Points', 'qsot-seating' ),
						'space between points and comma between x and xy: (ei: 0,0 10,0 10,10 0,10)' => __( 'space between points and comma between x and xy: (ei: 0,0 10,0 10,10 0,10)', 'qsot-seating' ),
						'Send to Back' => __( 'Send to Back', 'qsot-seating' ),
						'Bring to Front' => __( 'Bring to Front', 'qsot-seating' ),
						'Mass Selection (Marquee Tool)' => __( 'Mass Selection (Marquee Tool)', 'qsot-seating' ),
						'Fill Color' => __( 'Fill Color', 'qsot-seating' ),
						'No SNAPSVG canvas specified. Buttonbar cannot initialize.' => __( 'No SNAPSVG canvas specified. Buttonbar cannot initialize.', 'qsot-seating' ),
						'Pointer Tool' => __( 'Pointer Tool', 'qsot-seating' ),
						'Toggle Zoom Zones' => __( 'Toggle Zoom Zones', 'qsot-seating' ),
					),
				) );
				// enqueue the pricing control addon
				wp_enqueue_script( 'qsot-admin-price-struct' );
				wp_localize_script( 'qsot-admin-price-struct', '_qsot_price_struct', array(
					'data' => array(
						'tickets' => (object)$tickets,
						'structs' => (object)$structs,
					),
					'strings' => array(
						'what_name' => __( 'What is the name of the new Price Structure? (example: "Daytime Pricing")', 'qsot-seating' ),
						'change_name' => __( 'What would you like to change the name of "%s" to? (example: "Daytime Pricing")', 'qsot-seating' ),
						'structs' => __( 'Price Struct', 'qsot-seating' ),
						'tickets' => __( 'Tickets in Struct', ' qsot-seating' ),
						'new' => __( 'new', 'qsot-seating' ),
						'edit' => __( 'edit', 'qsot-seating' ),
						'struct_msg' => __( 'The pricing strctures created in this box are selectable on a per event basis, in the "new event" pages. Also, the prices set in this box apply to the entire seating chart, unless you have specifically set a different price for specific seats or zones.', 'qsot-seating' ),
						'customize' => __( 'Customize Pricing', 'qsot-seating' ),
						'sure' => __( 'Are you sure you want to customize the pricing for these zones?', 'qsot-seating' ),
						'yes' => __( 'Yes', 'qsot-seating' ),
						'zones' => __( 'Selected Zones', 'qsot-seating' ),
						'customize_msg' => __( 'Changes in this box apply to only the zones listed above. All other zones will either use the entire seating chart settings, or any custom settings you have already set for them.', 'qsot-seating' ),
						'empty' => __( '(empty-name)', 'qsot-seating' ),
						'customize' => __( 'customize pricing', 'qsot-seating' ),
					),
				) );
			break;

			case 'qsot-event':
				wp_enqueue_script( 'qsot-seating-event-settings' );
			break;

			case 'shop_order':
				wp_enqueue_style( 'qsot-seating-admin' );
				wp_enqueue_style( 'qsot-seating-event-frontend' );
				wp_enqueue_script( 'qsot-seating-admin-seat-selection-loader' );
				wp_localize_script( 'qsot-seating-admin-seat-selection-loader', '_qsot_admin_seating_loader', array(
					'assets' => array(
						'snap' => $url . 'js/libs/snapsvg/snap.svg' /*. $debug */ . '.js',
						'svg' => $url . 'js/frontend/ui.js',
						'res' => $url . 'js/admin/reservations.js',
						'ts' => $url . 'js/admin/ticket-selection.js',
					),
					'templates' => $this->get_admin_templates( array(), 'ticket-selection', array( 'exists' => $exists, 'post_id' => $post_id ) ),
					'nonce' => wp_create_nonce( 'qsot-admin-ajax' ),
					'order_id' => $post_id,
					'ajaxurl' => admin_url( 'admin-ajax.php' ),
				) );
			break;
		}
	}

	// get the frontend messages to use in the event selection ui
	public function get_messages( $event ) {
		$list = array(
			'Available' => __( 'Available', 'qsot-seating' ),
			'Available (%s)' => ( 'yes' == apply_filters( 'qsot-get-option-value', 'no', 'qsot-show-available-quantity' ) ) ? __( 'Available (%s)', 'qsot-seating' ) : __( 'Available', 'qsot-seating' ),
			'Unavailable' => __( 'Unavailable', 'qsot-seating' ),
			'Could not show interest in those tickets.' => __( 'Could not show interest in those tickets.', 'qsot-seating' ),
			'Could not reserve those tickets.' => __( 'Could not reserve those tickets.', 'qsot-seating' ),
			'Could not remove those tickets.' => __( 'Could not remove those tickets.', 'qsot-seating' ),
			'Could not load the required components.' => __( 'Could not load the required components.', 'qsot-seating' ),
			'Could not load a required component.' => __( 'Could not load a required component.', 'qsot-seating' ),
			'You do not have cookies enabled, and they are required.' => __( 'You do not have cookies enabled, and they are required.', 'qsot-seating' ),
			'You must have cookies enabled to purchase tickets.' => __( 'You must have cookies enabled to purchase tickets.', 'qsot-seating' ),
			'There are not enough %s tickets available.' => __( 'There are not enough %s tickets available.', 'qsot-seating' ),
			'Could not reserve a ticket for %s.' => __( 'Could not reserve a ticket for %s.', 'qsot-seating' ),
			'Could not remove the tickets for %s.' => __( 'Could not remove the tickets for %s.', 'qsot-seating' ),
			'Zoom-In' => __( 'Zoom-In', 'qsot-seating' ),
			'Zoom-Out' => __( 'Zoom-Out', 'qsot-seating' ),
			'Reset Zoom' => __( 'Reset Zoom', 'qsot-seating' ),
			'Button' => __( 'Button', 'qsot-seating' ),
			'No SNAPSVG canvas specified. Buttonbar cannot initialize.' => __( 'No SNAPSVG canvas specified. Buttonbar cannot initialize.', 'qsot-seating' ),
		);

		return $list;
	}

	// get the frontend template to use in the event selection ui
	public function get_templates( $event ) {
		// make sure we have an event area
		$event->event_area = isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', false, $GLOBALS['post'] );

		// if there is no event area, then bail
		if ( ! isset( $event->event_area ) || ! is_object( $event->event_area ) )
			return apply_filters( 'qsot-event-frontend-templates', array(), $event );

		// get a list of all the templates we need for the seating area type
		$needed_templates = apply_filters( 'qsot-seating-frontend-templates', array(
			'zone-info-tooltip',
			'one-title',
			'two-title',
			'msg-block',
			'ticket-selection',
			'msg-block',
			'sel-nosvg',
			'owns-wrap',
			'interest-item',
			'owns',
			'owns-multiple',
			'ticket-type-display',
			'zone-select',
			'zone-option',
			'zone-single',
			'ticket-type-select',
			'ticket-type-option',
			'ticket-type-single',
			'helper-available',
			'helper-more-available',
			'price-selection-ui',
			'price-selection-ui-price',
			'loading',
		), $event, $this );

		// aggregate the data needed for the templates
		$args = array(
			'limit' => apply_filters( 'qsot-event-ticket-purchase-limit', 0, $event->ID ),
			'max' => 1000000,
			'cart_url' => '#',
		);

		$cart = WC()->cart;
		// if there is a cart, then try to update the cart url
		if ( is_object( $cart ) )
			$args['cart_url'] = $cart->get_cart_url();

		// figure out the true max, based on available info
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		$taken = $zoner->find( array( 'fields' => 'total', 'event_id' => $event->ID, 'state' => array( $stati['r'][0], $stati['c'][0] ) ) );
		$capacity = $event->event_area->meta['_capacity'];
		$capacity = $capacity > 0 ? $capacity : PHP_INT_MAX;
		$args['max'] = $args['limit'] > 0 ? min( $args['limit'], min( $args['max'], $capacity - $taken ) ) : min( $args['max'], $capacity - $taken );

		// allow modification of the args
		$args = apply_filters( 'qsot-seating-frontend-templates-data', $args, $event, $this );

		$templates = array();
		// load each template in the list
		foreach ( $needed_templates as $template )
			$templates[ $template ] = QSOT_Templates::maybe_include_template( 'event-area/seating/' . $template . '.php', $args );

		return $templates;
	}

	// get the admin templates that are needed based on type and args
	public function get_admin_templates( $list, $type, $args='' ) {
		switch ( $type ) {
			case 'ticket-selection':
				$list['seating'] = array();

				// create a list of the templates we need
				$needed_templates = array( 'info', 'actions-change', 'actions-add', 'inner-change', 'inner-add', 'inner-change-zones', 'inner-add-zones', 'price-selection-ui', 'price-selection-ui-price', 'zone-info-tooltip' );

				// add the needed templates to the output list
				foreach ( $needed_templates as $template )
					$list['seating'][ $template ] = QSOT_Templates::maybe_include_template( 'admin/ticket-selection/seating/' . $template . '.php', $args );
			break;

			case 'event-area':
			/*
				// create a list of all the templates we need in the edit event area section of the admin
				$needed_templates = array( 'shell', 'ticket-li', 'struct-li' );

				// add the needed templates to the output list
				foreach ( $needed_templates as $template )
					$list[ 'seating-' . $template ] = QSOT_Templates::maybe_include_template( 'admin/event-area/seating/' . $template . '.php', $args );
			*/
			break;
		}

		return $list;
	}

	// construct the data array that holds all the info we send to the frontend UI for selecting tickets
	protected function _get_frontend_event_data( $event ) {
		// get the pricing struct for this event
		$struct = $this->price_struct->get_by_event_id( $event->ID );

		// get our zoner for this event
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();

		// get the ticket price for this event area
		$prices = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'unique-ids' ) );
		$raw_ticket_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'unique-type-data' ) );
		$ticket_types = array();
		foreach ( $raw_ticket_types as $tt )
			$ticket_types[ $tt->product_id . '' ] = $tt;

		// determine the total number of sold or reserved seats, thus far
		$reserved_or_confirmed = $zoner->find( array( 'fields' => 'total', 'state' => array( $stati['r'][0], $stati['c'][0] ), 'event_id' => $event->ID, 'ticket_type' => $prices ) );

		// figure out how many that leaves for the picking
		$cap = isset( $event->event_area->meta, $event->event_area->meta['_capacity'] ) ? $event->event_area->meta['_capacity'] : 0;
		$left = $cap > 0 ? max( 0, $cap - $reserved_or_confirmed ) : 1000000;

		// start putting together the results
		$out = array(
			'id' => $event->ID,
			'name' => apply_filters( 'the_title', $event->post_title, $event->ID ),
			'ticket' => false,
			'link' => get_permalink( $event->ID ),
			'parent_link' => get_permalink( $event->post_parent ),
			'ticket_types' => $ticket_types,
			'capacity' => $cap,
			'available' => $left,
			'struct' => $struct,
			'zones' => $this->_remove_unneeded_zone_data( $zoner->get_zones( array( 'event' => $event, 'type' => self::ZONES ) ) ),
			'zzones' => $this->_remove_unneeded_zone_data( $zoner->get_zones( array( 'event' => $event, 'type' => self::ZOOM_ZONES ) ) ),
		);
		// put it all together in a format that the frontend will understand
		$out['zone_count'] = count( $out['zones'] );
		$out['ticket_type_count'] = count( $out['ticket_types'] );
		$out['stati'] = $this->_calc_zone_stati( $out['zones'], $event );

		return apply_filters( 'qsot-frontend-event-data', $out, $event );
	}

	// add the zone column header to the advanced tools reservations table
	public function adv_tools_draw_zone_column_header() {
		?><th><?php _e( 'Zone', 'qsot-seating' ) ?></th><?php
	}

	// add the zonecolumn to the advanced tools reservations table
	public function adv_tools_draw_zone_column( $row ) {
		// load the event area
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $row->event_id );
		if ( ! is_object( $event_area->area_type ) || is_wp_error( $event_area->area_type ) )
			return;

		// if the event is not a seated event, then bail
		if ( $event_area->area_type->get_slug() !== $this->get_slug() )
			return;
		$zoner = $this->get_zoner();

		// otherwise, add the column
		// first get the zone information
		$zone = $zoner->get_zone_info( $row->zone_id );
		if ( ! is_object( $zone ) || is_wp_error( $zone ) )
			return;
		$zone_name = ! empty( $zone->name ) ? $zone->name : $zone->abbr;

		// draw the column
		echo '<td>' . force_balance_tags( wp_kses_post( $zone_name ) ) . '</td>';
	}

	// add the zone_id to the end of the ticket row code, for the release action
	public function adv_tools_add_zone_id_to_code( $code, $row ) {
		return $code . ':' . $row->zone_id;
	}

	// add a key for the zone_id to the parsed ticket row code
	public function adv_tools_parse_ticket_row_code( $parsed, $code ) {
		$parsed = explode( ':', $code );
		$parsed = array(
			'event_id' => absint( $parsed[0] ),
			'order_id' => absint( $parsed[1] ),
			'quantity' => intval( $parsed[2] ),
			'product_id' => absint( $parsed[3] ),
			'session_id' => trim( $parsed[4] ),
			'zone_id' => trim( isset( $parsed[5] ) ? $parsed[5] : null ),
		);
		return $parsed;
	}

	// intercept the release request, and handle requests that have a zone_id in them
	public function adv_tools_handle_release_request( $result, $parsed, $code ) {
		// if there is no zone_id, then bail
		if ( ! isset( $parsed['zone_id'] ) )
			return $result;

		// otherwise, handle the request, including the zone_id

		// validate the request
		if ( empty( $parsed['event_id'] ) )
			return new WP_Error( 'unknown_event', __( 'The event id was invalid.', 'opentickets-community-edition' ) );
		if ( $parsed['quantity'] <= 0 )
			return new WP_Error( 'unknown_quantity', __( 'The quantity was invalid.', 'opentickets-community-edition' ) );
		if ( empty( $parsed['product_id'] ) )
			return new WP_Error( 'unknown_product', __( 'The product id was invalid.', 'opentickets-community-edition' ) );
		if ( '' == $parsed['session_id'] )
			return new WP_Error( 'unknown_session', __( 'The session id was invalid.', 'opentickets-community-edition' ) );
		if ( '' === $parsed['zone_id'] )
			return new WP_Error( 'unknown_zone', __( 'The zone id was invalid.', 'qsot-seating' ) );

		global $wpdb;
		// lookup the row we are requesting, to verify it exists
		$row = $wpdb->get_row( $wpdb->prepare(
			'select * from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %d and order_id = %d and quantity = %d and ticket_type_id = %d and session_customer_id = %s and zone_id = %d',
			$parsed['event_id'],
			$parsed['order_id'],
			$parsed['quantity'],
			$parsed['product_id'],
			$parsed['session_id'],
			$parsed['zone_id']
		) );

		// if there is no matching row, then bail
		if ( ! is_object( $row ) || is_wp_error( $row ) )
			return new WP_Error( 'no_match', __( 'Could not find that DB record.', 'opentickets-community-edition' ) );

		// otherwise, kill that row completely
		$wpdb->query( $wpdb->prepare(
			'delete from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %d and order_id = %d and quantity = %d and ticket_type_id = %d and session_customer_id = %s and zone_id = %d limit 1',
			$parsed['event_id'],
			$parsed['order_id'],
			$parsed['quantity'],
			$parsed['product_id'],
			$parsed['session_id'],
			$parsed['zone_id']
		) );

		return true;
	}

	// add the drop down for selecting the zone, to the add-ticket form on the advanced tools page
	public function adv_tools_draw_zone_field( $event ) {
		// load the event area
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event->ID );
		if ( ! is_object( $event_area->area_type ) || is_wp_error( $event_area->area_type ) )
			return;

		// if the event is not a seated event, then bail
		if ( $event_area->area_type->get_slug() !== $this->get_slug() )
			return;
		$zoner = $this->get_zoner();

		$zones = $zoner->get_zones( array( 'event' => $event ) );
		?>
			<div class="field">
				<label><?php _e( 'Zone', 'qsot-seating' ) ?></label>
				<select class="widefat" name="zone_id">
					<?php foreach ( $zones as $zone ): ?>
						<?php
							if ( ! is_object( $zone ) || is_wp_error( $zone ) )
								continue;
							$zone_name = ! empty( $zone->name ) ? $zone->name : $zone->abbr;
						?>
						<option value="<?php echo esc_attr( $zone->id ); ?>"><?php echo $zone_name ?></option>
					<?php endforeach; ?>
				</select>
				<div class="helper"><?php _e( 'Select the zone that this ticket should be for, from the list of zones on the seating chart.', 'qsot-seating' ) ?></div>
			</div>
		<?php
	}

	// add the zone_id to the advanced tools save data function
	public function adv_tools_add_ticket_data( $data, $post ) {
		// load the event area
		$event_area = apply_filters( 'qsot-event-area-for-event', false, isset( $post['event_id'] ) ? $post['event_id'] : 0 );
		if ( ! is_object( $event_area->area_type ) || is_wp_error( $event_area->area_type ) )
			return;

		// if the event is not a seated event, then bail
		if ( $event_area->area_type->get_slug() !== $this->get_slug() )
			return;

		// add the zone_id to the save data
		$data['zone_id'] = isset( $post['zone_id'] ) ? $post['zone_id'] : 0;
		return $data;
	}

	// when using the resync tool in the system status page, we need to consider the zone_id
	public function sstools_add_zone_id( $data, $item, $item_id, $order_id ) {
		// if the zone_id is set, then add it to the data
		if ( isset( $item['_zone_id'] ) )
			$data['zone_id'] = $item['_zone_id'];

		return $data;
	}

	// determine the status of each zone in the zone list, based on capacity and total reservations
	protected function _calc_zone_stati( $zones, $event ) {
		$out = array();
		// aggregate the capacity of each zone
		foreach ( $zones as $zone )
			if ( isset( $zone->id, $zone->capacity ) )
				$out[ $zone->id . '' ] = array( (int)$zone->capacity, (int)$zone->capacity );
		
		// get a condensed list of all reservations for the event
		$zoner = $this->get_zoner();
		$all_claimed = $zoner->find( array( 'fields' => 'total-zone', 'event_id' => $event->ID ) );

		// subtract all reservations from the capacities from above to create a final availability number
		foreach ( $all_claimed as $zid => $total )
			if ( isset( $out[ $zid . '' ] ) )
				$out[ $zid . '' ][1] = max( 0, $out[ $zid . '' ][1] - $total );

		// testing only
		//foreach ( $out as $z => $rem ) if ( rand( 0, 1 ) ) $out[ $z ] = 0;

		return $out;
	}

	// remove data from the frontend zone output, because it is not used
	protected function _remove_unneeded_zone_data( $zones ) {
		foreach ( $zones as &$zone )
			unset( $zone->seating_chart_id, $zone->meta['image-id'] );
		return $zones;
	}

	// get the display name of a given ticket order item, for the upcoming tickets my-account module
	public function upcoming_tickets_display_name( $ticket ) {
		// these tickets will have the zone listed too
		$zoner = $this->get_zoner();
		$zone = isset( $ticket->_zone_id ) ? $zoner->get_zone_info( $ticket->_zone_id ) : false;

		// render result
		return sprintf(
			'%s %s @ %s',
			isset( $ticket->product ) ? $ticket->product->get_title() : __( 'Ticket', 'opentickets-community-edition' ),
			$zone ? sprintf( __( '[%s]', 'qsot-seating' ), ! empty( $zone->name ) ? $zone->name : $zone->abbr )  : '',
			isset( $ticket->_line_subtotal ) ? wc_price( $ticket->_line_subtotal ) : __( '(free)', 'opentickets-community-edition' )
		);
	}

	// get a list of all the zones, based on the supplied criteria
	public function get_zones( $args='' ) {
		// normalize the input data
		$args = wp_parse_args( $args, array(
			'event' => 0,
			'event_area' => 0,
			'type' => 0,
		) );

		// if the event is supplied, but not the event_area, then try to get the event area from the event
		$args['event_area'] = ( ( is_object( $arsg['event'] ) || $args['event'] > 0 ) && ( ! is_object( $args['event_area'] ) || intval( $args['event_area'] ) <= 0 ) )
				? apply_filters( 'qsot-event-area-for-event', false, $args['event'] )
				: $args['event_area'];

		// if there is no event area, then bail
		if ( ! is_object( $args['event_area'] ) || ! isset( $args['event_area']->ID ) )
			return array();

		// attempt to fetch the seating zone info from cache
		$cacher = QSOT_Seating_Cacher::instance();
		$cache = $cacher->get( 'all-zone-' . $type . '-data', 'total-zones-' . $args['event_area']->ID );

		// if the cache was not fetched or empty, then regen
		if ( empty( $cache ) ) {
			// fetch a list of all the zones of the supplied type for this chart
			$cache = self::_get_zones_from_seating_chart( $args['event_area']->ID, $type );

			$ids = array();
			// extrapolate the list of ids of zones without meta
			foreach ( $cache as $zone )
				if ( ! isset( $zone->meta ) || empty( $zone->meta ) )
					$ids[] = $zone->id;

			// if there are zones in the list with no set meta, then fetch all the meta for those zones
			if ( count( $ids ) ) {
				$indexed_meta = self::get_indexed_zones_meta( $ids );
				foreach ( $indexed_meta as $zone_id => $meta )
					$cache[ $zone_id ]->meta = $meta;
			}

			$cacher->set( 'all-zone-' . $type . '-data', $cache, 'total-zones-' . $args['event_area']->ID, 3600, true );
		}

		return $cache;
	}

	// draw the event area image, for any event that has an image defined
	public function draw_event_area_image( $event, $area=false, $reserved=array() ) {
		// if the event is not valid, then dont even attempt to show anything
		if ( ! is_object( $event ) || ! isset( $event->ID ) )
			return;

		// if the event has passed, then bail
		if ( ! apply_filters( 'qsot-can-sell-tickets-to-event', false, $event->ID ) )
			return;

		// if the event_area was not passed, then attempt to loaded it from the event
		if ( ! is_object( $area ) || ! isset( $area->ID ) ) {
			$ea_id = (int)get_post_meta( $event->ID, '_event_area_id', true );
			if ( $ea_id <= 0 )
				return;
			$area = get_post( $ea_id );
		// if it was passed, then fetch the id
		} else {
			$ea_id = $area->ID;
		}

		// if the area is not of this area type, then skip this function
		if ( ! ( isset( $area->area_type ) && is_object( $area->area_type ) ) || $area->area_type->get_slug() !== $this->get_slug() )
			return;

		$thumb_id = 0;
		// if the event has zome zones, try to find the bg image zone, and use that image as the chart image
		if ( apply_filters( 'qsot-has-zones', false, $event->ID ) )
			$thumb_id = apply_filters( 'qsot-get-seating-bg-image-id', $thumb_id, $ea_id );

		// if there was no chart image found yet, then just use the event area featured image
		if ( empty( $thumb_id ) ) {
			// first attempt to get the featured image as the event area image
			$thumb_id = (int)get_post_meta( $ea_id, '_thumbnail_id', true );

			// if there was not a featured image and if the event has zones, check all the zones, in zindex order from lowest to highest, for the first bgimage defined
			if ( $thumb_id <= 0 && isset( $event->zones ) && is_array( $event->zones ) ) {
				$images_ordered = array();
				// get a list of all BG images in the seating chart
				foreach ( $event->zones as $zone )
					if ( isset( $zone->meta, $zone->meta['_type'], $zone->meta['_order'], $zone->meta['image-id'], $zone->meta['bg'] ) && 'image' == $zone->meta['_type'] && $zone->meta['bg'] && $zone->meta['image-id'] > 0 )
						$images_ordered[ $zone->meta['_order'] ] = $zone->meta['image-id'];

				// if there are any images
				if ( count( $images_ordered ) ) {
					// sort them by their zindex
					ksort( $images_ordered, SORT_NUMERIC );
					// use the lowest zindex image as the bg image
					$thumb_id = current( $images_ordered );
				}
			}
		}

		if ( $thumb_id > 0 ) {
			list( $thumb_url, $w, $h, $rs ) = wp_get_attachment_image_src( $thumb_id, 'full' );
			$thumb_url = is_ssl() ? preg_replace( '#http:#', 'http:', $thumb_url ) : $thumb_url;
			if ( $thumb_url ) {
				?>
				<div class="event-area-image-wrap">
					<img src="<?php echo esc_attr( $thumb_url ) ?>" class="event-area-image" alt="Image of the <?php echo esc_attr( apply_filters( 'the_title', $area->post_title ) ) ?>" />
				</div>
				<?php
			}
		}
	}

	// when running the seating report, we need the report to know about our valid reservation states. add then here
	public function add_state_types_to_report( $list, $event_id ) {
		// make sure that the event is in an area that is of this area type
		$area_type = apply_filters( 'qsot-event-area-type-for-event', null, $event_id );
		if ( ! is_object( $area_type ) || $area_type->get_slug() !== $this->get_slug() )
			return $list;

		// get a list of the valid states from our zoner
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();

		// add each one to the list we are returning
		foreach ( $stati as $status )
			$list[ $status[0] ] = $status;

		return $list;
	}

	// upon successful reservation of tickets, add those tickets to the cart
	public function add_tickets_to_cart( $success, $args ) {
		// if the reservation was not successful, then bail now
		if ( ! $success || is_wp_error( $success ) )
			return $success;

		// if there was an order number specified, then we dont need to add this item to a cart
		if ( isset( $args['order_id'] ) && $args['order_id'] > 0 )
			return $success;

		// make sure we have the needed data for associating this reservation to an item
		if ( ! isset( $args['event_id'] ) || ! isset( $args['zone_id'] ) )
			return $success;

		// make sure that the event is in an area that is of this area type
		$area_type = apply_filters( 'qsot-event-area-type-for-event', null, $args['event_id'] );
		if ( ! is_object( $area_type ) || $area_type->get_slug() !== $this->get_slug() )
			return $success;

		// otherwise, add a GA ticket to the cart, for this event, with this quantity
		// start by making sure we have a cart
		$cart = WC()->cart;
		if ( ! is_object( $cart ) ) {
			$this->get_zoner()->remove( $args );
			return new WP_Error( 'no_cart', __( 'Could not add those items to your cart.', 'opentickets-community-edition' ) );
		}

		// add the item to the cart. the WC_Cart class now handles situations where the item already exists, by simply updating the quantity
		$cart->add_to_cart( $args['ticket_type_id'], $args['final_qty'], '', array(), array( 'event_id' => $args['event_id'], 'zone_id' => $args['zone_id'] ) );

		return $success;
	}

	// when updating the quantity of tickets in the cart page, we need to perform the same update on our reservations, if allowed
	public function update_reservations_on_cart_update( $cart_item_key, $quantity, $old_quantity ) {
		// if this is not an update cart scenario, then bail now
		if ( ! isset( $_POST['update_cart'] ) )
			return;

		// fetch the zoner
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();

		// get the cart item
		$items = WC()->cart->get_cart();
		$item = isset( $items[ $cart_item_key ] ) ? $items[ $cart_item_key ] : false;
		if ( empty( $item ) || ! isset( $item['event_id'] ) || ! isset( $item['zone_id'] ) )
			return;

		// load the event and check that it is for this type of event area before doing anything else
		$area_type = apply_filters( 'qsot-event-area-type-for-event', false, $item['event_id'] );
		if ( ! is_object( $area_type ) || is_wp_error( $area_type ) || $area_type->get_slug() !== $this->get_slug() )
			return;

		// determine the maximum number that can be reserved
		$zone = $zoner->get_zone_info( $item['zone_id'] );
		$mine = $zoner->find( array( // reservations by this user
			'event_id' => $item['event_id'],
			'zone_id' => $item['zone_id'],
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'customer_id' => $zoner->current_user(),
			'fields' => 'total',
		) );
		$taken = $zoner->find( array( // reservations from everyone
			'event_id' => $item['event_id'],
			'zone_id' => $item['zone_id'],
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'fields' => 'total',
		) );
		$capacity = $zone->capacity;
		$remaining = max( 0, $capacity - $taken + $mine ); // total unreserved (or already reserved by this user) tickets
		$final_qty = min( $quantity, $remaining );

		// remove recursive filter
		remove_action( 'woocommerce_after_cart_item_quantity_update', array( &$this, 'update_reservations_on_cart_update' ), 10 );

		// update the reservations
		$result = $zoner->reserve( false, array(
			'order_id' => 0,
			'event_id' => $item['event_id'],
			'zone_id' => $item['zone_id'],
			'ticket_type_id' => $item['product_id'],
			'quantity' => $final_qty,
		) );

		// if the results of the reserve were successful, at least to some degree, update the quantity appropriately
		if ( ! is_wp_error( $result ) && is_scalar( $result ) && $result > 0 ) {
			// if the final quantity does not equal the requested quantity, then pop a message indicating that the reason is because there are not enough tickets
			if ( $result != $quantity )
				wc_add_notice( sprintf( __( 'There were not %d tickets available for that seat. We reserved %d for you instead, which is all that is available.', 'qsot-seating' ), $quantity, $result ), 'error' );

			WC()->cart->set_quantity( $cart_item_key, $result, true );
		// if the update failed, then revert the quantity
		} else if ( ! $result || is_wp_error( $result ) ) {
			// reset the quantity and pop an error as to why
			WC()->cart->set_quantity( $cart_item_key, $old_quantity, true );
			if ( is_wp_error( $result ) )
				wc_add_notice( implode( '', $result->get_error_messages() ), 'error' );
			else
				wc_add_notice( __( 'Could not update the quantity of that item.', 'opentickets-community-edition' ), 'error' );
		}

		// readd this filter for later checks
		add_action( 'woocommerce_after_cart_item_quantity_update', array( &$this, 'update_reservations_on_cart_update' ), 10, 3 );
	}

	// get the cart item quantity of the matched row/s
	public function cart_item_match_quantity( $item, $rows ) {
		$matches = array();
		// find the row that matches the item we were passed
		if ( isset( $item['zone_id'] ) ) {
			foreach ( $rows as $row )
				if ( $row->zone_id == $item['zone_id'] )
					$matches[] = $row;
		} else {
			$matches = $rows;
		}

		return array_sum( wp_list_pluck( $matches, 'quantity' ) );
	}

	// during cart item removal, we need to sync the ticket table as well
	public function delete_ticket_from_cart( $item_key ) {
		$WC = WC();
		// if we dont have a cart or woocommcer object, then bail
		if ( ! is_object( $WC ) || ! is_object( $WC->cart ) )
			return;

		$item = null;
		// figure out which item we are syncing
		// check the removed items first
		if ( isset( $WC->cart->removed_cart_contents[ $item_key ] ) ) {
			// grab the item from the removed contents table
			$item = $WC->cart->removed_cart_contents[ $item_key ];

			// remove the item from the remove contents table, so that it cannot be 'restored'. we do this because restoring could happen after the available ticket has been purchased elsewhere
			unset( $WC->cart->removed_cart_contents[ $item_key ] );
		// if it is not in the removed items, checked the current items
		} else if ( isset( $WC->cart->cart_contents[ $item_key ] ) ) {
			$item = $WC->cart->cart_contents[ $item_key ];
		}

		// if we did not find the item, then bail
		if ( empty( $item ) )
			return;

		// if the item is not linked to an event, or a zone, then bail
		if ( ! isset( $item['event_id'], $item['zone_id'] ) )
			return;

		// load the event and area_type
		$event = get_post( $item['event_id'] );
		$area_type = apply_filters( 'qsot-event-area-type-for-event', false, $event );

		// if the event's area type is not this type, then bail
		if ( ! is_object( $area_type ) || $area_type->get_slug() !== $this->get_slug() )
			return;

		// get the zoner
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();

		// remove the reservation
		$res = $zoner->remove( false, array(
			'event_id' => $item['event_id'],
			'ticket_type_id' => $item['product_id'],
			'customer_id' => $zoner->current_user(),
			'quantity' => $item['quantity'],
			'state' => $stati['r'][0],
			'zone_id' => $item['zone_id'],
		) );
	}

	// determine if the supplied post could be of this area type. helps determine when data is legacy data that does not have the event type set
	public function post_is_this_type( $post ) {
		$found = null;
		// if this is not an event area, then it cannot be
		if ( 'qsot-event-area' != $post->post_type )
			return false;

		$type = get_post_meta( $post->ID, '_qsot-event-area-type', true );
		// if the area_type is set, and it is not equal to this type, then bail. this short circuits the additional expensive check below
		if ( ! empty( $type ) && $type !== $this->slug )
			return false;

		$found = null;
		// if this event_area does not have any pricing structs, then bail
		$cache = wp_cache_get( 'post-' . $post->ID, 'seating-check', false, $found );
		if ( ( null !== $found && ! $found) || ( null === $found && false === $cache ) ) {
			$zoner = $this->get_zoner();
			$cache = $zoner->get_zones( array( 'event_area_id' => $post->ID, 'type' => self::ZONES ) );
			$cache = is_array( $cache ) ? count( $cache ) : 0;
			wp_cache_set( 'post-' . $post->ID, $cache, 'seating-check', 3600 );
		}
		if ( empty( $cache ) )
			return false;

		// otherwise, it is
		return true;
	}

	// modify the query parts of the zoner_query, if the fields return type is our new custom type
	public function find_query_for_custom_fields( $parts, $args ) {
		// only update the query if our return type is our custom one
		if ( isset( $args['fields'] ) && in_array( $args['fields'], array( 'total-zone-ticket-type-state', 'total-zone-ticket-type-state-flat' ) ) ) {
			$parts['fields'] = array( 'sum(ezo.quantity) quantity', 'ezo.state', 'ezo.zone_id', 'ezo.ticket_type_id' );
			$parts['groupby'] = array( 'ezo.zone_id', 'ezo.ticket_type_id', 'ezo.state' );
		} else if ( isset( $args['fields'] ) && 'total-zone' == $args['fields'] ) {
			$parts['fields'] = array( 'sum(ezo.quantity) quantity', 'ezo.zone_id' );
			$parts['groupby'] = array( 'ezo.zone_id' );
		}

		global $wpdb;
		// add the wheres for the zone_id if it was supplied
		foreach ( array( 'zone_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && '' !== $args[ $key ] ) {
				if ( is_array( $args[ $key ] ) ) {
					$ids = array_map( 'absint', $args[ $key ] );
					if ( ! empty( $ids ) )
						$parts['where'][] = 'and ' . $key . ' in (' . implode( ',', $ids ) . ')';
				} else if ( is_numeric( $args[ $key ] ) ) {
					$parts['where'][] = $wpdb->prepare( 'and ' . $key . ' = %d', $args[ $key ] );
				}
			}
		}

		return $parts;
	}

	// create the actual return value for the zoner_query::find() if the return type is our custom one
	public function find_query_return_zone_ticket_type_state( $results, $args ) {
		$indexed = array();
		// cycle through the results, and add them to our indexed result list
		foreach ( $results as $item ) {
			// if there is not an entry for this zone, then make one
			if ( ! isset( $indexed[ $item->zone_id ] ) )
				$indexed[ $item->zone_id ] = array();

			// if the indexed ticket type container does not exist, create it
			if ( ! isset( $indexed[ $item->zone_id ][ $item->ticket_type_id ] ) )
				$indexed[ $item->zone_id ][ $item->ticket_type_id ] = array();

			// if the state sub index container does not exist, create it
			if ( ! isset( $indexed[ $item->zone_id ][ $item->ticket_type_id ][ $item->state ] ) )
				$indexed[ $item->zone_id ][ $item->ticket_type_id ][ $item->state ] = 0;

			$indexed[ $item->zone_id ][ $item->ticket_type_id ][ $item->state ] += $item->quantity;
		}

		return $indexed;
	}

	// create the actual return value for the zoner_query::find() if the return type is our custom one
	public function find_query_return_zone_ticket_type_state_flat( $results, $args ) {
		$indexed = array();

		// cycle through the results and reformat them
		foreach ( $results as $row )
			$indexed[] = array( 'z' => $row->zone_id, 't' => $row->ticket_type_id, 'q' => $row->quantity );

		return $indexed;
	}

	// create the actual return value for the zoner_query::find() if the return type is our custom one
	public function find_query_return_total_zone( $results, $args ) {
		$indexed = array();
		// cycle through the results, and add them to our indexed result list
		foreach ( $results as $item ) {
			// if there is not an entry for this zone, then make one
			if ( ! isset( $indexed[ $item->zone_id ] ) )
				$indexed[ $item->zone_id ] = 0;

			$indexed[ $item->zone_id ] += $item->quantity;
		}

		return $indexed;
	}

	// get the list of metaboxes relevant for this event type
	// postbox_classes_qsot-event-area_qsot-event-area-type
	public function get_meta_boxes() {
		// if we already generated this list, then use the cached version
		if ( is_array( $this->meta_boxes ) )
			return $this->meta_boxes;

		// create a container for the metabox list
		$meta_boxes = array();

		// create a list of all the metaboxes we should add for this area type
		foreach ( apply_filters( 'qsot-seating-attributes-screens', array( 'qsot-event-area' ) ) as $screen ) {
			// seating chart drawing tool
			$meta_boxes[] = array(
				'qsot-seating-attributes',
				__( 'Seating - Attributes', 'qsot-seating' ),
				array( &$this, 'mb_attributes' ),
				$screen,
				'normal',
				'high'
			);

			// seating chart pricing settings box
			$meta_boxes[] = array(
				'price-structures',
				__( 'Price Structures', 'qsot-seating' ),
				array( &$this, 'mb_price_structures' ),
				$screen,
				'side',
				'core'
			);
		}

		return $this->meta_boxes = apply_filters( 'qsot-seating-meta-boxes', $meta_boxes );
	}

	// draw the contents of the attributes metabox
	public function mb_attributes( $post ) {
		?><div class="qsot qsot-seating-chart-ui" rel="qsot-scui"><div class="qsot-misfea"><em><strong><?php
			_e( 'The seating chart creation & editing tool requires a Javascript and SVG enabled browser. You are missing one or more of these features.', 'qsot' )
		?></strong></em></div></div>
		<script language="javascript">
			jQuery( function( $ ) {
				console.log( 'debug', QS );
				QS.SC = new QS.SeatingUI( { container:'[rel="qsot-scui"]' } );
			} );
		</script>
		<input type="hidden" name="qsot-seating-n" value="<?php echo wp_create_nonce( 'save-qsot-seating-now' ); ?>" />
		<?php
	}

	// draw the box to control the pricing structures of this seating chart
	public function mb_price_structures( $post, $mb ) {
		?>
			<div class="show-if-js qsot">
				<div class="pricing-ui" rel="price-ui"></div>
			</div>

			<div class="hide-if-js">
				<p><?php echo __( 'The Price Structure UI requires javascript. Enable it, or this feature will not be editable in your current browser.', 'qsot-seating' ) ?></p>
			</div>
		<?php
	}

	// handle the saving of event areas of this type
	// registered during area_type registration. then called in inc/event-area/post-type.class.php save_post()
	public function save_post( $post_id, $post, $updated ) {
		// check the nonce for our settings. if not there or invalid, then bail
		if ( ! isset( $_POST['qsot-seating-n'] ) || ! wp_verify_nonce( $_POST['qsot-seating-n'], 'save-qsot-seating-now' ) )
			return;

		$zoner = $this->get_zoner();
		// load the current values for zones and zoom zones
		$zones = $zoner->get_zones( array( 'event_area_id' => $post_id, 'type' => self::ZONES ) );
		$zzones = $zoner->get_zones( array( 'event_area_id' => $post_id, 'type' => self::ZOOM_ZONES ) );

		// create a zone-name ot zone-id map for lookups that are not id based. for instance if a zone was accidentally deleted and then recreated in the same page load
		$existing_zone_map = $existing_zzone_map = $zids = array();
		foreach ( $zones as $zone )
			$existing_zone_map[ $zone->abbr ] = $zids[] = $zone->id;
		foreach ( $zzones as $zone )
			$existing_zzone_map[ $zone->abbr ] = $zids[] = $zone->id;

		// grab and interpret the sent data
		$raw_settings = wp_parse_args( @json_decode( stripslashes( $_POST['qsot-seating-settings'] ), true ), array() );
		$raw_zones = @json_decode( stripslashes( $_POST['qsot-seating-zones'] ), true );
		$raw_zones = is_array( $raw_zones ) ? $raw_zones : array();
		$raw_zzones = @json_decode( stripslashes( $_POST['qsot-seating-zoom-zones'] ), true );
		$raw_zzones = is_array( $raw_zzones ) ? $raw_zzones : array();

		// create a list of zone updates. can be inserts, updates or deletes
		$zones = $this->_merge_input_data( $raw_zones, $zones, $existing_zone_map );
		$total_capacity = 0;
		// tally up the total capacity of the zones
		foreach ( $zones as $zone )
			$total_capacity += $zone->capacity;
		$zzones = $this->_merge_input_data( $raw_zzones, $zzones, $existing_zzone_map );
		
		// actaully perform the zone updates. returns a map of 'new item ids' to their actual new id, like: -1 => 1234; where -1 is the faux id from the frontend, and 1234 is the actual zone id
		$new_zone_map = $zoner->update_zones( $post_id, $zones, self::ZONES );
		$new_zzone_map = $zoner->update_zones( $post_id, $zzones, self::ZOOM_ZONES );

		// consolidate and save the pricing for this event_area, happens here, instead of later, because it removes the ->meta['pricing'] meta data, preventing that from getting stored as meta
		$pricing = $this->price_struct->consolidate_pricing( $raw_settings['pricing'], $zones, $new_zone_map );

		$cacher = QSOT_Seating_Cacher::instance();
		// this is last, so that the save happens before it does, for extremely low powered clients, like those hosted on godaddy
		// remove zone and chart caches now
		$cache_delete_pairs = array(
			array( $post_id . ':zids', 'qsot-seating' ),
			array( $post_id . ':zones', 'qsot-seating' ),
		);
		foreach ( $zids as $zid )
			$cache_delete_pairs[] = array( $zid . ':zmeta', 'qsot-seating' );
			//die(var_dump( $cache_delete_pairs ));

		// delete the caches
		$cacher->delete( $cache_delete_pairs );

		// update the total capacity and 'base price' for otce compatibility
		update_post_meta( $post_id, '_capacity', $total_capacity );

		// save the pricing for this chart
		$this->price_struct->save_pricing( $pricing, $post_id );

		// clear all seating cache for this chart
		$cacher->delete( 'chart-' . $post_id, 'zones' );
		$cacher->delete( 'all-zone-*-data', 'total-zones-' . $post_id );
		$cacher->delete( 'all-zone-' . self::ZONES . '-data', 'total-zones-' . $post_id );
		$cacher->delete( 'all-zone-' . self::ZOOM_ZONES . '-data', 'total-zones-' . $post_id );
		foreach ( $pricing as $ps_id => $_ )
			$cacher->delete( 'price-struct-' . $ps_id, 'ea-structs-' . $post_id );

		// tell every one of the update
		do_action( 'qsot-saved-seating', $post_id, $zones, $zzones, $new_zone_map, $new_zzone_map, $pricing );
	}

	// updates to existing zones, the creation of new zones, or the deletion of existing zones, where applicable.
	protected function _merge_input_data( $input, $zones, $existing_zone_map ) {
		// number used to create unique faux ids on new zones that do not already have one
		static $ind = -1;
		$touched = array();

		// cycle through each input zone
		foreach ( $input as $zone ) {
			// if the zone has an id already, adn that id exists in our list of existing zone ids, then
			if ( isset( $zone[ 'zone_id' ] ) && isset( $zones[ $zone[ 'zone_id' ] ] ) ) {
				$zone_id = $zone['zone_id'];
				// track which zones we have updated from the existing zone list
				$touched[] = $zone_id;
				// copy the existing zone meta
				if ( isset( $zones[ $zone_id ]->meta ) && is_array( $zones[ $zone_id ]->meta ) )
					foreach ( $zones[ $zone_id ]->meta as $k => $v ) $zones[ $zone_id ]->meta[ $k ] = '';
				// overlay the new settings on top the old settings, for this record. create an 'update existing zone' record
				foreach ( $zone as $k => $v ) {
					$v = urldecode( $v );
					switch ( $k ) {
						case 'zone_id': break;
						case 'id': $zones[ $zone_id ]->abbr = trim( $v ); break;
						case 'zone': $zones[ $zone_id ]->name = trim( $v ); break;
						case 'capacity': $zones[ $zone_id ]->capacity = (int) $v; break;
						default: $zones[ $zone_id ]->meta[ $k ] = $v; break;
					}
				}
			// we must have at least an 'id' (different than zone_id) in order to save the zone information, because that is presumably the unique id used to identify a zone. without at least this, we are lost,
			// and cannot save any information for the zone. this may change later
			} else if ( isset( $zone['id'] ) ) {
				// if the zone was removed and then readded with the same 'id' (different than zone_id), thus losing it's zone_id, we can use the 'id' to lookup if it was previoously assigned a zone_id. if it was,
				// we can use that zone_id as a reference, and simply update that record
				if ( isset( $existing_zone_map[ $zone['id'] ] ) ) {
					$zone_id = $existing_zone_map[ $zone['id'] ];
					// track which zones we have updated from the existing zone list
					$touched[] = $zone_id;
					// copy the existing meta from the existing zone entry
					if ( isset( $zones[ $zone_id ]->meta ) && is_array( $zones[ $zone_id ]->meta ) )
						foreach ( $zones[ $zone_id ]->meta as $k => $v ) $zones[ $zone_id ]->meta[ $k ] = '';
					// overlay all the new data on top the old data
					foreach ( $zone as $k => $v ) {
						$v = urldecode( $v );
						switch ( $k ) {
							case 'zone_id': break;
							case 'id': $zones[ $zone_id ]->abbr = trim( $v ); break;
							case 'zone': $zones[ $zone_id ]->name = trim( $v ); break;
							case 'capacity': $zones[ $zone_id ]->capacity = (int) $v; break;
							default: $zones[ $zone_id ]->meta[ $k ] = $v; break;
						}
					}
				// otherwise we will need to create a 'new zone record' which will add a zone to the seating chart
				} else {
					// create the base default zone information
					$new_zone = (object)array( 'abbr' => '', 'name' => '', 'capacity' => 0, 'meta' => array() );
					// overlay any new settings on top of those defaults
					foreach ( $zone as $k => $v ) {
						$v = urldecode( $v );
						switch ( $k ) {
							case 'id': $new_zone->abbr = trim( $v ); break;
							case 'zone': $new_zone->name = trim( $v ); break;
							case 'capacity': $new_zone->capacity = (int) $v; break;
							default: $new_zone->meta[ $k ] = $v; break;
						}
					}

					// if there is in fact an 'id' present, then
					if ( strlen( $new_zone->abbr ) > 0 ) {
						// normalize the display name
						if ( ! isset( $new_zone->name ) ) $new_zone->name = str_replace( '-', ' ', $new_zone->abbr );
						// assign it a faux zone_id and add it to the list
						$zones[ ( $ind-- ) . '' ] = $new_zone;
					}
				}
			}
		}

		// mark any previously existing zones that did not receive an update above as needing deletion
		$untouched = array_diff( array_keys( $zones ), $touched );
		foreach ( $untouched as $zone_id ) if ( $zone_id > 0 ) $zones[ $zone_id . '' ]->_delete = 1;

		return $zones;
	}

	// render the frontend ui
	public function render_ui( $event, $event_area ) {
		// get the zoner for this event_area
		$zoner = $event_area->area_type->get_zoner();

		// get the zoner stati
		$stati = $zoner->get_stati();

		// figure out how many tickets we have reserved for this event currently
		$reserved = $zoner->find( array( 'fields' => 'total-zone-ticket-type-state', 'event_id' => $event->ID, 'customer_id' => $zoner->current_user(), 'order_id' => 0, 'state' => array( $stati['i'][0], $stati['r'][0] ) ) );
		$total = 0;
		foreach ( $reserved as $zone_id => $group )
			foreach ( $group as $ticket_type => $state_group )
				foreach ( $state_group as $state => $qty )
					$total += $qty;

		// default template
		$template_file = 'post-content/event-area-closed.php';

		// if the event can have ticket sold, or if it is sold out but this user has active reservations, then show the event ticket selection UI
		if ( apply_filters( 'qsot-can-sell-tickets-to-event', false, $event->ID ) || $total > 0 )
			$template_file = 'post-content/seating-event-area.php';

		$out = '';
		// if we have the event area, then go ahead and render the appropriate interface
		if ( is_object( $event_area ) ) {
			$event_area->prices = $this->get_ticket_type( array( 'event' => $event ) );
			$template = apply_filters( 'qsot-locate-template', '', array( $template_file, 'post-content/seating-event-area.php' ), false, false );
			ob_start();
			if ( ! empty( $template ) )
				QSOT_Templates::include_template( $template, apply_filters( 'qsot-draw-seating-event-area-args', array(
					'event' => $event,
					'reserved' => $reserved,
					'total_reserved' => $total,
					'area' => $event_area,
					'edata' => $this->_get_frontend_event_data( $event ),
				), $event, $event_area ), true, false );
			$out = ob_get_contents();
			ob_end_clean();
		}

		// allow modification if needed
		return apply_filters( 'qsot-no-js-seating-seat-selection-form', $out, $event_area, $event, 0, $reserved );
	}

	// get the event area display name, based on the event area and its meta
	public function get_event_area_display_name( $event_area ) {
		// get the capacity of the event_area
		$capacity = (int) get_post_meta( $event_area->ID, '_capacity', true );

		// get the number of pricing structures for this event_area
		$count = $this->price_struct->find( array( 'event_area_id' => $event_area->ID, 'fields' => 'ids', 'price_sub_group' => 0 ) );
		$count = is_array( $count ) ? count( $count ) : 0;

		// construct the final name for the event area to be displayed
		return sprintf(
			'%s [x%s] (%s)',
			apply_filters( 'the_title', $event_area->post_title, $event_area->ID ),
			$capacity,
			sprintf( _n( '%d pricing structure', '%d pricing structures', $count, 'qsot-seating' ), $count )
		);
	}

	// determine the ticket_type for the supplied data for this area_type
	public function get_ticket_type( $data='' ) {
		// normalize the supplied data
		$data = wp_parse_args( $data, array(
			'event' => false,
			'fields' => 'objects',
		) );

		// if there is no event in the supplied, data, then bail
		if ( false == $data['event'] )
			return new WP_Error( 'invalid_event', __( 'Could not find that event.', 'qsot-seating' ) );

		// if the event supplied is an id, try to load the area
		if ( $data['event'] && is_numeric( $data['event'] ) )
			$data['event'] = get_post( $data['event'] );

		// if there is still no event object, then bail
		if ( ! is_object( $data['event'] ) || ! isset( $data['event']->ID ) )
			return new WP_Error( 'invalid_event', __( 'Could not find that event.', 'qsot-ga-multi-price' ) );

		// get the entire pricing struct, for all zones
		$struct = $this->price_struct->get_by_event_id( $data['event']->ID );

		$unique_ids = $unique_data= $prices = array();
		// find a list of unique product ids accross all zones
		foreach ( $struct->prices as $sub_group => $group_prices ) {
			$prices[ $sub_group ] = array();
			foreach ( $group_prices as $price ) {
				$prices[ $sub_group ][] = $unique_ids[] = $price->product_id;
				$unique_data[ $price->product_id ] = $price;
			}
		}
		$unique_ids = array_unique( $unique_ids );

		// if the request was for only a list of the unique ids of the prices, then return that now
		if ( 'unique-ids' == $data['fields'] )
			return $unique_ids;

		// if the request was for the ticket type info for the unique ids, then do that now
		if ( 'unique-type-data' == $data['fields'] ) {
			$result = array();
			foreach ( $unique_ids as $id )
				$result[] = $unique_data[ $id ];
			return $result;
		}

		// find the pricing struct for this event
		$struct = $this->price_struct->get_by_event_id( $data['event']->ID );

		// find all the prices for this pricing struct. if only ids are requested, reduce the result to a list of ids
		$prices = array();
		if ( isset( $struct->prices ) ) {
			foreach ( $struct->prices as $sub_group => $group_prices ) {
				$prices[ $sub_group ] = array();
				foreach ( $group_prices as $price ) {
					// if only ids are needed, set that now and skip other logic
					if ( 'ids' == $data['fields'] ) {
						$prices[ $sub_group ][] = $price->product_id;
						continue;
					}

					// otherwise add the product object if it exists
					$product = wc_get_product( $price->product_id );
					if ( is_object( $product ) && ! is_wp_error( $product ) ) {
						foreach ( $price as $k => $v )
							$product->{$k} = $v;
						$prices[ $sub_group ][] = $product;
					}
				}
			}
		}

		return $prices;
	}

	// add the 'data' to each response passed to this function
	protected function _add_data( $resp, $event, $event_area=null, $ticket_types=null ) {
		$def = array( 'owns' => 0, 'available' => 0, 'available_more' => 0 );
		$resp['data'] = wp_parse_args( isset( $resp['data'] ) ? $resp['data'] : array(), $def );
		$orig = $resp['data'];
		// get the needex objects for data construction
		$zoner = $this->get_zoner();
		$event_area = is_object( $event_area ) ? $event_area : ( isset( $event->event_area ) && is_object( $event->event_area ) ? $event->event_area : apply_filters( 'qsot-event-area-for-event', false, $event ) );
		$ticket_types = is_array( $ticket_types ) ? $ticket_types : $this->get_ticket_type( array( 'event' => $event, 'fields' => 'unique-ids' ) );

		// if any of the data is missing or errors, then bail
		if ( ! is_object( $zoner ) || is_wp_error( $zoner ) )
			return $resp;
		if ( ! is_object( $event_area ) || is_wp_error( $event_area ) )
			return $resp;
		if ( ! is_array( $ticket_types ) || empty( $ticket_types ) )
			return $resp;

		// normalize the ticket types to an array of product_ids
		$raw_ticket_types = $ticket_types;
		$ticket_types = array( 0 );
		foreach ( $raw_ticket_types as $ind => $ticket_type )
			if ( is_numeric( $ticket_type ) )
				$ticket_types[] = $ticket_type;
			else if ( is_array( $ticket_type ) ) {
				foreach ( $ticket_type as $group => $group_ticket_types )
					foreach ( $group_ticket_types as $group_ticket_type )
						if ( is_numeric( $group_ticket_type ) )
							$ticket_types[] = $group_ticket_type;
						else if ( is_object( $group_ticket_type ) && isset( $group_ticket_type->product_id ) )
							$ticket_types[] = $group_ticket_type->product_id;
						else if ( is_object( $group_ticket_type ) && isset( $group_ticket_type->id ) )
							$ticket_types[] = $group_ticket_type->id;
			} else if ( is_object( $ticket_type ) && isset( $ticket_type->product_id ) )
				$ticket_types[] = $ticket_type->product_id;
			else if ( is_object( $ticket_type ) && isset( $ticket_type->id ) )
				$ticket_types[] = $ticket_type->id;

		// if there are no valid ticket types, then bail
		if ( empty( $ticket_types ) )
			return $resp;

		$stati = $zoner->get_stati();
		// add the extra data used to update the ui
		$resp['data'] = wp_parse_args( array(
			'owns' => $zoner->find( array(
				'fields' => 'total-zone-ticket-type-state-flat',
				'event_id' => $event->ID,
				'customer_id' => $zoner->current_user(),
				'order_id' => 0,
				'ticket_type_id' => $ticket_types,
				'state' => array( $stati['i'][0], $stati['r'][0] ),
			) ),
			'available' => 0,
			'available_more' => 0,
		), $orig );

		// add the remaining availability to each reservation zone entry
		foreach ( $resp['data']['owns'] as &$own )
			$own['c'] = $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $own['z'] ) );

		// only show the remaining availability if we are allowed by settings
		if ( 'yes' == apply_filters( 'qsot-get-option-value', 'yes', 'qsot-show-available-quantity' ) ) {
			// determine how many tickets have been sold or reserved for this event so far
			$reserved_or_confirmed = $zoner->find( array( 'fields' => 'total', 'event_id' => $event->ID ) );

			// calculate how many are left
			$capacity = isset( $event_area->meta, $event_area->meta['_capacity'] ) ? $event_area->meta['_capacity'] : 0;
			$left = max( 0, $capacity - $reserved_or_confirmed );

			// update the response
			$resp['data']['available'] = $resp['data']['available_more'] = $capacity > 0 ? $left : 1000000;
		}

		return $resp;
	}

	// handle the reserve ticket ajax requests
	public function aj_interest( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		$resp['r'] = $resp['is'] = array();
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-interest', $resp, $event, null, $_POST['items'] ), $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-interest', $resp, $event, null, $_POST['items'] ), $event );
		}

		// force woo to start a session
		WC()->session->interested = 1; 
		WC()->session->set_customer_session_cookie( true );

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$current_user = $zoner->current_user();

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => 0, 'quantity' => 1, 'method' => 'interest' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() );
				return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-interest', $resp, $event, null, $_POST['items'] ), $event );
			} else if ( ! $for_event ) {
				$resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) );
				return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-interest', $resp, $event, null, $_POST['items'] ), $event );
			}

			// attempt to reserve the seat
			$res = $zoner->interest( false, array(
				'event_id'=> $event->ID,
				'customer_id' => $current_user,
				'zone_id' => $zone_id,
				'order_id' => 0,
			) );

			// if the result was successful
			if ( $res && ! is_wp_error( $res ) ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['r'][] = array( 's' => true, 'z' => $zone_id, 't' => 0, 'q' => 1, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => 0, 'q' => 1, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => 0, 'q' => 1, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			}
		}

		// if there are an successes, then set the woo cookie
		if ( $resp['s'] ) {
			// force the cart to send the cookie, because sometimes it doesnt. stupid bug
			WC()->cart->maybe_set_cart_cookies();
		}

		// if the user is not logged in and this action is successful, generate a new nonce to use from now on, because this action caused the nonce hash to change, since WC 2.3.11
		if ( ! is_user_logged_in() && $resp['s'] )
			$resp['nn'] = wp_create_nonce( 'do-qsot-frontend-ajax' );

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-interest', $resp, $event, $event_area, $_POST['items'] ), $event, $event_area );
	}

	// handle the reserve ticket ajax requests
	public function aj_reserve( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		$resp['r'] = $resp['is'] = array();
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-reserve', $resp, $event, null, $_POST['items'] ), $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-reserve', $resp, $event, null, $_POST['items'] ), $event );
		}

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$current_user = $zoner->current_user();

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;
			$ticket_type = isset( $item['t'] ) ? $item['t'] : 0;
			$quantity = isset( $item['q'] ) ? $item['q'] : 0;

			// figure out the current quantity for this zone
			$current_qty = $zoner->find( array(
				'fields' => 'total',
				'zone_id' => $zone_id,
				'ticket_type_id' => $ticket_type,
				'customer_id' => $current_user,
				'order_id' => 0,
				'event_id' => $event->ID,
			) );

			// if the quantity is not a positive number, then bail
			if ( $quantity <= 0 ) {
				$resp['r'][] = array(
					's' => false,
					'z' => $zone_id,
					't' => $ticket_type,
					'q' => $current_qty,
					'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ),
					'e' => ( $resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' ) ),
				);
				continue;
			}

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			$ticket_type_pool = isset( $valid_types[ $zone_id ] ) ? $valid_types[ $zone_id ] : $valid_types[0];
			// if the selected ticket type is not valid, then bail
			if ( ! in_array( $ticket_type, $ticket_type_pool ) ) {
				$resp['r'][] = array(
					's' => false,
					'z' => $zone_id,
					't' => $ticket_type,
					'q' => $current_qty,
					'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ),
					'e' => ( $resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name ) ),
				);
				continue;
			}

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => $ticket_type, 'quantity' => $quantity, 'method' => 'reserve' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['r'][] = array(
					's' => false,
					'z' => $zone_id,
					't' => $ticket_type,
					'q' => $current_qty,
					'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ),
					'e' => ( $resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() ) ),
				);
				continue;
			} else if ( ! $for_event ) {
				$resp['r'][] = array(
					's' => false,
					'z' => $zone_id,
					't' => $ticket_type,
					'q' => $current_qty,
					'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ),
					'e' => ( $resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) ) ),
				);
				continue;
			}

			// attempt to reserve the seat
			$res = $zoner->reserve( false, array(
				'event_id'=> $event->ID,
				'ticket_type_id' => $ticket_type,
				'customer_id' => $current_user,
				'quantity' => $quantity,
				'zone_id' => $zone_id,
				'order_id' => 0,
			) );
			$resp['raw'] = $res;

			// if the result was successful
			if ( ! is_wp_error( $res ) && is_scalar( $res ) && $res > 0 ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
				$resp['r'][] = array( 's' => true, 'z' => $zone_id, 't' => $ticket_type, 'q' => $res, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
				$resp['r'][] = array( 's' => false, 'rm' => 1, 'z' => $zone_id, 't' => $ticket_type, 'q' => $res, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
				if ( ! isset( $resp['data'] ) )
					$resp['data'] = array( 'rm' => array() );
				$resp['data']['rm'][] = array( 'z' => $zone_id, 't' => $ticket_type, 'q' => $res );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $res, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			}
		}
		$resp[ 'request' ] = $_POST['items'];

		// if there are an successes, then set the woo cookie
		if ( $resp['s'] ) {
			// force the cart to send the cookie, because sometimes it doesnt. stupid bug
			WC()->cart->maybe_set_cart_cookies();
		}

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-reserve', $resp, $event, $event_area, $_POST['items'] ), $event, $event_area );
	}

	// handle the remove reservation ajax requests
	public function aj_remove( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		$resp['rm'] = $resp['is'] = array();
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-remove', $resp, $event, null, $_POST['items'] ), $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-remove', $resp, $event, null, $_POST['items'] ), $event );
		}

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		$current_user = $zoner->current_user();
		$current_user_id = get_current_user_id();

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;
			$ticket_type = isset( $item['t'] ) ? $item['t'] : 0;
			$quantity = isset( $item['q'] ) ? $item['q'] : 0;
			$state = array( $stati['i'][0], $stati['r'][0], $stati['c'][0] );

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			$ticket_type_pool = isset( $valid_types[ $zone_id ] ) ? $valid_types[ $zone_id ] : $valid_types[0];
			// if the selected ticket type is not valid, then bail
			if ( $ticket_type && ! in_array( $ticket_type, $ticket_type_pool ) ) {
				$resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name );
				return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-remove', $resp, $event, null, $_POST['items'] ), $event );
			}

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => $ticket_type, 'quantity' => $quantity, 'method' => 'remove' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() );
				return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-remove', $resp, $event, null, $_POST['items'] ), $event );
			} else if ( ! $for_event ) {
				$resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) );
				return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-remove', $resp, $event, null, $_POST['items'] ), $event );
			}

			// aggregate the args used for the remote function
			$rargs = array(
				'event_id'=> $event->ID,
				'ticket_type_id' => $ticket_type,
				// after an order is created, the user's user_id becomes the session_customer_id. adding this logic for thos wishywashy ppl
				'customer_id' => array_filter( array( $current_user, $current_user_id ) ),
				'order_id' => 0,
				'zone_id' => $zone_id,
				'state' => $state,
			);

			// include any order ids for orders that still require payment
			$rargs['order_id'] = is_array( $rargs['order_id'] ) ? $rargs['order_id'] : array( absint( $rargs['order_id'] ) );
			$rargs['order_id'][] = isset( WC()->session->order_awaiting_payment ) ? absint( WC()->session->order_awaiting_payment ) : 0;
			$rargs['order_id'] = array_unique( $rargs['order_id'] );

			// attempt to remove the reservation
			$res = $zoner->remove( false, $rargs );

			// if the result was successful
			if ( $res && ! is_wp_error( $res ) ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
				$resp['r'][] = array( 's' => true, 'z' => $zone_id, 't' => $ticket_type, 'q' => $quantity, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $quantity, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $quantity, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			}
		}

		// if there are an successes, then set the woo cookie
		if ( $resp['s'] ) {
			// force the cart to send the cookie, because sometimes it doesnt. stupid bug
			WC()->cart->maybe_set_cart_cookies();
		}

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		return $this->_add_data( apply_filters( 'qsot-seating-ajax-response-remove', $resp, $event, $event_area, $_POST['items'] ), $event, $event_area );
	}

	// confirm the tickets defined by an order item
	public function confirm_tickets( $item, $item_id, $order, $event, $event_area ) {
		$cuids = array();

		// figure out the list of session ids to use for the lookup
		if ( ( $ocuid = get_post_meta( $order->id, '_customer_user', true ) ) )
			$cuids[] = $ocuid;
		$cuids[] = QSOT::current_user();
		$cuids[] = md5( $order->id . ':' . site_url() );
		$cuids = array_filter( $cuids );

		// get the zoner and stati that are valid
		$zoner = $event_area->area_type->get_zoner();
		$stati = $zoner->get_stati();

		global $wpdb;
		// perform the update
		return $zoner->update( false, array(
			'event_id' => $item['event_id'],
			'quantity' => $item['qty'],
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'order_id' => array( 0, $order->id ),
			'zone_id' => $item['zone_id'],
			'order_item_id' => array( 0, $item_id ),
			'ticket_type_id' => $item['product_id'],
			'where__extra' => array(
				$wpdb->prepare( 'and ( order_item_id = %d or ( order_item_id = 0 and session_customer_id in(\'' . implode( "','", array_map( 'esc_sql', $cuids ) ) . '\') ) )', $item_id )
			),
		), array(
			'state' => $stati['c'][0],
			'order_id' => $order->id,
			'order_item_id' => $item_id,
			'session_customer_id' => current( $cuids ),
		) );
	}

	// unconfirm the tickets defined by an order item
	public function unconfirm_tickets( $item, $item_id, $order, $event, $event_area ) {
		$cuids = array();

		// figure out the list of session ids to use for the lookup
		if ( ( $ocuid = get_post_meta( $order->id, '_customer_user', true ) ) )
			$cuids[] = $ocuid;
		$cuids[] = QSOT::current_user();
		$cuids[] = md5( $order->id . ':' . site_url() );
		$cuids = array_filter( $cuids );

		// get the zoner and stati that are valid
		$zoner = $event_area->area_type->get_zoner();
		$stati = $zoner->get_stati();

		global $wpdb;
		// perform the update
		return $zoner->update( false, array(
			'event_id' => $item['event_id'],
			'quantity' => $item['qty'],
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'order_id' => array( 0, $order->id ),
			'zone_id' => $item['zone_id'],
			'order_item_id' => array( 0, $item_id ),
			'ticket_type_id' => $item['product_id'],
			'where__extra' => array(
				$wpdb->prepare( 'and ( order_item_id = %d or ( order_item_id = 0 and session_customer_id in(\'' . implode( "','", array_map( 'esc_sql', $cuids ) ) . '\') ) )', $item_id )
			),
		), array(
			'state' => $stati['r'][0],
			'order_id' => $order->id,
			'order_item_id' => $item_id,
			'session_customer_id' => current( $cuids ),
			'since' => current_time( 'mysql' ),
		) );
	}

	// cancel the tickets defined by an order item
	public function cancel_tickets( $item, $item_id, $order, $event, $event_area ) {
		$cuids = array();

		// figure out the list of session ids to use for the lookup
		if ( ( $ocuid = get_post_meta( $order->id, '_customer_user', true ) ) )
			$cuids[] = $ocuid;
		$cuids[] = QSOT::current_user();
		$cuids[] = md5( $order->id . ':' . site_url() );
		$cuids = array_filter( $cuids );

		// get the zoner and stati that are valid
		$zoner = $event_area->area_type->get_zoner();
		$stati = $zoner->get_stati();

		global $wpdb;
		// perform the update
		return $zoner->remove( false, array(
			'event_id' => $item['event_id'],
			'quantity' => $item['qty'],
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'order_id' => array( 0, $order->id ),
			'zone_id' => $item['zone_id'],
			'order_item_id' => array( 0, $item_id ),
			'ticket_type_id' => $item['product_id'],
			'where__extra' => array(
				$wpdb->prepare( 'and ( order_item_id = %d or ( order_item_id = 0 and session_customer_id in(\'' . implode( "','", array_map( 'esc_sql', $cuids ) ) . '\') ) )', $item_id )
			),
		) );
	}

	// add the seating event data to the admin ajax load event response
	public function admin_ajax_load_event( $data, $event, $event_area, $order ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		// add the html versions of the start and end date
		$frmt = __( 'D, F jS, Y h:ia', 'opentickets-community-edition' );
		$data['_html_date'] = sprintf( '<span class="from">%s</span> - <span class="to">%s</span>', date_i18n( $frmt, strtotime( $event->meta->start ) ), date_i18n( $frmt, strtotime( $event->meta->end ) ) );

		// add the capacity
		$data['_capacity'] = intval( isset( $event_area->meta['_capacity'] ) ? $event_area->meta['_capacity'] : 0 );

		// get the available amount of tickets left on the event
		$zoner = $this->get_zoner();
		$data['_available'] = $zoner->get_availability( $event, $event_area );

		// add the raw event data, in case we want it, and the edit event link
		$event->event_area = $event_area;
		$data['_raw'] = $event;
		$data['_link'] = sprintf( '<a href="%s" target="_blank">%s</a>', get_edit_post_link( $event->ID ), $data['name'] );

		// load all the image sizes for the featured image of the event area
		$data['_imgs'] = array();
		// if the event area has a featured image, load that image's details for use in the ui
		if ( isset( $event_area->meta['_thumbnail_id'] ) ) {
			// get the image data, and store it in the result, so the ui can do with it what it wants
			$img_info = get_post_meta( $event_area->meta['_thumbnail_id'], '_wp_attachment_metadata', true );
			$data['_image_info_raw'] = $img_info;

			// then for each image size, aggregate some information for displaying the image, which is used to create the image tags
			if ( isset( $img_info['file'] ) && is_array( $img_info ) && isset( $img_info['sizes'] ) && is_array( $img_info['sizes'] ) ) {
				$u = wp_upload_dir();
				$base_file = $img_info['file'];
				$file_path = trailingslashit( trailingslashit( $u['baseurl'] ) . str_replace( basename( $base_file ), '', $base_file ) );
				// for each image size, add a record with the image path and size details
				foreach ( $img_info['sizes'] as $k => $info ) {
					$data['_imgs'][$k] = array(
						'url' => $file_path . $info['file'],
						'width' => $info['width'],
						'height' => $info['height'],
					);
				}
				// also add an entry for the fullsize version
				$data['_imgs']['full'] = array(
					'url' => trailingslashit( $u['baseurl'] ) . $base_file,
					'width' => $img_info['width'],
					'height' => $img_info['height'],
				);
			}
		}

		// figure out the appropriate customer id
		$customer_id = 'order:' . $order->id;
		if ( isset( $_POST['customer_user'] ) && ! empty( $_POST['customer_user'] ) )
			$customer_id = $_POST['customer_user'];
		elseif ( ( $order_customer_id = get_post_meta( $order->id, '_customer_user', true ) ) )
			$customer_id = $order_customer_id;

		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		// default number of tickets owned by this order and the default ticket data
		$data['_owns'] = $zoner->find( array(
			'event_id' => $event->ID,
			'order_id' => $order->id,
			'state' => array( $stati['r'][0], $stati['c'][0] ),
			'customer_id' => $customer_id,
		) );

		// add the pricing structure information for this event
		$data['_struct'] = $this->price_struct->get_by_event_id( $event->ID );

		// add all the event area chart data to the response
		$data['edata'] = $this->_get_frontend_event_data( $event );

		// get the templates and messages used by the frontend ui
		$data['templates'] = $this->get_templates( $event );
		$data['messages'] = $this->get_messages( $event );

		return apply_filters( 'qsot-seating-admin-ajax-load-event', $data, $event, $event_area );
	}

	// add the extra ajax response data we need on every admin ajax request
	protected function _add_admin_data( $data ) {
		return $data;
	}

	// handle the admin ajax request to show interest in a ticket
	public function admin_ajax_interest( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		$resp['r'] = $resp['is'] = array();
		// if the event does not exist, then bail
		if ( ! is_object( $event ) ) {
			$resp['e'][] = __( 'Could not find that event.', 'qsot-seating' );
			return $resp;
		}
		do_action( 'qsot-clear-zone-locks', array( 'event_id' => $event->ID ) );
		
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_admin_data( $resp, $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_admin_data( $resp, $event );
		}

		// load the order. if it does not exist, bail
		$order = wc_get_order( isset( $_POST['oid'] ) ? $_POST['oid'] : -1 );
		if ( ! is_object( $order ) || is_wp_error( $order ) ) {
			$resp['e'][] = __( 'Could not load that order.', 'qsot-seating' );
			return $this->_add_admin_data( $resp );
		}

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$current_user = $zoner->current_user( array( 'order_id' => $order->id ) );

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => 0, 'quantity' => 1, 'method' => 'interest' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() );
				return $this->_add_admin_data( $resp, $event );
			} else if ( ! $for_event ) {
				$resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) );
				return $this->_add_admin_data( $resp, $event );
			}

			// attempt to reserve the seat
			$res = $zoner->interest( false, array(
				'event_id'=> $event->ID,
				'customer_id' => $current_user,
				'zone_id' => $zone_id,
				'order_id' => $order->id,
			) );

			// if the result was successful
			if ( $res && ! is_wp_error( $res ) ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['r'][] = array( 's' => true, 'z' => $zone_id, 't' => 0, 'q' => 1, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );

				// notifiy externals of the change
				$event->event_area = $event_area;
				do_action( 'qsot-order-admin-seating-interest-tickets', array(
					'order' => $order,
					'event' => $event,
					'quantity' => 1,
					'customer_id' => $current_user,
					'order_item_id' => 0,
					'zone_id' => $zone_id,
				) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => 0, 'q' => 1, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => 0, 'q' => 1, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			}
		}

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		return $this->_add_admin_data( $resp );
	}

	// handle the admin ajax request to add a ticket to an order
	public function admin_ajax_reserve( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		$resp['r'] = $resp['is'] = array();
		// if the event does not exist, then bail
		if ( ! is_object( $event ) ) {
			$resp['e'][] = __( 'Could not find that event.', 'qsot-seating' );
			return $resp;
		}
		
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_admin_data( $resp, $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_admin_data( $resp, $event );
		}

		// load the order. if it does not exist, bail
		$order = wc_get_order( isset( $_POST['oid'] ) ? $_POST['oid'] : -1 );
		if ( ! is_object( $order ) || is_wp_error( $order ) ) {
			$resp['e'][] = __( 'Could not load that order.', 'qsot-seating' );
			return $this->_add_admin_data( $resp );
		}

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		$current_user = $zoner->current_user( array( 'order_id' => $order->id ) );

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;
			$ticket_type = isset( $item['t'] ) ? $item['t'] : 0;
			$quantity = isset( $item['q'] ) ? $item['q'] : 0;

			// if the quantity is not a positive number, then bail
			if ( $quantity <= 0 ) {
				$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
				continue;
			}

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			$ticket_type_pool = isset( $valid_types[ $zone_id ] ) ? $valid_types[ $zone_id ] : $valid_types[0];
			// if the selected ticket type is not valid, then bail
			if ( ! in_array( $ticket_type, $ticket_type_pool ) ) {
				$resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name );
				continue;
			}
			$product = wc_get_product( $ticket_type );
			if ( ! is_object( $product ) || is_wp_error( $product ) ) {
				$resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name );
				continue;
			}

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => $ticket_type, 'quantity' => $quantity, 'method' => 'reserve' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() );
				continue;
			} else if ( ! $for_event ) {
				$resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) );
				continue;
			}

			// attempt to reserve the seat
			$res = $zoner->reserve( false, array(
				'event_id'=> $event->ID,
				'ticket_type_id' => $ticket_type,
				'customer_id' => $current_user,
				'quantity' => $quantity,
				'zone_id' => $zone_id,
				'order_id' => $order->id,
			) );

			// if the result was successful
			if ( ! is_wp_error( $res ) && is_scalar( $res ) && $res > 0 ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
				$resp['r'][] = array( 's' => true, 'z' => $zone_id, 't' => $ticket_type, 'q' => $res, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );

				// add the item to the order
				$item_id = $this->_add_or_update_order_item( $order, $product, $res, array( 'event_id' => $event->ID, 'zone_id' => $zone_id ) );

				// update the reservation entry with the order_item_id
				$new_state = $stati['c'][0];
				$zoner->update( false, array(
					'event_id' => $event->ID,
					'order_id' => $order->id,
					'quantity' => $res,
					'customer_id' => $current_user,
					'ticket_type_id' => $ticket_type,
					'zone_id' => $zone_id,
				), array( 'order_item_id' => $item_id, 'state' => $new_state ) );

				// notifiy externals of the change
				$event->event_area = $event_area;
				do_action( 'qsot-order-admin-seating-reserve-tickets', array(
					'order' => $order,
					'event' => $event,
					'quantity' => $res,
					'customer_id' => $current_user,
					'order_item_id' => $item_id,
					'zone_id' => $zone_id,
					'ticket_type_id' => $ticket_type,
				) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $res, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $res, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			}
		}

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		// reload the event data for an update
		$resp['data'] = array(
			'id' => $event->ID,
			'name' => apply_filters( 'the_title', $event->post_title, $event->ID ),
			'area_type' => $event_area->area_type->get_slug(),
		);
		$resp['data'] = $event_area->area_type->admin_ajax_load_event( $resp['data'], $event, $event_area, $order );

		return $this->_add_admin_data( $resp );
	}

	// add a new item or update an existing item for this reservation request
	protected function _add_or_update_order_item( $order, $product, $qty, $args ) {
		$found = 0;
		// cycle through the order items and find the first matching order item for this event and product combo
		foreach ( $order->get_items( 'line_item' ) as $oiid => $item ) {
			// if there is no product_id on this item, skip it
			if ( ! isset( $item['product_id'] ) || $item['product_id'] != $product->id )
				continue;

			$matched = true;
			// figure out if all the args match
			foreach ( $args as $k => $v ) {
				if ( ! isset( $item[ $k ] ) || $item[ $k ] != $v ) {
					$matched = false;
					break;
				}
			}

			// if all the fields match, then use this order item
			if ( $matched ) {
				$found = $oiid;
				break;
			}
		}

		$item_id = 0;
		// if the product-event combo was found in an existing order item, then simply update the quantity of that order item
		if ( $found > 0 ) {
			$order->update_product( $found, $product, array( 'qty' => $qty ) );
			$item_id = $found;
		// otherwise add a new order item for this seleciton
		} else {
			$item_id = $order->add_product( $product, $qty );
			foreach ( $args as $k => $v )
				wc_add_order_item_meta( $item_id, '_' . $k, $v );
		}

		return $item_id;
	}

	// handle the admin ajax request to add a ticket to an order
	public function admin_ajax_update_ticket( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		$resp['r'] = $resp['is'] = array();
		// if the event does not exist, then bail
		if ( ! is_object( $event ) ) {
			$resp['e'][] = __( 'Could not find that event.', 'qsot-seating' );
			return $resp;
		}
		
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_admin_data( $resp, $event );
		}

		// if there is no target order item id, then bail
		$oiid = isset( $_POST['oiid'] ) ? $_POST['oiid'] : 0;
		if ( $oiid <= 0 ) {
			$resp['e'][] = __( 'Could not modify that order item.', 'qsot-seating' );
			return $this->_add_admin_data( $resp, $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_admin_data( $resp, $event );
		}

		// load the order. if it does not exist, bail
		$order = wc_get_order( isset( $_POST['oid'] ) ? $_POST['oid'] : -1 );
		if ( ! is_object( $order ) || is_wp_error( $order ) ) {
			$resp['e'][] = __( 'Could not load that order.', 'qsot-seating' );
			return $this->_add_admin_data( $resp );
		}

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$stati = $zoner->get_stati();
		$current_user = $zoner->current_user( array( 'order_id' => $order->id ) );

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;
			$ticket_type = isset( $item['t'] ) ? $item['t'] : 0;
			$quantity = isset( $item['q'] ) ? $item['q'] : 0;

			// if the quantity is not a positive number, then bail
			if ( $quantity <= 0 ) {
				$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
				continue;
			}

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			$ticket_type_pool = isset( $valid_types[ $zone_id ] ) ? $valid_types[ $zone_id ] : $valid_types[0];
			// if the selected ticket type is not valid, then bail
			if ( ! in_array( $ticket_type, $ticket_type_pool ) ) {
				$resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name );
				continue;
			}
			$product = wc_get_product( $ticket_type );
			if ( ! is_object( $product ) || is_wp_error( $product ) ) {
				$resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name );
				continue;
			}

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => $ticket_type, 'quantity' => $quantity, 'method' => 'reserve' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() );
				continue;
			} else if ( ! $for_event ) {
				$resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) );
				continue;
			}

			// attempt to reserve the seat
			$new_state = in_array( $order->get_status(), apply_filters( 'qsot-zoner-confirmed-statuses', array( 'on-hold', 'processing', 'completed' ) ) ) ? $stati['c'][0] : $stati['r'][0];
			$res = $zoner->update( false, array( 'order_item_id' => $oiid, 'zone_id' => '', 'state' => array( $stati['r'][0], $stati['c'][0] ) ), array(
				'event_id'=> $event->ID,
				'ticket_type_id' => $ticket_type,
				'customer_id' => $current_user,
				'quantity' => $quantity,
				'zone_id' => $zone_id,
				'state' => $new_state,
				'order_id' => $order->id,
				'order_item_id' => $oiid,
			) );

			// if the result was successful
			if ( $res && ! is_wp_error( $res ) ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
				$resp['r'][] = array( 's' => true, 'z' => $zone_id, 't' => $ticket_type, 'q' => $quantity, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );

				// add the item to the order
				wc_update_order_item_meta( $oiid, '_event_id', $event->ID );
				wc_update_order_item_meta( $oiid, '_zone_id', $zone_id );

				// remove the interest row we created
				$zoner->remove( false, array(
					'event_id'=> $event->ID,
					'customer_id' => $current_user,
					'zone_id' => $zone_id,
					'state' => $stati['i'][0],
					'order_id' => $order->id,
				) );

				// notifiy externals of the change
				$event->event_area = $event_area;
				do_action( 'qsot-order-admin-seating-update-ticket', array(
					'order' => $order,
					'event' => $event,
					'quantity' => $quantity,
					'customer_id' => $current_user,
					'order_item_id' => $oiid,
					'zone_id' => $zone_id,
					'ticket_type_id' => $ticket_type,
				) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
				$resp['raw'] = $res;
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $quantity, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
				$resp['r'][] = array( 's' => false, 'z' => $zone_id, 't' => $ticket_type, 'q' => $quantity, 'c' => $zoner->get_event_zone_availability( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'all_states' => true ) ) );
			}
		}

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		return $this->_add_admin_data( $resp );
	}

	// handle the remove reservation ajax requests
	public function admin_ajax_remove( $resp, $event ) {
		$resp['e'] = ! isset( $resp['e'] ) || ! is_array( $resp['e'] ) ? array() : $resp['e'];
		$resp['m'] = ! isset( $resp['m'] ) || ! is_array( $resp['m'] ) ? array() : $resp['m'];
		// get the event_area based on the event
		$event_area = apply_filters( 'qsot-event-area-for-event', false, $event );
		if ( ! is_object( $event_area )  ) {
			$resp['e'][] = __( 'Could not find that event.', 'opentickets-community-edition' );
			return $this->_add_admin_data( $resp, $event );
		}

		// if there are no reservations being requested, then bail
		if ( ! isset( $_POST['items'] ) || ! is_array( $_POST['items'] ) || empty( $_POST['items'] ) ) {
			$resp['e'][] = __( 'You must choose a seat to reserve', 'qsot-seating' );
			return $this->_add_admin_data( $resp, $event );
		}

		// load the order. if it does not exist, bail
		$order = wc_get_order( isset( $_POST['oid'] ) ? $_POST['oid'] : -1 );
		if ( ! is_object( $order ) || is_wp_error( $order ) ) {
			$resp['e'][] = __( 'Could not load that order.', 'qsot-seating' );
			return $this->_add_admin_data( $resp );
		}

		// get the zoner for this area type
		$zoner = $this->get_zoner();
		$current_user = $zoner->current_user( array( 'order_id' => $order->id ) );

		// obtain the pricing structure for this event
		$valid_types = $this->get_ticket_type( array( 'event' => $event, 'fields' => 'ids' ) );

		// cycle through the requested reservations, and attempt to make the reservations
		foreach ( $_POST['items'] as $item ) {
			// extract the data from each item
			$zone_id = isset( $item['z'] ) ? $item['z'] : 0;
			$ticket_type = isset( $item['t'] ) ? $item['t'] : 0;
			$quantity = isset( $item['q'] ) ? $item['q'] : 0;

			// if the quantity is not a positive number, then bail
			if ( $quantity <= 0 ) {
				$resp['e'][] = __( 'The quantity must be greater than zero.', 'opentickets-community-edition' );
				return $this->_add_admin_data( $resp, $event );
			}

			// get the zone information 
			$zone = $zoner->get_zone_info( $zone_id, $event_area->ID );

			$ticket_type_pool = isset( $valid_types[ $zone_id ] ) ? $valid_types[ $zone_id ] : $valid_types[0];
			// if the selected ticket type is not valid, then bail
			if ( ! in_array( $ticket_type, $ticket_type_pool ) ) {
				$resp['e'][] = sprintf( __( 'The price you selected is not valid for the [%s] zone.', 'qsot-seating' ), $zone->name );
				return $this->_add_admin_data( $resp, $event );
			}

			// check if the zone has available tickets
			$for_event = $zoner->is_event_zone_available( array( 'event_id' => $event->ID, 'zone_id' => $zone_id, 'ticket_type_id' => $ticket_type, 'quantity' => $quantity, 'method' => 'remove' ) );
			if ( is_wp_error( $for_event ) ) {
				$resp['e'] = array_merge( $resp['e'], $for_event->get_error_messages() );
				return $this->_add_admin_data( $resp, $event );
			} else if ( ! $for_event ) {
				$resp['e'][] = sprintf( __( 'The zone [%s] does not have enough available tickets.', 'qsot-seating' ), is_object( $zone ) ? $zone->name : __( 'unknown', 'qsot-seating' ) );
				return $this->_add_admin_data( $resp, $event );
			}

			// attempt to remove the reservation
			$res = $zoner->remove( false, array(
				'event_id'=> $event->ID,
				'ticket_type_id' => $ticket_type,
				'customer_id' => $current_user,
				'order_id' => $order->id,
				'zone_id' => $zone_id,
				'state' => array( $stati['i'][0], $stati['r'][0] ),
			) );

			// if the result was successful
			if ( $res && ! is_wp_error( $res ) ) {
				// construct an affirmative response, with the remainder data if applicable
				$resp['s'] = true;
				$resp['m'] = array( __( 'Updated your reservations successfully.', 'opentickets-community-edition' ) );
			// if the request failed for a known reason, then add that reason to the response
			} else if ( is_wp_error( $res ) ) {
				$resp['e'] = array_merge( $resp['e'], $res->get_error_messages() );
			// otherwise it failed for an unknown reason. add an error to the response
			} else {
				$resp['e'][] = __( 'Could not update your reservations.', 'opentickets-community-edition' );
			}
		}

		// remove duplicate messages
		$resp['e'] = array_unique( $resp['e'] );
		$resp['m'] = array_unique( $resp['m'] );

		return $this->_add_admin_data( $resp, $event, $event_area, $ticket_type );
	}

	// draw the 'qsot-single-image' setting fields
	public function image_id_setting( $value ) {
		$current = get_option( $value['id'], '' );

		// normalize the args
		$value = wp_parse_args( $value, array(
			'preview-size' => array( 30, 30 ),
		) );
		$value['preview-size'] = is_scalar( $value['preview-size'] ) ? explode( ',', $value['preview-size'] ) : $value['preview-size'];

		$offset = 0;
		?>
			<tr valign="top" class="qsot-image-ids">
				<th scope="row" class="titledesc">
					<?php echo force_balance_tags( $value['title'] ) ?>
					<?php if ( isset( $value['desc_tip'] ) ): ?>
						<img class="help_tip" data-tip="<?php echo esc_attr( $value['desc_tip'] ) ?>" src="<?php echo WC()->plugin_url() ?>/assets/images/help.png" height="16" width="16" />
					<?php endif; ?>
				</th>
				<td>
					<style>
						<?php echo sprintf(
							'.image-id-selection .preview-img.img-%s { min-height:%spx; min-width:%spx; }',
							implode( 'x', $value['preview-size'] ),
							$value['preview-size'][0],
							$value['preview-size'][1]
						) ?>
					</style>
					<p class="description"><?php echo $value['desc'] ?></p>

					<?php
						$img_id = isset( $current ) ? $current : 0;
						$tag = is_numeric( $img_id ) ? wp_get_attachment_image( $img_id, $value['preview-size'], false ) : '';
					?>
					<div class="image-id-selection <?php echo ( 'noimg' == $img_id ) ? 'no-img' : '' ?>" rel="image-select">
						<label><?php _e( 'Select Image', 'qsot-seating' ) ?></label>
						<div class="preview-img img-30x30" rel="image-preview"><?php echo $tag ?></div>
						<div class="clear"></div>
						<input type="hidden" name="<?php echo esc_attr( $value['id'] ) ?>" value="<?php echo esc_attr( $img_id ) ?>" class="image-id" rel="img-id" />
						<input type="button" class="button select-button qsot-popmedia" value="Select Image" rel="select-image-btn" scope="[rel='image-select']" /><br/>
						<a href="#remove-img" class="remove-img" rel="remove-img" scope="[rel='image-select']"><?php _e( 'remove image', 'opentickets-community-edition' ) ?></a><br/>
						<a href="#no-img" class="no-image" rel="no-img" scope="[rel='image-select']"><?php _e( 'no image', 'opentickets-community-edition' ) ?></a>
					</div>

					<div class="clear"></div>
				</td>
			</tr>
		<?php
	}

	// add the subtab to the 'frontend' main tab on the opentickets settings page
	public function add_seating_charts_subtab( $subtabs=array() ) {
		$newtabs = array();

		// cycle through the existing tabs, and add this new tab after the tickets tab
		foreach ( $subtabs as $tab => $label ) {
			$newtabs[ $tab ] = $label;
			if ( 'tickets' == $tab )
				$newtabs['seating-charts'] = __( 'Seating Charts', 'qsot-seating' );
		}

		return $newtabs;
	}

	// setup all the options for the admin settings page that this plugin will use
	protected function _setup_admin_options() {
		// load all admin settings
		$options = QSOT_Options::instance();

		// setup the default values
		$options->def( 'qsot-seating-one-click-single-price', 'yes' );
		$options->def( 'qsot-seating-chart-position', 'above' );
		$options->def( 'qsot-seating-zoom-in-icon-id', '' );
		$options->def( 'qsot-seating-zoom-out-icon-id', '' );
		$options->def( 'qsot-seating-zoom-reset-icon-id', '' );

		// the 'Seating Charts' heading on the Frontend tab
		$options->add( array(
			'order' => 600,
			'type' => 'title',
			'title' => __( 'Seating Charts', 'qsot-seating' ),
			'id' => 'heading-frontend-seating-charts-1',
			'page' => 'frontend',
			'section' => 'seating-charts',
		) );

		// enable / disable one click functionality
		$options->add( array(
			'order' => 601,
			'id' => 'qsot-seating-one-click-single-price',
			'type' => 'checkbox',
			'title' => __( 'One-click reservations', 'qsot-seating' ),
			'desc' => __( 'When there is a single price level available for a seat, and the maximum capacity for the seat is "1", then allow the user to click one time to reserve the seat, instead of once to choose the seat and once to select a price level.', 'qsot-seating' ),
			'default' => 'yes',
			'page' => 'frontend',
			'section' => 'seating-charts',
		) );

		// which image is shown on the left side of the ticket. either no image, the Event image, the Venue image, the Ticket Product image
		$options->add(array(
			'order' => 605,
			'id' => 'qsot-seating-chart-position',
			'type' => 'radio',
			'title' => __( 'Chart Position', 'qsot-seating' ),
			'desc_tip' => __( 'The location of the seating chart on the frontend display of a seated event, in relation to the seat selection form.', 'qsot-seating' ),
			'options' => array(
				'above' => __( 'Chart shows above the seat selection form', 'qsot-seating' ),
				'below' => __( 'Seat selection form shows above the chart', 'qsot-seating' ),
			),
			'default' => 'above',
			'page' => 'frontend',
			'section' => 'seating-charts',
		));

		// zoom in icon image
		$options->add( array(
			'order' => 609,
			'id' => 'qsot-seating-zoom-in-icon-id',
			'type' => 'qsot-single-image',
			'title' => __( 'Zoom-In Icon', 'qsot-seating' ),
			'desc_tip' => __( 'Zoom-In icon image, used on the frontend display of the seating chart.', 'qsot-seating' ),
			'desc' => __( 'The recommended size of these images is 30px by 30px. Images will be resized to 30px by 30px, no matter the starting size, and may be stretched. Choosing "no image" will remove the zoom-in button from the interface', 'qsot-seating' ),
			'preview-size' => array( 30, 30 ),
			'page' => 'frontend',
			'section' => 'seating-charts',
		) );

		// zoom in icon image
		$options->add( array(
			'order' => 609,
			'id' => 'qsot-seating-zoom-out-icon-id',
			'type' => 'qsot-single-image',
			'title' => __( 'Zoom-Out Icon', 'qsot-seating' ),
			'desc_tip' => __( 'Zoom-Out icon image, used on the frontend display of the seating chart.', 'qsot-seating' ),
			'desc' => __( 'The recommended size of these images is 30px by 30px. Images will be resized to 30px by 30px, no matter the starting size, and may be stretched. Choosing "no image" will remove the zoom-in button from the interface', 'qsot-seating' ),
			'preview-size' => array( 30, 30 ),
			'page' => 'frontend',
			'section' => 'seating-charts',
		) );

		// zoom in icon image
		$options->add( array(
			'order' => 609,
			'id' => 'qsot-seating-zoom-reset-icon-id',
			'type' => 'qsot-single-image',
			'title' => __( 'Zoom-Reset Icon', 'qsot-seating' ),
			'desc_tip' => __( 'Zoom-Reset icon image, used on the frontend display of the seating chart.', 'qsot-seating' ),
			'desc' => __( 'The recommended size of these images is 30px by 30px. Images will be resized to 30px by 30px, no matter the starting size, and may be stretched. Choosing "no image" will remove the zoom-in button from the interface', 'qsot-seating' ),
			'preview-size' => array( 30, 30 ),
			'page' => 'frontend',
			'section' => 'seating-charts',
		) );

		// end the 'Tickets' section on the page
		$options->add(array(
			'order' => 699,
			'type' => 'sectionend',
			'id' => 'heading-frontend-seating-charts-1',
			'page' => 'frontend',
			'section' => 'seating-charts',
		));
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Seating_Area_Type::instance();
