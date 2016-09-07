<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// add the admin functionality of the display_options plugin
class QSOT_display_options_admin {
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

		// during activation, we need to update some data
		add_action( 'qsot-display-options-activation', array( __CLASS__, 'on_activation' ) );

		// during load of the admin, see if we need to pop an admin notice about a required update
		add_action( 'admin_init', array( __CLASS__, 'check_required_data_update' ), 10 );

		// handle the system status tools page
		add_action( 'qsot-system-status-tools', array( __CLASS__, 'sys_status_draw_tools' ), 100 );
		add_action( 'qsot-ss-performed-failed-event-update', array( __CLASS__, 'sys_status_performed' ), 10, 1 );
		add_action( 'qsot-ss-performed-updated-events', array( __CLASS__, 'sys_status_performed' ), 10, 1 );
		add_filter( 'qsot-ss-tool-DOdataUp', array( __CLASS__, 'sys_status_do_tool' ), 10, 2 );

		// when saving an event, make sure to update our child event meta with our new price field
		add_filter( 'qsot-events-save-sub-event-settings', array( __CLASS__, 'child_event_price' ), 100, 3 );
		// same thing when updating the event area itself
		add_action( 'qsot-save-event-area', array( __CLASS__, 'event_area_price' ), PHP_INT_MAX, 3 );

		// add our new settings pages and settings
		add_filter( 'qsot_get_settings_pages', array( __CLASS__, 'load_settings_pages' ), 9, 1 );

		// load the assets we need in the admin
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_assets' ), 10, 1 );

