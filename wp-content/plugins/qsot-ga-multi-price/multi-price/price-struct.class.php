<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// class to handle everything dealing with the GAMP pricing structs
class QSOT_GAMP_Price_Struct {
	// container for the singleton instance
	protected static $instance = null;

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_GAMP_Price_Struct )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new QSOT_GAMP_Price_Struct();
	}

	// constructor. handles instance setup, and multi instance prevention
	public function __construct() {
		// if there is already an instance of this object, then bail now
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_GAMP_Price_Struct )
			throw new Exception( sprintf( __( 'There can only be one instance of the %s object at a time.', 'opentickets-community-edition' ), __CLASS__ ), 12000 );

		// otherwise, set this as the known instance
		self::$instance = $this;

		// defaults from parent
		//parent::__construct();

		// and call the intialization function
		$this->initialize();
	}

	// destructor. handles instance destruction
	public function __destruct() {
		$this->deinitialize();
	}


	// setup the object
	public function initialize() {
		// setup the tables and table names used by this event area type
		$this->setup_table_names();
		add_action( 'switch_blog', array( &$this, 'setup_table_names' ), PHP_INT_MAX, 2 );
		add_filter( 'qsot-upgrader-table-descriptions', array( &$this, 'setup_tables' ), 1 );

		// sub event bulk edit stuff
		add_action( 'qsot-events-bulk-edit-settings', array( &$this, 'price_struct_bulk_edit_settings' ), 30, 3 );
		add_filter( 'qsot-events-save-sub-event-settings', array( &$this, 'save_sub_event_settings' ), 10, 3 );
		add_filter( 'qsot-load-child-event-settings', array( &$this, 'load_child_event_settings' ), 10, 3 );
	}

	// destroy the object
	public function deinitialize() {
	}

	// get the pricing structure for a given event
	public function get_by_event_id( $event_id, $extra_params='' ) {
		// get the pricing structure id for the event
		$ps_id = intval( get_post_meta( $event_id, '_pricing_struct_id', true ) );

		// if there is no struct id, then bail
		if ( $ps_id <= 0 )
			return null;

		$extra_params = wp_parse_args( $extra_params, array() );
		// otherwise, load the price struct
		$result = $this->find( wp_parse_args( array( 'id' => $ps_id ), $extra_params ) );

		return is_array( $result ) && isset( $result[ $ps_id ] ) ? $result[ $ps_id ] : new WP_Error( 'no_such_struct', __( 'Could not find that pricing structure.', 'qsot-ga-multi-price' ) );
	}

	// get the pricing struct by the struct id
	public function get_by_id( $id, $extra_params='' ) {
		$extra_params = wp_parse_args( $extra_params, array() );
		// otherwise, load the price struct
		$result = $this->find( wp_parse_args( array( 'id' => $id ), $extra_params ) );

		return is_array( $result ) && isset( $result[ $id ] ) ? $result[ $id ] : new WP_Error( 'no_such_struct', __( 'Could not find that pricing structure.', 'qsot-ga-multi-price' ) );
	}

	// get the pricing structs by the event_area_id
	public function get_by_event_area_id( $event_area_id, $extra_params='' ) {
		$extra_params = wp_parse_args( $extra_params, array() );
		// get all the matching structs
		$result = $this->find( wp_parse_args( array( 'event_area_id' => $event_area_id ), $extra_params ) );

		// if the supplied event_area_id was not an array, then just return whatever the found list is
		if ( ! is_array( $event_area_id ) )
			return $result;

		// otherwise, create a new list, indexed by the event_area_id, and return that
		$by_ea_id = array();
		foreach ( $result as $item ) {
			// if there is not yet an index fot the event area of this item, then create it
			if ( ! isset( $by_ea_id[ $item->event_area_id ] ) )
				$by_ea_id[ $item->event_area_id ] = array();

			$by_ea_id[ $item->event_area_id ][ $item->id ] = $item;
		}

		return $by_ea_id;
	}

	// get a list of pricing structs that match the given criteria
	public function find( $args='' ) {
		// normalize the input
		$args = wp_parse_args( $args, apply_filters( 'qsot-find-price-struct-default-args', array(
			'id' => '',
			'event_area_id' => '',
			'fields' => 'objects',
			'with__prices' => true, // with the prices by default. if you change this, please leave note as to why
			'price_list_format' => 'objects',
			'price_sub_group' => '',
		), $args ) );

		// sanitize some of the data
		$args['id'] = is_array( $args['id'] ) ? array_filter( array_map( 'absint', $args['id'] ) ) : absint( $args['id'] );
		$args['event_area_id'] = is_array( $args['event_area_id'] ) ? array_filter( array_map( 'absint', $args['event_area_id'] ) ) : absint( $args['event_area_id'] );

		// if there is no id or event_area_id specified, then bail, because that could produce a huge list
		if ( empty( $args['id'] ) && empty( $args['event_area_id'] ) )
			return array();

		global $wpdb;
		// if we already looked up the matching ids for this query before, then use that cache
		$found = null;
		$key = md5( @json_encode( $args ) );
		$ids = wp_cache_get( $key, 'ps-find', false, $found );

		// if there was no matching cache item then regen the value
		if ( ( null !== $found && ! $found ) || ( null === $found && false === $ids ) ) {
			// construct the search query to find the matching price structs
			$q = 'select id, event_area_id from ' . $wpdb->qsot_price_structs . ' where 1=1';

			// if the id was supplied, then add that to the query
			if ( ! empty( $args['id'] ) ) {
				if ( is_array( $args['id'] ) )
					$q .= ' and id in (' . implode( ',', $args['id'] ) . ')';
				else
					$q .= $wpdb->prepare( ' and id = %d', $args['id'] );
			}

			// if the event_area_id was supplied, then add that to the query
			if ( ! empty( $args['event_area_id'] ) ) {
				if ( is_array( $args['event_area_id'] ) )
					$q .= ' and event_area_id in (' . implode( ',', $args['event_area_id'] ) . ')';
				else
					$q .= $wpdb->prepare( ' and event_area_id = %d', $args['event_area_id'] );
			}

			// run the search query to get the list of ids matching the criteria
			$ids = $wpdb->get_results( $q );

			// update the cache
			wp_cache_set( $key, $ids, 'ps-find', 3600 );
		}

		// if there were no matches, then bail
		if ( empty( $ids ) )
			return array();

		// if the request is just for the matching pricing structure ids, then return now with a list of the matching ids
		if ( 'ids' == $args['fields'] )
			return wp_list_pluck( $ids, 'id' );

		$results = $lookup = array();
		// cycle through the results. if we have a cache for any of the items, then use the cache. otherwise, add the item to a secondary list that will be looked up completely in a moment
		foreach ( $ids as $id ) {
			$found = null;
			$key = 'price-struct-' . $id->id;
			$group = 'ea-structs-' . $id->event_area_id;
			// attempt to lookup this item in the cache
			$cache = wp_cache_get( $key, $group, false, $found );

			// if the cache exists, then use it
			if ( ( null !== $found && $found ) || ( null === $found && false !== $cache ) )
				$results[ $id->id ] = clone $cache;
			else
				$lookup[] = $id->id;
		}

		// if there are items that need looking up, then do the full lookup now
		if ( ! empty( $lookup ) ) {
			// lookup all the data for the core structs
			$raw_structs = $wpdb->get_results( 'select * from ' . $wpdb->qsot_price_structs . ' where id in (' . implode( ',', $lookup ) . ')' );

			// if there are matching structs, then additionally lookup all the struct prices
			if ( is_array( $raw_structs ) && ! empty( $raw_structs ) ) {
				$structs = array();
				// index the structs by their id, and add a key for the prices to be stored
				while ( count( $raw_structs ) ) {
					$struct = array_shift( $raw_structs );
					$struct->prices = array( '0' => array() );
					$structs[ $struct->id ] = $struct;
				}

				// lookup all the prices for all the structs
				$all_prices = $wpdb->get_results( 'select * from ' . $wpdb->qsot_price_struct_prices . ' where price_struct_id in (' . implode( ',', $lookup ) . ')' );

				// cycle through the resulting prices, and assign them to the appropriate struct
				foreach ( $all_prices as $price ) {
					// if the struct that this price belongs to is not in our struct list, then skip
					if ( ! isset( $structs[ $price->price_struct_id ] ) )
						continue;

					// if the subgroup is not created yet, then create it
					if ( ! isset( $structs[ $price->price_struct_id ]->prices[ $price->sub_group ] ) )
						$structs[ $price->price_struct_id ]->prices[ $price->sub_group ] = array();

					// add this price to that list
					$structs[ $price->price_struct_id ]->prices[ $price->sub_group ][] = $price;
				}

				// order the price lists for each struct, by the display order. also update the cache for each of the structs we found. finally, update our returned resultset with each struct
				while ( count( $structs ) ) {
					$struct = array_shift( $structs );

					foreach ( $struct->prices as $sg_id => $sub_group ) {
						// create a list of the display orders for the group items
						$display_orders = wp_list_pluck( $sub_group, 'display_order' );

						// sort the list by the display order
						array_multisort( $display_orders, SORT_ASC, SORT_NUMERIC, $sub_group );

						// update the list
						$struct->prices[ $sg_id ] = $sub_group;
					}

					// update the cache for this struct
					wp_cache_set( 'price-struct-' . $struct->id, $struct, 'ea-structs-' . $struct->event_area_id, 3600 );

					// update our results
					$results[ $struct->id ] = $struct;
				}
			}
		}

		// if the result was to include the prices, then
		if ( $args['with__prices'] ) {
			// first filter out any from the list, that this user cannot view
			if ( is_array( $results ) ) {
				foreach ( $results as $ps_id => $struct ) {
					if ( is_array( $struct->prices ) ) {
						foreach ( $struct->prices as $sg_id => $sub_group ) {
							if ( is_array( $sub_group ) ) {
								$new_sub_group = array();
								foreach ( $sub_group as $index => $price ) {
									$price_post = get_post( $price->product_id );
									if ( 'private' == $price_post->post_status && current_user_can( 'read_private_posts', $price_post->ID ) )
										$new_sub_group[] = $price;
									else if ( 'private' != $price_post->post_status )
										$new_sub_group[] = $price;
								}
								$struct->prices[ $sg_id ] = $new_sub_group;
							}
						}
					}
				}
			}

			// if the request was that the prices just be in product_id form, then convert them to id for now
			if ( 'ids' === $args['price_list_format'] ) {
				if ( is_array( $results ) ) foreach ( $results as $ps_id => $struct )
					if ( is_array( $struct->prices ) ) foreach ( $struct->prices as $sg_id => $sub_group )
						if ( is_array( $sub_group ) ) foreach ( $sub_group as $ind => $price )
							$results[ $ps_id ]->prices[ $sg_id ][ $ind ] = $price->product_id;
			// if the request was for price objects, then load the wc_products now
			} else if ( 'objects' === $args['price_list_format'] ) {
				foreach ( $results as $ps_id => $struct ) {
					foreach ( $struct->prices as $sg_id => $sub_group ) {
						foreach ( $sub_group as $ind => $price ) {
							$found = null;
							$cache = wp_cache_get( 'product-' . $price->product_id, 'ps-prices', false, $found );
							// if the product cache was not found, then regen now
							if ( ( null !== $found && ! $found ) || ( null === $found && false === $cache ) ) {
								$cache = wc_get_product( $price->product_id );
								if ( is_object( $cache ) && ! is_wp_error( $cache ) )
									wp_cache_set( 'product-' . $price->product_id, $cache, 'ps-prices', 3600 );
								else
									$cache = false;
							}
							// if we found the product, then add it to the list
							if ( false !== $cache ) {
								$price->product_raw_name = $cache->get_title();
								$price->product_raw_price = strip_tags( wc_price( $cache->get_price() ) );
								$price->product_price = strip_tags( $cache->get_price() );
								$price->product_name = sprintf( __( '"%s" (%s)', 'qsot-seating' ), $price->product_raw_name, $price->product_raw_price );
								$results[ $ps_id ]->prices[ $sg_id ][ $ind ] = $price;
							}
						}
					}
				}
			}

			// if the results should only include prices for a specific sub_group, then condense the list to just that one subgroup
			if ( '' !== $args['price_sub_group'] && false !== $args['price_sub_group'] )
				foreach ( $results as $ps_id => $struct )
					$results[ $ps_id ]->prices = isset( $struct->prices[ $args['price_sub_group'] ] ) ? $struct->prices[ $args['price_sub_group'] ] : array();
		// otherwise remove the prices completely
		} else {
			foreach ( $results as $ps_id => $struct )
				unset( $results[ $ps_id ]->prices );
		}

		return $results;
	}

	// delete existing structs, based on the supplied struct_ids
	public function delete_structs( $ids ) {
		// normalize the input
		$ids = array_filter( array_map( 'intval', $ids ) );

		// if there are none to delete, then bail
		if ( empty( $ids ) )
			return;

		global $wpdb;
		// delete the prices first
		$wpdb->query( 'delete from ' . $wpdb->qsot_price_struct_prices . ' where price_struct_id in (' . implode( ',', $ids ) . ')' );

		// now delete the actual structs
		$wpdb->query( 'delete from ' . $wpdb->qsot_price_structs . ' where id in (' . implode( ',', $ids ) . ')' );
	}

	// update existing pricing structs, based on the supplied data
	public function update_structs( $updates ) {
		global $wpdb;
		$case = '';
		$ids = array();
		// construct update case sql statement, based on the supplied data
		foreach ( $updates as $update ) {
			$case .= $wpdb->prepare( ' when id = %d then %s', $update['old']->id, $update['new']->name );
			$ids[] = $update['old']->id;
		}

		// if the case is empty, bail
		if ( empty( $case ) )
			return;

		// glue the statement for the final update statement for the structs only
		$q = 'update ' . $wpdb->qsot_price_structs . ' set name = case' . $case . ' else name end where id in (' . implode( ',', $ids ) . ')';

		// run the update
		$wpdb->query( $q );

		// now update the prices for each struct. do this by removing them all, and re-adding them all
		$wpdb->query( 'delete from ' . $wpdb->qsot_price_struct_prices . ' where price_struct_id in (' . implode( ',', $ids ) . ')' );
		
		$next = '';
		// construct the insert statement for adding them all back
		$q = 'insert into ' . $wpdb->qsot_price_struct_prices . ' (price_struct_id,product_id,display_order,sub_group) values ';
		foreach ( $updates as $update ) {
			foreach ( $update['new']->prices as $price ) {
				$q .= $next . $wpdb->prepare( '(%d,%d,%d,%s)', $update['old']->id, $price->product_id, $price->display_order, isset( $price->sub_group ) ? $price->sub_group : 0 );
				$next = ',';
			}
		}

		// if there was at least one price to insert, then run the insert
		if ( $next )
			$wpdb->query( $q );
	}

	// insert new pricing structs, based on the supplied data
	public function insert_structs( $inserts, $event_area_id ) {
		global $wpdb;
		$temp_to_id = array();
		// cycle through the supplied list, and insert each one, one at a time
		foreach ( $inserts as $temp_id => $insert )
			if ( $wpdb->insert( $wpdb->qsot_price_structs, array( 'event_area_id' => $event_area_id, 'name' => $insert->name ) ) )
				$temp_to_id[ $temp_id ] = $wpdb->insert_id;

		$next = '';
		// now construct the query to insert all the prices
		$q = 'insert into ' . $wpdb->qsot_price_struct_prices . ' (price_struct_id,product_id,display_order,sub_group) values ';
		foreach ( $inserts as $insert ) {
			foreach ( $insert->prices as $price ) if ( isset( $temp_to_id[ $insert->id ] ) ) {
				$q .= $next . $wpdb->prepare( '(%d,%d,%d,%s)', $temp_to_id[ $insert->id ], $price->product_id, $price->display_order, isset( $price->sub_group ) ? $price->sub_group : 0 );
				$next = ',';
			}
		}

		// if there was at least one price to insert, then run the insert
		if ( $next )
			$wpdb->query( $q );
	}

	// add the form field that controls the price struct selection for events, on the edit event page
	public function price_struct_bulk_edit_settings( $list, $post, $mb ) {
		// get a list of all event_area_ids, so that we can feed it to our price struct lookup function
		$event_area_ids = get_posts( array(
			'post_type' => 'qsot-event-area',
			'post_status' => 'any',
			'posts_per_page' => -1,
			'fields' => 'ids',
		) );

		// get a list of all the pricing structures for all event areas
		// @NOTE - this needs love, possibly to be ajaxy.
		$price_structures = $this->find( array( 'event_area_id' => $event_area_ids, 'with__prices' => false ) );

		// render the form fields
		ob_start();
		?>
			<div class="setting-group">
				<div class="setting" rel="setting-main" tag="price-struct">
					<div class="setting-current">
						<span class="setting-name"><?php _e( 'Pricing Structure:', 'qsot' ) ?></span>
						<span class="setting-current-value" rel="setting-display"></span>
						<a class="edit-btn" href="#" rel="setting-edit" scope="[rel=setting]" tar="[rel=form]"><?php _e( 'Edit', 'qsot' ) ?></a>
						<input type="hidden" name="settings[price-struct]" value="" scope="[rel=setting-main]" rel="price-struct" />
					</div>
					<div class="setting-edit-form" rel="setting-form">
						<select rel="pool" style="display:none;">
							<option value="0"><?php _e( '- None -', 'qsot' ) ?></option>
							<?php foreach ( $price_structures as $struct ): ?>
								<option value="<?php echo esc_attr( $struct->id ) ?>" event-area-id="<?php echo $struct->event_area_id ?>"><?php echo $struct->name ?></option>
							<?php endforeach; ?>
						</select>
						<select name="price-struct" rel="vis-list">
							<option value="0"><?php _e( '- None -', 'qsot' ) ?></option>
						</select>
						<div class="edit-setting-actions">
							<input type="button" class="button" rel="setting-save" value="<?php _e( 'OK', 'qsot' ) ?>" />
							<a href="#" rel="setting-cancel"><?php _e( 'Cancel', 'qsot' ) ?></a>
						</div>
					</div>
				</div>
			</div>
		<?php
		$out = ob_get_contents();
		ob_end_clean();

		// update the list with the pricing struct form fields
		$list['price-struct'] = $out;

		return $list;
	}

	// when saving a sub event, we need to make sure to save what pricing structure to use
	public function save_sub_event_settings( $settings, $parent_id, $parent ) {
		// if the pricing structure was selected
		if ( isset( $settings['submitted'], $settings['submitted']->price_struct ) ) {
			// then add it to the list of meta to save for this child event
			$settings['meta']['_pricing_struct_id'] = $settings['submitted']->price_struct;
		}

		return $settings;
	}

	// during page load of the edit event page, we need to load all the data about the child events. this method will add the price_struct data to the child event
	public static function load_child_event_settings( $settings, $defs, $event ) {
		// if the event is loaded then
		if ( is_object( $event ) && isset( $event->ID ) ) {
			// add the pricing struct data
			$settings['price-struct'] = get_post_meta( $event->ID, '_pricing_struct_id', true );
		}

		return $settings;
	}

	// 1.2.1 has an upgrade to table indexes, which core wp DB upgrader does not handle very well. this function does a pre-update that prevents the problem
	public function version_1_2_1_upgrade() {
		global $wpdb;

		// list of indexes to drop
		$drop_indexes = array( 'ps2p', 'pid' );
		$tables = $wpdb->get_col( 'show tables' );
		$tables = array_combine( $tables, array_fill( 0, count( $tables ), 1 ) );

		// for each index
		foreach ( $drop_indexes as $index ) {
			if ( isset( $tables[ $wpdb->qsot_price_struct_prices ] ) ) {
				// if the index exists
				$exists = $wpdb->get_row( $wpdb->prepare( 'show index from ' . $wpdb->qsot_price_struct_prices . ' where Key_name = %s', $index ) );
				if ( $exists ) {
					// drop it
					$q = 'alter ignore table ' . $wpdb->qsot_price_struct_prices . ' drop index `' . $index . '`';
					$wpdb->query( $q );
				}
			}
		}
	}

	// setup the table names used by the general admission area type, for the current blog
	public function setup_table_names() {
		global $wpdb;
		$wpdb->qsot_price_structs = $wpdb->prefix . 'qsot_price_structures';
		$wpdb->qsot_price_struct_prices = $wpdb->prefix . 'qsot_price_structure_prices';
	}

	public function setup_tables( $tables ) {
    global $wpdb;

		// if the opentickets plugin is at a version before we improved the db updater, then run the upgrae manually
		if ( class_exists( 'QSOT' ) && version_compare( QSOT::version(), '1.10.6' ) < 0 ) {
			// maybe remove index if structs table is out of date, since the unique key gets updated. unfortunately this is not handled gracefully in wp.... yet
			$versions = get_option( '_qsot_upgrader_db_table_versions', array() );
			if ( isset( $versions[ $wpdb->qsot_price_struct_prices ] ) && version_compare( $versions[ $wpdb->qsot_price_struct_prices ], '0.1.1' ) < 0 )
				self::version_1_2_1_upgrade();
		}

		// table to hold the actual pricing structures
    $tables[ $wpdb->qsot_price_structs ] = array(
      'version' => '0.1.0',
      'fields' => array(
				'id' => array( 'type' => 'bigint(20) unsigned', 'extra' => 'auto_increment' ), // id of this price structure
				'event_area_id' => array( 'type' => 'bigint(20) unsigned' ), // id of the event area the pricing structure links to
				'name' => array( 'type' => 'varchar(200)' ), // name of this pricing strucutre to be displayed in the admin
      ),   
      'keys' => array(
        'PRIMARY KEY  (id)',
				'INDEX ea (event_area_id)',
      ),
			'pre-update' => array(
				'when' => array(
					'exists' => array(
						'alter ignore table ' . $wpdb->qsot_price_structs . ' drop index `ea`',
					),
				),
			),
    );   

		// table that holds the list of prices that each pricing structure has
    $tables[ $wpdb->qsot_price_struct_prices ] = array(
      'version' => '0.1.2',
      'fields' => array(
				'price_struct_id' => array( 'type' => 'bigint(20) unsigned' ), // id of the price structure that this price is part of
				'product_id' => array( 'type' => 'bigint(20) unsigned' ), // id of the the product for this price
				'display_order' => array( 'type' => 'tinyint(3) unsigned' ), // order in which to display this price
				'sub_group' => array( 'type' => 'bigint(20) unsigned', 'default' => '0' ), // sub grouping of prices. used to specify specific seat pricing
      ),   
      'keys' => array(
				'UNIQUE KEY ps2p (price_struct_id, product_id, sub_group)',
				'INDEX pid (product_id)',
				'KEY sb_id (sub_group)',
      ),
			'pre-update' => array(
				'when' => array(
					'exists' => array(
						'alter ignore table ' . $wpdb->qsot_price_struct_prices . ' drop index `ps2p`',
						'alter ignore table ' . $wpdb->qsot_price_struct_prices . ' drop index `pid`',
					),
				),
			),
    );   

    return $tables;
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_GAMP_Price_Struct::instance();
