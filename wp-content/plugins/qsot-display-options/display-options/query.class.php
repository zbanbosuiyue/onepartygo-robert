<?php if ( __FILE__ == $_SERVER['SCRIPT_FILENAME'] ) die( header( 'Location: /') );

// add the functionality of the display_options plugin that takes over the product query, by allowing the tickets for events show in the shop
class QSOT_display_options_query {
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
			//self::_setup_admin_options();
		}

		add_action( 'qsot-after-loading-modules-and-plugins', array( __CLASS__, 'setup_additional_actions_and_filters' ), 10 );
	}

	// we have to load these actions after all opentickets files have been loaded, because otherwise the options check will fail, if the options page has never been saved
	public static function setup_additional_actions_and_filters() {
		if ( 'yes' == self::$options->{'qsot-do-show-in-shop'} ) {
			// setup the basic actions and filters we will need
			add_action( 'woocommerce_product_query', array( __CLASS__, 'product_query' ), 100000, 2 );

			// overtake the product type of event objects, when displaying them in the shop
			add_filter( 'woocommerce_product_class', array( __CLASS__, 'event_product_class' ), 10000, 4 );
			add_action( 'the_post', array( __CLASS__, 'setup_product_for_event' ), 100000, 1 );
		}
	}

	// emulate product setup from wc_setup_product_data()
	public static function setup_product_for_event( $post ) {
		// get the post object
		if ( is_int( $post ) )
			$post = get_post( $post );

		// check that we have an event object
		if ( empty( $post->post_type ) || ! in_array( $post->post_type, array( self::$o->core_post_type ) ) )
			return;

		// unset any previous product
		unset( $GLOBALS['product'] );

		// setup the new product
		$GLOBALS['product'] = wc_get_product( $post );

		return $GLOBALS['product'];
	}

	// when doing the product query, like in the shop, we need to overtake the 'event' objects and make them still appear as products, but not normal ones
	public static function event_product_class( $classname, $product_type, $post_type, $product_id ) {
		// only overtake the classname if the post_type is an event
		if ( self::$o->core_post_type == $post_type ) {
			$classname = 'QSOT_Event_Product';
		}

		return $classname;
	}

	// if we are on a product archive page, then queue up the actions and filters we will need to integrate our event results into the query
	public static function product_query( $q, $wc_query ) {
		add_filter( 'posts_clauses', array( __CLASS__, 'post_clauses' ), 10000, 2 );
		add_filter( 'get_meta_sql', array( __CLASS__, 'get_meta_sql' ), 10000, 6 );
		//add_filter( 'posts_request', function($R) { die(var_dump($R)); }, 10000 );
		
		// get the current post_types
		$types = (array)$q->get( 'post_type' );

		// update the types to include our event post type
		$types[] = qsot_settings::instance()->get( 'core_post_type' );
		$q->query_vars['post_type'] = $types;
		$q->set( 'post_type', $types );
	}

	// integrate our events into this query
	public static function post_clauses( $clauses, $q ) {
		remove_filter( 'posts_clauses', array( __CLASS__, 'post_clauses' ), 10000 );
		//die(var_dump($clauses));
		return $clauses;
	}

	// augment the meta query sql to be selective in how it filters meta
	public static function get_meta_sql( $sql, $queries, $type, $primary_table, $primary_id_column, $context ) {
		remove_filter( 'get_meta_sql', array( __CLASS__, 'get_meta_sql' ), 10000 );
		global $wpdb;

		if ( ! preg_match( '#postmeta#', $sql['join'] ) )
			return $sql;

		// generate the 'product only' sql query, and store it in the where list
		$wheres = array( ' ( ' . $primary_table . '.post_type = "product" ' . $sql['where'] . ' ) ' );

		// do something different depending on the sort orderby
		$orderby = isset( $context->query, $context->query['orderby'] ) ? $context->query['orderby'] : 'id';
		switch ( $orderby ) {
			case 'price':
			case 'price-desc':
				// figure out the price of the ticket, and order by that
				$wheres[] = ' ( ' . $primary_table . '.post_type = "qsot-event" and ' . $wpdb->postmeta . '.meta_key = "_price" ) ';
			break;

			default:
			case 'rating':
			case 'date':
				// we need to sort by product publish date or event start date
				add_filter( 'posts_fields', array( __CLASS__, 'add_special_date' ), 1000, 2 );
				add_filter( 'posts_orderby', array( __CLASS__, 'orderby_special_date' ), 1000, 2 );

				// if the settings say to hide past events, then do so
				$core_where = $primary_table . '.post_parent != 0 and ' . $primary_table . '.post_type = "qsot-event" and ' . $wpdb->postmeta . '.meta_key = "_start" ';
				if ( 'yes' == self::$options->{'qsot-do-hide-past-events'} )
					$wheres[] = ' ( ' . $core_where . ' and cast( ' . $wpdb->postmeta . '.meta_value as datetime ) >= now() ) ';
				else
					$wheres[] = ' ( ' . $core_where . ' ) ';
			break;
		}

		// reconstruct the where
		$sql['where'] = ' and ( ' . implode( ' or ', $wheres ) . ' ) ';

		return $sql;
	}

	// when sorting by date, we need to sort by either the product publish date, or the event start date
	public static function add_special_date( $fields, $query ) {
		remove_filter( 'posts_fields', array( __CLASS__, 'add_special_date' ), 1000 ); // affect only a single query
		global $wpdb;
		$fields .= ', if( ' . $wpdb->postmeta . '.meta_key = "_start", cast( ' . $wpdb->postmeta . '.meta_value as datetime ), ' . $wpdb->posts . '.post_date ) special_date ';
		return $fields;
	}

	// when sorting by date, we need to use our special date field, since it will combine either the product publish date or the event start date into a single field
	public static function orderby_special_date( $orderby, $query ) {
		remove_filter( 'posts_orderby', array( __CLASS__, 'orderby_special_date' ), 1000 ); // affect only a single query
		global $wpdb;
		return str_replace( $wpdb->posts . '.post_date', 'special_date', $orderby );
	}
}

// security
if ( defined( 'ABSPATH' ) && function_exists( 'add_action' ) )
	QSOT_display_options_query::pre_init();