		// js modal text
		add_filter( 'media_view_strings', array( __CLASS__, 'media_strings' ), 10, 2 );
	}

	// custom media modal strings
	public static function media_strings( $strings, $post ) {
		$strings['qsotsButton'] = __( 'Insert Shortcode', 'qsot-display-options' );
		$strings['qsotsMenuTitle'] = __( 'OpenTickets Shortcodes', 'qsot-display-options' );
		return $strings;
	}

	// load the assets we need in the admin
	public static function admin_enqueue_assets( $hook ) {
		// on the edit post page
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ) ) ) {
			// queue up scripts ans styles, and initialize their settings
			wp_enqueue_style( 'qsot-do-admin-widgets' );
			wp_enqueue_script( 'qsot-do-shortcode-generator' );
			wp_localize_script( 'qsot-do-utils', '_qsot_do_settings', array(
				'nonce' => wp_create_nonce( 'display-options-ajax' ),
				'msgs' => apply_filters( 'qsot-display-options-ui-msgs', array(), 0 ),
				'templ' => apply_filters( 'qsot-display-options-ui-templates', array(), 0 ),
			) );

			// render the needed templates
			do_action( 'qsot-display-options-fpb-modal-templates' );
			do_action( 'qsot-display-options-shortcodes-modal-templates' );
		}
	}

	// when saving an event, make sure to update our child events so that we know the 'base price' of tickets, for shop sorting
	public static function child_event_price( $settings, $parent_id, $parent ) {
		// only perform this mod, if the needed information is present
		if ( isset( $settings['meta'] ) && is_array( $settings['meta'] ) ) {
			$settings['meta']['_price'] = isset( $settings['meta'][ self::$o->{'meta_key.event_area'} ] )
					? self::_find_lowest_ea_price( $settings['meta'][ self::$o->{'meta_key.event_area'} ], $settings )
					: 0;
		}

		return $settings;
	}

	// find the lowest price attached to an event area
	protected static function _find_lowest_ea_price( $ea_id, $settings ) {
		static $cache = array();
		// if we already did this calc for the given event area, then use the cached value we came up with last time
		if ( isset( $cache[ $ea_id ] ) )
			return $cache[ $ea_id ];

		$price = null;
		// if there is a 'pricing structure' assigned, find the lowest price in the structure
		if ( isset( $settings['meta']['_pricing_struct_id'] ) && has_filter( 'qsot-get-price-structure-prices' ) && ( class_exists( 'QSOT_multi_price_launcher' ) || class_exists( 'QSOT_seating_launcher' ) ) ) {
			// get all the price structure prices
			$prices = apply_filters( 'qsot-get-price-structure-prices', array(), array( 'price_struct_id' => $settings['meta']['_pricing_struct_id'] ) );

			$lowest = null;
			// find the lowest price
			if ( isset( $prices[ $settings['meta']['_pricing_struct_id'] ], $prices[ $settings['meta']['_pricing_struct_id'] ][0] ) )
				foreach ( $prices[ $settings['meta']['_pricing_struct_id'] ][0] as $struct )
					if ( isset( $struct['product_price'] ) )
						$lowest = null !== $lowest ? min( $lowest, $struct['product_price'] ) : $struct['product_price'];

			// if a lowest price was found, then use that as the final price
			if ( null !== $lowest )
				$price = $lowest;
		}
		
		// otherwise, use the OTCE core pricing_options product_id to find the lowest
		if ( null === $price ) {
			// find the primary ticket type Pricing Option product id
			$po_id = $ea_id ? get_post_meta( $ea_id, self::$o->{'event_area.mk.po'}, true ) : 0;

			// find the price of that product, and use it as the price of the event
			$price = (float)get_post_meta( $po_id, '_price', true );
		}

		return $cache[ $ea_id ] = (float)$price;
	}

	// when saving the event area, make sure to update the event prices for those events using the event area
	public static function event_area_price( $_, $ea_id, $new_data ) {
		global $wpdb;

		// fetch lists of all events that use this event area, and update their "_price" meta
		$q = 'select id from ' . $wpdb->postmeta . ' where meta_key = %s limit %d offset %d';
		$per = 1000;
		$offset = 0;

		// query to update existing entries with ne value
		$uq = 'update ' . $wpdb->postmeta . ' set meta_value = %s where meta_key = "_price" and post_id in ';

		// get the new price of the designated tt product
		$price = (float)get_post_meta( $new_data['ttid'], '_price', true );

		// cycle through all matching events, a few at a time
		while ( $ids = array_filter( array_map( 'absint', $wpdb->get_col( $q, self::$o->{'meta_key.event_area'}, $per, $offset ) ) ) ) {
			$offset += $per;
			$wpdb->query( $wpdb->prepare( $uq, $price ) . '(' . implode( ',', $ids ) . ')' );
		}
	}

	// during admin_init, check if there is a data update required. if so, then add a message indicating so
	public static function check_required_data_update() {
		$check = get_option( '_qsot_do_needs_update', array() );

		// if the update is required, then add a admin notice stating so
		if ( $check )
			add_action( 'admin_notices', array( __CLASS__, 'notice_needs_data_update' ), 10 );
	}

	// pop an admin notice saying that we need a data update
	public static function notice_needs_data_update() {
		$args = array( 'page' => 'qsot-system-status', 'tab' => 'tools', 'qsot-tool' => 'DOdataUp', 'n' => wp_create_nonce( 'DOdataUp' ) );
		$url = add_query_arg( $args, apply_filters( 'qsot-get-menu-page-uri', '', 'main', true ) );

		$check = get_option( '_qsot_do_needs_update', array() );
		$links = array();
		// aggregate a list of edit event links that will allow the user to correct the issues
		if ( is_array( $check ) ) foreach ( $check as $event_id ) {
			$event = get_post( $event_id );
			$links[] = sprintf(
				'<a href="%s" target="_blank" title="%s">%s</a>',
				get_edit_post_link( $event->post_parent ),
				__( 'Edit the parent event', 'qsot-display-options' ),
				apply_filters( 'the_title', $event->post_title, $event->ID )
			);
		}

		if ( empty( $links ) )
			return;
		?>
			<div class="error"><p><?php echo sprintf(
				__( 'The %s extension has detected that it needs to update your event data. Would you like to %s?', 'qsot-display-options' ),
				'<b><em>' . QSOT_display_options_launcher::name() . '</em></b>',
				sprintf( '<a class="button" href="%s">%s</a>', $url, __( 'Do it now', 'qsot-display-options' ) )
			) ?><br/><?php
				_e( 'Affected Parent Events', 'qsot-display-options' );
				echo ': ', implode( ', ', $links );
			?></p></div>
		<?php
	}

	// draw the data update tool
	public static function sys_status_draw_tools() {
		$url = remove_query_arg( array( 'updated', 'performed', 'qsot-tool', 'qsotn' ) );
		$nonce = wp_create_nonce( 'DOdataUp' );
		?>
			<tr class="tool-item">
				<td>
					<a class="button" href="<?php echo esc_attr( add_query_arg( array( 'qsot-tool' => 'DOdataUp', 'n' => $nonce ), $url ) ) ?>"><?php
						_e( 'Update Event Data', 'qsot-display-options' )
					?></a>
				</td>
				<td>
					<span class="helper"><?php echo sprintf( __( 'The %s requires an event data update. Clicking this button performs that update.', 'qsot-display-options' ), QSOT_display_options_launcher::name() ) ?></span>
				</td>
			</tr>
		<?php
	}

	// draw the "performed" tool message
	public static function sys_status_performed( $performed ) {
		switch ( $performed ) {
			case 'updated-events':
				echo sprintf(
					'<div class="updated"><p>%s</p></div>',
					__( 'Updated all events for Display Options compatibility.', 'qsot-display-options' )
				);
			break;

			case 'failed-event-update':
				echo sprintf(
					'<div class="error"><p>%s</p></div>',
					__( 'One or more events could not be updated for Display Options compatibility.', 'qsot-display-options' )
				);
			break;
		}
	}

	// handle the tool logic
	public static function sys_status_do_tool( $performed, $args ) {
		if ( ! isset( $_GET['n'] ) || ! wp_verify_nonce( $_GET['n'], 'DOdataUp' ) )
			return array( $performed, $args );

		global $wpdb, $wp_object_cache;

		$per = 500;
		$offset = 0;
		// setup a query to grab a list of events to update the data for
		$q = 'select id from ' . $wpdb->posts . ' p left join ' . $wpdb->postmeta . ' pm on pm.post_id = p.id and meta_key = "_price" where post_type = "qsot-event" and post_parent != 0 and meta_value is null limit %d offset %d';

		// while there are events to process
		while ( $ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( $q, $per, $offset ) ) ) ) {
			$offset += $per;

			// find all the event_area_ids for the events, so that we can look up the prices
			$raw = $wpdb->get_results( 'select post_id, meta_value from ' . $wpdb->postmeta . ' where meta_key = "_event_area_id" and post_id in (' . implode( ',', $ids ) . ')' );

			// create a map of event to event_area, and a unique list of event areas to find prices for
			$ea_list = $map = array();
			foreach ( $raw as $row ) {
				$ea_list[ $row->meta_value ] = 1;
				$map[ $row->post_id ] = $row->meta_value;
			}

			// bail if no prices could be found, for some reason
			if ( empty( $ea_list ) )
				continue;

			// get the product_ids of the event_area main price
			$raw = $wpdb->get_results( 'select post_id, meta_value from ' . $wpdb->postmeta . ' where meta_key = "_pricing_options" and post_id in (' . implode( ',', array_map( 'absint', array_keys( $ea_list ) ) ) . ')' );

			// map the event_areas to products, and create a unique list of products to retrieve prices for
			$prod_list = $ea_map = array();
			foreach ( $raw as $row ) {
				$prod_list[ $row->meta_value ] = 1;
				$ea_map[ $row->post_id ] = $row->meta_value;
			}

			// if there are no products found, for some reason, then bail
			if ( empty( $prod_list ) )
				continue;

			// find all the prices for the products we found
			$raw = $wpdb->get_results( 'select post_id, meta_value from ' . $wpdb->postmeta . ' where meta_key = "_price" and post_id in (' . implode( ',', array_map( 'absint', array_keys( $prod_list ) ) ) . ')' );

			// create a map of product to price
			$prod_map = array();
			foreach ( $raw as $row ) {
				$prod_map[ $row->post_id ] = $row->meta_value;
			}

			// update the price for each event
			foreach ( $map as $event_id => $ea_id ) {
				$prod_id = isset( $ea_map[ $ea_id ] ) ? $ea_map[ $ea_id ] : false;
				update_post_meta( $event_id, '_price', $prod_id && isset( $prod_map[ $prod_id ] ) ? $prod_map[ $prod_id ] : 0 );
			}

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
		$needs = get_option( '_qsot_do_needs_update', array() );
		if ( $needs ) {
			$args['performed'] = 'failed-event-update';
		// otherwise we passed
		} else {
			$args['performed'] = 'updated-events';
		}

		return array( $performed, $args );
	}

	// load the settings pages
	public static function load_settings_pages( $list ) {
		$list[] = include_once( QSOT_display_options_launcher::plugin_dir() . 'display-options/settings/display-options.php' );
		return $list;
	}

	// setup the options that are available to control our 'Display Options'
	protected static function _setup_admin_options() {
		// setup the default values
		self::$options->def( 'qsot-do-show-in-shop', 'yes' );
		self::$options->def( 'qsot-do-hide-past-events', 'yes' );

		// the 'Events in Shop' heading on the Frontend tab
		self::$options->add( array(
			'order' => 100,
			'type' => 'title',
			'title' => __( 'Events in Shop', 'qsot-display-options' ),
			'id' => 'heading-frontend-eis-1',
			'page' => 'display-options',
		) );

		// whether or not to show the events in the shop experience
		self::$options->add( array(
			'order' => 110,
			'id' => 'qsot-do-show-in-shop',
			'type' => 'checkbox',
			'title' => __( 'Show Events in Shop', 'qsot-display-options' ),
			'desc' => __( 'Yes, not only show events on the calendar, but they also show them in the shop, as purchaseable products.', 'qsot-display-options' ),
			'default' => 'yes',
			'page' => 'display-options',
		) );

		// whether or not to hide events from the shop that have already passed
		self::$options->add( array(
			'order' => 120,
			'id' => 'qsot-do-hide-past-events',
			'type' => 'checkbox',
			'title' => __( 'Hide Past Events - Shop', 'qsot-display-options' ),
			'desc' => __( 'Yes, when showing events in the shop, hide events that have already passed.', 'qsot-display-options' ),
			'default' => 'yes',
			'page' => 'display-options',
		) );

		// end the 'Events in Shop' section on the page
		self::$options->add(array(
			'order' => 199,
			'type' => 'sectionend',
			'id' => 'heading-frontend-eis-1',
			'page' => 'display-options',
		));
	}

	// internal check to see if we need a data update
	protected static function _check_data_update() {
		global $wpdb;

		// check if there are any events that do not have a '_price' meta_value
		$exists = $wpdb->get_col(
			'select id from ' . $wpdb->posts . ' p left join ' . $wpdb->postmeta . ' pm on pm.post_id = p.id and meta_key = "_price" where post_type = "qsot-event" and post_parent != 0 and meta_value is null'
		);

		// if there are any that exist without a price designation, then update our option to indicate that
		update_option( '_qsot_do_needs_update', $exists, '', 'no' );
	}

	// during activation, update some data
	public static function on_activation() {
		self::_check_data_update();
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_display_options_admin::pre_init();
