<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

if ( ! class_exists( 'QSOT_Seating_Cacher' ) ):

// handle the caching for elements of the seating charts. specifically for the complex zone and pricing calculations that could be expensive computationally
class QSOT_Seating_Cacher {
	const DEFAULT_EXPIRE = 3600;

	public $max_packet = 0;

	// container for the singleton instance
	protected static $instance = null;

	// get the singleton instance
	public static function instance() {
		// if the instance already exists, use it
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Seating_Cacher )
			return self::$instance;

		// otherwise, start a new instance
		return self::$instance = new QSOT_Seating_Cacher();
	}

	// constructor. handles instance setup, and multi instance prevention
	public function __construct() {
		// if there is already an instance of this object, then bail now
		if ( isset( self::$instance ) && self::$instance instanceof QSOT_Seating_Cacher )
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


	// initialize the object. maybe add actions and filters
	public function initialize() {
		global $wpdb;
		$wpdb->qsot_seating_cache = $wpdb->prefix . 'qsot_seating_cache';
		$this->_max_packet();

		add_filter( 'qsot-upgrader-table-descriptions', array( &$this, 'setup_tables' ), 10000 );
	}

	// deinitialize the object. maybe remove actions and filters
	public function deinitialize() {
	}

	// generic wrappers for admin messages
	protected function _updatedw( $str ) { return sprintf( '<div class="updated"><p>%s</p></div>', $str ); }
	protected function _errorw( $str ) { return sprintf( '<div class="error"><p>%s</p></div>', $str ); }

	// get the max packet size from mysql, if we can
	protected function _max_packet() {
		global $wpdb;

		// find the var in the mysql settings
		$res = $wpdb->get_row( 'show variables like "max_allowed_packet"' );

		// determine the value
		return $this->max_packet = ( 0.95 * ( is_object( $res ) && isset( $res->Value ) ? $res->Value : 1048576 ) );
	}

	// set a cache value
	public function set( $key, $value, $group=false, $expire=false, $never_expire=false ) {
		global $wpdb;

		// normallize the input
		$group = empty( $group ) ? 'default' : $group;
		$expire = empty( $expire ) ? self::DEFAULT_EXPIRE : $expire;
		$never_expire = ! empty( $never_expire );

		// update the wp_cache value
		wp_cache_set( $key, $value, $group, $expire );

		// encode the data for storage in the db
		$value = base64_encode( maybe_serialize( $value ) );

		// if the size of the value is greater than 95% of the max packet, then bail
		if ( strlen( $value ) > $this->max_packet )
			return false;

		// update the database
		return $wpdb->query( $wpdb->prepare(
			'insert into ' . $wpdb->qsot_seating_cache . ' (cache_key, cache_group, cache_expire, cache_never_expire, cache_data) values (%s, %s, %s, %d, %s) on duplicate key update cache_data = %s',
			$key,
			$group,
			mysql2date( 'Y-m-d H:i:s', current_time( 'mysql' ) . ' ' . $expire . ' seconds' ),
			$never_expire ? '1' : '0',
			$value,
			$value
		) );
	}

	// get a cache value based on the key and group
	public function get( $key, $group=false, $force=false, &$found=null ) {
		global $wpdb;

		$one = false;
		$pairs = array();
		// if our request is for multiple entries, then fill our pairs list with that request
		if ( is_array( $key ) ) {
			$pairs = $key;
		// if this request is for a single entry, then normalize that single entry into 
		} else if ( is_scalar( $key ) ) {
			$one = true;
			// normalize the input
			$group = empty( $group ) ? 'default' : $group;
			$pairs[] = array( $key, $group );
		}

		$need_lookup = $result = array();
		// if we are being forced to refetch, then throw all pairs into the need_lookup list
		if ( $force ) {
			$need_lookup = $pairs;
		// otherwise use the local cache for any that dont need looking up
		} else {
			// fetch the wp_cache values, and aggregate a list in which those values are not in local cache
			foreach ( $pairs as $pair ) {
				$found = null;
				// fetch the wp_cache value for the current pair
				$cache = wp_cache_get( $pair[0], $pair[1], false, $found );
				if ( false === $found || ( null === $found && false === $cache ) )
					$need_lookup[] = $pair;
				else
					$result[ $key . ':' . $group ] = $cache;
			}
		}

		// if there are any results that need looking up, do that now
		if ( ! empty( $need_lookup ) ) {
			// generate the sql to grab all the entries being asked for
			$q = 'select * from ' . $wpdb->qsot_seating_cache . ' where ( 1=0';
			foreach ( $need_lookup as $pair )
				$q .= $wpdb->prepare( ' or ( cache_key = %s and cache_group = %s )', $pair[0], $pair[1] );
			$q .= ' ) and ( cache_never_expire = 1 or cache_expire < now() )';
			$lookup = $wpdb->get_results( $q );

			// add the results to the results list
			if ( is_array( $lookup ) )
				foreach ( $lookup as $row )
					$result[ $row->cache_key . ':' . $row->cache_group ] = maybe_unserialize( base64_decode( $row->cache_data ) );
		}

		// if the request was for a single entry, then just return the data for that one entry now
		if ( $one )
			$result = current( $result );

		// if there are any results, mark the results as being found. otherwise mark as not found
		$found = !!$result;

		return $result;
	}

	// delete a cache value based on key and group
	public function delete( $key=false, $group=false ) {
		global $wpdb;

		$pairs = array();
		// if our request is for multiple entries, then fill our pairs list with that request
		if ( is_array( $key ) ) {
			$pairs = $key;
		// if this request is for a single entry, then normalize that single entry into 
		} else if ( is_scalar( $key ) ) {
			// normalize the input
			$group = empty( $group ) ? 'default' : $group;
			$pairs[] = array( $key, $group );
		}

		// if there were no pairs, bail
		if ( empty( $pairs ) )
			return false;

		// remove the local cache for each pair
		foreach ( $pairs as $pair )
			if ( ! ( false === $pair[0] || null === $pair[0] ) && ! ( false === $pair[1] || null === $pair[1] ) )
				wp_cache_delete( $pair[0], $pair[1] );

		$qs = array();
		// construct a query to delete all the caches for each supplied key-group pair
		$q = 'delete from ' . $wpdb->qsot_seating_cache . ' where 1=1 and (';
    foreach ( $pairs as $pair ) { 
      if ( ! empty( $pair[0] ) && ! empty( $pair[1] ) ) 
        $qs[] = $wpdb->prepare( '(cache_key = %s and cache_group = %s)', $pair[0], $pair[1] );
      elseif ( ! empty( $pair[0] ) ) 
        $qs[] = $wpdb->prepare( '(cache_key = %s)', $pair[0] );
      elseif ( ! empty( $pair[1] ) ) 
        $qs[] = $wpdb->prepare( '(cache_group = %s)', $pair[1] );
    }   
		$q .= implode( ' or ', $qs ) . ')';

		return $wpdb->query( $q );
	}

	// setup the tables used by this class
	public function setup_tables( $tables ) {
    global $wpdb;
		
		// skip this if the func is called before the needed vars are set yet (like in a late OTCE activation)
		if ( ! isset( $wpdb->qsot_seating_cache ) )
			return $tables;

		// setup the seating cache table. used to store jsonized versions of various expensive, reused datas, such as the list of all zones in a seating chart, and all their metas
    $tables[ $wpdb->qsot_seating_cache ] = array(
      'version' => '1.0.1',
      'fields' => array(
				'cache_id' => array( 'type' => 'bigint(20) unsigned', 'extra' => 'auto_increment' ), // unique id of this cached object
				'cache_key' => array( 'type' => 'varchar(64)' ), // the unique key for this cache object. like 'event-area-is-375' or 'pricing-structure-11'
				'cache_group' => array( 'type' => 'varchar(64)' ), // the group in which this cached object exists. like 'zones-cache' or 'price-cache'
				'cache_data' => array( 'type' => 'longtext' ), // contents of the cache
				'cache_expire' => array( 'type' => 'datetime' ), // when the cache expires
				'cache_never_expire' => array( 'type' => 'tinyint(1)', 'default' => '0' ), // whether the value never expires
      ),
      'keys' => array(
        'PRIMARY KEY  (cache_id)',
        'KEY grp (cache_group)',
				'UNIQUE item (cache_key, cache_group)',
      )
    );

    return $tables;
	}
}

endif;

if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_Seating_Cacher::instance();
