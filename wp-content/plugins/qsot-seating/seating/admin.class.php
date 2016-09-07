<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) && function_exists( 'is_admin' ) && is_admin() ):

// class to handle the Seating plugin admin tools
class QSOT_Seating_Admin {
	// container for the singleton instance
	protected static $instance = array();

	// internal constants for zome types
	const ZONES = 1;
	const ZOOM_ZONES = 2;

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof self )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new self();
	}

	// constructor. handles instance setup, and multi instance prevention
	protected function __construct() {
		// and call the intialization function
		$this->initialize();
	}

	// destructor. handles instance destruction
	public function __destruct() {
		$this->deinitialize();
	}


	// setup the object
	public function initialize() {
		// add and handle a new system status tool that will force a resync from scratch
		add_action( 'qsot-ss-tool-HFrSync', array( &$this, 'run_HFrSync_tool' ), 1000, 2 );
		add_action( 'qsot-ss-tool-FDupes', array( &$this, 'run_FDupes_tool' ), 1000, 2 );

		// add the zone_id to our tool
		add_filter( 'qsot-system-status-tools-RsOi2Tt-exists-where', array( &$this, 'add_zone_to_resync_where' ), 10, 5 );
		add_filter( 'qsot-system-status-tools-RsOi2Tt-update-data', array( &$this, 'add_zone_to_resync_data' ), 10, 4 );

		// if we have already run the plugins_loaded action, just call this method outright, otherwise queue it to run at the appropriate time
		if ( did_action( 'admin_init' ) )
			$this->on_admin_init();
		else
			add_action( 'admin_init', array( &$this, 'on_admin_init' ), PHP_INT_MAX );
	}

	// destroy the object
	public function deinitialize() {
	}

	// during initialization of the admin, we need to do some stuff, like register tools
	public function on_admin_init() {
		// if the system status page is not declared, or this is not the admin, the bail
		if ( ! is_admin() || ! class_exists( 'QSOT_system_status_page' ) )
			return;

		global $wpdb;
		// get the system status page
		$ss = QSOT_system_status_page::instance();

		// register our tool to clear the cache table
		$ss->register_tool(
			'CsCt',
			array(
				'name' => __( 'Clear the seating and pricing cache', 'qsot-seating' ),
				'description' => sprintf( __( 'This tool clears out the %s table\'s contents.', 'qsot-seating' ), '<code>' . $wpdb->qsot_seating_cache . '</code>' ),
				'function' => array( &$this, 'run_CsCt_tool' ),
				'messages' => array(
					'cleared' => $this->_updatedw( __( 'The %s table has been cleared.', 'qsot-seating' ) ),
					'clear-denied' => $this->_errorw( __( 'You do not have permission to use this tool.', 'qsot-seating' ) ),
				),
			)
		);
		$ss->register_tool(
			'HFrSync',
			array(
				'name' => __( 'Force Resync (from scratch)', 'qsot-seating' ),
				'description' => __( 'Same as the "Resync" tool above, but clears out the reservations table completely first, forcing all reservations to be recalculated based on the purchased tickets.', 'qsot-seating' ),
				'function' => array( &$this, 'run_HFrSync_tool' ),
				'messages' => array(),
			)
		);
		$ss->register_tool(
			'FDupes',
			array(
				'name' => __( 'Find Dupes', 'qsot-seating' ),
				'description' => __( 'Older versions of the Seating Extension may have caused some tickets to be sold twice. To find any conflicts, use this tool.', 'qsot-seating' ),
				'function' => array( &$this, 'run_FDupes_tool' ),
				'messages' => array(),
			)
		);
	}

	// generic wrappers for admin messages
	protected function _updatedw( $str ) { return sprintf( '<div class="updated"><p>%s</p></div>', $str ); }
	protected function _errorw( $str ) { return sprintf( '<div class="error"><p>%s</p></div>', $str ); }

	// tool that clears out the cache table
	public function run_CsCt_tool( $result, $args ) {
		global $wpdb;
		// first check the user can do this
		if ( ! current_user_can( 'manage_options' ) ) {
			$result[0] = false;
			$result[1]['performed'] = 'clear-denied';
			return $result;
		}

		// url back to tools page
		$url = add_query_arg( $result[1], remove_query_arg( array( 'updated', 'performed', 'qsot-tool', 'qsotn' ) ) );

		// clear out the table, while clearing out the wp_cache
		$per = 1000;
		$offset = 0;
		$q = 'select cache_id, cache_key, cache_group from ' . $wpdb->qsot_seating_cache . ' limit %d offset %d';

		?><!doctype html>
<html><style>
.button { border-radius:3px; border-style:solid; border-width:1px; box-sizing:border-box; cursor:pointer; display:inline-block; font-size:13px; height:28px; line-height:26px;
margin:0; padding:0 10px 1px; text-decoration:none; white-space:nowrap; background:#0085ba none repeat scroll 0 0; border-color:#0073aa #006799 #006799; box-shadow:0 1px 0 #006799;
color:#fff; text-decoration:none; text-shadow:0 -1px 1px #006799, 1px 0 1px #006799, 0 1px 1px #006799, -1px 0 1px #006799; }
</style><body style="padding:0; margin:0; background-color:#eee;"><pre style="line-height:1.1em; z-index:1000; position:relative; font-size:10px; padding:1em; margin:0;"><?php
		// get the next group of entries from the table
		$list = $wpdb->get_results( $wpdb->prepare( $q, $per, $offset ) );
		if ( is_array( $list ) && count( $list ) )
			echo '<div style="padding-top:1em">NEXT LIST -> FOUND (' . ( is_array( $list ) ? count( $list ) : 0 ) . ') entries to remove</div>';
		else
			echo '<div style="padding-top:1em"><em>No entries found. These entries are generated by visiting events that have seating charts.</em></div>';

		// cycle through the results, and remove the cache for each
		while ( is_array( $list ) && count( $list ) ) {
			$offset += $per;

			$ids = array();
			// cycle through the list of entries and remove them from wp_cache, while aggregating a list of the ids
			foreach ( $list as $row ) {
				echo '<div>[<u>' . $row->cache_id . '</u>] - <em>' . $row->cache_key . '::' . $row->cache_group . '</em> -> <strong>cleared</strong></div>';
				wp_cache_delete( $row->cache_key, $row->cache_group );
				$ids[] = $row->cache_id;
			}

			// remove all the fetched entries from the cache table
			$ids = array_map( 'absint', $ids );
			if ( ! empty( $ids ) )
				$wpdb->query( 'delete from ' . $wpdb->qsot_seating_cache . ' where cache_id in (' . implode( ',', $ids ) . ')' );

			// get the next group of entries from the table
			$list = $wpdb->get_results( $wpdb->prepare( $q, $per, $offset ) );
			if ( is_array( $list ) && count( $list ) )
				echo '<div style="padding-top:1em">NEXT LIST -> FOUND (' . ( is_array( $list ) ? count( $list ) : 0 ) . ') entries to remove</div>';
			else
				echo '<div style="padding-top:1em"><em>No more entries found to remove.</em></div>';
		}
?>

DONE!
<a class="button" href="<?php echo esc_attr( $url ) ?>"><?php _e( 'Return to Tools Page', 'opentickets-community-edition' ) ?></a>
</pre></body></html><?php
		die();

		// report a successful run
		$result[0] = true;
		$result[1]['performed'] = 'cleared';

		return $result;
	}

	// run the force resync tool logic
	public function run_HFrSync_tool( $result, $args ) {
		if ( ! $this->_verify_action_nonce( 'HFrSync' ) )
			return $result;

		global $wpdb;
		// remove all reservations that are not in progress
		$wpdb->query( 'delete from ' . $wpdb->qsot_event_zone_to_order . ' where state != "interest"' );

		$slug = 'RsOi2Tt';
		$url = $this->_action_nonce( $slug, add_query_arg( array( 'qsot-tool' => $slug ), remove_query_arg( array( 'updated', 'performed', 'qsot-tool', 'qsotn' ) ) ) );
		wp_safe_redirect( $url );
		exit;
	}

	// run the dupe finder tool
	public function run_FDupes_tool( $result, $args ) {
		if ( ! $this->_verify_action_nonce( 'FDupes' ) )
			return $result;

		$this->_find_dupes();
	}

	// add an nonce to action urls
	protected function _action_nonce( $tool='', $url=null ) {
		$nonce = wp_create_nonce( 'qsot-system-status-tools-action-' . $tool );
		return add_query_arg( array( 'qsotn' => $nonce ), $url );
	}

	// verify the nonce on action urls
	protected function _verify_action_nonce( $tool= '' ) {
		if ( ! isset( $_GET['qsotn'] ) ) return false;
		return wp_verify_nonce( $_GET['qsotn'], 'qsot-system-status-tools-action-' . $tool );
	}

	// tool to help find dupes sales for the same seat. really this is a tool to find zones that are over-sold, because of a weird edge case on seating for a few versions
	protected function _find_dupes() {
		// setup the output container
		?><html><head><style>
.bad{ color:#800; }
.good{ color:#080; }
.button { border-radius:3px; border-style:solid; border-width:1px; box-sizing:border-box; cursor:pointer; display:inline-block; font-size:13px; height:28px; line-height:26px;
margin:0; padding:0 10px 1px; text-decoration:none; white-space:nowrap; background:#0085ba none repeat scroll 0 0; border-color:#0073aa #006799 #006799; box-shadow:0 1px 0 #006799;
color:#fff; text-decoration:none; text-shadow:0 -1px 1px #006799, 1px 0 1px #006799, 0 1px 1px #006799, -1px 0 1px #006799; }
</style></head><body><pre style="font-size:10px; line-height:1.2em; color:#333;"><?php

		global $wpdb;
		// get a list of all child event
		$event_ids = get_posts( array(
			'post_type' => 'qsot-event',
			'post_parent__not_in' => array( 0 ),
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
			'meta_key' => '_start',
			'meta_type' => 'DATETIME',
			'orderby' => 'meta_value',
			'order' => 'asc',
		) );

		// cycle through the list of events, and determine if there are any oversold zones
		foreach ( $event_ids as $event_id ) {
			$this->_clean_memory();
			// get the event parts
			$event = get_post( $event_id );
			$event_area = apply_filters( 'qsot-event-area-for-event', false, $event_id );
			$area_type = is_object( $event_area ) && ! is_wp_error( $event_area ) && isset( $event_area->area_type ) ? $event_area->area_type : false;
			$zoner = is_object( $area_type ) && ! is_wp_error( $area_type ) && is_callable( array( &$area_type, 'get_zoner' ) ) ? $area_type->get_zoner() : false;
			if ( ! is_object( $zoner ) || is_wp_error( $zoner ) ) {
				$this->_msg( "SKIPPING [#$event_id {$event->post_title}]\n\n", 'bad' );
				continue;
			}

			// if the zoner has zones, then handle them
			if ( is_callable( array( &$zoner, 'get_zones' ) ) )
				$this->_handle_zones( $zoner, $area_type, $event_area, $event );
			// otherwise, just count for the entire event
			else
				$this->_handle_ga( $zoner, $area_type, $event_area, $event );

			$this->_msg( "\n" );
		}

		// url & button back to tools page
		$url = add_query_arg( array(), remove_query_arg( array( 'updated', 'performed', 'qsot-tool', 'qsotn' ) ) );
		echo '<a class="button" href="' . esc_attr( $url ) . '">' . __( 'Return to Tools Page', 'opentickets-community-edition' ) . '</a>';

		// close output container, and end execution
		echo '</pre></body></html>';
		exit;
	}

	// because this can accumulate a lot of memory usage over time, we need to occassionally clear out our internal caches to compensate
	protected function _clean_memory() {
		global $wpdb, $wp_object_cache;
		// clear our the query cache, cause it can be huge
		$wpdb->flush();

		// clear out the wp_cache cache, if we are using the core wp method, which is an internal associative array
		if ( isset( $wp_object_cache->cache ) && is_array( $wp_object_cache->cache ) ) {
			unset( $wp_object_cache->cache );
			$wp_object_cache->cache = array();
		}
	}

	// print a message to the screen, for our dupe tool
	protected function _msg( $msg, $context='none' ) {
		echo sprintf( '<div class="%s">%s</div>', $context, $msg );
	}

	// handle the dupe finder for zoned events
	protected function _handle_zones( $zoner, $area_type, $event_area, $event ) {
		global $wpdb;
		// print out the enent information
		$this->_msg( "EVENT ID: #{$event->ID}" );
		$this->_msg( ".. EVENT NAME: {$event->post_title}" );
		$this->_msg( ".. STARTS: " . $event->_start );
		$this->_msg( ".. TOTAL CAPACITY (zoned): " . $event_area->meta['_capacity'] );

		// get permanent stati list
		$raw_stati = $zoner->get_stati();
		$stati = array();
		foreach ( $raw_stati as $status )
			if ( 0 == $status[1] )
				$stati[] = $status[0];

		// count the total number of reserved tickets
		$reserved = $wpdb->get_var( $wpdb->prepare( 'select sum( quantity ) from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %s and state in ("' . implode( '","', $stati ) . '")', $event->ID ) );
		$this->_msg( ".. RESERVATIONS: {$reserved}", $reserved > $event_area->meta['_capacity'] ? 'bad' : 'good' );

		// count the total number of sold tickets
		$order_item_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'select distinct order_item_id from ' . $wpdb->prefix . 'woocommerce_order_itemmeta where meta_key = %s and meta_value = %s', '_event_id', $event->ID ) ) );
		$sold = count( $order_item_ids ) ? $wpdb->get_var( $wpdb->prepare(
			'select sum( meta_value ) from '. $wpdb->prefix . 'woocommerce_order_itemmeta where meta_key = %s and order_item_id in (' . implode( ',', $order_item_ids ) . ')',
			'_qty'
		) ) : 0;
		$this->_msg( ".. SOLD TICKETS: {$sold}", $sold > $event_area->meta['_capacity'] ? 'bad' : 'good' );

		// if sold and reserved are equal, then no more logic required
		//if ( $sold == $reserved ) return;

		// otherwise, report on which reservations do not match

		// get the list of zones and their settings, so that we can compare teh capacities later in our logic
		$zones = $zoner->get_zones( array( 'event_area_id' => $event_area->ID ) );
		$zone_ids = array_keys( $zones );

		$all_reserved_indexed = array();
		// get all reservations, and index by zone
		$raw = count( $zone_ids ) ? $wpdb->get_results( $wpdb->prepare(
			'select order_id, order_item_id, zone_id, quantity from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %s and state in ("' . implode( '","', $stati ) . '") and zone_id in (' . implode( ',', $zone_ids ) . ')',
			$event->ID
		) ) : array();
		foreach ( $raw as $row ) {
			if ( ! isset( $all_reserved_indexed[ $row->zone_id ] ) )
				$all_reserved_indexed[ $row->zone_id ] = array();
			$all_reserved_indexed[ $row->zone_id ][ $row->order_item_id ] = $row;
		}

		$all_sold_indexed = array();
		// get all sold tickets, and index by zone
		$raw = count( $order_item_ids ) ? $wpdb->get_results( $wpdb->prepare(
			'select o.order_id, z.order_item_id, z.meta_value zone_id, q.meta_value quantity from ' . $wpdb->prefix . 'woocommerce_order_itemmeta z '
					. 'join ' . $wpdb->prefix . 'woocommerce_order_itemmeta q on z.order_item_id = q.order_item_id and q.meta_key = %s '
					. 'join ' . $wpdb->prefix . 'woocommerce_order_items o on o.order_item_id = z.order_item_id '
					. 'where z.meta_key = %s and z.meta_value in(' . implode( ',', $zone_ids ) . ') and z.order_item_id in(' . implode( ',', $order_item_ids ) . ')',
			'_qty',
			'_zone_id'
		) ) : array();
		foreach ( $raw as $row ) {
			if ( ! isset( $all_sold_indexed[ $row->zone_id ] ) )
				$all_sold_indexed[ $row->zone_id ] = array();
			$all_sold_indexed[ $row->zone_id ][ $row->order_item_id ] = $row;
		}

		// cycle through each zone, and fetch the number of reservations and number of sold tickets for each, and report if they are different
		foreach ( $zone_ids as $zone_id ) {
			$zone = $zones[ $zone_id ];

			// setup the zone specific data containers
			$reserved_indexed = isset( $all_reserved_indexed[ $zone_id ] ) ? $all_reserved_indexed[ $zone_id ] : array();
			$sold_indexed = isset( $all_sold_indexed[ $zone_id ] ) ? $all_sold_indexed[ $zone_id ] : array();
			$reserved_oiids = array_keys( $reserved_indexed );
			$sold_oiids = array_keys( $sold_indexed );

			$msg = array();
			// figure out if there are any reserves without order items, or any order items without reserves
			$only_reserved = array_diff( $reserved_oiids, $sold_oiids );
			$only_sold = array_diff( $sold_oiids, $reserved_oiids );
			$both = array_intersect( $reserved_oiids, $sold_oiids );

			// report on any that only have reservations
			if ( count( $only_reserved ) ) {
				$msg[] = array( ".. there are [" . count( $only_reserved ) . "] reservations that are not linked to existing order_items for zone_id [{$zone_id}]", 'bad' );
				foreach ( $only_reserved as $oiid )
					$msg[] = array( '.. ' . @json_encode( $reserved_indexed[ $oiid ] ), 'bad' );
			}

			// report on any that only have order_items
			if ( count( $only_sold ) ) {
				$msg[] = array( ".. there are [" . count( $only_sold ) . "] sold tickets that are not linked to existing reservation for zone_id [{$zone_id}]", 'bad' );
				foreach ( $only_sold as $oiid )
					$msg[] = array( sprintf( '.. <a href="%s" target="blank">order #%s</a> (item #%s)', get_edit_post_link( $sold_indexed[ $oiid ]->order_id ), $sold_indexed[ $oiid ]->order_id, $oiid ), 'bad' );
			}

			$no_match = array();
			// cycle through the list that exists in both reservations and order items, and report on any that do not match
			foreach ( $both as $oiid ) {
				if ( $reserved_indexed[ $oiid ] == $sold_indexed[ $oiid ] )
					continue;
				$ri = $reserved_indexed[ $oiid ];
				$si = $sold_indexed[ $oiid ];
				$no_match[] = sprintf( '.. <a href="%s" target="blank">order #%s</a> (item #%s) sold=%s; reserved=%s', get_edit_post_link( $ri->order_id ), $ri->order_id, $oiid, $si->quantity, $ri->quantity );
			}
			if ( ! empty( $no_match ) )
				$msg[] = array( '.. the following sold order ticket quantities do not match their reservation quantities' . "\n" . implode( "\n", $no_match ), 'bad' );

			// finally if the sold or reserved values are higher than the capacity, report on that too
			if ( array_sum( wp_list_pluck( $reserved_indexed, 'quantity' ) ) > $zone->capacity ) {
				$msg[] = array( '.. the RESERVATIONS for this zone total more than the capacity for the zone, which is [' . $zone->capacity . ']', 'bad' );
				foreach ( $reserved_indexed as $row )
					$msg[] = array( sprintf( '.. .. <a href="%s" target="blank">order #%s</a> (item #%s) reserved=%s', get_edit_post_link( $row->order_id ), $row->order_id, $row->order_item_id, $row->quantity ), 'bad' );
			}
			if ( array_sum( wp_list_pluck( $sold_indexed, 'quantity' ) ) > $zone->capacity ) {
				$msg[] = array( '.. the SOLD TICKETS for this zone total more than the capacity for the zone, which is [' . $zone->capacity . ']', 'bad' );
				foreach ( $sold_indexed as $row )
					$msg[] = array( sprintf( '.. .. <a href="%s" target="blank">order #%s</a> (item #%s) sold=%s', get_edit_post_link( $row->order_id ), $row->order_id, $row->order_item_id, $row->quantity ), 'bad' );
			}

			// if there are any messages for this zone, report them now
			if ( count( $msg ) ) {
				$this->_msg( '.. PROBLEMS for zone [<u><strong>' . ( '' !== $zone->name ? $zone->name : $zone->abbr ) . ' #' . $zone->id . '</strong></u>]', 'bad' );
				foreach ( $msg as $m )
					$this->_msg( '.. ' . $m[0], $m[1] );
			}
		}
	}

	// handle ga events dupe finder
	protected function _handle_ga( $zoner, $area_type, $event_area, $event ) {
		global $wpdb;
		// print out the enent information
		$this->_msg( "EVENT ID: #{$event->ID}" );
		$this->_msg( ".. EVENT NAME: {$event->post_title}" );
		$this->_msg( ".. STARTS: " . $event->_start );
		$this->_msg( ".. CAPACITY: " . $event_area->meta['_capacity'] );

		// get permanent stati list
		$raw_stati = $zoner->get_stati();
		$stati = array();
		foreach ( $raw_stati as $status )
			if ( 0 == $status[1] )
				$stati[] = $status[0];

		// count the total number of reserved tickets
		$reserved = absint( $wpdb->get_var( $wpdb->prepare( 'select sum( quantity ) from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %s and state in ("' . implode( '","', $stati ) . '")', $event->ID ) ) );
		$this->_msg( ".. RESERVATIONS: {$reserved}", $reserved > $event_area->meta['_capacity'] ? 'bad' : 'good' );

		// count the total number of sold tickets
		$order_item_ids = array_map( 'absint', $wpdb->get_col( $wpdb->prepare( 'select distinct order_item_id from ' . $wpdb->prefix . 'woocommerce_order_itemmeta where meta_key = %s and meta_value = %s', '_event_id', $event->ID ) ) );
		$sold = count( $order_item_ids ) ? $wpdb->get_var( $wpdb->prepare(
			'select sum( meta_value ) from '. $wpdb->prefix . 'woocommerce_order_itemmeta where meta_key = %s and order_item_id in (' . implode( ',', $order_item_ids ) . ')',
			'_qty'
		) ) : 0;
		$this->_msg( ".. SOLD TICKETS: {$sold}", $sold > $event_area->meta['_capacity'] ? 'bad' : 'good' );

		// if sold and reserved are equal, then no more logic required
		if ( $sold == $reserved )
			return;

		// otherwise, report on which reservations do not match

		// grab the list of reserved ticket order_item_ids
		$reserved_oiids = $wpdb->get_col( $wpdb->prepare( 'select order_item_id from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %s and state in ("' . implode( '","', $stati ) . '")', $event->ID ) );

		// report if there are any reservations that do not have order items, or any order items that do not have reservations
		$only_reserved = array_diff( $reserved_oiids, $order_item_ids );
		$only_order_item = array_diff( $order_item_ids, $reserved_oiids );
		$both = array_intersect( $order_item_ids, $reserved_oiids );
		if ( count( $only_reserved ) ) {
			$this->_msg( ".. there are [" . count( $only_reserved ) . "] reservations that are not linked to existing order_items", 'bad' );
			$this->_msg(
				'.. ' . implode(
					"\n.. ",
					@json_encode(
						$wpdb->get_row( $wpdb->prepare(
							'select * from ' . $wpdb->qsot_event_zone_to_order . ' where event_id = %s and state in ("' . implode( '","', $stati ) . '") and order_item_id in (' . implode( ',', $only_reserved ) . ')',
							$event->ID 
						) )
					)
				),
				'bad'
			);
		}
		if ( count( $only_order_item ) ) {
			$this->_msg( ".. there are [" . count( $only_order_item ) . "] sold tickets that are not linked to existing reservation", 'bad' );
			$order_map = $wpdb->get_results( 'select order_item_id, order_id from ' . $wpdb->prefix . 'woocommerce_order_items where order_item_id in (' . implode( ',', $only_order_item ) . ')' );
			foreach ( $order_map as $row ) {
				$this->_msg( sprintf( '.. <a href="%s" target="blank">order #%s</a> (item #%s)', get_edit_post_link( $row->order_id ), $row->order_id, $row->order_item_id ), 'bad' );
			}
		}

		$om = $q = $rq = array();
		// report on lines that exist in both tables, but do not match up in quantity
		$order_map = count( $both ) ? $wpdb->get_results( 'select order_item_id, order_id from ' . $wpdb->prefix . 'woocommerce_order_items where order_item_id in (' . implode( ',', $both ) . ')' ) : array();
		$qtys = count( $both ) ? $wpdb->get_results( 'select order_item_id, meta_value qty from ' . $wpdb->prefix . 'woocommerce_order_itemmeta where meta_key = "_qty" and order_item_id in(' . implode( ',', $both ) . ')' ) : array();
		$rqtys = count( $both ) ? $wpdb->get_results( 'select order_item_id, quantity from ' . $wpdb->qsot_event_zone_to_order . ' where order_item_id in(' . implode( ',', $both ) . ')' ) : array();
		foreach ( $order_map as $map )
			$om[ $map->order_item_id ] = $map->order_id;
		foreach ( $qtys as $qty )
			$q[ $qty->order_item_id ] = $qty->qty;
		foreach ( $rqtys as $qty )
			$rq[ $qty->order_item_id ] = isset( $rq[ $qty->order_item_id ] ) ? $rq[ $qty->order_item_id ] + $qty->quantity : $qty->quantity;

		// cycle through and compare the two lists. if any do not match, report on them
		$this->_msg( '.. the following sold order ticket quantities do not match their reservation quantities' );
		$s = $r = 0;
		foreach ( $rq as $oiid => $qty ) {
			if ( $qty == $q[ $oiid ] )
				continue;
			$this->_msg(
				sprintf( '.. <a href="%s" target="blank">order #%s</a> (item #%s) sold=%s; reserved=%s', get_edit_post_link( $om[ $oiid ] ), $om[ $oiid ], $oiid, $q[ $oiid ], $qty ),
				'bad'
			);
		}
	}

	// add_the zone_id to the resync tool's data aggregation
	public function add_zone_to_resync_data( $data, $item, $item_id, $order_id ) {
		if ( isset( $item['_zone_id'] ) && $item['_zone_id'] > 0 )
			$data['zone_id'] = intval( $item['_zone_id'] );
		return $data;
	}

	// add the zone_id to our resync tool's where clause
	public function add_zone_to_resync_where( $where, $update, $item, $item_id, $order_id ) {
		if ( isset( $update['zone_id'] ) )
			$where['zone_id'] = $update['zone_id'];
		return $where;
	}
}

QSOT_Seating_Admin::instance();

endif;
